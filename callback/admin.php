<?php
/**
 * 管理画面。管理者アカウント（ログインID＋パスワード）でログインして使う。
 * 管理者が1人もいない間だけ「初回設定」で最初の管理者を作成できる。
 *  - 掲示の削除（誤って個人情報を書いた掲示など）
 *  - 管理者ログイン中は、詳細画面で簡易パスワードなしに「連絡がついた」「取り下げ」「延長」ができる
 *  - 管理者アカウントの追加・パスワード再設定・無効化
 */
define('CB_APP', true);
$config = require __DIR__ . '/config.php';
require __DIR__ . '/common.php';
require __DIR__ . '/store.php';

$errors = [];
$fatal = null;
try {
    $pdo = cb_mysql($config);
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}

if ($fatal === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    cb_csrf_check();
    $act = (string)($_POST['act'] ?? '');

    try {
        if ($act === 'setup') {
            // ---- 初回設定：管理者が1人もいない間だけ、最初の管理者を作成できる ----
            if (cb_admin_count($pdo, false) > 0) {
                $errors[] = '初回設定はすでに完了しています。管理者アカウントでログインしてください。';
            } else {
                $loginId = trim((string)($_POST['login_id'] ?? ''));
                $name    = trim((string)($_POST['name'] ?? ''));
                $pass    = (string)($_POST['password'] ?? '');
                $errors  = cb_admin_input_errors($loginId, $name, $pass, (string)($_POST['password2'] ?? ''));
                if (!$errors) {
                    cb_admin_add($pdo, $loginId, $name, $pass);
                    $a = cb_admin_find_login($pdo, $loginId);
                    session_regenerate_id(true);
                    $_SESSION['cb_admin_id']   = (int)$a['id'];
                    $_SESSION['cb_admin_name'] = $a['name'];
                    cb_log($pdo, null, 'admin_add', $name, "初回設定で管理者「{$loginId}」を作成");
                    cb_flash_set('最初の管理者を作成し、ログインしました。');
                    header('Location: admin.php');
                    exit;
                }
            }

        } elseif ($act === 'login') {
            // ---- ログイン（誤りが続くIPは一時ロック） ----
            $loginId = trim((string)($_POST['login_id'] ?? ''));
            $ip      = cb_client_ip();
            $admin   = $loginId !== '' ? cb_admin_find_login($pdo, $loginId) : null;
            if (cb_fail_count($pdo, 'login_fail', $ip, (int)$config['lock_minutes']) >= (int)$config['login_max_fail']) {
                $errors[] = 'パスワードの誤りが続いたため、' . (int)$config['lock_minutes'] . '分間ログインを停止しています。';
            } elseif ($admin !== null && (int)$admin['active'] === 1
                      && password_verify((string)($_POST['password'] ?? ''), $admin['pass_hash'])) {
                session_regenerate_id(true);
                $_SESSION['cb_admin_id']   = (int)$admin['id'];
                $_SESSION['cb_admin_name'] = $admin['name'];
                $pdo->prepare('UPDATE cb_admins SET last_login_at = NOW() WHERE id = ?')->execute([(int)$admin['id']]);
                cb_log($pdo, null, 'login', $admin['name'], '管理者ログイン');
                header('Location: admin.php');
                exit;
            } else {
                cb_log($pdo, null, 'login_fail', mb_substr($loginId, 0, 64), 'ログインIDまたはパスワード誤り');
                $errors[] = 'ログインIDまたはパスワードが違います。';
            }

        } elseif ($act === 'logout') {
            unset($_SESSION['cb_admin_id'], $_SESSION['cb_admin_name']);
            cb_flash_set('ログアウトしました。');
            header('Location: index.php');
            exit;

        } elseif (cb_is_admin()) {
            $who = cb_admin_name();
            switch ($act) {
                case 'delete':
                    $id = (int)($_POST['id'] ?? 0);
                    if (cb_find($pdo, $id) === null) {
                        $errors[] = '対象の掲示が見つかりません（すでに削除された可能性があります）。';
                        break;
                    }
                    cb_delete($pdo, $id, $who);
                    cb_flash_set("掲示 #{$id} を削除しました。");
                    break;

                case 'admin_add':
                    $loginId = trim((string)($_POST['login_id'] ?? ''));
                    $name    = trim((string)($_POST['name'] ?? ''));
                    $pass    = (string)($_POST['password'] ?? '');
                    $errors  = cb_admin_input_errors($loginId, $name, $pass, (string)($_POST['password2'] ?? ''));
                    if (!$errors && !cb_admin_add($pdo, $loginId, $name, $pass)) {
                        $errors[] = 'そのログインIDはすでに使われています。';
                    }
                    if (!$errors) {
                        cb_log($pdo, null, 'admin_add', $who, "管理者「{$loginId}」を追加");
                        cb_flash_set("管理者「{$name}」を追加しました。");
                    }
                    break;

                case 'admin_pass':
                    $aid  = (int)($_POST['admin_id'] ?? 0);
                    $a    = cb_admin_find($pdo, $aid);
                    $pass = (string)($_POST['password'] ?? '');
                    if ($a === null) {
                        $errors[] = '対象の管理者が見つかりません。';
                        break;
                    }
                    $errors = cb_admin_input_errors('', $a['name'], $pass, (string)($_POST['password2'] ?? ''));
                    if (!$errors) {
                        cb_admin_set_pass($pdo, $aid, $pass);
                        cb_log($pdo, null, 'admin_pass', $who, "管理者「{$a['login_id']}」のパスワードを変更");
                        cb_flash_set("「{$a['name']}」のパスワードを変更しました。");
                    }
                    break;

                case 'admin_active':
                    $aid    = (int)($_POST['admin_id'] ?? 0);
                    $a      = cb_admin_find($pdo, $aid);
                    $active = (string)($_POST['active'] ?? '') === '1';
                    if ($a === null) {
                        $errors[] = '対象の管理者が見つかりません。';
                    } elseif (!$active && $aid === cb_admin_id()) {
                        $errors[] = '自分自身は無効化できません。';
                    } elseif (!$active && cb_admin_count($pdo) <= 1) {
                        $errors[] = '最後の有効な管理者は無効化できません。';
                    } else {
                        cb_admin_set_active($pdo, $aid, $active);
                        cb_log($pdo, null, 'admin_active', $who, "管理者「{$a['login_id']}」を" . ($active ? '有効化' : '無効化'));
                        cb_flash_set("「{$a['name']}」を" . ($active ? '有効にしました。' : '無効にしました。'));
                    }
                    break;

                default:
                    $errors[] = '不明な操作です。';
            }
            if (!$errors) {
                header('Location: admin.php');
                exit;
            }
        } else {
            $errors[] = '管理者ログインが必要です。';
        }
    } catch (Throwable $e) {
        $errors[] = '処理に失敗しました: ' . $e->getMessage();
    }
}

