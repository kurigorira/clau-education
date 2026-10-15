<?php
/**
 * 研修スケジュールアプリの動作診断。ブラウザで education/check.php を開くと、
 * [1] PHP環境 [2] MySQL接続・テーブル作成 [3] 管理者パスワード [4] 登録件数 を順に確認します。
 * 正常稼働を確認したら削除して構いません。
 */
define('EDU_APP', true);
header('Content-Type: text/html; charset=UTF-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/common.php';
require __DIR__ . '/store.php';

$ok   = " <span class='ok'>OK</span>";
$ng   = fn(string $m) => " <span class='ng'>NG</span> " . h($m);
$warn = fn(string $m) => " <span class='warn'>注意</span> " . h($m);

echo "<!doctype html><html lang='ja'><head><meta charset='utf-8'><title>研修スケジュール 診断</title>";
echo "<style>body{font-family:sans-serif;line-height:1.7;padding:20px;max-width:760px;margin:auto}";
echo ".ok{color:#1b6b35;font-weight:bold}.ng{color:#b53434;font-weight:bold}.warn{color:#7a5b00;font-weight:bold}";
echo "code{background:#f0f0f0;padding:2px 5px;border-radius:4px}li{margin:6px 0}</style></head><body>";
echo "<h1>研修スケジュール 動作診断</h1>";

// ---- [1] PHP環境 ----
echo "<h2>1. PHP環境</h2><ul>";
echo "<li>PHPバージョン：<code>" . h(phpversion()) . "</code>"
   . (version_compare(PHP_VERSION, '8.0', '>=') ? $ok : $warn('8.0以上が必要です')) . "</li>";
foreach (['pdo_mysql', 'mbstring'] as $ext) {
    echo "<li>拡張 <code>$ext</code>：" . (extension_loaded($ext) ? $ok : $ng('php.ini で有効にしてください')) . "</li>";
}
echo "<li>タイムゾーン：<code>" . h(date_default_timezone_get()) . "</code> 現在 " . h(date('Y-m-d H:i:s')) . "</li>";
echo "</ul>";

// ---- [2] MySQL ----
echo "<h2>2. MySQL接続・テーブル</h2><ul>";
$m = $config['mysql'];
echo "<li>接続先：<code>" . h("{$m['user']}@{$m['host']}:{$m['port']}/{$m['database']}") . "</code></li>";
$pdo = null;
try {
    $pdo = edu_mysql($config);
    echo "<li>接続・テーブル自動作成：$ok</li>";
    foreach (['edu_events', 'edu_logs'] as $t) {
        $exists = (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch();
        echo "<li>テーブル <code>$t</code>：" . ($exists ? $ok : $ng('作成できませんでした（CREATE権限を確認）')) . "</li>";
    }
    $dbNow = (string)$pdo->query('SELECT NOW()')->fetchColumn();
    $diff  = abs(strtotime($dbNow) - time());
    echo "<li>MySQLの現在時刻：<code>" . h($dbNow) . "</code>"
       . ($diff < 120 ? $ok : $warn('PHPの時刻とずれています（「今後の予定」の判定に影響）')) . "</li>";
} catch (Throwable $e) {
    echo "<li>接続：" . $ng($e->getMessage()) . "<br>"
       . "<code>education/config.local.php</code> の mysql 設定を確認してください。</li>";
}
echo "</ul>";

// ---- [3] 管理者パスワード ----
echo "<h2>3. 管理者パスワード</h2><ul>";
$hash = (string)$config['admin_password_hash'];
if ($hash === '') {
    echo "<li>admin_password_hash：" . $ng('未設定です。tools/make_password_hash.php でハッシュを作成し config.local.php に記入してください') . "</li>";
} elseif (password_get_info($hash)['algo'] === null || password_get_info($hash)['algo'] === 0) {
    echo "<li>admin_password_hash：" . $ng('ハッシュの形式ではありません（平文のパスワードを書いていないか確認）') . "</li>";
} else {
    echo "<li>admin_password_hash：$ok</li>";
}
echo "<li>config.local.php：" . (is_file(__DIR__ . '/config.local.php') ? $ok : $warn('未作成（config.php の既定値で動作中）')) . "</li>";
echo "</ul>";

// ---- [4] 件数 ----
if ($pdo !== null) {
    echo "<h2>4. 登録件数</h2><ul>";
    foreach (edu_status_map() as $code => $label) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM edu_events WHERE status = ?');
        $st->execute([$code]);
        echo "<li>" . h($label) . "：" . (int)$st->fetchColumn() . " 件</li>";
    }
    echo "</ul>";
}

echo "<p><a href='index.php'>一覧へ</a></p></body></html>";
