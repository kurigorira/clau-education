<?php
/**
 * デモ用のサンプル掲示を登録する。操作説明・試験運用用。
 *   php tools/demo_data.php            … 折り返し待ち・連絡がついた・期限切れの掲示を登録
 *   php tools/demo_data.php --clear    … 先にデモ登録分（担当者名が「デモ」で始まるもの）を削除してから登録
 * デモ掲示の簡易パスワードはすべて「demo」。
 * 本番データには使わないこと。コマンドラインからのみ実行できる。
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }

define('CB_APP', true);
$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../common.php';
require __DIR__ . '/../store.php';

$pdo = cb_mysql($config);
if (in_array('--clear', $argv, true)) {
    $pdo->exec("DELETE l FROM cb_logs l JOIN cb_posts p ON p.id = l.post_id WHERE p.caller_name LIKE 'デモ%'");
    $n = $pdo->exec("DELETE FROM cb_posts WHERE caller_name LIKE 'デモ%'");
    echo "デモ掲示を {$n} 件削除しました\n";
}

$hash = password_hash('demo', PASSWORD_DEFAULT);
// [何分前に電話, 掲示時間, 担当者, 部署, 内線, 不在時, 区分, 相手名, 下4桁, 用件, 状態]
$rows = [
    [12,   24, 'デモ 佐藤', '新3階病棟', '3312',          '新3階病棟 詰所 内線3300', '患者家族', '山田様', '4821', '退院日程のご相談', 'open'],
    [35,   24, 'デモ 田中', '医療連携室', '2105',      '連携室 代表 内線2100', '他の医療機関', '〇〇病院 地域連携室', '', '転院受け入れの返答', 'open'],
    [70,    8, 'デモ 鈴木', '医事課',     '1203',          '', '患者本人', '中村様', '0937', '外来予約日の変更について', 'open'],
    [150,  24, 'デモ 高橋', '検査科',     'PHS 5521',      '検査科 受付 内線1500', '業者', '△△メディカル', '7710', '試薬の納品日確認', 'open'],
    [300,  48, 'デモ 伊藤', 'リハビリテーション科', '1802', '', '施設・ケアマネ', '□□居宅介護 木村様', '3366', 'サービス担当者会議の日程', 'open'],
    [200,  24, 'デモ 渡辺', '外来・手術室', '1101',          '', '患者家族', '小林様', '5150', '書類のお渡しについて', 'done'],
    [1600, 24, 'デモ 山本', '薬剤部',     '1401',          '', '他の医療機関', '◇◇薬局', '2288', '処方内容の確認', 'expired'],
];
foreach ($rows as [$min, $hours, $name, $dept, $ext, $alt, $type, $party, $last4, $purpose, $state]) {
    $pdo->prepare(
        'INSERT INTO cb_posts (caller_name, caller_dept, caller_ext, alt_contact, party_type, party_name,
                               phone_last4, purpose, called_at, expires_at, edit_pass_hash)
         VALUES (?,?,?,?,?,?,?,?, NOW() - INTERVAL ? MINUTE, NOW() - INTERVAL ? MINUTE + INTERVAL ? HOUR, ?)'
    )->execute([$name, $dept, $ext, $alt, $type, $party, $last4, $purpose, $min, $min, $hours, $hash]);
    $id = (int)$pdo->lastInsertId();
    cb_log($pdo, $id, 'create', $name, "掲示期間 {$hours}時間");
    if ($state === 'done') {
        cb_close($pdo, $id, 'done', $name, '折り返しあり、説明済み');
    }
}
// 折り返しを受けた記録の例（担当者不在で伝言）
$first = (int)$pdo->query("SELECT id FROM cb_posts WHERE caller_name = 'デモ 田中' ORDER BY id DESC LIMIT 1")->fetchColumn();
cb_receive($pdo, $first, 'デモ 交換', '総合受付：担当者不在のため伝言、15時に再度かけるとのこと');

echo count($rows) . " 件のデモ掲示を登録しました（簡易パスワード: demo）\n";
