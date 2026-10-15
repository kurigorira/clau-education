<?php
/**
 * 予定の変更・中止（担当者は編集用パスワード、管理者はログインで操作できる）。
 *  - 公開中の予定を担当者が変更 → 公開は続け「変更あり」として管理者に知らせる
 *  - 差戻しの予定を担当者が変更 → 承認待ちに戻る
 *  - 公開中の予定の中止 → 一覧・カレンダーに「中止」として残す
 *  - 承認待ち・差戻しの予定の取り下げ → 削除（掲載前なので履歴のみ残す）
 */
define('EDU_APP', true);
$config = require __DIR__ . '/config.php';
require __DIR__ . '/common.php';
require __DIR__ . '/store.php';
require __DIR__ . '/form.php';

$id = (int)($_GET['id'] ?? 0);
$errors = [];
$fatal = null;
$event = null;
$old = null;
$locked = false;

try {
    $pdo   = edu_mysql($config);
    $event = $id > 0 ? edu_find($pdo, $id) : null;
    if ($event !== null) {
        $config = edu_config_with_event($config, $event);
    }
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}

if ($event !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    edu_csrf_check();
    $act = (string)($_POST['act'] ?? '');

    try {
        if ($act === 'unlock') {
            // ---- 編集用パスワードの確認（誤りが続くIPは一時ロック） ----
            $fails = edu_fail_count($pdo, 'unlock_fail', edu_client_ip(), (int)$config['lock_minutes']);
            if ($fails >= (int)$config['login_max_fail']) {
                $errors[] = 'パスワードの誤りが続いたため、' . (int)$config['lock_minutes'] . '分間ロックしています。';
            } elseif (password_verify((string)($_POST['edit_pass'] ?? ''), $event['edit_pass_hash'])) {
                edu_unlock($id);
                header('Location: edit.php?id=' . $id);
                exit;
            } else {
                edu_log($pdo, $id, 'unlock_fail', '', '編集用パスワード誤り');
                $errors[] = '編集用パスワードが違います。忘れた場合は管理者に再設定を依頼してください。';
            }

        } elseif (!edu_can_edit($id)) {
            $errors[] = '先に編集用パスワードを入力してください。';

        } elseif ($act === 'save') {
            // ---- 内容の変更 ----
            if ($event['status'] === 'cancelled' && !edu_is_admin()) {
                $errors[] = '中止した予定は変更できません。再開する場合は管理者に連絡するか、新たに登録してください。';
            } else {
                [$old, $data, $errors] = edu_form_read($config, $_POST);
                if (!$errors) {
                    $actor = edu_is_admin() ? edu_admin_name() : $data['contact_name'];
                    $diff  = edu_update($pdo, $event, $data, $actor, edu_is_admin());
                    if ($diff === '') {
                        edu_flash_set('変更点がなかったため、保存しませんでした。');
                    } elseif (!edu_is_admin() && $event['status'] === 'approved') {
                        edu_flash_set('変更を保存しました。掲載は続いたまま、管理者に「変更あり」として通知されます。');
                    } elseif (!edu_is_admin() && $event['status'] === 'rejected') {
                        edu_flash_set('変更を保存し、再申請しました（承認待ち）。');
                    } else {
                        edu_flash_set('変更を保存しました。');
                    }
                    header('Location: view.php?id=' . $id);
                    exit;
                }
            }

        } elseif ($act === 'cancel') {
            // ---- 中止／取り下げ ----
            $reason = trim((string)($_POST['reason'] ?? ''));
            $actor  = edu_is_admin() ? edu_admin_name() : trim((string)($_POST['who'] ?? ''));
            if ($actor === '') {
                $errors[] = '操作者の氏名を入力してください。';
            } elseif (mb_strlen($reason) > 500 || mb_strlen($actor) > 64) {
                $errors[] = '理由は500文字、氏名は64文字以内で入力してください。';
            } elseif ($event['status'] === 'approved') {
                edu_cancel($pdo, $id, $actor, $reason);
                edu_flash_set('「中止」にしました。一覧・カレンダーには中止として表示されます。');
                header('Location: view.php?id=' . $id);
                exit;
            } elseif (in_array($event['status'], ['pending', 'rejected'], true)) {
                edu_delete($pdo, $event, $actor);
                edu_flash_set('登録申請を取り下げました（#' . $id . '）。');
                header('Location: index.php');
                exit;
            } else {
                $errors[] = 'この予定はすでに中止になっています。';
            }
        }
    } catch (Throwable $e) {
        $errors[] = '処理に失敗しました: ' . $e->getMessage();
    }
}

