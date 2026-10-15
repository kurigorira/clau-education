<?php
/**
 * 院内研修・勉強会スケジュール 設定（このアプリ単体で完結）。
 *  - MySQL はレセプト・空床・問い合わせアプリと同じサーバ・DBを使ってよい
 *  - 主催・種別・対象職種・会場の選択肢は、ここに書いた値が「初期値」として初回にDBへ登録される。
 *    以後は管理画面の「選択肢の管理」で追加・変更する（このファイルを書き換えても反映されない）
 *  - 管理者（承認者）は、初回アクセス時の「初回設定」画面と「管理者アカウント」画面で作成する
 *
 * 接続情報は config.local.php に置くこと（*.local.php は Git 管理外）。
 */
if (!defined('EDU_APP')) { http_response_code(403); exit('Forbidden'); }

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
    'title'    => '院内研修・勉強会スケジュール',
    'hospital' => '長崎北徳洲会病院',
    'per_page' => 50,   // 一覧の1ページあたり表示件数

    // ---- 選択肢の初期値（初回のみDBへ登録。以後は「選択肢の管理」画面で編集） ----
    // 主催（区分 => 主催名の一覧）。どれにも当てはまらない場合は「その他」で自由入力できる
    'organizers' => [
        '委員会' => [
            '医療安全管理委員会', '院内感染対策委員会', '褥瘡対策委員会', 'NST委員会',
            '教育委員会', '防災委員会', '輸血療法委員会', '薬事委員会',
            '個人情報保護委員会', '医療ガス安全管理委員会', '虐待防止委員会',
            '身体的拘束最小化委員会', '接遇委員会', 'クリニカルパス委員会',
        ],
        '本部' => [
            '徳洲会本部', '九州ブロック',
        ],
        '部署' => [
            '診療部', '看護部', '薬剤部', '検査科', '放射線科', 'リハビリテーション科',
            '栄養科', '臨床工学科', '医事課', '総務課', '地域医療連携室', 'システム課',
        ],
    ],

    // 種別の選択肢
    'categories' => ['講習会', '勉強会', '研修会', 'その他'],

    // 対象職種の選択肢（複数選択可）
    'targets' => [
        '全職員', '医師', '看護師', '看護補助者', '薬剤師', '臨床検査技師',
        '診療放射線技師', 'リハビリ職', '管理栄養士', '臨床工学技士', '事務職', '新入職員',
    ],

    // 会場の候補（入力欄の候補として表示。自由入力も可）
    'places' => [
        '6階会議室（約100名）', '講堂', '大会議室', '小会議室', '各病棟', 'リハビリ室', 'オンライン（院内配信）',
    ],

    // 管理画面のログイン失敗がこの回数に達したら、lock_minutes 分ログインを受け付けない（IP単位）
    'login_max_fail' => 5,
    'lock_minutes'   => 10,
];

/*
 * 接続情報は config.local.php に置く。
 * （config.local.php.example を複製して記入）
 * organizers / categories / targets / places は「丸ごと置き換え」、それ以外は項目単位で上書きする。
 */
$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $override = require $local;
    if (is_array($override)) {
        foreach (['organizers', 'categories', 'targets', 'places'] as $listKey) {
            if (isset($override[$listKey]) && is_array($override[$listKey])) {
                $config[$listKey] = $override[$listKey];
                unset($override[$listKey]);
            }
        }
        $config = array_replace_recursive($config, $override);
    }
}
return $config;
