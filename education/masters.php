<?php
/**
 * 選択肢の管理（管理者のみ）。
 * 主催（委員会・本部・部署）・会場・対象職種・種別を画面で追加・名前変更・並び替え・使用停止できる。
 *  - 使用停止にすると登録画面の選択肢から消えるだけで、登録済みの予定の表示は変わらない
 *  - 名前を変更しても、登録済みの予定の表記はそのまま（予定には登録時の名前を保存しているため）
 */
define('EDU_APP', true);
$config = require __DIR__ . '/config.php';
require __DIR__ . '/common.php';
require __DIR__ . '/store.php';

$kinds = edu_master_kinds();
$kind  = (string)($_GET['kind'] ?? 'organizer');
if (!isset($kinds[$kind])) { $kind = 'organizer'; }

$errors = [];
$fatal = null;
try {
    $pdo = edu_mysql($config);
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}
if ($fatal === null) {
    edu_require_admin();
}

/** 主催の区分（入力値の選択または新しい区分名） */
$readGroup = function () use ($kind): string {
    if ($kind !== 'organizer') { return ''; }
    $new = trim((string)($_POST['group_new'] ?? ''));
    return $new !== '' ? $new : trim((string)($_POST['group'] ?? ''));
};

if ($fatal === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    edu_csrf_check();
    $act = (string)($_POST['act'] ?? '');
    $who = edu_admin_name();
    try {
        if ($act === 'add') {
            $name  = trim((string)($_POST['name'] ?? ''));
            $group = $readGroup();
            if ($name === '' || mb_strlen($name) > 100) {
                $errors[] = '名前を100文字以内で入力してください。';
            } elseif ($kind === 'organizer' && ($group === '' || mb_strlen($group) > 32)) {
                $errors[] = '区分（委員会・本部・部署など）を選ぶか、32文字以内で入力してください。';
            } elseif (str_contains($name, ',')) {
                $errors[] = '名前に「,」（半角カンマ）は使えません。';
            } elseif (!edu_master_add($pdo, $kind, $group, $name)) {
                $errors[] = "「{$name}」はすでに登録されています（使用停止中の場合は「再開」してください）。";
            } else {
                edu_log($pdo, null, 'master_add', $who, $kinds[$kind] . ': ' . ($group !== '' ? "{$group}／" : '') . $name);
                edu_flash_set("「{$name}」を追加しました。");
            }
        } else {
            $m = edu_master_find($pdo, (int)($_POST['id'] ?? 0));
            if ($m === null || $m['kind'] !== $kind) {
                $errors[] = '対象の選択肢が見つかりません。';
            } elseif ($act === 'rename') {
                $name  = trim((string)($_POST['name'] ?? ''));
                $group = $kind === 'organizer' ? $readGroup() : '';
                if ($name === '' || mb_strlen($name) > 100 || str_contains($name, ',')) {
                    $errors[] = '名前を100文字以内（半角カンマなし）で入力してください。';
                } elseif ($kind === 'organizer' && ($group === '' || mb_strlen($group) > 32)) {
                    $errors[] = '区分を入力してください。';
                } elseif (!edu_master_rename($pdo, $m, $group, $name)) {
                    $errors[] = "「{$name}」はすでに登録されています。";
                } else {
                    edu_log($pdo, null, 'master_edit', $who, "{$kinds[$kind]}: {$m['name']} → {$name}");
                    edu_flash_set('変更しました。登録済みの予定の表記は変わりません。');
                }
            } elseif ($act === 'up' || $act === 'down') {
                edu_master_move($pdo, $m, $act === 'up' ? -1 : 1);
            } elseif ($act === 'disable' || $act === 'enable') {
                edu_master_set_active($pdo, (int)$m['id'], $act === 'enable');
                edu_log($pdo, null, 'master_' . $act, $who, "{$kinds[$kind]}: {$m['name']}");
                edu_flash_set($act === 'enable' ? "「{$m['name']}」を再開しました。" : "「{$m['name']}」を使用停止にしました（選択肢から外れます）。");
            }
        }
        if (!$errors) {
            header('Location: masters.php?kind=' . urlencode($kind) . '#list');
            exit;
        }
    } catch (Throwable $e) {
        $errors[] = '処理に失敗しました: ' . $e->getMessage();
    }
}

