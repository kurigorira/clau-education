<?php
/**
 * 予定の登録申請（ログイン不要・誰でも登録できる）。
 * 登録した予定は「承認待ち」となり、管理者が承認すると一覧・カレンダーに掲載される。
 * 登録者は自分で決めた編集用パスワードで、後から編集・中止できる。
 */
define('EDU_APP', true);
$config = require __DIR__ . '/config.php';
require __DIR__ . '/common.php';
require __DIR__ . '/store.php';
require __DIR__ . '/form.php';

$errors = [];
$old = edu_form_defaults();

// 「この予定を複製して登録」（毎月・毎年の定例研修を手早く登録するため）
$copyId = (int)($_GET['copy'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $copyId > 0) {
    try {
        $src = edu_find(edu_mysql($config), $copyId);
        if ($src !== null && in_array($src['status'], ['approved', 'cancelled'], true)) {
            $old = edu_form_from_event($config, $src);
            $old['date'] = '';
        }
    } catch (Throwable $e) {
        $errors[] = 'データベースに接続できませんでした: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    edu_csrf_check();
    [$old, $data, $errors] = edu_form_read($config, $_POST);

    $pass  = (string)($_POST['edit_pass'] ?? '');
    $pass2 = (string)($_POST['edit_pass2'] ?? '');
    if (mb_strlen($pass) < 4) {
        $errors[] = '編集用パスワードは4文字以上で入力してください。';
    } elseif ($pass !== $pass2) {
        $errors[] = '編集用パスワード（確認）が一致しません。';
    }

    if (!$errors) {
        try {
            $pdo = edu_mysql($config);
            $id  = edu_create($pdo, $data, password_hash($pass, PASSWORD_DEFAULT));
            edu_unlock($id);   // 登録直後はそのまま編集できるようにする
            edu_flash_set("登録申請を受け付けました（登録番号 #{$id}）。管理者の承認後に一覧・カレンダーへ掲載されます。"
                        . '後から変更・中止するときは、この登録番号と編集用パスワードを使います。');
            header('Location: view.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            $errors[] = '登録に失敗しました: ' . $e->getMessage();
        }
    }
}

edu_layout_header($config, '予定を登録', 'new');
?>

<div class="panel">
  <h2>研修・勉強会の予定を登録</h2>
  <p class="lead">
    委員会・本部・部署の担当者なら、どなたでも登録できます（ログイン不要）。
    登録後、管理者が内容を確認して承認すると、一覧とカレンダーに掲載されます。
  </p>

  <?php edu_render_errors($errors); ?>

  <form method="post" action="new.php">
    <?= edu_csrf_field() ?>
    <?php edu_render_form_fields($config, $old); ?>

    <fieldset>
      <legend>編集用パスワード</legend>
      <div class="note">
        登録した予定を、後から<strong>あなた自身で変更・中止する</strong>ときに使います。
        4文字以上で自由に決めてください（職員番号＋好きな数字 など）。
        忘れた場合は管理者に再設定を依頼してください。
      </div>
      <div class="form-grid">
        <div class="form-row">
          <label>編集用パスワード<span class="required">必須</span></label>
          <input type="password" name="edit_pass" autocomplete="new-password">
        </div>
        <div class="form-row">
          <label>編集用パスワード（確認）<span class="required">必須</span></label>
          <input type="password" name="edit_pass2" autocomplete="new-password">
        </div>
      </div>
    </fieldset>

    <button type="submit">登録を申請する</button>
    <a href="index.php" class="btn secondary">一覧へ戻る</a>
  </form>
</div>

<?php edu_layout_footer($config); ?>
