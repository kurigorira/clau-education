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
$fatal = null;
$candidates = [];
$copyQ = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 50);
$copied = null;

try {
    $pdo = edu_mysql($config);   // 選択肢（主催・会場など）をDBから読み込む
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}

if ($fatal === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    // 「過去の研修から複製」の候補（毎年・毎月の定例研修を手早く登録するため）
    $candidates = edu_copy_candidates($pdo, $copyQ, 20);

    $copyId = (int)($_GET['copy'] ?? 0);
    if ($copyId > 0) {
        $src = edu_find($pdo, $copyId);
        if ($src !== null && in_array($src['status'], ['approved', 'cancelled'], true)) {
            $old = edu_form_from_event($config, $src);
            // 日付だけ空にする（新しい日程を入れてもらう）
            $old['date'] = $old['online_from'] = $old['online_to'] = '';
            $copied = $src;
        }
    }
}

if ($fatal === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $id = edu_create($pdo, $data, password_hash($pass, PASSWORD_DEFAULT));
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

<?php if ($fatal !== null): ?>
  <?php edu_db_error($fatal); ?>
<?php else: ?>

<?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
<details class="panel copy-panel" <?= ($copyQ !== '' && $copied === null) ? 'open' : '' ?>>
  <summary><strong>過去の研修から複製して登録する</strong>
    <span class="muted">（前回・昨年の研修の内容をそのまま使い、日付だけ入れ直せます）</span></summary>
  <form method="get" action="new.php" class="filter-form">
    <div class="form-row">
      <label>研修名・主催・会場で検索</label>
      <input type="text" name="q" value="<?= h($copyQ) ?>" placeholder="例: 医療安全 / 感染対策委員会">
    </div>
    <button type="submit">検索</button>
  </form>
  <?php if (!$candidates): ?>
    <div class="muted">該当する過去の研修はありません。</div>
  <?php else: ?>
    <table class="data compact">
      <thead><tr><th>日時</th><th>研修名</th><th>主催</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($candidates as $c): ?>
          <tr>
            <td class="nowrap"><?= h(edu_when_label($c)) ?></td>
            <td><?= h($c['title']) ?></td>
            <td><?= h($c['organizer']) ?></td>
            <td><a class="btn small" href="new.php?copy=<?= (int)$c['id'] ?>">この内容で登録</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</details>
<?php endif; ?>

<div class="panel">
  <h2>研修・勉強会の予定を登録</h2>
  <?php if ($copied !== null): ?>
    <div class="warn">「<?= h($copied['title']) ?>」（<?= h(edu_when_label($copied)) ?>）の内容を複製しました。
      <strong>日付を入力</strong>し、必要なら研修名（第○回など）を直してから申請してください。</div>
  <?php endif; ?>
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

<?php endif; ?>

<?php edu_layout_footer($config); ?>