$recent = [];
$admins = [];
$hasAdmin = true;
if ($fatal === null) {
    try {
        $hasAdmin = cb_admin_count($pdo, false) > 0;
        if (cb_is_admin()) {
            $recent = cb_history($pdo, [], 1, 100)['rows'];
            $admins = cb_admin_rows($pdo);
        }
    } catch (Throwable $e) {
        $errors[] = '読み込みに失敗しました: ' . $e->getMessage();
    }
}

cb_layout_header($config, '管理', 'admin');
if ($fatal !== null) {
    cb_db_error($fatal);
    cb_layout_footer($config);
    exit;
}
cb_render_errors($errors);

if (!$hasAdmin): ?>
  <div class="panel narrow">
    <h2>初回設定（最初の管理者を作成）</h2>
    <p class="lead">管理者がまだいません。設置したらすぐに作成してください。</p>
    <form method="post" action="admin.php">
      <?= cb_csrf_field() ?>
      <input type="hidden" name="act" value="setup">
      <div class="form-row"><label for="login_id">ログインID</label><input type="text" id="login_id" name="login_id" required></div>
      <div class="form-row"><label for="name">氏名</label><input type="text" id="name" name="name" required></div>
      <div class="form-row"><label for="password">パスワード（8文字以上）</label><input type="password" id="password" name="password" required autocomplete="new-password"></div>
      <div class="form-row"><label for="password2">パスワード（確認）</label><input type="password" id="password2" name="password2" required autocomplete="new-password"></div>
      <button type="submit">作成してログイン</button>
    </form>
  </div>
