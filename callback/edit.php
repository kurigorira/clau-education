<?php
/**
 * 掲示の編集（誰でも。編集した人の氏名と変更点（旧→新）を記録に残す。管理者はログイン名で記録）。
 * 掲示期間は編集では変えない（詳細画面の「延長」で変える）。
 */
define('CB_APP', true);
$config = require __DIR__ . '/config.php';
require __DIR__ . '/common.php';
require __DIR__ . '/store.php';
require __DIR__ . '/form.php';

$id = (int)($_GET['id'] ?? 0);
$errors = [];
$fatal = null;
$post = null;
try {
    $pdo  = cb_mysql($config);
    $post = $id > 0 ? cb_find($pdo, $id) : null;
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}
$old = $post !== null ? cb_form_from_post($config, $post) : [];

if ($post !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    cb_csrf_check();
    [$old, $data, $errors] = cb_form_read($config, $_POST, true);
    if (!$errors) {
        try {
            $who  = cb_is_admin() ? cb_admin_name() . '（管理者）' : $old['actor'];
            $diff = cb_update($pdo, $post, $data, $who);
            cb_flash_set($diff === '' ? '変更はありませんでした。' : '掲示の内容を変更しました。変更点は下の記録に残ります。');
            header('Location: view.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            $errors[] = '保存に失敗しました: ' . $e->getMessage();
        }
    }
}

cb_layout_header($config, '掲示の編集', '');
if ($fatal !== null) {
    cb_db_error($fatal);
    cb_layout_footer($config);
    exit;
}
if ($post === null) {
    echo '<div class="empty">掲示が見つかりません（保存期間を過ぎたか、削除された可能性があります）。<a href="index.php">一覧へ</a></div>';
    cb_layout_footer($config);
    exit;
}
?>
<p class="lead">掲示の内容を直します。編集した方の氏名と変更点が記録に残ります。掲示期間を変えるときは、詳細画面の「掲示を延長」を使ってください。</p>
<div class="warn">
  <strong>個人情報に注意：</strong>院内の全職員が見る掲示です。相手のお名前は<strong>姓のみ</strong>、
  用件は<strong>概略</strong>にしてください。病名・住所などは書かないでください。
</div>
<?php cb_render_errors($errors); ?>
<?php cb_form_render($config, $old, 'edit.php?id=' . $id, '変更を保存', true, 'view.php?id=' . $id); ?>
<?php cb_layout_footer($config); ?>
