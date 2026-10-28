<?php
/**
 * 掲示の入力フォーム（掲示する画面・編集画面で共用）。
 *  - cb_form_defaults() … 新規掲示の初期値
 *  - cb_form_from_post() … 掲示済みの内容を編集フォームの初期値にする
 *  - cb_form_read()      … 送信内容の読み取りと入力チェック
 *  - cb_form_render()    … フォームの表示（「電話した相手」→「あなた」→「掲示の設定」の順）
 */
if (!defined('CB_APP')) { http_response_code(403); exit('Forbidden'); }

/** 掲示期間の選択肢（時間）と既定値 */
function cb_form_hours(array $config): array
{
    $choices = array_map('intval', $config['expire_hours']);
    $default = (int)$config['expire_default_hours'];
    return [$choices, in_array($default, $choices, true) ? $default : $choices[0]];
}

/** 新規掲示の初期値 */
function cb_form_defaults(array $config): array
{
    return [
        'party_type' => '', 'party_name' => '', 'patient_id' => '', 'purpose' => '',
        'called_date' => date('Y-m-d'), 'called_time' => date('H:i'),
        'caller_name' => '', 'caller_dept' => '', 'caller_dept_other' => '', 'caller_ext' => '', 'alt_contact' => '',
        'hours' => cb_form_hours($config)[1], 'actor' => '',
    ];
}

/** 掲示済みの内容を編集フォームの初期値にする（マスタにない部署は「その他」＋部署名） */
function cb_form_from_post(array $config, array $p): array
{
    $old = cb_form_defaults($config);
    foreach (['party_type', 'party_name', 'patient_id', 'purpose', 'caller_name', 'caller_ext', 'alt_contact'] as $k) {
        $old[$k] = (string)$p[$k];
    }
    $ts = strtotime($p['called_at']);
    $old['called_date'] = date('Y-m-d', $ts);
    $old['called_time'] = date('H:i', $ts);
    if (in_array($p['caller_dept'], $config['departments'], true)) {
        $old['caller_dept'] = $p['caller_dept'];
    } else {
        $old['caller_dept'] = $config['dept_other'];
        $old['caller_dept_other'] = $p['caller_dept'];
    }
    return $old;
}

/**
 * 送信内容を読み取り、入力チェックする。
 * $isEdit … 編集のときは掲示期間を読まず、編集する方の氏名を必須にする
 * @return array{0: array, 1: array, 2: string[]} [フォームの再表示用の値, 保存する値, エラー]
 */
