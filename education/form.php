<?php
/**
 * 予定の入力フォーム（新規登録 new.php と編集 edit.php で共用）。
 * 入力値の読み取り・検証と、フォーム項目の描画を行う。
 */
if (!defined('EDU_APP')) { http_response_code(403); exit('Forbidden'); }

const EDU_OTHER = '__other__';   // 主催「その他（自由入力）」の選択値

/** 空のフォーム値 */
function edu_form_defaults(): array
{
    return [
        'format' => 'venue',
        'title' => '', 'category' => '', 'organizer' => '', 'organizer_other' => '',
        'targets' => [], 'is_mandatory' => '0',
        'date' => '', 'start_time' => '', 'end_time' => '',
        'online_from' => '', 'online_to' => '',
        'place' => '', 'capacity' => '', 'body' => '',
        'contact_name' => '', 'contact_dept' => '', 'contact_ext' => '',
    ];
}

/** 主催名 => 区分 の対応表 */
function edu_organizer_types(array $config): array
{
    $map = [];
    foreach ($config['organizers'] as $type => $names) {
        foreach ($names as $name) { $map[$name] = $type; }
    }
    return $map;
}

/** 主催区分の選択肢（絞り込み用） */
function edu_organizer_type_options(array $config): array
{
    return array_merge(array_keys($config['organizers']), ['その他']);
}

/**
 * 編集時、予定に保存済みの種別・対象が選択肢から外れて（使用停止されて）いても
 * そのまま選べるように選択肢へ加える。
 */
function edu_config_with_event(array $config, array $e): array
{
    if (!in_array($e['category'], $config['categories'], true)) {
        $config['categories'][] = $e['category'];
    }
    foreach ($e['targets'] !== '' ? explode(',', $e['targets']) : [] as $t) {
        if (!in_array($t, $config['targets'], true)) { $config['targets'][] = $t; }
    }
    return $config;
}

/** DBの予定行をフォーム値に変換する（編集画面の初期値） */
function edu_form_from_event(array $config, array $e): array
{
    $known = edu_organizer_types($config);
    $isKnown = isset($known[$e['organizer']]);
    $online  = edu_is_online($e);
    $from    = date('Y-m-d', strtotime($e['start_at']));
    return [
        'format'          => $online ? 'online' : 'venue',
        'title'           => $e['title'],
        'category'        => $e['category'],
        'organizer'       => $isKnown ? $e['organizer'] : EDU_OTHER,
        'organizer_other' => $isKnown ? '' : $e['organizer'],
        'targets'         => $e['targets'] !== '' ? explode(',', $e['targets']) : [],
        'is_mandatory'    => (string)(int)$e['is_mandatory'],
        'date'            => $online ? '' : $from,
        'start_time'      => $online ? '' : date('H:i', strtotime($e['start_at'])),
        'end_time'        => !$online && $e['end_at'] ? date('H:i', strtotime($e['end_at'])) : '',
        'online_from'     => $online ? $from : '',
        'online_to'       => $online && $e['end_at'] ? date('Y-m-d', strtotime($e['end_at'])) : '',
        'place'           => $e['place'],
        'capacity'        => $e['capacity'] !== null ? (string)$e['capacity'] : '',
        'body'            => $e['body'],
        'contact_name'    => $e['contact_name'],
        'contact_dept'    => $e['contact_dept'],
        'contact_ext'     => $e['contact_ext'],
    ];
}

/**
 * POST からフォーム値を読み取り検証する。
 * @return array{0: array, 1: array, 2: array} [フォーム値, DB保存用データ, エラー一覧]
 */
