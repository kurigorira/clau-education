<?php
/**
 * 研修スケジュールアプリ共通処理（セッション・エスケープ・CSRF・フラッシュ・レイアウト）。
 * 他アプリ（receipt/beds/inquiry/jobs）とはコード・設定とも共有しない。
 */
if (!defined('EDU_APP')) { http_response_code(403); exit('Forbidden'); }

session_name('EDUSESSID');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

/** HTMLエスケープ */
function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** ステータスコード => 表示名（DBには英字コードで保存する） */
function edu_status_map(): array
{
    return [
        'pending'   => '承認待ち',
        'approved'  => '公開中',
        'rejected'  => '差戻し',
        'cancelled' => '中止',
    ];
}

/** ステータスコード => バッジ用CSSクラス */
function edu_status_class(string $status): string
{
    return [
        'pending'   => 'badge-progress',
        'approved'  => 'badge-done',
        'rejected'  => 'badge-open',
        'cancelled' => 'badge-muted',
    ][$status] ?? 'badge-muted';
}

/** 種別 => カレンダー・バッジの色クラス（config の並び順で色を割り当てる） */
function edu_category_class(array $config, string $category): string
{
    $i = array_search($category, $config['categories'], true);
    return 'cat-' . ($i === false ? 'x' : ($i % 6));
}

/* ---------------- CSRF ---------------- */

/** フォームに埋め込むトークン（セッションごとに1つ） */
function edu_csrf_token(): string
{
    if (empty($_SESSION['edu_csrf'])) {
        $_SESSION['edu_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['edu_csrf'];
}

function edu_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(edu_csrf_token()) . '">';
}

/** POST のトークンを検証する。不一致なら処理を止める */
function edu_csrf_check(): void
{
    $sent = (string)($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals(edu_csrf_token(), $sent)) {
        http_response_code(400);
        exit('画面の有効期限が切れました。ブラウザの「戻る」で戻り、ページを再読み込みしてからやり直してください。');
    }
}

/* ---------------- 権限 ---------------- */

/** 管理者（承認者）としてログイン中か */
function edu_is_admin(): bool
{
    return !empty($_SESSION['edu_admin']);
}

/** 管理者の表示名（承認者名として記録する） */
function edu_admin_name(): string
{
    return (string)($_SESSION['edu_admin_name'] ?? '管理者');
}

/** 編集用パスワードで解錠済みの予定か（または管理者） */
function edu_can_edit(int $id): bool
{
    return edu_is_admin() || !empty($_SESSION['edu_unlocked'][$id]);
}

function edu_unlock(int $id): void
{
    $_SESSION['edu_unlocked'][$id] = true;
}

/** 接続元IP（ログ用） */
function edu_client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/* ---------------- フラッシュ・表示補助 ---------------- */

/** フラッシュメッセージを設定する（$kind: 'ok' | 'error'） */
function edu_flash_set(string $msg, string $kind = 'ok'): void
{
    $_SESSION['edu_flash'] = ['msg' => $msg, 'kind' => $kind];
}

/** フラッシュメッセージを取り出して消す */
function edu_flash_take(): ?array
{
    $flash = $_SESSION['edu_flash'] ?? null;
    unset($_SESSION['edu_flash']);
    return $flash;
}

/** 曜日（日本語1文字） */
function edu_wday(int $ts): string
{
    return ['日', '月', '火', '水', '木', '金', '土'][(int)date('w', $ts)];
}

/** 開催日の表示（例 2026/11/5(木)） */
function edu_date_label(?string $dt): string
{
    if (!$dt) { return ''; }
    $ts = strtotime($dt);
    return date('Y/n/j', $ts) . '(' . edu_wday($ts) . ')';
}

/** 時間帯の表示（例 17:30〜18:30） */
function edu_time_label(?string $start, ?string $end): string
{
    if (!$start) { return ''; }
    $s = date('G:i', strtotime($start));
    return $end ? $s . '〜' . date('G:i', strtotime($end)) : $s . '〜';
}

/** 登録・更新日時の表示 */
function edu_datetime_label(?string $dt): string
{
    if (!$dt) { return ''; }
    return date('Y/n/j H:i', strtotime($dt));
}

/** 主催の表示（例 委員会：医療安全管理委員会） */
function edu_organizer_label(array $e): string
{
    return $e['organizer_type'] !== '' ? $e['organizer_type'] . '：' . $e['organizer'] : $e['organizer'];
}

/** 年度（4月始まり） */
function edu_fiscal_year(int $ts): int
{
    $y = (int)date('Y', $ts);
    return (int)date('n', $ts) >= 4 ? $y : $y - 1;
}

/* ---------------- レイアウト ---------------- */

/** ページ共通ヘッダ（$active: 'list' | 'calendar' | 'new' | 'admin'） */
function edu_layout_header(array $config, string $subtitle, string $active = ''): void
{
    $title = $config['title'];
    $nav = [
        'list'     => ['index.php', '予定一覧'],
        'calendar' => ['calendar.php', 'カレンダー'],
        'new'      => ['new.php', '予定を登録'],
        'admin'    => ['admin.php', edu_is_admin() ? '管理（ログイン中）' : '管理'],
    ];
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($subtitle) ?> | <?= h($title) ?></title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="topbar">
  <h1><a href="index.php"><?= h($title) ?></a></h1>
  <nav class="nav">
    <?php foreach ($nav as $key => [$href, $label]): ?>
      <a href="<?= h($href) ?>" class="<?= $active === $key ? 'active' : '' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>
</header>
<main class="container">
<?php
    $flash = edu_flash_take();
    if ($flash !== null) {
        $kind = $flash['kind'] === 'error' ? 'error' : 'ok';
        echo '<div class="flash ' . $kind . '">' . h($flash['msg']) . '</div>';
    }
}

/** ページ共通フッタ */
function edu_layout_footer(array $config): void
{
    ?>
</main>
<footer class="foot"><?= h($config['hospital']) ?> <?= h($config['title']) ?>（院内イントラネット運用）</footer>
</body>
</html>
<?php
}

/** エラー一覧の表示 */
function edu_render_errors(array $errors): void
{
    if (!$errors) { return; }
    ?>
    <div class="error">
      <strong>入力内容をご確認ください。</strong>
      <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php
}

/** MySQL 接続失敗時の共通メッセージ */
function edu_db_error(string $msg): void
{
    ?>
  <div class="error">
    <strong>データベース(MySQL)に接続できませんでした。</strong><br>
    <?= h($msg) ?><br>
    設定は <code>education/config.local.php</code>、診断は <a href="check.php">check.php</a> を確認してください。
  </div>
<?php
}
