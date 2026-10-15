<?php
/**
 * 月間カレンダー（イントラネット公開・ログイン不要）。
 * サーバ側でHTMLの表として描画する（JavaScript・外部ライブラリ不要。閉域網でもそのまま動く）。
 */
define('EDU_APP', true);
$config = require __DIR__ . '/config.php';
require __DIR__ . '/common.php';
require __DIR__ . '/store.php';
require __DIR__ . '/form.php';

$typeOptions = edu_organizer_type_options($config);

$ym = (string)($_GET['ym'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) { $ym = date('Y-m'); }
$filters = [
    'category'       => (string)($_GET['category'] ?? ''),
    'organizer_type' => (string)($_GET['organizer_type'] ?? ''),
];
if (!in_array($filters['category'], $config['categories'], true)) { $filters['category'] = ''; }
if (!in_array($filters['organizer_type'], $typeOptions, true))    { $filters['organizer_type'] = ''; }

$first     = strtotime($ym . '-01');
$daysInMon = (int)date('t', $first);
$lead      = (int)date('w', $first);                 // 月初の曜日（0=日）
$gridStart = strtotime("-{$lead} day", $first);
$weeks     = (int)ceil(($lead + $daysInMon) / 7);
$gridEnd   = strtotime('+' . ($weeks * 7) . ' day', $gridStart);

$fatal = null;
$byDate = [];
try {
    $pdo = edu_mysql($config);
    $events = edu_events_between($pdo, date('Y-m-d', $gridStart), date('Y-m-d', $gridEnd), $filters);
    foreach ($events as $e) {
        $byDate[date('Y-m-d', strtotime($e['start_at']))][] = $e;
    }
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}

$monthUrl = function (string $target) use ($filters): string {
    $q = array_filter($filters, fn($v) => $v !== '');
    $q['ym'] = $target;
    return 'calendar.php?' . http_build_query($q);
};
$prev  = date('Y-m', strtotime('-1 month', $first));
$next  = date('Y-m', strtotime('+1 month', $first));
$today = date('Y-m-d');
$monthCount = 0;
foreach ($byDate as $d => $list) {
    if (substr($d, 0, 7) === $ym) { $monthCount += count($list); }
}

edu_layout_header($config, date('Y年n月', $first) . 'のカレンダー', 'calendar');
?>

<?php if ($fatal !== null): ?>
  <?php edu_db_error($fatal); ?>
<?php else: ?>

  <div class="cal-head">
    <a class="btn secondary" href="<?= h($monthUrl($prev)) ?>">&lt; 前月</a>
    <h2><?= h(date('Y年n月', $first)) ?> <small><?= $monthCount ?>件</small></h2>
    <a class="btn secondary" href="<?= h($monthUrl($next)) ?>">翌月 &gt;</a>
    <?php if ($ym !== date('Y-m')): ?><a class="btn secondary" href="<?= h($monthUrl(date('Y-m'))) ?>">今月</a><?php endif; ?>

    <form method="get" action="calendar.php" class="filter-form inline">
      <input type="hidden" name="ym" value="<?= h($ym) ?>">
      <select name="category" aria-label="種別">
        <option value="">種別：すべて</option>
        <?php foreach ($config['categories'] as $c): ?>
          <option value="<?= h($c) ?>" <?= $filters['category'] === $c ? 'selected' : '' ?>><?= h($c) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="organizer_type" aria-label="主催">
        <option value="">主催：すべて</option>
        <?php foreach ($typeOptions as $t): ?>
          <option value="<?= h($t) ?>" <?= $filters['organizer_type'] === $t ? 'selected' : '' ?>><?= h($t) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit">絞り込み</button>
    </form>
  </div>

  <div class="legend">
    <?php foreach ($config['categories'] as $c): ?>
      <span class="badge cat <?= h(edu_category_class($config, $c)) ?>"><?= h($c) ?></span>
    <?php endforeach; ?>
    <span class="badge badge-must">必須</span>
    <span class="muted">取り消し線＝中止</span>
  </div>

  <table class="calendar">
    <thead>
      <tr>
        <?php foreach (['日', '月', '火', '水', '木', '金', '土'] as $i => $w): ?>
          <th class="<?= $i === 0 ? 'sun' : ($i === 6 ? 'sat' : '') ?>"><?= $w ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php for ($w = 0; $w < $weeks; $w++): ?>
        <tr>
          <?php for ($d = 0; $d < 7; $d++): ?>
            <?php
              $ts   = strtotime('+' . ($w * 7 + $d) . ' day', $gridStart);
              $date = date('Y-m-d', $ts);
              $cls  = [];
              if (date('Y-m', $ts) !== $ym) { $cls[] = 'other'; }
              if ($date === $today)        { $cls[] = 'today'; }
              if ($d === 0)                { $cls[] = 'sun'; }
              if ($d === 6)                { $cls[] = 'sat'; }
              $list = $byDate[$date] ?? [];
            ?>
            <td class="<?= implode(' ', $cls) ?>">
              <div class="day"><?= (int)date('j', $ts) ?><span class="wd">(<?= edu_wday($ts) ?>)</span></div>
              <?php foreach ($list as $e): ?>
                <a href="view.php?id=<?= (int)$e['id'] ?>"
                   class="ev <?= h(edu_category_class($config, $e['category'])) ?> <?= $e['status'] === 'cancelled' ? 'cancelled' : '' ?>"
                   title="<?= h($e['title'] . '／' . $e['place'] . '／' . $e['organizer']) ?>">
                  <span class="t"><?= h(date('G:i', strtotime($e['start_at']))) ?></span>
                  <?php if ((int)$e['is_mandatory'] === 1): ?><span class="must">必須</span><?php endif; ?>
                  <?= h($e['title']) ?>
                </a>
              <?php endforeach; ?>
            </td>
          <?php endfor; ?>
        </tr>
      <?php endfor; ?>
    </tbody>
  </table>

<?php endif; ?>

<?php edu_layout_footer($config); ?>
