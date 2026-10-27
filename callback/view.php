<?php
/**
 * 掲示の詳細。
 *  - 折り返しを受けた職員（誰でも）… 「折り返しを受けた記録」を残す（掲示は閉じない）
 *  - 掲示した本人（簡易パスワード）・管理者 … 連絡がついた／取り下げ／延長・再掲示
 */
define('CB_APP', true);
$config = require __DIR__ . '/config.php';
require __DIR__ . '/common.php';
require __DIR__ . '/store.php';

$id = (int)($_GET['id'] ?? 0);
$hoursChoices = array_map('intval', $config['expire_hours']);
$errors = [];
$fatal = null;
$post = null;
try {
    $pdo  = cb_mysql($config);
    $post = $id > 0 ? cb_find($pdo, $id) : null;
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}

$recv = ['recv_name' => '', 'recv_dept' => '', 'recv_dept_other' => '', 'recv_memo' => ''];
$note = '';

if ($post !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    cb_csrf_check();
    $act = (string)($_POST['act'] ?? '');

    try {
        if ($act === 'receive') {
            // ---- 折り返しを受けた記録（誰でも） ----
            foreach ($recv as $k => $_) {
                $recv[$k] = trim(mb_convert_kana((string)($_POST[$k] ?? ''), 's'));
            }
            $dept = cb_dept_read($config, $_POST, 'recv_dept', '受けた方の部署');
            if ($recv['recv_name'] === '') {
                $errors[] = '受けた方の氏名を入力してください。';
            } elseif ($dept['error'] !== null) {
                $errors[] = $dept['error'];
            } elseif (mb_strlen($recv['recv_name']) > 32 || mb_strlen($recv['recv_memo']) > 100) {
                $errors[] = '氏名は32文字・メモは100文字以内で入力してください。';
            } else {
                $detail = $dept['value'] . ($recv['recv_memo'] !== '' ? '：' . $recv['recv_memo'] : '');
                cb_receive($pdo, $id, $recv['recv_name'], $detail);
                cb_flash_set('折り返しを受けた記録を残しました。担当者に繋がっていない場合は、掲示はそのまま残ります。');
                header('Location: view.php?id=' . $id);
                exit;
            }

        } elseif (in_array($act, ['done', 'withdraw', 'reopen'], true)) {
            // ---- 本人（簡易パスワード）または管理者の操作 ----
            $note = trim(mb_convert_kana((string)($_POST['note'] ?? ''), 's'));
            $authorized = cb_is_admin();
            if (!$authorized) {
                $fails = cb_fail_count($pdo, 'unlock_fail', cb_client_ip(), (int)$config['lock_minutes']);
                if ($fails >= (int)$config['login_max_fail']) {
                    $errors[] = 'パスワードの誤りが続いたため、' . (int)$config['lock_minutes'] . '分間ロックしています。';
                } elseif (password_verify((string)($_POST['edit_pass'] ?? ''), $post['edit_pass_hash'])) {
                    $authorized = true;
                } else {
                    cb_log($pdo, $id, 'unlock_fail', '', '簡易パスワード誤り');
                    $errors[] = '簡易パスワードが違います。忘れた場合は管理者に依頼してください。';
                }
            }
            if ($authorized && mb_strlen($note) > 100) {
                $errors[] = 'メモは100文字以内で入力してください。';
                $authorized = false;
            }
            if ($authorized) {
                $who = cb_is_admin() ? cb_admin_name() . '（管理者）' : $post['caller_name'];
                if ($act === 'reopen') {
                    $hours = (int)($_POST['hours'] ?? 0);
                    if (!in_array($hours, $hoursChoices, true)) {
                        $errors[] = '延長する時間を選んでください。';
                    } else {
                        cb_reopen($pdo, $id, $hours, $who);
                        cb_flash_set("掲示を今から{$hours}時間に延長しました。");
                    }
                } else {
                    cb_close($pdo, $id, $act === 'done' ? 'done' : 'withdrawn', $who, $note);
                    cb_flash_set($act === 'done' ? '「連絡がついた」にしました。一覧から消え、履歴に残ります。' : '掲示を取り下げました。');
                }
                if (!$errors) {
                    header('Location: ' . ($act === 'reopen' ? 'view.php?id=' . $id : 'index.php'));
                    exit;
                }
            }
        } else {
            $errors[] = '不明な操作です。';
        }
    } catch (Throwable $e) {
        $errors[] = '処理に失敗しました: ' . $e->getMessage();
    }
}

$logs = [];
if ($post !== null) {
    try {
        $logs = cb_logs_for($pdo, $id);
    } catch (Throwable $e) {
        $errors[] = '操作履歴を読み込めませんでした: ' . $e->getMessage();
    }
}

cb_layout_header($config, '掲示の詳細', '');
if ($fatal !== null) {
    cb_db_error($fatal);
    cb_layout_footer($config);
    exit;
}
if ($post === null) {
    echo '<div class="empty">掲示が見つかりません（保存期間を過ぎたか、削除された可能性があります）。<a href="index.php">一覧へ</a></div>';
    cb_layout_footer($config);
    exit;
}
$state = cb_state($post);
?>
<?php cb_render_errors($errors); ?>