$rows = $fatal === null ? edu_master_rows($pdo, $kind) : [];
$groups = [];
foreach ($rows as $r) {
    if ($r['group_name'] !== '') { $groups[$r['group_name']] = true; }
}
$groups = array_keys($groups ?: ['委員会' => 1, '本部' => 1, '部署' => 1]);

/** 区分の選択欄（既存の区分＋新しい区分名） */
$groupFields = function (string $current) use ($groups): void { ?>
  <select name="group" aria-label="区分">
    <?php foreach ($groups as $g): ?>
      <option value="<?= h($g) ?>" <?= $current === $g ? 'selected' : '' ?>><?= h($g) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="text" name="group_new" maxlength="32" placeholder="新しい区分（任意）" size="10">
<?php };

/** POSTボタン1つの小さなフォーム */
$button = function (int $id, string $act, string $label, string $cls = 'small secondary') use ($kind): void { ?>
  <form method="post" action="masters.php?kind=<?= h($kind) ?>" class="inline">
    <?= edu_csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="act" value="<?= h($act) ?>">
    <button type="submit" class="<?= h($cls) ?>"><?= h($label) ?></button>
  </form>
<?php };

edu_layout_header($config, '選択肢の管理', 'masters');
?>

<?php if ($fatal !== null): ?>
  <?php edu_db_error($fatal); ?>
<?php else: ?>

  <nav class="tabs">
    <?php foreach ($kinds as $k => $label): ?>
      <a href="masters.php?kind=<?= h($k) ?>" class="<?= $k === $kind ? 'active' : '' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php edu_render_errors($errors); ?>

  <div class="panel">
    <h2><?= h($kinds[$kind]) ?>を追加</h2>
    <form method="post" action="masters.php?kind=<?= h($kind) ?>" class="filter-form">
      <?= edu_csrf_field() ?>
      <input type="hidden" name="act" value="add">
      <?php if ($kind === 'organizer'): ?>
        <div class="form-row">
          <label>区分</label>
          <?php $groupFields($groups[0] ?? ''); ?>
        </div>
      <?php endif; ?>
      <div class="form-row">
        <label>名前</label>
        <input type="text" name="name" maxlength="100" placeholder="<?= h([
            'organizer' => '例: 情報システム委員会', 'place' => '例: 6階会議室（約100名）',
            'target' => '例: 介護福祉士', 'category' => '例: e-ラーニング',
        ][$kind]) ?>">
      </div>
      <button type="submit">追加</button>
    </form>
    <p class="note">使用停止にすると登録画面の選択肢から消えます（登録済みの予定はそのまま表示されます）。
      名前を変更しても、登録済みの予定の表記は変わりません。</p>
  </div>

  <div class="panel" id="list">
    <h2>登録済み（<?= count($rows) ?>件）</h2>
    <table class="data compact masters">
      <thead><tr><?= $kind === 'organizer' ? '<th>区分</th>' : '' ?><th>名前</th><th>状態</th><th>並び順</th><th>操作</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr class="<?= (int)$r['active'] ? '' : 'inactive' ?>">
            <?php if ($kind === 'organizer'): ?><td class="nowrap"><?= h($r['group_name']) ?></td><?php endif; ?>
            <td><?= h($r['name']) ?></td>
            <td class="nowrap"><?= (int)$r['active'] ? '<span class="badge badge-done">使用中</span>' : '<span class="badge badge-muted">使用停止</span>' ?></td>
            <td class="nowrap">
              <?php $button((int)$r['id'], 'up', '↑'); ?>
              <?php $button((int)$r['id'], 'down', '↓'); ?>
            </td>
            <td class="admin-actions">
              <?php if ((int)$r['active']): ?>
                <?php $button((int)$r['id'], 'disable', '使用停止'); ?>
              <?php else: ?>
                <?php $button((int)$r['id'], 'enable', '再開', 'small'); ?>
              <?php endif; ?>
              <details class="inline">
                <summary>名前を変更</summary>
                <form method="post" action="masters.php?kind=<?= h($kind) ?>" class="inline">
                  <?= edu_csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="act" value="rename">
                  <?php if ($kind === 'organizer') { $groupFields($r['group_name']); } ?>
                  <input type="text" name="name" value="<?= h($r['name']) ?>" maxlength="100">
                  <button type="submit" class="small">保存</button>
                </form>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php endif; ?>

<?php edu_layout_footer($config); ?>