function edu_form_read(array $config, array $post): array
{
    $old = edu_form_defaults();
    foreach ($old as $key => $default) {
        if ($key === 'targets') {
            $old[$key] = array_values(array_filter(
                (array)($post['targets'] ?? []),
                fn($t) => in_array($t, $config['targets'], true)
            ));
        } else {
            $old[$key] = trim((string)($post[$key] ?? ''));
        }
    }
    $old['is_mandatory'] = $old['is_mandatory'] === '1' ? '1' : '0';
    $old['format'] = $old['format'] === 'online' ? 'online' : 'venue';
    $online = $old['format'] === 'online';

    $errors = [];
    $len = function (string $key, string $label, int $max, bool $required) use (&$old, &$errors) {
        if ($required && $old[$key] === '') {
            $errors[] = "{$label}を入力してください。";
        } elseif (mb_strlen($old[$key]) > $max) {
            $errors[] = "{$label}は{$max}文字以内で入力してください。";
        }
    };

    $len('title', '研修名', 200, true);
    if (!in_array($old['category'], $config['categories'], true)) {
        $errors[] = '種別を選択してください。';
    }

    $types = edu_organizer_types($config);
    $organizerType = '';
    $organizer = '';
    if ($old['organizer'] === EDU_OTHER) {
        $len('organizer_other', '主催（その他）', 100, true);
        $organizerType = 'その他';
        $organizer = $old['organizer_other'];
    } elseif (isset($types[$old['organizer']])) {
        $organizerType = $types[$old['organizer']];
        $organizer = $old['organizer'];
    } else {
        $errors[] = '主催を選択してください。';
    }

    if (!$old['targets']) {
        $errors[] = '対象を1つ以上選んでください。';
    }

    $parseDate = function (string $v) {
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        return ($d && $d->format('Y-m-d') === $v) ? $d : null;
    };
    $startAt = $endAt = null;
    if ($online) {
        // ---- オンライン視聴：視聴期間（開始日〜終了日） ----
        $from = $parseDate($old['online_from']);
        $to   = $parseDate($old['online_to']);
        if (!$from) { $errors[] = '視聴開始日を正しく入力してください。'; }
        if (!$to)   { $errors[] = '視聴終了日（締切）を正しく入力してください。'; }
        if ($from && $to) {
            if ($to < $from) {
                $errors[] = '視聴終了日は開始日以降にしてください。';
            } elseif ($from->diff($to)->days > 366) {
                $errors[] = '視聴期間は1年以内にしてください。';
            } else {
                $startAt = $from->format('Y-m-d') . ' 00:00:00';
                $endAt   = $to->format('Y-m-d') . ' 23:59:59';
            }
        }
    } else {
        // ---- 会場で開催：開催日＋時刻 ----
        $date = $parseDate($old['date']);
        if (!$date) {
            $errors[] = '開催日を正しく入力してください。';
        }
        $timeOk = fn(string $t) => (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
        if (!$timeOk($old['start_time'])) {
            $errors[] = '開始時刻を正しく入力してください（例 17:30）。';
        }
        if ($old['end_time'] !== '') {
            if (!$timeOk($old['end_time'])) {
                $errors[] = '終了時刻を正しく入力してください（例 18:30）。';
            } elseif ($timeOk($old['start_time']) && $old['end_time'] <= $old['start_time']) {
                $errors[] = '終了時刻は開始時刻より後にしてください。';
            }
        }
        if ($date && $timeOk($old['start_time'])) {
            $d = $date->format('Y-m-d');
            $startAt = "$d {$old['start_time']}:00";
            $endAt   = $old['end_time'] !== '' && $timeOk($old['end_time']) ? "$d {$old['end_time']}:00" : null;
        }
    }

    if ($online && $old['place'] === '') {
        $old['place'] = 'オンライン';
    }
    $len('place', $online ? '視聴方法・URL' : '会場', 100, true);
    if ($old['capacity'] !== '' && (!ctype_digit($old['capacity']) || (int)$old['capacity'] > 100000)) {
        $errors[] = '定員は数字で入力してください（未定なら空欄）。';
    }
    $len('body', '内容', 5000, false);
    $len('contact_name', '担当者氏名', 64, true);
    $len('contact_dept', '担当者の所属', 64, true);
    $len('contact_ext', '連絡先', 64, false);

    $data = [];
    if (!$errors) {
        $data = [
            'format'         => $old['format'],
            'title'          => $old['title'],
            'category'       => $old['category'],
            'organizer_type' => $organizerType,
            'organizer'      => $organizer,
            'targets'        => implode(',', $old['targets']),
            'is_mandatory'   => (int)$old['is_mandatory'],
            'start_at'       => $startAt,
            'end_at'         => $endAt,
            'place'          => $old['place'],
            'capacity'       => $old['capacity'] !== '' ? (int)$old['capacity'] : null,
            'body'           => $old['body'],
            'contact_name'   => $old['contact_name'],
            'contact_dept'   => $old['contact_dept'],
            'contact_ext'    => $old['contact_ext'],
        ];
    }
    return [$old, $data, $errors];
}

/** フォーム項目の描画（<form> タグと送信ボタンは呼び出し側） */
function edu_render_form_fields(array $config, array $old): void
{
    ?>
    <fieldset>
      <legend>研修の内容</legend>
      <div class="form-row">
        <label>研修名<span class="required">必須</span></label>
        <input type="text" name="title" value="<?= h($old['title']) ?>" maxlength="200" placeholder="例: 医療安全研修（第1回）転倒・転落防止">
      </div>
      <div class="form-grid">
        <div class="form-row">
          <label>種別<span class="required">必須</span></label>
          <select name="category">
            <option value="">選択してください</option>
            <?php foreach ($config['categories'] as $c): ?>
              <option value="<?= h($c) ?>" <?= $old['category'] === $c ? 'selected' : '' ?>><?= h($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <label>主催<span class="required">必須</span></label>
          <select name="organizer">
            <option value="">選択してください</option>
            <?php foreach ($config['organizers'] as $type => $names): ?>
              <optgroup label="<?= h($type) ?>">
                <?php foreach ($names as $n): ?>
                  <option value="<?= h($n) ?>" <?= $old['organizer'] === $n ? 'selected' : '' ?>><?= h($n) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
            <option value="<?= EDU_OTHER ?>" <?= $old['organizer'] === EDU_OTHER ? 'selected' : '' ?>>その他（下に入力）</option>
          </select>
          <input type="text" name="organizer_other" value="<?= h($old['organizer_other']) ?>" maxlength="100"
                 placeholder="一覧に無い場合のみ入力（例: ○○ワーキンググループ）" class="mt">
        </div>
      </div>
      <div class="form-row">
        <label>対象<span class="required">必須</span><span class="hint">複数選択可</span></label>
        <div class="checks">
          <?php foreach ($config['targets'] as $t): ?>
            <label class="check"><input type="checkbox" name="targets[]" value="<?= h($t) ?>"
              <?= in_array($t, $old['targets'], true) ? 'checked' : '' ?>> <?= h($t) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-row">
        <label class="check"><input type="checkbox" name="is_mandatory" value="1" <?= $old['is_mandatory'] === '1' ? 'checked' : '' ?>>
          必須研修（全職員の受講が義務付けられているもの。一覧に「必須」と表示）</label>
      </div>
    </fieldset>

    <fieldset>
      <legend>開催形式・日時・会場</legend>
      <div class="form-row">
        <label>開催形式<span class="required">必須</span></label>
        <div class="checks format-choice">
          <label class="check"><input type="radio" name="format" value="venue" <?= $old['format'] !== 'online' ? 'checked' : '' ?>>
            会場で開催（日時を決めて集合）</label>
          <label class="check"><input type="radio" name="format" value="online" <?= $old['format'] === 'online' ? 'checked' : '' ?>>
            オンライン視聴（視聴期間を指定）</label>
        </div>
      </div>

      <div class="format-block" data-format="venue">
        <div class="block-title">会場で開催する場合</div>
        <div class="form-grid">
          <div class="form-row">
            <label>開催日<span class="required">必須</span></label>
            <input type="date" name="date" value="<?= h($old['date']) ?>">
          </div>
          <div class="form-row">
            <label>開始時刻<span class="required">必須</span></label>
            <input type="time" name="start_time" value="<?= h($old['start_time']) ?>" step="300">
          </div>
          <div class="form-row">
            <label>終了時刻</label>
            <input type="time" name="end_time" value="<?= h($old['end_time']) ?>" step="300">
          </div>
        </div>
        <div class="note">複数日にわたる研修は、日ごとに登録してください（研修名に「1日目」「2日目」など）。</div>
      </div>

      <div class="format-block" data-format="online">
        <div class="block-title">オンライン視聴の場合</div>
        <div class="form-grid">
          <div class="form-row">
            <label>視聴開始日<span class="required">必須</span></label>
            <input type="date" name="online_from" value="<?= h($old['online_from']) ?>">
          </div>
          <div class="form-row">
            <label>視聴終了日（締切）<span class="required">必須</span></label>
            <input type="date" name="online_to" value="<?= h($old['online_to']) ?>">
          </div>
        </div>
        <div class="note">期間内に視聴できなかった場合の扱い（レポート提出など）は、下の「内容・参加方法など」に記入してください。</div>
      </div>

      <div class="form-grid">
        <div class="form-row">
          <label>会場（オンラインは視聴方法）<span class="required">必須</span></label>
          <input type="text" name="place" value="<?= h($old['place']) ?>" maxlength="100" list="places">
          <datalist id="places">
            <?php foreach ($config['places'] as $p): ?><option value="<?= h($p) ?>"><?php endforeach; ?>
          </datalist>
        </div>
        <div class="form-row">
          <label>定員</label>
          <input type="text" name="capacity" value="<?= h($old['capacity']) ?>" inputmode="numeric" placeholder="空欄＝制限なし">
        </div>
      </div>
      <div class="form-row">
        <label>内容・参加方法など</label>
        <textarea name="body" maxlength="5000" placeholder="講師、内容、持ち物、申込方法（例: 各部署でとりまとめて○日までに教育委員へ）&#10;オンラインの場合: 視聴方法、期間内に視聴できなかった場合のレポート提出先・期限 など"><?= h($old['body']) ?></textarea>
      </div>
    </fieldset>
    <script>
    // 選んだ開催形式の入力欄だけを表示する（JavaScript が無効でも両方表示され、選んだ形式の欄だけ検証される）
    (function () {
      var radios = document.querySelectorAll('input[name="format"]');
      function apply() {
        var v = document.querySelector('input[name="format"]:checked').value;
        document.querySelectorAll('.format-block').forEach(function (b) {
          b.style.display = b.getAttribute('data-format') === v ? '' : 'none';
        });
        var place = document.querySelector('input[name="place"]');
        if (v === 'online' && place && place.value === '') { place.value = 'オンライン（院内配信）'; }
      }
      radios.forEach(function (r) { r.addEventListener('change', apply); });
      apply();
    })();
    </script>

    <fieldset>
      <legend>担当者（問い合わせ先）</legend>
      <div class="form-grid">
        <div class="form-row">
          <label>氏名<span class="required">必須</span></label>
          <input type="text" name="contact_name" value="<?= h($old['contact_name']) ?>" maxlength="64">
        </div>
        <div class="form-row">
          <label>所属<span class="required">必須</span></label>
          <input type="text" name="contact_dept" value="<?= h($old['contact_dept']) ?>" maxlength="64" placeholder="例: 3階病棟">
        </div>
        <div class="form-row">
          <label>内線・PHS</label>
          <input type="text" name="contact_ext" value="<?= h($old['contact_ext']) ?>" maxlength="64" placeholder="例: 内線1234">
        </div>
      </div>
    </fieldset>
<?php
}
