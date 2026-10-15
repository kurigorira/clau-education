<?php
/**
 * 研修・勉強会の予定一覧（イントラネット公開・ログイン不要）。
 * 既定は「今後の予定」。種別・主催区分・対象職種・月・キーワードで絞り込みできる。
 * 掲載されるのは承認済み（公開中）と中止の予定のみ。
 */
define('EDU_APP', true);
$config = require __DIR__ . '/config.php';
require __DIR__ . '/common.php';
require __DIR__ . '/store.php';
require __DIR__ . '/form.php';

$periods = ['upcoming' => '今後の予定', 'past' => '終了した研修', 'all' => 'すべて'];
$typeOptions = edu_organizer_type_options($config);

// フィルタ（不正な値は無視して「すべて」扱い）
$filters = [
    'period'         => (string)($_GET['period'] ?? 'upcoming'),
    'month'          => (string)($_GET['month'] ?? ''),
    'category'       => (string)($_GET['category'] ?? ''),
    'organizer_type' => (string)($_GET['organizer_type'] ?? ''),
    'target'         => (string)($_GET['target'] ?? ''),
    'q'              => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 50),
];
if (!isset($periods[$filters['period']]))                                 { $filters['period'] = 'upcoming'; }
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $filters['month']))          { $filters['month'] = ''; }
if (!in_array($filters['category'], $config['categories'], true))         { $filters['category'] = ''; }
if (!in_array($filters['organizer_type'], $typeOptions, true))            { $filters['organizer_type'] = ''; }
if (!in_array($filters['target'], $config['targets'], true))              { $filters['target'] = ''; }

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, (int)$config['per_page']);

$fatal = null;
$rows = [];
$total = 0;
$counts = null;
try {
    $pdo    = edu_mysql($config);
    $counts = edu_summary_counts($pdo);
    $result = edu_search($pdo, $filters, $page, $perPage);
    $rows   = $result['rows'];
    $total  = $result['total'];
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}

$lastPage = max(1, (int)ceil($total / $perPage));

/** フィルタを維持したままページ番号だけ変えたURLを作る */
$pageUrl = function (int $p) use ($filters): string {
    $q = array_filter($filters, fn($v) => $v !== '');
    $q['page'] = $p;
    return 'index.php?' . http_build_query($q);
};

$today = date('Y-m-d');

edu_layout_header($config, '予定一覧', 'list');
?>

<?php if ($fatal !== null): ?>
  <?php edu_db_error($fatal); ?>
