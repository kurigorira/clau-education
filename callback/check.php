<?php
/**
 * 外線折り返し連絡板の動作診断。ブラウザで callback/check.php を開くと、
 * [1] PHP環境 [2] MySQL接続・テーブル作成 [3] 管理者アカウント・部署マスタ [4] 掲示件数 を順に確認します。
 * 正常稼働を確認したら削除して構いません。
 */
define('CB_APP', true);
header('Content-Type: text/html; charset=UTF-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/common.php';
require __DIR__ . '/store.php';

$ok   = " <span class='ok'>OK</span>";
$ng   = fn(string $m) => " <span class='ng'>NG</span> " . h($m);
$warn = fn(string $m) => " <span class='warn'>注意</span> " . h($m);

echo "<!doctype html><html lang='ja'><head><meta charset='utf-8'><title>折り返し連絡板 診断</title>";
echo "<style>body{font-family:\"BIZ UDPGothic\",\"Yu Gothic UI\",\"Yu Gothic\",Meiryo,\"MS PGothic\",sans-serif;background:#fff6ec;line-height:1.7;padding:20px;max-width:760px;margin:auto}";
echo ".ok{color:#1b6b35;font-weight:bold}.ng{color:#b53434;font-weight:bold}.warn{color:#7a5b00;font-weight:bold}";
echo "code{background:#f0f0f0;padding:2px 5px;border-radius:4px}li{margin:6px 0}</style></head><body>";
echo "<h1>外線折り返し連絡板 動作診断</h1>";

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
    $pdo = cb_mysql($config);
    echo "<li>接続・テーブル自動作成：$ok</li>";
    foreach (['cb_posts', 'cb_logs', 'cb_admins', 'cb_depts'] as $t) {
        $exists = (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch();
        echo "<li>テーブル <code>$t</code>：" . ($exists ? $ok : $ng('作成できませんでした（CREATE権限を確認）')) . "</li>";
    }
    $dbNow = (string)$pdo->query('SELECT NOW()')->fetchColumn();
    $diff  = abs(strtotime($dbNow) - time());
    echo "<li>MySQLの現在時刻：<code>" . h($dbNow) . "</code>"
       . ($diff < 120 ? $ok : $warn('PHPの時刻とずれています（掲示期限の判定に影響）')) . "</li>";
} catch (Throwable $e) {
    echo "<li>接続：" . $ng($e->getMessage()) . "<br>"
       . "<code>callback/config.local.php</code> の mysql 設定を確認してください。</li>";
}
echo "</ul>";

// ---- [3] 管理者アカウント ----
echo "<h2>3. 管理者アカウント・設定</h2><ul>";
if ($pdo !== null) {
    $n = cb_admin_count($pdo);
    echo "<li>有効な管理者：{$n} 人" . ($n > 0 ? $ok
        : $warn('まだいません。admin.php を開いて「初回設定」で最初の管理者を作成してください')) . "</li>";
    $n = count($config['departments']);
    echo "<li>部署マスタ：使用中 {$n} 件" . ($n > 0 ? $ok : $warn('管理画面の「部署マスタ」で追加してください'))
       . (in_array($config['dept_other'], $config['departments'], true) ? '' : $warn("「{$config['dept_other']}」がありません（自由入力ができません）")) . "</li>";
}
echo "<li>config.local.php：" . (is_file(__DIR__ . '/config.local.php') ? $ok : $warn('未作成（config.php の既定値で動作中）')) . "</li>";
echo "<li>掲示期間の選択肢：" . h(implode('・', $config['expire_hours'])) . " 時間（既定 " . (int)$config['expire_default_hours'] . " 時間）</li>";
echo "<li>保存期間：" . (int)$config['retention_days'] . " 日（過ぎた掲示は自動削除）</li>";
echo "</ul>";

// ---- [4] 件数 ----
if ($pdo !== null) {
    echo "<h2>4. 掲示件数</h2><ul>";
    $counts = array_fill_keys(array_keys(cb_state_map()), 0);
    foreach ($pdo->query('SELECT status, expires_at FROM cb_posts') as $p) {
        $counts[cb_state($p)]++;
    }
    foreach (cb_state_map() as $code => $label) {
        echo "<li>" . h($label) . "：" . $counts[$code] . " 件</li>";
    }
    echo "</ul>";
}

echo "<p><a href='index.php'>一覧へ</a></p></body></html>";