<?php elseif (!cb_is_admin()): ?>
  <div class="panel narrow">
    <h2>管理者ログイン</h2>
    <form method="post" action="admin.php">
      <?= cb_csrf_field() ?>
      <input type="hidden" name="act" value="login">
      <div class="form-row"><label for="login_id">ログインID</label><input type="text" id="login_id" name="login_id" required></div>
      <div class="form-row"><label for="password">パスワード</label><input type="password" id="password" name="password" required autocomplete="current-password"></div>
      <button type="submit">ログイン</button>
    </form>
  </div>
<?php else: ?>
  <form method="post" action="admin.php" class="logout">
    <?= cb_csrf_field() ?>
    <span class="muted"><?= h(cb_admin_name()) ?> さんでログイン中</span>
    <button type="submit" name="act" value="logout" class="secondary small">ログアウト</button>
  </form>

  <div class="panel">
    <h2>最近の掲示（新しい順・100件まで）</h2>
    <p class="muted small">「連絡がついた」「延長」は各掲示の詳細画面から、パスワードなしで操作できます。誤って個人情報を書いた掲示は削除してください。</p>
    <?php if (!$recent): ?>
      <p class="muted">掲示はありません。</p>
    <?php else: ?>
      <table class="data">
        <thead><tr><th>#</th><th>電話した日時</th><th>状態</th><th>相手先</th><th>用件</th><th>担当者</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recent as $p): $state = cb_state($p); ?>
          <tr>
            <td><?= (int)$p['id'] ?></td>
            <td class="nowrap"><a href="view.php?id=<?= (int)$p['id'] ?>"><?= h(cb_datetime_label($p['called_at'])) ?></a></td>
            <td><span class="badge <?= h(cb_state_class($state)) ?>"><?= h(cb_state_map()[$state]) ?></span></td>
            <td><?= h(cb_party_label($p)) ?> <?= h(cb_phone_label($p)) ?></td>
            <td><?= h($p['purpose']) ?></td>
            <td><?= h($p['caller_name']) ?>（<?= h(cb_ext_label($p)) ?>）</td>
            <td>
              <form method="post" action="admin.php" class="inline" onsubmit="return confirm('掲示 #<?= (int)$p['id'] ?> を削除します。よろしいですか？');">
                <?= cb_csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button type="submit" name="act" value="delete" class="danger small">削除</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>管理者アカウント</h2>
    <table class="data">
      <thead><tr><th>ログインID</th><th>氏名</th><th>状態</th><th>最終ログイン</th><th>パスワード変更</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($admins as $a): ?>
        <tr>
          <td><?= h($a['login_id']) ?></td>
          <td><?= h($a['name']) ?><?= (int)$a['id'] === cb_admin_id() ? '（自分）' : '' ?></td>
          <td><?= (int)$a['active'] ? '有効' : '<span class="muted">無効</span>' ?></td>
          <td class="nowrap"><?= h(cb_datetime_label($a['last_login_at'])) ?></td>
          <td>
            <form method="post" action="admin.php" class="inline">
              <?= cb_csrf_field() ?>
              <input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>">
              <input type="password" name="password" placeholder="新しいパスワード" autocomplete="new-password" required>
              <input type="password" name="password2" placeholder="確認" autocomplete="new-password" required>
              <button type="submit" name="act" value="admin_pass" class="small">変更</button>
            </form>
          </td>
          <td>
            <?php if ((int)$a['id'] !== cb_admin_id()): ?>
            <form method="post" action="admin.php" class="inline">
              <?= cb_csrf_field() ?>
              <input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>">
              <input type="hidden" name="active" value="<?= (int)$a['active'] ? '0' : '1' ?>">
              <button type="submit" name="act" value="admin_active" class="secondary small"><?= (int)$a['active'] ? '無効にする' : '有効にする' ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <h3 class="sub">管理者を追加</h3>
    <form method="post" action="admin.php" class="filter-form">
      <?= cb_csrf_field() ?>
      <input type="hidden" name="act" value="admin_add">
      <div class="form-row"><label for="new_login">ログインID</label><input type="text" id="new_login" name="login_id" required></div>
      <div class="form-row"><label for="new_name">氏名</label><input type="text" id="new_name" name="name" required></div>
      <div class="form-row"><label for="new_pass">パスワード（8文字以上）</label><input type="password" id="new_pass" name="password" required autocomplete="new-password"></div>
      <div class="form-row"><label for="new_pass2">確認</label><input type="password" id="new_pass2" name="password2" required autocomplete="new-password"></div>
      <button type="submit">追加</button>
    </form>
  </div>
<?php endif;
cb_layout_footer($config);
