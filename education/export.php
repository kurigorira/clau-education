<?php
/**
 * 年度（4月〜翌3月）の研修計画を CSV で出力する（管理者のみ）。
 * Excel でそのまま開けるよう BOM 付き UTF-8 で出力する。
 */
define('EDU_APP', true);
$config = require __DIR__ . '/config.php';
require __DIR__ . '/common.php';
require __DIR__ . '/store.php';

try {
    $pdo = edu_mysql($config);   // 無効化された管理者のログイン状態もここで解除される
} catch (Throwable $e) {
    http_response_code(500);
    exit('データベースに接続できませんでした: ' . h($e->getMessage()));
}

if (!edu_is_admin()) {
    edu_flash_set('CSV出力は管理者ログインが必要です。', 'error');
    header('Location: admin.php');
    exit;
}

$fy = (int)($_GET['fy'] ?? edu_fiscal_year(time()));
if ($fy < 2000 || $fy > 2100) { $fy = edu_fiscal_year(time()); }

$rows = edu_fiscal_rows($pdo, $fy);

$statusMap = edu_status_map();
$filename  = "研修計画_{$fy}年度.csv";

header('Content-Type: text/csv; charset=UTF-8');
header("Content-Disposition: attachment; filename=\"education_{$fy}.csv\"; filename*=UTF-8''" . rawurlencode($filename));
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['開催形式', '開催日・視聴開始日', '曜日', '開始時刻', '終了時刻・視聴締切日', '研修名', '種別', '必須', '主催区分', '主催', '対象',
               '会場・配信方法', '定員', '状態', '担当者所属', '担当者', '連絡先', '内容', '登録番号']);
foreach ($rows as $r) {
    $ts = strtotime($r['start_at']);
    // 先頭が = + - @ のセルは Excel で式として解釈されるため ' を付けて無害化する
    $safe = fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : (string)$v;
    $online = edu_is_online($r);
    fputcsv($out, array_map($safe, [
        edu_format_label($r['format']),
        date('Y/m/d', $ts),
        edu_wday($ts),
        $online ? '' : date('H:i', $ts),
        $r['end_at'] ? date($online ? 'Y/m/d' : 'H:i', strtotime($r['end_at'])) : '',
        $r['title'],
        $r['category'],
        (int)$r['is_mandatory'] === 1 ? '必須' : '',
        $r['organizer_type'],
        $r['organizer'],
        str_replace(',', '、', $r['targets']),
        $r['place'],
        $r['capacity'] ?? '',
        $statusMap[$r['status']] ?? $r['status'],
        $r['contact_dept'],
        $r['contact_name'],
        $r['contact_ext'],
        $r['body'],
        $r['id'],
    ]));
}
fclose($out);
