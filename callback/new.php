<?php
/**
 * 掲示する（ログイン不要）。
 * 外線をかけて相手に繋がらなかった職員が、自分の氏名・部署・内線と、相手先の概要を掲示する。
 * 相手先の情報は最小限（区分・姓・電話番号の下4桁・用件の概略）にとどめる。
 * 「連絡がついた」「取り下げ」「延長」は、詳細画面から誰でも（操作した人の氏名を記録して）できる。
 */
define('CB_APP', true);
$config = require __DIR__ . '/config.php';
require __DIR__ . '/common.php';
require __DIR__ . '/store.php';

$hoursChoices = array_map('intval', $config['expire_hours']);
$defaultHours = (int)$config['expire_default_hours'];
if (!in_array($defaultHours, $hoursChoices, true)) { $defaultHours = $hoursChoices[0]; }

$old = [
    'caller_name' => '', 'caller_dept' => '', 'caller_dept_other' => '', 'caller_ext' => '', 'alt_contact' => '',
    'party_type' => '', 'party_name' => '', 'phone_last4' => '', 'purpose' => '',
    'called_date' => date('Y-m-d'), 'called_time' => date('H:i'), 'hours' => $defaultHours,
];
$errors = [];
$fatal = null;
try {
    $pdo = cb_mysql($config);
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}