<?php else: ?>

  <div class="summary">
    <div class="card"><div class="label">今月の研修</div><div class="value"><?= $counts['this_month'] ?><small>件</small></div></div>
    <div class="card"><div class="label">今後30日</div><div class="value"><?= $counts['next30'] ?><small>件</small></div></div>
    <div class="card"><div class="label"><?= $counts['fy'] ?>年度 合計</div><div class="value"><?= $counts['fiscal_year'] ?><small>件</small></div></div>
    <?php if (edu_is_admin() && $counts['pending'] > 0): ?>
      <a class="card alert" href="admin.php"><div class="label">承認待ち</div><div class="value"><?= $counts['pending'] ?><small>件</small></div></a>
    <?php endif; ?>
    <a class="card cta" href="new.php"><div class="label">担当者の方へ</div><div class="value-sm">＋ 予定を登録する</div></a>
  </div>

  <form method="get" action="index.php" class="filter-form">
    <div class="form-row">
      <label>期間</label>
      <select name="period">
        <?php foreach ($periods as $code => $label): ?>
          <option value="<?= h($code) ?>" <?= $filters['period'] === $code ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label>月を指定</label>
      <input type="month" name="month" value="<?= h($filters['month']) ?>">
    </div>
    <div class="form-row">
      <label>種別</label>
      <select name="category">
        <option value="">すべて</option>
        <?php foreach ($config['categories'] as $c): ?>
          <option value="<?= h($c) ?>" <?= $filters['category'] === $c ? 'selected' : '' ?>><?= h($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label>主催</label>
      <select name="organizer_type">
        <option value="">すべて</option>
        <?php foreach ($typeOptions as $t): ?>
          <option value="<?= h($t) ?>" <?= $filters['organizer_type'] === $t ? 'selected' : '' ?>><?= h($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label>対象職種</label>
      <select name="target">
        <option value="">すべて</option>
        <?php foreach ($config['targets'] as $t): ?>
          <option value="<?= h($t) ?>" <?= $filters['target'] === $t ? 'selected' : '' ?>><?= h($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label>キーワード</label>
      <input type="text" name="q" value="<?= h($filters['q']) ?>" placeholder="研修名・主催・会場">
    </div>
    <button type="submit">絞り込み</button>
    <a href="index.php" class="btn secondary">解除</a>
  </form>

  <?php if (!$rows): ?>
    <div class="empty">
      該当する研修・勉強会はありません。<br>
      担当者の方は <a href="new.php">予定を登録</a> から登録できます。
    </div>
  <?php else: ?>
    <div class="result-count"><?= $total ?>件<?= $filters['month'] !== '' ? '（' . h(date('Y年n月', strtotime($filters['month'] . '-01'))) . '）' : '' ?></div>
    <table class="data events">
      <thead>
        <tr><th>日時</th><th>研修名</th><th>種別</th><th>主催</th><th>対象</th><th>会場</th></tr>
      </thead>
      <tbody>
        <?php $prevMonth = null; ?>
        <?php foreach ($rows as $r): ?>
          <?php
            $ym = date('Y年n月', strtotime($r['start_at']));
            $isCancelled = $r['status'] === 'cancelled';
            $isToday = date('Y-m-d', strtotime($r['start_at'])) === $today;
          ?>
          <?php if ($ym !== $prevMonth): $prevMonth = $ym; ?>
            <tr class="month-row"><td colspan="6"><?= h($ym) ?></td></tr>
          <?php endif; ?>
          <tr class="<?= $isCancelled ? 'cancelled' : '' ?> <?= $isToday ? 'today' : '' ?>">
            <td class="nowrap" data-label="日時">
              <strong><?= h(edu_date_label($r['start_at'])) ?></strong><br>
              <span class="muted"><?= h(edu_time_label($r['start_at'], $r['end_at'])) ?></span>
            </td>
            <td data-label="研修名">
              <?php if ($isToday): ?><span class="badge badge-today">本日</span><?php endif; ?>
              <?php if ($isCancelled): ?><span class="badge badge-muted">中止</span><?php endif; ?>
              <?php if ((int)$r['is_mandatory'] === 1): ?><span class="badge badge-must">必須</span><?php endif; ?>
              <?php if ((int)$r['changed_after_approval'] === 1): ?><span class="badge badge-progress">変更あり</span><?php endif; ?>
              <a href="view.php?id=<?= (int)$r['id'] ?>" class="title"><?= h($r['title']) ?></a>
            </td>
            <td class="nowrap" data-label="種別"><span class="badge cat <?= h(edu_category_class($config, $r['category'])) ?>"><?= h($r['category']) ?></span></td>
            <td data-label="主催"><?= h($r['organizer']) ?></td>
            <td data-label="対象" class="small"><?= h(str_replace(',', '、', $r['targets'])) ?></td>
            <td data-label="会場"><?= h($r['place']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($lastPage > 1): ?>
      <nav class="pager">
        <?php for ($p = 1; $p <= $lastPage; $p++): ?>
          <?php if ($p === $page): ?>
            <span class="current"><?= $p ?></span>
          <?php else: ?>
            <a href="<?= h($pageUrl($p)) ?>"><?= $p ?></a>
          <?php endif; ?>
        <?php endfor; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>

<?php endif; ?>

<?php edu_layout_footer($config); ?>
