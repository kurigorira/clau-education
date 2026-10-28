<?php
/**
 * 折り返し待ち一覧（全職員）。
 * 外線をかけて相手に繋がらなかった職員の掲示を、新しい順にカードで表示する。
 * 折り返しの電話を受けた職員は、相手の名前・ID番号で探し、担当者の内線へ転送する。
 * 一定間隔で自動的に再読み込みする（JavaScript 不使用）。
 */
define('CB_APP', true);
$config = require __DIR__ . '/config.php';
require __DIR__ . '/common.php';
require __DIR__ . '/store.php';

$q = trim((string)($_GET['q'] ?? ''));
$fatal = null;
$posts = [];
$receipts = [];
$counts = ['open' => 0, 'done_today' => 0];
try {
    $pdo = cb_mysql($config);
    cb_purge_old($pdo, (int)$config['retention_days']);
    $posts    = cb_open_posts($pdo, $q);
    $receipts = cb_receipts_for($pdo, array_map(fn($p) => (int)$p['id'], $posts));
    $counts   = cb_summary_counts($pdo);
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}

cb_layout_header($config, '折り返し待ち一覧', 'list', (int)$config['refresh_seconds'], 'receive');
if ($fatal !== null) {
    cb_db_error($fatal);
    cb_layout_footer($config);
    exit;
}
?>
<div class="summary">
  <div class="card open"><div class="label">折り返し待ち</div><div class="value"><?= $counts['open'] ?><small>件</small></div></div>
  <div class="card done"><div class="label">今日 連絡がついた</div><div class="value"><?= $counts['done_today'] ?><small>件</small></div></div>
  <a class="card cta" href="new.php"><div class="label">外線が繋がらなかったら</div><div class="value-sm">＋ 掲示する</div></a>
</div>

<div class="howto">
  <strong>折り返しの電話を受けたら：</strong>
  相手のお名前・<strong>ID番号</strong>で下の一覧から探し、<strong>担当者の内線へ転送</strong>してください。
  担当者に繋がらない場合は「代わりの連絡先」へ。転送したら、カードを開いて「折り返しを受けた記録」を残してください。
</div>

<form class="filter-form" method="get" action="index.php">
  <div class="form-row">
    <label for="q">相手の名前・ID番号・担当者・部署で探す</label>
    <input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="例：山田　0012345" inputmode="search">
  </div>
  <button type="submit">探す</button>
  <?php if ($q !== ''): ?><a class="btn secondary" href="index.php">すべて表示</a><?php endif; ?>
</form>

<?php if (!$posts): ?>
  <div class="empty">
    <?= $q !== '' ? '条件に合う折り返し待ちの掲示はありません。<a href="history.php?q=' . h(urlencode($q)) . '">履歴で探す</a>' : '現在、折り返し待ちの掲示はありません。' ?>
  </div>
<?php else: ?>
  <div class="posts">
  <?php foreach ($posts as $p):
      $rc = $receipts[(int)$p['id']] ?? [];
  ?>
    <article class="post">
      <div class="post-party">
        <span class="badge badge-party"><?= h($p['party_type']) ?></span>
        <span class="party-name"><?= h($p['party_name'] !== '' ? $p['party_name'] : '（お名前なし）') ?></span>
        <?php if ($p['patient_id'] !== ''): ?>
          <span class="phone"><?= h(cb_id_label($p)) ?></span>
        <?php endif; ?>
      </div>
      <?php if ($p['purpose'] !== ''): ?><div class="post-purpose"><?= h($p['purpose']) ?></div><?php endif; ?>
      <div class="post-to">
        <span class="arrow">転送先</span>
        <span class="ext"><?= h(cb_ext_label($p)) ?></span>
        <span class="who"><strong class="who-name"><?= h($p['caller_name']) ?></strong>（<?= h($p['caller_dept']) ?>）</span>
      </div>
      <?php if ($p['alt_contact'] !== ''): ?>
        <div class="post-alt">不在のとき：<?= h($p['alt_contact']) ?></div>
      <?php endif; ?>
      <?php if ($rc): ?>
        <div class="post-received">
          折り返しあり <?= count($rc) ?>回（最新 <?= h(cb_when_label($rc[0]['created_at'])) ?> <?= h($rc[0]['actor']) ?>）
        </div>
      <?php endif; ?>
      <div class="post-foot">
        <span>架電 <?= h(cb_when_label($p['called_at'])) ?>（<?= h(cb_ago_label($p['called_at'])) ?>）</span>
        <span>掲示 <?= h(cb_left_label($p['expires_at'])) ?></span>
        <a href="view.php?id=<?= (int)$p['id'] ?>">詳細・受電記録・連絡がついた・編集 &raquo;</a>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<p class="muted small mt">
  <?php if ((int)$config['refresh_seconds'] > 0): ?>この画面は<?= (int)$config['refresh_seconds'] ?>秒ごとに自動で更新されます。<?php endif; ?>
  掲示は「連絡がついた」にするか、掲示期間を過ぎると一覧から消え、<a href="history.php">履歴</a>に残ります。
</p>
<?php cb_layout_footer($config); ?>
