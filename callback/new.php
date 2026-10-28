<?php
/**
 * 掲示する（ログイン不要）。
 * 外線をかけて相手に繋がらなかった職員が、相手先の概要と、自分の氏名・部署・内線を掲示する。
 * 相手先の情報は最小限（区分・姓・ID番号・用件の概略）にとどめる。
 * 「連絡がついた」「取り下げ」「延長」「編集」は、詳細画面から誰でも（操作した人の氏名を記録して）できる。
 */
define('CB_APP', true);
$config = require __DIR__ . '/config.php';
require __DIR__ . '/common.php';
require __DIR__ . '/store.php';
require __DIR__ . '/form.php';

$old = cb_form_defaults($config);
$errors = [];
$fatal = null;
try {
    $pdo = cb_mysql($config);
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}

if ($fatal === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    cb_csrf_check();
    [$old, $data, $errors] = cb_form_read($config, $_POST, false);
    if (!$errors) {
        try {
            $id = cb_create($pdo, $data, $old['hours']);
            cb_flash_set('掲示しました。連絡がついたら、この画面の「連絡がついた」を押してください。');
            header('Location: view.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            $errors[] = '登録に失敗しました: ' . $e->getMessage();
        }
    }
}

cb_layout_header($config, '掲示する', 'new');
if ($fatal !== null) {
    cb_db_error($fatal);
    cb_layout_footer($config);
    exit;
}
?>
<p class="lead">外線をかけて相手に繋がらなかったときに掲示します。折り返しの電話を受けた職員が、あなたの内線へ転送できるようになります。</p>
<div class="warn">
  <strong>個人情報に注意：</strong>院内の全職員が見る掲示です。相手のお名前は<strong>姓のみ</strong>、
  用件は「検査結果の件」「入院日程の確認」程度の<strong>概略</strong>にしてください。病名・住所などは書かないでください。
</div>
<?php cb_render_errors($errors); ?>
<?php cb_form_render($config, $old, 'new.php', '掲示する', false, 'index.php'); ?>
<?php cb_layout_footer($config); ?>
