<?php
/**
 * 履歴（全職員）。連絡がついた・取り下げ・期限切れを含むすべての掲示を検索する。
 * 保存期間（config の retention_days）を過ぎた掲示は自動で削除されるため表示されない。
 */
define('CB_APP', true);
$config = require __DIR__ . '/config.php';
require __DIR__ . '/common.php';
require __DIR__ . '/store.php';

$filters = [
    'from'  => (string)($_GET['from'] ?? ''),
    'to'    => (string)($_GET['to'] ?? ''),
    'state' => (string)($_GET['state'] ?? ''),
    'q'     => trim((string)($_GET['q'] ?? '')),
];
foreach (['from', 'to'] as $k) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$k])) { $filters[$k] = ''; }
}
if (!isset(cb_state_map()[$filters['state']])) { $filters['state'] = ''; }
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)$config['per_page'];

$fatal = null;
$result = ['rows' => [], 'total' => 0];
try {
    $pdo = cb_mysql($config);
    cb_purge_old($pdo, (int)$config['retention_days']);
    $result = cb_history($pdo, $filters, $page, $perPage);
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}

cb_layout_header($config, '履歴', 'history');
if ($fatal !== null) {
    cb_db_error($fatal);
    cb_layout_footer($config);
    exit;
}
$pages = max(1, (int)ceil($result['total'] / $perPage));
?>
<p class="lead">過去の掲示を探せます（直近<?= (int)$config['retention_days'] ?>日分）。期限切れ・取り下げた掲示も、詳細画面から「もう一度掲示する」ことができます。</p>

<form class="filter-form" method="get" action="history.php">
  <div class="form-row"><label for="from">電話した日（から）</label><input type="date" id="from" name="from" value="<?= h($filters['from']) ?>"></div>
  <div class="form-row"><label for="to">（まで）</label><input type="date" id="to" name="to" value="<?= h($filters['to']) ?>"></div>
  <div class="form-row"><label for="state">状態</label>
    <select id="state" name="state">
      <option value="">すべて</option>
      <?php foreach (cb_state_map() as $code => $label): ?>
        <option value="<?= h($code) ?>" <?= $filters['state'] === $code ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-row"><label for="q">キーワード</label><input type="text" id="q" name="q" value="<?= h($filters['q']) ?>" placeholder="名前・ID番号・担当者"></div>
  <button type="submit">探す</button>
</form>

<?php if (!$result['rows']): ?>
  <div class="empty">該当する掲示はありません。</div>
<?php else: ?>
  <p class="muted small"><?= (int)$result['total'] ?> 件</p>
  <table class="data history">
    <thead><tr><th>電話した日時</th><th>状態</th><th>相手先</th><th>用件</th><th>担当者（内線）</th><th>終了</th></tr></thead>
    <tbody>
    <?php foreach ($result['rows'] as $p): $state = cb_state($p); ?>
      <tr>
        <td class="nowrap"><a href="view.php?id=<?= (int)$p['id'] ?>"><?= h(cb_datetime_label($p['called_at'])) ?></a></td>
        <td><span class="badge <?= h(cb_state_class($state)) ?>"><?= h(cb_state_map()[$state]) ?></span></td>
        <td data-label="相手先"><?= h(cb_party_label($p)) ?> <?= h(cb_id_label($p)) ?></td>
        <td data-label="用件"><?= h($p['purpose']) ?></td>
        <td data-label="担当者"><?= h($p['caller_name']) ?>（<?= h($p['caller_dept']) ?> <?= h(cb_ext_label($p)) ?>）</td>
        <td data-label="終了" class="small"><?= $p['closed_at'] ? h(cb_datetime_label($p['closed_at']) . ' ' . $p['closed_note']) : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($pages > 1): ?>
    <div class="pager">
      <?php for ($i = 1; $i <= $pages; $i++):
          $qs = http_build_query(array_filter($filters, fn($v) => $v !== '') + ['page' => $i]); ?>
        <?php if ($i === $page): ?><span class="current"><?= $i ?></span>
        <?php else: ?><a href="history.php?<?= h($qs) ?>"><?= $i ?></a><?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php cb_layout_footer($config); ?>
