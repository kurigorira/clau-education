<?php
/**
 * 外線折り返し連絡 掲示板 設定（このアプリ単体で完結）。
 *  - MySQL はレセプト・研修スケジュールアプリと同じサーバ・DBを使ってよい
 *  - 管理者は、初回アクセス時の「初回設定」画面と管理画面で作成する
 *  - 部署の選択肢は、ここに書いた値が「初期値」として初回にDBへ登録される。
 *    以後は管理画面の「部署マスタ」で追加・変更する（このファイルを書き換えても反映されない）
 *
 * 接続情報は config.local.php に置くこと（*.local.php は Git 管理外）。
 */
if (!defined('CB_APP')) { http_response_code(403); exit('Forbidden'); }

date_default_timezone_set('Asia/Tokyo');

$config = [
    // ---- MySQL（保存先） ----
    // レセプトアプリと同じ MySQL に保存する場合は、
    // receipt/config/config.php と同じ値を書けばよい
    'mysql' => [
        'host'     => '127.0.0.1',
        'port'     => '3306',
        'database' => 'receipt_db',
        'user'     => 'receipt_user',
        'pass'     => 'change_me',
    ],

    // ---- 画面設定 ----
    'title'    => '外線折り返し連絡板',
    'hospital' => '長崎北徳洲会病院',
    'per_page' => 50,   // 履歴の1ページあたり表示件数
    'refresh_seconds' => 60,   // 一覧の自動更新間隔（秒）。0 で自動更新しない

    // ---- 相手先の区分（掲示する画面に表示する選択肢） ----
    'party_types' => [
        '患者本人', '患者家族', '他の医療機関', '施設・ケアマネ', '業者', '行政・公的機関', 'その他',
    ],

    // ---- 部署マスタの初期値（コード => 名称。初回のみDBへ登録。以後は管理画面の「部署マスタ」で編集） ----
    // 電子カルテの部署マスタ（30:部署）から、掲示に使わない部署を除いたもの
    'departments' => [
        '0001' => '診療部',           '0002' => '事務部',         '0003' => '総務課',
        '0004' => '医療連携室',       '0005' => '医事課',         '0006' => '薬剤部',
        '0007' => '検査科',           '0008' => '放射線科',       '0009' => 'リハビリテーション科',
        '0010' => '栄養科',           '0012' => '外来・手術室',   '0013' => '地域支援センター',
        '0014' => '通所リハビリ',     '0018' => '健康管理センター', '0019' => '看護部',
        '0021' => '訪問看護',         '0022' => '訪問介護',       '0023' => '居宅',
        '0024' => '臨床工学科',       '0025' => '医療秘書課',     '0030' => '新3階病棟',
        '0031' => '新4階病棟',        '0032' => '新5階病棟',      '0034' => '医師事務支援課',
        '0099' => 'その他',
    ],
    // この名前の部署を選んだときは、部署名を自由入力する（管理画面で使用停止にはできない）
    'dept_other' => 'その他',

    // ---- 掲示期間（時間）の選択肢と既定値 ----
    // 期間を過ぎると一覧から自動的に消え、履歴に「期限切れ」として残る
    'expire_hours'         => [4, 8, 24, 48, 72],
    'expire_default_hours' => 24,

    // ---- 保存期間 ----
    // 掲示からこの日数を過ぎたものは自動で削除する（相手先の情報を溜め込まないため）
    'retention_days' => 180,

    // 管理者パスワードの誤りがこの回数に達したら、
    // lock_minutes 分受け付けない（IP単位）
    'login_max_fail' => 5,
    'lock_minutes'   => 10,
];

/*
 * 接続情報は config.local.php に置く。
 * （config.local.php.example を複製して記入）
 * party_types / expire_hours / departments は「丸ごと置き換え」、それ以外は項目単位で上書きする。
 */
$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $override = require $local;
    if (is_array($override)) {
        foreach (['party_types', 'expire_hours', 'departments'] as $listKey) {
            if (isset($override[$listKey]) && is_array($override[$listKey])) {
                $config[$listKey] = $override[$listKey];
                unset($override[$listKey]);
            }
        }
        $config = array_replace_recursive($config, $override);
    }
}
return $config;