if ($event !== null && $old === null) {
    $old = edu_form_from_event($config, $event);
}

edu_layout_header($config, $event ? '変更・中止：' . $event['title'] : '予定の変更', 'list');
?>

<?php if ($fatal !== null): ?>
  <?php edu_db_error($fatal); ?>
<?php elseif ($event === null): ?>
  <?php http_response_code(404); ?>
  <div class="empty">指定された予定は見つかりませんでした。<br><a href="index.php">一覧へ戻る</a></div>

<?php elseif (!edu_can_edit($id)): ?>
  <div class="panel narrow">
    <h2>変更・中止：<?= h($event['title']) ?></h2>
    <p class="lead">
      <?= h(edu_when_label($event)) ?>
      ／ 登録者 <?= h($event['contact_name']) ?><br>
      登録時に決めた<strong>編集用パスワード</strong>を入力してください。
    </p>
    <?php edu_render_errors($errors); ?>
    <form method="post" action="edit.php?id=<?= (int)$id ?>">
      <?= edu_csrf_field() ?>
      <input type="hidden" name="act" value="unlock">
      <div class="form-row">
        <label>編集用パスワード</label>
        <input type="password" name="edit_pass" autofocus autocomplete="current-password">
      </div>
      <button type="submit">確認</button>
      <a href="view.php?id=<?= (int)$id ?>" class="btn secondary">戻る</a>
    </form>
    <p class="note">パスワードを忘れた場合は、管理者（教育委員会 等）に再設定を依頼してください。</p>
  </div>

<?php else: ?>
  <?php edu_render_errors($errors); ?>

  <?php if ($event['status'] === 'cancelled' && !edu_is_admin()): ?>
    <div class="warn">この予定は中止になっているため変更できません。</div>
  <?php else: ?>
  <div class="panel">
    <h2>内容を変更（登録番号 #<?= (int)$id ?>）</h2>
    <?php if ($event['status'] === 'approved' && !edu_is_admin()): ?>
      <div class="warn">公開中の予定です。保存すると掲載は続いたまま「変更あり」と表示され、変更履歴が残ります。</div>
    <?php elseif ($event['status'] === 'rejected'): ?>
      <div class="warn">差戻し理由: <?= h((string)$event['reject_reason']) ?>　— 修正して保存すると承認待ちに戻ります。</div>
    <?php endif; ?>
    <form method="post" action="edit.php?id=<?= (int)$id ?>">
      <?= edu_csrf_field() ?>
      <input type="hidden" name="act" value="save">
      <?php edu_render_form_fields($config, $old); ?>
      <button type="submit">変更を保存</button>
      <a href="view.php?id=<?= (int)$id ?>" class="btn secondary">保存せずに戻る</a>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($event['status'] !== 'cancelled'): ?>
  <div class="panel danger">
    <h2><?= $event['status'] === 'approved' ? 'この研修を中止する' : 'この登録申請を取り下げる' ?></h2>
    <p class="note">
      <?= $event['status'] === 'approved'
          ? '中止にすると、一覧・カレンダーに「中止」と表示されます（予定は消えません）。'
          : 'まだ掲載前のため、取り下げると予定は削除されます。' ?>
    </p>
    <form method="post" action="edit.php?id=<?= (int)$id ?>"
          onsubmit="return confirm('本当に<?= $event['status'] === 'approved' ? '中止' : '取り下げ' ?>しますか？');">
      <?= edu_csrf_field() ?>
      <input type="hidden" name="act" value="cancel">
      <div class="form-grid">
        <?php if (!edu_is_admin()): ?>
        <div class="form-row">
          <label>操作者の氏名<span class="required">必須</span></label>
          <input type="text" name="who" maxlength="64" value="<?= h($event['contact_name']) ?>">
        </div>
        <?php endif; ?>
        <div class="form-row">
          <label>理由</label>
          <input type="text" name="reason" maxlength="500" placeholder="例: 講師都合のため（後日再開催予定）">
        </div>
      </div>
      <button type="submit" class="danger"><?= $event['status'] === 'approved' ? '中止にする' : '取り下げる' ?></button>
    </form>
  </div>
  <?php endif; ?>
<?php endif; ?>

<?php edu_layout_footer($config); ?>
