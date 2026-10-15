<?php
/**
 * 予定の詳細（ログイン不要）。
 * 承認待ち・差戻しの予定も登録番号で開けば表示する（登録者が状況を確認できるように）。
 */
define('EDU_APP', true);
$config = require __DIR__ . '/config.php';
require __DIR__ . '/common.php';
require __DIR__ . '/store.php';

$statusMap = edu_status_map();
$id = (int)($_GET['id'] ?? 0);

$fatal = null;
$event = null;
$logs  = [];
try {
    $pdo   = edu_mysql($config);
    $event = $id > 0 ? edu_find($pdo, $id) : null;
    if ($event !== null) {
        $logs = edu_logs_for($pdo, $id);
    }
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}

$actionLabels = [
    'create' => '登録申請', 'edit' => '編集', 'approve' => '承認', 'ack' => '変更確認',
    'reject' => '差戻し', 'cancel' => '中止', 'restore' => '中止取消', 'reset_pass' => 'パスワード再設定',
];

edu_layout_header($config, $event ? $event['title'] : '予定の詳細', 'list');
?>

<?php if ($fatal !== null): ?>
  <?php edu_db_error($fatal); ?>
<?php elseif ($event === null): ?>
  <?php http_response_code(404); ?>
  <div class="empty">
    指定された予定は見つかりませんでした（削除された可能性があります）。<br>
    <a href="index.php">一覧へ戻る</a>
  </div>
<?php else: ?>

  <?php if ($event['status'] === 'pending'): ?>
    <div class="warn">この予定は<strong>承認待ち</strong>です。管理者が承認すると一覧・カレンダーに掲載されます。</div>
  <?php elseif ($event['status'] === 'rejected'): ?>
    <div class="error"><strong>差戻しされています。</strong>内容を修正して再申請してください（編集すると承認待ちに戻ります）。
      <?php if ((string)$event['reject_reason'] !== ''): ?><br>理由: <?= h($event['reject_reason']) ?><?php endif; ?>
    </div>
  <?php elseif ($event['status'] === 'cancelled'): ?>
    <div class="error"><strong>この研修は中止になりました。</strong>
      <?php if ((string)$event['reject_reason'] !== ''): ?><br>理由: <?= h($event['reject_reason']) ?><?php endif; ?>
    </div>
  <?php elseif ((int)$event['changed_after_approval'] === 1): ?>
    <div class="warn">承認後に内容が変更されています（下の変更履歴をご確認ください）。</div>
  <?php endif; ?>

  <div class="panel">
    <h2 class="<?= $event['status'] === 'cancelled' ? 'strike' : '' ?>">
      <?= h($event['title']) ?>
    </h2>
    <div class="tags">
      <span class="badge <?= h(edu_status_class($event['status'])) ?>"><?= h($statusMap[$event['status']] ?? $event['status']) ?></span>
      <span class="badge cat <?= h(edu_category_class($config, $event['category'])) ?>"><?= h($event['category']) ?></span>
      <?php if ((int)$event['is_mandatory'] === 1): ?><span class="badge badge-must">必須</span><?php endif; ?>
      <?php if ((int)$event['changed_after_approval'] === 1): ?><span class="badge badge-progress">変更あり</span><?php endif; ?>
    </div>
    <dl class="detail">
      <dt>日時</dt>
      <dd class="big"><?= h(edu_date_label($event['start_at'])) ?>　<?= h(edu_time_label($event['start_at'], $event['end_at'])) ?></dd>
      <dt>会場</dt><dd><?= h($event['place']) ?></dd>
      <dt>主催</dt><dd><?= h(edu_organizer_label($event)) ?></dd>
      <dt>対象</dt><dd><?= h(str_replace(',', '、', $event['targets'])) ?></dd>
      <dt>定員</dt><dd><?= $event['capacity'] !== null ? (int)$event['capacity'] . '名' : '制限なし' ?></dd>
      <dt>担当者</dt>
      <dd><?= h($event['contact_dept']) ?>　<?= h($event['contact_name']) ?>
        <?= $event['contact_ext'] !== '' ? '（' . h($event['contact_ext']) . '）' : '' ?></dd>
      <dt>登録番号</dt><dd>#<?= (int)$event['id'] ?>（登録 <?= h(edu_datetime_label($event['created_at'])) ?>）</dd>
    </dl>
  </div>

  <?php if ($event['body'] !== ''): ?>
  <div class="panel">
    <h2>内容・参加方法</h2>
    <div class="body-text"><?= h($event['body']) ?></div>
  </div>
  <?php endif; ?>

  <div class="actions">
    <a href="index.php" class="btn secondary">一覧へ戻る</a>
    <a href="calendar.php?ym=<?= h(date('Y-m', strtotime($event['start_at']))) ?>" class="btn secondary">カレンダーで見る</a>
    <a href="edit.php?id=<?= (int)$event['id'] ?>" class="btn">変更・中止（担当者用）</a>
    <?php if (in_array($event['status'], ['approved', 'cancelled'], true)): ?>
      <a href="new.php?copy=<?= (int)$event['id'] ?>" class="btn secondary">この内容で次回を登録</a>
    <?php endif; ?>
    <?php if (edu_is_admin()): ?>
      <a href="admin.php#e<?= (int)$event['id'] ?>" class="btn secondary">管理画面で開く</a>
    <?php endif; ?>
  </div>

  <?php if ($logs): ?>
  <div class="panel">
    <h2>変更履歴</h2>
    <table class="data compact">
      <thead><tr><th>日時</th><th>操作</th><th>操作者</th><th>内容</th></tr></thead>
      <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td class="nowrap"><?= h(edu_datetime_label($l['created_at'])) ?></td>
            <td class="nowrap"><?= h($actionLabels[$l['action']] ?? $l['action']) ?></td>
            <td class="nowrap"><?= h($l['actor']) ?></td>
            <td class="pre"><?= h((string)$l['detail']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

<?php endif; ?>

<?php edu_layout_footer($config); ?>