<article class="post post-detail">
  <div class="tags"><span class="badge <?= h(cb_state_class($state)) ?>"><?= h(cb_state_map()[$state]) ?></span></div>
  <div class="post-party">
    <span class="badge badge-party"><?= h($post['party_type']) ?></span>
    <span class="party-name"><?= h($post['party_name'] !== '' ? $post['party_name'] : '（お名前なし）') ?></span>
    <?php if ($post['phone_last4'] !== ''): ?><span class="phone">電話 <?= h(cb_phone_label($post)) ?></span><?php endif; ?>
  </div>
  <div class="post-purpose"><?= h($post['purpose']) ?></div>
  <div class="post-to">
    <span class="arrow">転送先</span>
    <span class="ext"><?= h(cb_ext_label($post)) ?></span>
    <span class="who"><?= h($post['caller_name']) ?>（<?= h($post['caller_dept']) ?>）</span>
  </div>
  <?php if ($post['alt_contact'] !== ''): ?><div class="post-alt">不在のとき：<?= h($post['alt_contact']) ?></div><?php endif; ?>
  <dl class="detail mt">
    <dt>電話した日時</dt><dd><?= h(cb_when_label($post['called_at'])) ?>（<?= h(cb_ago_label($post['called_at'])) ?>）</dd>
    <dt>掲示期限</dt><dd><?= h(cb_datetime_label($post['expires_at'])) ?><?= $state === 'open' ? '（' . h(cb_left_label($post['expires_at'])) . '）' : '' ?></dd>
    <?php if ($post['status'] !== 'open'): ?>
      <dt><?= h(cb_state_map()[$state]) ?></dt>
      <dd><?= h(cb_datetime_label($post['closed_at'])) ?> <?= h($post['closed_by']) ?><?= $post['closed_note'] !== '' ? '：' . h($post['closed_note']) : '' ?></dd>
    <?php endif; ?>
  </dl>
</article>

<?php if ($state === 'open'): ?>
<div class="panel">
  <h2>折り返しの電話を受けた方へ</h2>
  <p class="lead">
    <strong><?= h(cb_ext_label($post)) ?>（<?= h($post['caller_name']) ?>）</strong>へ転送してください。
    <?= $post['alt_contact'] !== '' ? '繋がらない場合は「' . h($post['alt_contact']) . '」へ。' : '' ?>
    転送・伝言をしたら、下の記録を残してください（担当者や他の職員が、折り返しがあったことを確認できます）。
  </p>
  <form method="post" action="view.php?id=<?= (int)$post['id'] ?>" class="filter-form">
    <?= cb_csrf_field() ?>
    <input type="hidden" name="act" value="receive">
    <div class="form-row"><label for="recv_name">受けた方の氏名</label>
      <input type="text" id="recv_name" name="recv_name" value="<?= h($recv['recv_name']) ?>" maxlength="32" required></div>
    <div class="form-row"><label for="recv_dept">部署</label>
      <?php cb_dept_select($config, 'recv_dept', $recv['recv_dept'], $recv['recv_dept_other']); ?></div>
    <div class="form-row"><label for="recv_memo">メモ（任意）</label>
      <input type="text" id="recv_memo" name="recv_memo" value="<?= h($recv['recv_memo']) ?>" maxlength="100" placeholder="例：担当者へ転送済み／不在のため伝言"></div>
    <button type="submit">折り返しを受けた記録を残す</button>
  </form>
</div>
<?php endif; ?>

<div class="panel">
  <h2>掲示した方（<?= h($post['caller_name']) ?>）の操作</h2>
  <form method="post" action="view.php?id=<?= (int)$post['id'] ?>">
    <?= cb_csrf_field() ?>
    <div class="form-grid">
      <?php if (!cb_is_admin()): ?>
        <div class="form-row"><label for="edit_pass">簡易パスワード</label>
          <input type="password" id="edit_pass" name="edit_pass" autocomplete="current-password"></div>
      <?php endif; ?>
      <div class="form-row"><label for="note">メモ（任意）</label>
        <input type="text" id="note" name="note" value="<?= h($note) ?>" maxlength="100" placeholder="例：折り返しあり、説明済み"></div>
    </div>
    <?php if (cb_is_admin()): ?><p class="muted small">管理者としてログイン中のため、パスワードなしで操作できます。</p><?php endif; ?>
    <div class="actions">
      <?php if ($state === 'open'): ?>
        <button type="submit" name="act" value="done">連絡がついた（掲示を終える）</button>
        <button type="submit" name="act" value="withdraw" class="secondary">取り下げ</button>
      <?php endif; ?>
      <span class="extend">
        <select name="hours" aria-label="延長する時間">
          <?php foreach ($hoursChoices as $hr): ?>
            <option value="<?= $hr ?>" <?= $hr === (int)$config['expire_default_hours'] ? 'selected' : '' ?>>今から<?= $hr ?>時間</option>
          <?php endforeach; ?>
        </select>
        <button type="submit" name="act" value="reopen" class="secondary"><?= $state === 'open' ? '掲示を延長' : 'もう一度掲示する' ?></button>
      </span>
    </div>
  </form>
</div>

<div class="panel">
  <h2>記録</h2>
  <?php if (!$logs): ?>
    <p class="muted">記録はありません。</p>
  <?php else: ?>
    <table class="data">
      <thead><tr><th>日時</th><th>内容</th><th>操作した人</th><th>メモ</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $l): ?>
        <tr>
          <td class="nowrap"><?= h(cb_datetime_label($l['created_at'])) ?></td>
          <td class="nowrap"><?= h(cb_action_label($l['action'])) ?></td>
          <td><?= h($l['actor']) ?></td>
          <td><?= h($l['detail']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<p><a href="index.php">&laquo; 一覧へ戻る</a></p>
<?php cb_layout_footer($config); ?>