function cb_form_read(array $config, array $post, bool $isEdit): array
{
    $old = cb_form_defaults($config);
    foreach ($old as $k => $_) {
        $old[$k] = trim(mb_convert_kana((string)($post[$k] ?? ''), 's'));
    }
    $old['patient_id'] = mb_convert_kana($old['patient_id'], 'a');
    $old['caller_ext'] = mb_convert_kana($old['caller_ext'], 'a');
    $old['hours'] = (int)$old['hours'];
    $errors = [];

    // ---- 電話した相手 ----
    if (!in_array($old['party_type'], $config['party_types'], true)) {
        $errors[] = '相手先の区分を選んでください。';
    }
    if (mb_strlen($old['party_name']) > 32) {
        $errors[] = '相手のお名前は32文字以内（姓のみ）で入力してください。';
    }
    if ($old['patient_id'] !== '' && !preg_match('/^[0-9A-Za-z\-]{1,16}$/', $old['patient_id'])) {
        $errors[] = 'ID番号は半角英数字16文字以内で入力してください。';
    }
    if ($old['party_name'] === '' && $old['patient_id'] === '') {
        $errors[] = '折り返しを受けた人が照合できるよう、相手のお名前（姓）かID番号のどちらかは入力してください。';
    }
    if (mb_strlen($old['purpose']) > 100) {
        $errors[] = '用件の概略は100文字以内で入力してください。';
    }
    $calledTs = strtotime($old['called_date'] . ' ' . $old['called_time']);
    if ($calledTs === false || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['called_date'])) {
        $errors[] = '電話した日時を正しく入力してください。';
    } elseif ($calledTs > time() + 600) {
        $errors[] = '電話した日時が未来になっています。';
    } elseif (!$isEdit && $calledTs < time() - 7 * 86400) {
        $errors[] = '電話した日時は7日以内にしてください。';
    }

    // ---- あなた（折り返しの転送先） ----
    $limits = ['caller_name' => ['あなたの氏名', 64], 'caller_ext' => ['内線・PHS番号', 32]];
    foreach ($limits as $k => [$label, $max]) {
        if ($old[$k] === '') {
            $errors[] = "{$label}を入力してください。";
        } elseif (mb_strlen($old[$k]) > $max) {
            $errors[] = "{$label}は{$max}文字以内で入力してください。";
        }
    }
    $dept = cb_dept_read($config, $post, 'caller_dept', '所属部署');
    $old['caller_dept_other'] = $dept['other'];
    if ($dept['error'] !== null) { $errors[] = $dept['error']; }
    if (mb_strlen($old['alt_contact']) > 100) {
        $errors[] = '代わりの連絡先は100文字以内で入力してください。';
    }

    // ---- 掲示の設定・編集する方 ----
    if (!$isEdit && !in_array($old['hours'], cb_form_hours($config)[0], true)) {
        $errors[] = '掲示期間を選んでください。';
    }
    if ($isEdit && !cb_is_admin() && ($old['actor'] === '' || mb_strlen($old['actor']) > 32)) {
        $errors[] = '編集する方の氏名を32文字以内で入力してください。';
    }

    $data = [];
    foreach (['party_type', 'party_name', 'patient_id', 'purpose', 'caller_name', 'caller_ext', 'alt_contact'] as $k) {
        $data[$k] = $old[$k];
    }
    $data['caller_dept'] = $dept['value'];
    $data['called_at']   = $calledTs !== false ? date('Y-m-d H:i:s', $calledTs) : '';
    return [$old, $data, $errors];
}

/** フォームの表示（$isEdit のときは掲示期間の代わりに「編集する方の氏名」を出す） */
function cb_form_render(array $config, array $old, string $action, string $submitLabel, bool $isEdit, string $cancelUrl): void
{
    [$hoursChoices] = cb_form_hours($config);
    ?>
<form method="post" action="<?= h($action) ?>" class="panel">
  <?= cb_csrf_field() ?>
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
        <label for="patient_id">ID番号<span class="hint">患者IDなど・半角英数字</span></label>
        <input type="text" id="patient_id" name="patient_id" value="<?= h($old['patient_id']) ?>" maxlength="16" inputmode="numeric" placeholder="例：0012345">
      </div>
    </div>
    <div class="form-row">
      <label for="purpose">用件の概略<span class="hint">任意・100文字以内・病名や詳しい内容は書かない</span></label>
      <input type="text" id="purpose" name="purpose" value="<?= h($old['purpose']) ?>" maxlength="100" placeholder="例：外来予約日の変更について">
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
      <label for="alt_contact">あなたが不在のときの連絡先<span class="hint">任意（例：新3階病棟 詰所 内線3300・リーダー看護師へ）</span></label>
      <input type="text" id="alt_contact" name="alt_contact" value="<?= h($old['alt_contact']) ?>" maxlength="100">
    </div>
  </fieldset>

  <?php if ($isEdit): ?>
  <fieldset>
    <legend>編集する方</legend>
    <?php if (cb_is_admin()): ?>
      <p class="muted small">管理者としてログイン中のため、「<?= h(cb_admin_name()) ?>（管理者）」として記録されます。</p>
    <?php else: ?>
      <div class="form-row">
        <label for="actor">編集する方の氏名<span class="required">必須</span><span class="hint">変更内容とあわせて記録に残ります</span></label>
        <input type="text" id="actor" name="actor" value="<?= h($old['actor']) ?>" maxlength="32" required>
      </div>
    <?php endif; ?>
  </fieldset>
  <?php else: ?>
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
  <?php endif; ?>

  <button type="submit"><?= h($submitLabel) ?></button>
  <a class="btn secondary" href="<?= h($cancelUrl) ?>">やめる</a>
</form>
<?php
}
