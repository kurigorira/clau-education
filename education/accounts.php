<?php
/**
 * 管理者アカウント（管理者のみ）。
 *  - 管理者（承認者）の追加・氏名変更・パスワード再設定・無効化／有効化
 *  - 自分のパスワード変更（現在のパスワードを確認）
 * 自分自身と、最後の有効な管理者は無効化できない（誰もログインできなくなるのを防ぐ）。
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
if ($fatal === null) {
    edu_require_admin();
}

if ($fatal === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    edu_csrf_check();
    $act = (string)($_POST['act'] ?? '');
    $who = edu_admin_name();
    try {
        if ($act === 'add') {
            $loginId = trim((string)($_POST['login_id'] ?? ''));
            $name    = trim((string)($_POST['name'] ?? ''));
            $pass    = (string)($_POST['password'] ?? '');
            $errors  = edu_admin_input_errors($loginId, $name, $pass, (string)($_POST['password2'] ?? ''));
            if (!$errors && !edu_admin_add($pdo, $loginId, $name, $pass)) {
                $errors[] = "ログインID「{$loginId}」はすでに使われています。";
            }
            if (!$errors) {
                edu_log($pdo, null, 'admin_add', $who, "管理者「{$loginId}（{$name}）」を追加");
                edu_flash_set("管理者「{$name}」を追加しました。ログインIDとパスワードを本人に伝えてください。");
            }

        } elseif ($act === 'my_pass') {
            $me  = edu_admin_find($pdo, edu_admin_id());
            $new = (string)($_POST['password'] ?? '');
            if (!password_verify((string)($_POST['current'] ?? ''), $me['pass_hash'])) {
                $errors[] = '現在のパスワードが違います。';
            } else {
                $errors = edu_admin_input_errors($me['login_id'], $me['name'], $new, (string)($_POST['password2'] ?? ''));
            }
            if (!$errors) {
                edu_admin_set_pass($pdo, (int)$me['id'], $new);
                edu_log($pdo, null, 'admin_pass', $who, '自分のパスワードを変更');
                edu_flash_set('パスワードを変更しました。');
            }

        } else {
            $a = edu_admin_find($pdo, (int)($_POST['id'] ?? 0));
            if ($a === null) {
                $errors[] = '対象の管理者が見つかりません。';
            } elseif ($act === 'rename') {
                $name = trim((string)($_POST['name'] ?? ''));
                $errors = edu_admin_input_errors('', $name, null, null);
                if (!$errors) {
                    edu_admin_set_name($pdo, (int)$a['id'], $name);
                    edu_log($pdo, null, 'admin_edit', $who, "管理者「{$a['login_id']}」の氏名: {$a['name']} → {$name}");
                    edu_flash_set('氏名を変更しました。');
                }
            } elseif ($act === 'reset_pass') {
                $pass = (string)($_POST['password'] ?? '');
                $errors = edu_admin_input_errors($a['login_id'], $a['name'], $pass, $pass);
                if (!$errors) {
                    edu_admin_set_pass($pdo, (int)$a['id'], $pass);
                    edu_log($pdo, null, 'admin_pass', $who, "管理者「{$a['login_id']}」のパスワードを再設定");
                    edu_flash_set("「{$a['name']}」のパスワードを再設定しました。本人に伝えてください。");
                }
            } elseif ($act === 'disable') {
                if ((int)$a['id'] === edu_admin_id()) {
                    $errors[] = '自分自身は無効化できません。ほかの管理者に依頼してください。';
                } elseif (edu_admin_count($pdo) <= 1) {
                    $errors[] = '最後の有効な管理者は無効化できません。';
                } else {
                    edu_admin_set_active($pdo, (int)$a['id'], false);
                    edu_log($pdo, null, 'admin_disable', $who, "管理者「{$a['login_id']}」を無効化");
                    edu_flash_set("「{$a['name']}」を無効化しました（ログインできなくなります）。");
                }
            } elseif ($act === 'enable') {
                edu_admin_set_active($pdo, (int)$a['id'], true);
                edu_log($pdo, null, 'admin_enable', $who, "管理者「{$a['login_id']}」を有効化");
                edu_flash_set("「{$a['name']}」を有効にしました。");
            }
        }
        if (!$errors) {
            header('Location: accounts.php');
            exit;
        }
    } catch (Throwable $e) {
        $errors[] = '処理に失敗しました: ' . $e->getMessage();
    }
}

$rows = $fatal === null ? edu_admin_rows($pdo) : [];

edu_layout_header($config, '管理者アカウント', 'accounts');
?>

<?php if ($fatal !== null): ?>
  <?php edu_db_error($fatal); ?>
<?php else: ?>
  <?php edu_render_errors($errors); ?>

  <div class="panel">
    <h2>管理者（承認者）一覧</h2>
    <table class="data compact">
      <thead><tr><th>ログインID</th><th>氏名</th><th>状態</th><th>最終ログイン</th><th>操作</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $a): ?>
          <?php $isMe = (int)$a['id'] === edu_admin_id(); ?>
          <tr class="<?= (int)$a['active'] ? '' : 'inactive' ?>">
            <td class="nowrap"><?= h($a['login_id']) ?><?= $isMe ? ' <span class="badge badge-today">自分</span>' : '' ?></td>
            <td><?= h($a['name']) ?></td>
            <td class="nowrap"><?= (int)$a['active'] ? '<span class="badge badge-done">有効</span>' : '<span class="badge badge-muted">無効</span>' ?></td>
            <td class="nowrap"><?= $a['last_login_at'] ? h(edu_datetime_label($a['last_login_at'])) : '－' ?></td>
            <td class="admin-actions">
              <details class="inline">
                <summary>氏名を変更</summary>
                <form method="post" action="accounts.php" class="inline">
                  <?= edu_csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                  <input type="hidden" name="act" value="rename">
                  <input type="text" name="name" value="<?= h($a['name']) ?>" maxlength="64">
                  <button type="submit" class="small">保存</button>
                </form>
              </details>
              <?php if (!$isMe): ?>
                <details class="inline">
                  <summary>パスワード再設定</summary>
                  <form method="post" action="accounts.php" class="inline">
                    <?= edu_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="act" value="reset_pass">
                    <input type="text" name="password" placeholder="新しいパスワード（8文字以上）" size="20" autocomplete="off">
                    <button type="submit" class="small">再設定</button>
                  </form>
                </details>
                <form method="post" action="accounts.php" class="inline"
                      <?= (int)$a['active'] ? 'onsubmit="return confirm(\'この管理者を無効化しますか？\');"' : '' ?>>
                  <?= edu_csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                  <input type="hidden" name="act" value="<?= (int)$a['active'] ? 'disable' : 'enable' ?>">
                  <button type="submit" class="small <?= (int)$a['active'] ? 'danger' : '' ?>"><?= (int)$a['active'] ? '無効化' : '有効にする' ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="form-columns">
    <div class="panel">
      <h2>管理者を追加</h2>
      <form method="post" action="accounts.php">
        <?= edu_csrf_field() ?>
        <input type="hidden" name="act" value="add">
        <div class="form-row">
          <label>ログインID<span class="hint">半角英数字</span></label>
          <input type="text" name="login_id" maxlength="32" autocomplete="off">
        </div>
        <div class="form-row">
          <label>氏名<span class="hint">承認者として記録されます</span></label>
          <input type="text" name="name" maxlength="64" placeholder="例: 看護部 教育担当 佐藤">
        </div>
        <div class="form-row">
          <label>パスワード<span class="hint">8文字以上</span></label>
          <input type="password" name="password" autocomplete="new-password">
        </div>
        <div class="form-row">
          <label>パスワード（確認）</label>
          <input type="password" name="password2" autocomplete="new-password">
        </div>
        <button type="submit">追加する</button>
      </form>
    </div>

    <div class="panel">
      <h2>自分のパスワードを変更</h2>
      <form method="post" action="accounts.php">
        <?= edu_csrf_field() ?>
        <input type="hidden" name="act" value="my_pass">
        <div class="form-row">
          <label>現在のパスワード</label>
          <input type="password" name="current" autocomplete="current-password">
        </div>
        <div class="form-row">
          <label>新しいパスワード<span class="hint">8文字以上</span></label>
          <input type="password" name="password" autocomplete="new-password">
        </div>
        <div class="form-row">
          <label>新しいパスワード（確認）</label>
          <input type="password" name="password2" autocomplete="new-password">
        </div>
        <button type="submit">変更する</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php edu_layout_footer($config); ?>
