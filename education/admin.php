<?php
/**
 * 管理画面（承認者用）。管理者パスワードでログインして使う。
 *  - 承認待ちの予定を承認（公開）／差戻し
 *  - 公開後に担当者が変更した予定の確認
 *  - 中止の取り消し・削除・編集用パスワードの再設定
 *  - 年度の研修計画をCSV出力
 */
define('EDU_APP', true);
$config = require __DIR__ . '/config.php';
require __DIR__ . '/common.php';
require __DIR__ . '/store.php';

$errors = [];
$fatal = null;
try {
    $pdo = edu_mysql($config);
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}

if ($fatal === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    edu_csrf_check();
    $act = (string)($_POST['act'] ?? '');

    try {
        if ($act === 'login') {
            // ---- ログイン（誤りが続くIPは一時ロック） ----
            $name = trim((string)($_POST['name'] ?? ''));
            $ip   = edu_client_ip();
            if ((string)$config['admin_password_hash'] === '') {
                $errors[] = '管理者パスワードが未設定です。config.local.php の admin_password_hash を設定してください（README参照）。';
            } elseif (edu_fail_count($pdo, 'login_fail', $ip, (int)$config['lock_minutes']) >= (int)$config['login_max_fail']) {
                $errors[] = 'パスワードの誤りが続いたため、' . (int)$config['lock_minutes'] . '分間ログインを停止しています。';
            } elseif ($name === '' || mb_strlen($name) > 64) {
                $errors[] = '承認者名（64文字以内）を入力してください。承認の記録に使います。';
            } elseif (password_verify((string)($_POST['password'] ?? ''), (string)$config['admin_password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['edu_admin'] = true;
                $_SESSION['edu_admin_name'] = $name;
                edu_log($pdo, null, 'login', $name, '管理者ログイン');
                header('Location: admin.php');
                exit;
            } else {
                edu_log($pdo, null, 'login_fail', $name, '管理者パスワード誤り');
                $errors[] = 'パスワードが違います。';
            }

        } elseif ($act === 'logout') {
            unset($_SESSION['edu_admin'], $_SESSION['edu_admin_name']);
            edu_flash_set('ログアウトしました。');
            header('Location: index.php');
            exit;

        } elseif (edu_is_admin()) {
            $id    = (int)($_POST['id'] ?? 0);
            $event = $id > 0 ? edu_find($pdo, $id) : null;
            $who   = edu_admin_name();
            if ($event === null) {
                $errors[] = '対象の予定が見つかりません（すでに削除された可能性があります）。';
            } else {
                $label = '「' . $event['title'] . '」';
                switch ($act) {
                    case 'approve':
                        edu_approve($pdo, $id, $who);
                        edu_flash_set("{$label}を承認し、掲載しました。");
                        break;
                    case 'ack':
                        edu_ack_change($pdo, $id, $who);
                        edu_flash_set("{$label}の変更を確認済みにしました。");
                        break;
                    case 'reject':
                        $reason = trim((string)($_POST['reason'] ?? ''));
                        if ($reason === '') {
                            $errors[] = '差戻しの理由を入力してください（登録者に表示されます）。';
                            break;
                        }
                        edu_reject($pdo, $id, $who, mb_substr($reason, 0, 500));
                        edu_flash_set("{$label}を差戻しました。");
                        break;
                    case 'restore':
                        edu_restore($pdo, $id, $who);
                        edu_flash_set("{$label}の中止を取り消し、公開中に戻しました。");
                        break;
                    case 'delete':
                        edu_delete($pdo, $event, $who);
                        edu_flash_set("{$label}を削除しました（操作履歴は残ります）。");
                        break;
                    case 'reset_pass':
                        $pass = (string)($_POST['new_pass'] ?? '');
                        if (mb_strlen($pass) < 4) {
                            $errors[] = '新しい編集用パスワードは4文字以上で入力してください。';
                            break;
                        }
                        edu_reset_pass($pdo, $id, password_hash($pass, PASSWORD_DEFAULT), $who);
                        edu_flash_set("{$label}の編集用パスワードを再設定しました。担当者に伝えてください。");
                        break;
                    default:
                        $errors[] = '不明な操作です。';
                }
            }
            if (!$errors) {
                header('Location: admin.php');
                exit;
            }
        }
    } catch (Throwable $e) {
        $errors[] = '処理に失敗しました: ' . $e->getMessage();
    }
}

$lists = [];
if ($fatal === null && edu_is_admin()) {
    try {
        foreach (['pending', 'changed', 'rejected', 'all'] as $k) {
            $lists[$k] = edu_admin_list($pdo, $k);
        }
    } catch (Throwable $e) {
        $fatal = $e->getMessage();
    }
}
$statusMap = edu_status_map();
$fyNow = edu_fiscal_year(time());

/** 予定1件の要約（管理画面のカード） */
$summary = function (array $e) use ($config): void { ?>
  <div class="ev-summary">
    <div class="ev-title">
      <span class="badge cat <?= h(edu_category_class($config, $e['category'])) ?>"><?= h($e['category']) ?></span>
      <?php if ((int)$e['is_mandatory'] === 1): ?><span class="badge badge-must">必須</span><?php endif; ?>
      <a href="view.php?id=<?= (int)$e['id'] ?>"><strong><?= h($e['title']) ?></strong></a>
      <span class="muted">#<?= (int)$e['id'] ?></span>
    </div>
    <div class="ev-meta">
      <?= h(edu_date_label($e['start_at'])) ?> <?= h(edu_time_label($e['start_at'], $e['end_at'])) ?>
      ／ <?= h($e['place']) ?> ／ <?= h(edu_organizer_label($e)) ?>
      ／ 対象: <?= h(str_replace(',', '、', $e['targets'])) ?>
    </div>
    <div class="ev-meta muted">
      登録: <?= h($e['contact_dept']) ?> <?= h($e['contact_name']) ?> <?= h($e['contact_ext']) ?>
      （<?= h(edu_datetime_label($e['created_at'])) ?>）
    </div>
  </div>
<?php };

/** POSTボタン1つの小さなフォーム */
$button = function (int $id, string $act, string $label, string $cls = '', string $confirm = ''): void { ?>
  <form method="post" action="admin.php" class="inline"<?= $confirm !== '' ? ' onsubmit="return confirm(\'' . h($confirm) . '\');"' : '' ?>>
    <?= edu_csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="act" value="<?= h($act) ?>">
    <button type="submit" class="<?= h($cls) ?>"><?= h($label) ?></button>
  </form>
<?php };

edu_layout_header($config, '管理', 'admin');
?>

<?php if ($fatal !== null): ?>
  <?php edu_db_error($fatal); ?>

<?php elseif (!edu_is_admin()): ?>
  <div class="panel narrow">
    <h2>管理者ログイン（承認者用）</h2>
    <p class="lead">予定の承認・差戻し・削除を行う管理者（教育委員会 等）専用です。<br>
      予定を登録・変更するだけなら、ログインは不要です。</p>
    <?php edu_render_errors($errors); ?>
    <form method="post" action="admin.php">
      <?= edu_csrf_field() ?>
      <input type="hidden" name="act" value="login">
      <div class="form-row">
        <label>承認者名（記録に残ります）</label>
        <input type="text" name="name" maxlength="64" placeholder="例: 教育委員会 山田">
      </div>
      <div class="form-row">
        <label>管理者パスワード</label>
        <input type="password" name="password" autocomplete="current-password">
      </div>
      <button type="submit">ログイン</button>
    </form>
  </div>

<?php else: ?>
  <?php edu_render_errors($errors); ?>

  <div class="admin-bar">
    <span>ログイン中: <strong><?= h(edu_admin_name()) ?></strong></span>
    <form method="get" action="export.php" class="inline">
      <select name="fy" aria-label="年度">
        <?php for ($y = $fyNow + 1; $y >= $fyNow - 3; $y--): ?>
          <option value="<?= $y ?>" <?= $y === $fyNow ? 'selected' : '' ?>><?= $y ?>年度</option>
        <?php endfor; ?>
      </select>
      <button type="submit" class="secondary">研修計画をCSV出力</button>
    </form>
    <form method="post" action="admin.php" class="inline">
      <?= edu_csrf_field() ?>
      <input type="hidden" name="act" value="logout">
      <button type="submit" class="secondary">ログアウト</button>
    </form>
  </div>

  <div class="panel">
    <h2>承認待ち（<?= count($lists['pending']) ?>件）</h2>
    <?php if (!$lists['pending']): ?>
      <div class="muted">承認待ちの予定はありません。</div>
    <?php endif; ?>
    <?php foreach ($lists['pending'] as $e): ?>
      <div class="admin-item" id="e<?= (int)$e['id'] ?>">
        <?php $summary($e); ?>
        <?php if ($e['body'] !== ''): ?><div class="body-text small"><?= h($e['body']) ?></div><?php endif; ?>
        <div class="admin-actions">
          <?php $button((int)$e['id'], 'approve', '承認して掲載'); ?>
          <form method="post" action="admin.php" class="inline">
            <?= edu_csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
            <input type="hidden" name="act" value="reject">
            <input type="text" name="reason" placeholder="差戻し理由（登録者に表示）" maxlength="500">
            <button type="submit" class="secondary">差戻し</button>
          </form>
          <a href="edit.php?id=<?= (int)$e['id'] ?>" class="btn secondary">修正</a>
          <?php $button((int)$e['id'], 'delete', '削除', 'danger', 'この予定を削除しますか？（重複・いたずら等）'); ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="panel">
    <h2>公開後に変更あり（<?= count($lists['changed']) ?>件）</h2>
    <?php if (!$lists['changed']): ?>
      <div class="muted">確認が必要な変更はありません。</div>
    <?php endif; ?>
    <?php foreach ($lists['changed'] as $e): ?>
      <?php
        $lastEdit = null;
        foreach (edu_logs_for($pdo, (int)$e['id']) as $l) {
            if ($l['action'] === 'edit') { $lastEdit = $l; break; }
        }
      ?>
      <div class="admin-item" id="e<?= (int)$e['id'] ?>">
        <?php $summary($e); ?>
        <?php if ($lastEdit): ?>
          <div class="diff"><strong><?= h(edu_datetime_label($lastEdit['created_at'])) ?> <?= h($lastEdit['actor']) ?> が変更:</strong><br><?= nl2br(h((string)$lastEdit['detail'])) ?></div>
        <?php endif; ?>
        <div class="admin-actions">
          <?php $button((int)$e['id'], 'ack', '確認済みにする'); ?>
          <a href="edit.php?id=<?= (int)$e['id'] ?>" class="btn secondary">修正</a>
          <a href="view.php?id=<?= (int)$e['id'] ?>" class="btn secondary">変更履歴を見る</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($lists['rejected']): ?>
  <div class="panel">
    <h2>差戻し中（<?= count($lists['rejected']) ?>件・登録者の修正待ち）</h2>
    <?php foreach ($lists['rejected'] as $e): ?>
      <div class="admin-item" id="e<?= (int)$e['id'] ?>">
        <?php $summary($e); ?>
        <div class="muted">理由: <?= h((string)$e['reject_reason']) ?></div>
        <div class="admin-actions">
          <?php $button((int)$e['id'], 'approve', 'このまま承認'); ?>
          <?php $button((int)$e['id'], 'delete', '削除', 'danger', 'この予定を削除しますか？'); ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="panel">
    <h2>すべての予定（新しい開催日順・最大300件）</h2>
    <table class="data compact">
      <thead><tr><th>#</th><th>開催日</th><th>研修名</th><th>主催</th><th>状態</th><th>操作</th></tr></thead>
      <tbody>
        <?php foreach ($lists['all'] as $e): ?>
          <tr id="e<?= (int)$e['id'] ?>">
            <td class="num"><?= (int)$e['id'] ?></td>
            <td class="nowrap"><?= h(edu_date_label($e['start_at'])) ?></td>
            <td><a href="view.php?id=<?= (int)$e['id'] ?>"><?= h($e['title']) ?></a></td>
            <td><?= h($e['organizer']) ?></td>
            <td class="nowrap"><span class="badge <?= h(edu_status_class($e['status'])) ?>"><?= h($statusMap[$e['status']] ?? $e['status']) ?></span></td>
            <td class="admin-actions">
              <a href="edit.php?id=<?= (int)$e['id'] ?>" class="btn small secondary">修正</a>
              <?php if ($e['status'] === 'cancelled'): ?>
                <?php $button((int)$e['id'], 'restore', '中止取消', 'small secondary'); ?>
              <?php endif; ?>
              <details class="inline">
                <summary>その他</summary>
                <form method="post" action="admin.php" class="inline">
                  <?= edu_csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                  <input type="hidden" name="act" value="reset_pass">
                  <input type="text" name="new_pass" placeholder="新しい編集用パスワード" size="14">
                  <button type="submit" class="small secondary">パスワード再設定</button>
                </form>
                <?php $button((int)$e['id'], 'delete', '削除', 'small danger', 'この予定を削除しますか？'); ?>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php edu_layout_footer($config); ?>
