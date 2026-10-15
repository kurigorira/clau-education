<?php
/**
 * 管理者パスワードのハッシュを作る。
 *   コマンド:  php tools/make_password_hash.php 新しいパスワード
 *   ブラウザ:  http://サーバ/education/tools/make_password_hash.php を開いて入力
 * 表示された $2y$... の文字列を config.local.php の admin_password_hash に貼り付ける。
 * （パスワード自体はどこにも保存しない）
 */
if (PHP_SAPI === 'cli') {
    $pass = $argv[1] ?? '';
    if (strlen($pass) < 8) {
        fwrite(STDERR, "使い方: php tools/make_password_hash.php 新しいパスワード（8文字以上）\n");
        exit(1);
    }
    echo password_hash($pass, PASSWORD_DEFAULT), "\n";
    exit(0);
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
$hash = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass = (string)($_POST['pass'] ?? '');
    if (strlen($pass) < 8) {
        $error = '8文字以上で入力してください。';
    } elseif ($pass !== (string)($_POST['pass2'] ?? '')) {
        $error = '確認用と一致しません。';
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
    }
}
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ja"><head><meta charset="utf-8"><title>管理者パスワードのハッシュ作成</title>
<style>body{font-family:sans-serif;line-height:1.7;padding:20px;max-width:680px;margin:auto}
input{font-size:15px;padding:6px;width:280px}code{background:#f0f0f0;padding:6px;display:block;word-break:break-all}
.ng{color:#b53434}</style></head>
<body>
<h1>管理者パスワードのハッシュ作成</h1>
<?php if ($error !== ''): ?><p class="ng"><?= $h($error) ?></p><?php endif; ?>
<?php if ($hash !== ''): ?>
  <p>次の文字列を <strong>education/config.local.php</strong> の <code style="display:inline">'admin_password_hash' => '…',</code> に貼り付けてください。</p>
  <code><?= $h($hash) ?></code>
  <p>設定後、このファイル（tools/make_password_hash.php）はサーバから削除しても構いません。</p>
<?php else: ?>
  <form method="post">
    <p>新しい管理者パスワード（8文字以上）<br><input type="password" name="pass" autocomplete="new-password"></p>
    <p>確認のためもう一度<br><input type="password" name="pass2" autocomplete="new-password"></p>
    <button type="submit">ハッシュを作成</button>
  </form>
<?php endif; ?>
</body></html>