if ($fatal === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    cb_csrf_check();
    foreach ($old as $k => $_) {
        $old[$k] = trim(mb_convert_kana((string)($_POST[$k] ?? ''), 's'));
    }
    $old['phone_last4'] = mb_convert_kana($old['phone_last4'], 'n');
    $old['caller_ext']  = mb_convert_kana($old['caller_ext'], 'a');
    $old['hours'] = (int)$old['hours'];

    $dept = cb_dept_read($config, $_POST, 'caller_dept', '所属部署');
    if ($dept['error'] !== null) { $errors[] = $dept['error']; }
    $limits = ['caller_name' => ['あなたの氏名', 64], 'caller_ext' => ['内線・PHS番号', 32]];
    foreach ($limits as $k => [$label, $max]) {
        if ($old[$k] === '') {
            $errors[] = "{$label}を入力してください。";
        } elseif (mb_strlen($old[$k]) > $max) {
            $errors[] = "{$label}は{$max}文字以内で入力してください。";
        }
    }
    if (mb_strlen($old['alt_contact']) > 100) {
        $errors[] = '代わりの連絡先は100文字以内で入力してください。';
    }
    if (!in_array($old['party_type'], $config['party_types'], true)) {
        $errors[] = '相手先の区分を選んでください。';
    }
    if (mb_strlen($old['party_name']) > 32) {
        $errors[] = '相手のお名前は32文字以内（姓のみ）で入力してください。';
    }
    if ($old['phone_last4'] !== '' && !preg_match('/^\d{4}$/', $old['phone_last4'])) {
        $errors[] = '電話番号は下4桁の数字だけを入力してください（全桁は掲示しません）。';
    }
    if ($old['party_name'] === '' && $old['phone_last4'] === '') {
        $errors[] = '折り返しを受けた人が照合できるよう、相手のお名前（姓）か電話番号の下4桁のどちらかは入力してください。';
    }
    if ($old['purpose'] === '') {
        $errors[] = '用件の概略を入力してください。';
    } elseif (mb_strlen($old['purpose']) > 100) {
        $errors[] = '用件の概略は100文字以内で入力してください。';
    }
    $calledTs = strtotime($old['called_date'] . ' ' . $old['called_time']);
    if ($calledTs === false || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['called_date'])) {
        $errors[] = '電話した日時を正しく入力してください。';
    } elseif ($calledTs > time() + 600) {
        $errors[] = '電話した日時が未来になっています。';
    } elseif ($calledTs < time() - 7 * 86400) {
        $errors[] = '電話した日時は7日以内にしてください。';
    }
    if (!in_array($old['hours'], $hoursChoices, true)) {
        $errors[] = '掲示期間を選んでください。';
    }

    if (!$errors) {
        try {
            $data = $old;
            $data['called_at'] = date('Y-m-d H:i:s', $calledTs);
            $data['caller_dept'] = $dept['value'];
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
  <strong>個人情報に注意：</strong>院内の全職員が見る掲示です。相手のお名前は<strong>姓のみ</strong>、電話番号は<strong>下4桁のみ</strong>、
  用件は「検査結果の件」「入院日程の確認」程度の<strong>概略</strong>にしてください。病名・ID・住所などは書かないでください。
</div>
<?php cb_render_errors($errors); ?>

<form method="post" action="new.php" class="panel">
  <?= cb_csrf_field() ?>
  <fieldset>
    <legend>あなた（折り返しの転送先）</legend>
    <div class="form-grid">
      <div class="form-row">
        <label for="caller_name">氏名<span class="required">必須</span></label>
        <input type="text" id="caller_name" name="caller_name" value="<?= h($old['caller_name']) ?>" maxlength="64" required>
      </div>
      <div class="form-row">
        <label for="caller_dept">所属部署<span class="required">必須</span></label>
        <?php cb_dept_select($config, 'caller_dept', $old['caller_dept'], $old['caller_dept_other']); ?>
      </div>
      <div class="form-row">
        <label for="caller_ext">内線・PHS番号<span class="required">必須</span></label>
        <input type="text" id="caller_ext" name="caller_ext" value="<?= h($old['caller_ext']) ?>" maxlength="32" required placeholder="例：1234／PHS 5678">
      </div>
    </div>
    <div class="form-row">
      <label for="alt_contact">あなたが不在のときの連絡先<span class="hint">任意（例：3階病棟 詰所 内線3300・リーダー看護師へ）</span></label>
      <input type="text" id="alt_contact" name="alt_contact" value="<?= h($old['alt_contact']) ?>" maxlength="100">
    </div>
  </fieldset>

  <fieldset>
    <legend>電話した相手</legend>
    <div class="form-grid">
      <div class="form-row">
        <label for="party_type">相手先の区分<span class="required">必須</span></label>
        <select id="party_type" name="party_type" required>
          <option value="">選んでください</option>
          <?php foreach ($config['party_types'] as $t): ?>
            <option value="<?= h($t) ?>" <?= $old['party_type'] === $t ? 'selected' : '' ?>><?= h($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label for="party_name">お名前（姓のみ）<span class="hint">例：山田様・〇〇病院 地域連携室</span></label>
        <input type="text" id="party_name" name="party_name" value="<?= h($old['party_name']) ?>" maxlength="32">
      </div>
      <div class="form-row">
        <label for="phone_last4">電話番号の下4桁<span class="hint">数字4桁</span></label>
        <input type="text" id="phone_last4" name="phone_last4" value="<?= h($old['phone_last4']) ?>" maxlength="4" inputmode="numeric" pattern="[0-9０-９]{4}" placeholder="1234">
      </div>
    </div>
    <div class="form-row">
      <label for="purpose">用件の概略<span class="required">必須</span><span class="hint">100文字以内・病名や詳しい内容は書かない</span></label>
      <input type="text" id="purpose" name="purpose" value="<?= h($old['purpose']) ?>" maxlength="100" required placeholder="例：外来予約日の変更について">
    </div>
    <div class="form-grid">
      <div class="form-row">
        <label for="called_date">電話した日</label>
        <input type="date" id="called_date" name="called_date" value="<?= h($old['called_date']) ?>" required>
      </div>
      <div class="form-row">
        <label for="called_time">時刻</label>
        <input type="time" id="called_time" name="called_time" value="<?= h($old['called_time']) ?>" required>
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend>掲示の設定</legend>
    <div class="form-row">
      <label>掲示期間<span class="hint">過ぎると一覧から自動で消えます（あとから延長できます）</span></label>
      <div class="checks">
        <?php foreach ($hoursChoices as $hr): ?>
          <label class="check"><input type="radio" name="hours" value="<?= $hr ?>" <?= (int)$old['hours'] === $hr ? 'checked' : '' ?>>
            <?= $hr % 24 === 0 ? ($hr / 24) . '日（' . $hr . '時間）' : $hr . '時間' ?></label>
        <?php endforeach; ?>
      </div>
    </div>
  </fieldset>

  <button type="submit">掲示する</button>
  <a class="btn secondary" href="index.php">やめる</a>
</form>
<?php cb_layout_footer($config); ?>
