<?php
/**
 * 外線折り返し連絡板の共通処理（セッション・エスケープ・CSRF・フラッシュ・レイアウト）。
 * 他アプリ（receipt/beds/inquiry/jobs/education）とはコード・設定とも共有しない。
 */
if (!defined('CB_APP')) { http_response_code(403); exit('Forbidden'); }

session_name('CBSESSID');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

/** HTMLエスケープ */
function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * 掲示の状態。DBの status（open/done/withdrawn）に、期限切れ（open かつ期限を過ぎた）を加えたもの。
 * @return string 'open' | 'expired' | 'done' | 'withdrawn'
 */
function cb_state(array $p): string
{
    if ($p['status'] === 'open' && strtotime($p['expires_at']) <= time()) {
        return 'expired';
    }
    return $p['status'];
}

/** 状態コード => 表示名 */
function cb_state_map(): array
{
    return [
        'open'      => '折り返し待ち',
        'done'      => '連絡がついた',
        'withdrawn' => '取り下げ',
        'expired'   => '期限切れ',
    ];
}

/** 状態コード => バッジ用CSSクラス */
function cb_state_class(string $state): string
{
    return [
        'open'      => 'badge-open',
        'done'      => 'badge-done',
        'withdrawn' => 'badge-muted',
        'expired'   => 'badge-muted',
    ][$state] ?? 'badge-muted';
}

/* ---------------- CSRF ---------------- */

/** フォームに埋め込むトークン（セッションごとに1つ） */
function cb_csrf_token(): string
{
    if (empty($_SESSION['cb_csrf'])) {
        $_SESSION['cb_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['cb_csrf'];
}

function cb_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(cb_csrf_token()) . '">';
}

/** POST のトークンを検証する。不一致なら処理を止める */
function cb_csrf_check(): void
{
    $sent = (string)($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals(cb_csrf_token(), $sent)) {
        http_response_code(400);
        exit('画面の有効期限が切れました。ブラウザの「戻る」で戻り、ページを再読み込みしてからやり直してください。');
    }
}

/* ---------------- 権限 ---------------- */

/** 管理者としてログイン中か */
function cb_is_admin(): bool
{
    return !empty($_SESSION['cb_admin_id']);
}

function cb_admin_id(): int
{
    return (int)($_SESSION['cb_admin_id'] ?? 0);
}

/** 管理者の表示名（操作者名として記録する） */
function cb_admin_name(): string
{
    return (string)($_SESSION['cb_admin_name'] ?? '管理者');
}

/** 管理者アカウントの入力チェック（$pass が null ならパスワードは確認しない） */
function cb_admin_input_errors(string $loginId, string $name, ?string $pass, ?string $pass2): array
{
    $errors = [];
    if ($loginId !== '' || $pass !== null) {
        if (!preg_match('/^[A-Za-z0-9_.\-]{2,32}$/', $loginId)) {
            $errors[] = 'ログインIDは半角英数字（記号は _ . - のみ）2〜32文字で入力してください。';
        }
    }
    if ($name === '' || mb_strlen($name) > 64) {
        $errors[] = '氏名を64文字以内で入力してください。';
    }
    if ($pass !== null) {
        if (strlen($pass) < 8) {
            $errors[] = 'パスワードは8文字以上で入力してください。';
        } elseif ($pass !== $pass2) {
            $errors[] = 'パスワード（確認）が一致しません。';
        }
    }
    return $errors;
}

/** 接続元IP（ログ用） */
function cb_client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/* ---------------- フラッシュ・表示補助 ---------------- */

/** フラッシュメッセージを設定する（$kind: 'ok' | 'error'） */
function cb_flash_set(string $msg, string $kind = 'ok'): void
{
    $_SESSION['cb_flash'] = ['msg' => $msg, 'kind' => $kind];
}

/** フラッシュメッセージを取り出して消す */
function cb_flash_take(): ?array
{
    $flash = $_SESSION['cb_flash'] ?? null;
    unset($_SESSION['cb_flash']);
    return $flash;
}

/** 曜日（日本語1文字） */
function cb_wday(int $ts): string
{
    return ['日', '月', '火', '水', '木', '金', '土'][(int)date('w', $ts)];
}

/** 日時の表示（今日なら時刻のみ。例 14:05 ／ 10/26(月) 9:30） */
function cb_when_label(?string $dt): string
{
    if (!$dt) { return ''; }
    $ts = strtotime($dt);
    if (date('Y-m-d', $ts) === date('Y-m-d')) {
        return '今日 ' . date('G:i', $ts);
    }
    $fmt = date('Y', $ts) === date('Y') ? 'n/j' : 'Y/n/j';
    return date($fmt, $ts) . '(' . cb_wday($ts) . ') ' . date('G:i', $ts);
}

/** 登録・更新日時の表示 */
function cb_datetime_label(?string $dt): string
{
    if (!$dt) { return ''; }
    return date('Y/n/j H:i', strtotime($dt));
}

/** 経過時間の表示（例 5分前・3時間前・2日前） */
function cb_ago_label(?string $dt): string
{
    if (!$dt) { return ''; }
    $sec = max(0, time() - strtotime($dt));
    if ($sec < 60)    { return 'たった今'; }
    if ($sec < 3600)  { return intdiv($sec, 60) . '分前'; }
    if ($sec < 86400) { return intdiv($sec, 3600) . '時間前'; }
    return intdiv($sec, 86400) . '日前';
}

/** 期限までの残り時間（例 あと3時間・あと40分） */
function cb_left_label(string $expiresAt): string
{
    $sec = strtotime($expiresAt) - time();
    if ($sec <= 0)    { return '期限切れ'; }
    if ($sec < 3600)  { return 'あと' . max(1, intdiv($sec, 60)) . '分'; }
    return 'あと' . intdiv($sec, 3600) . '時間';
}

/** 相手先の表示（例 患者家族 山田様 ／ 業者） */
function cb_party_label(array $p): string
{
    $name = trim((string)$p['party_name']);
    return $p['party_type'] . ($name !== '' ? ' ' . $name : '');
}

/** 転送先の表示（数字で始まれば「内線 1234」、それ以外は入力のまま。例 PHS 5521） */
function cb_ext_label(array $p): string
{
    return preg_match('/^\d/', $p['caller_ext']) ? '内線 ' . $p['caller_ext'] : $p['caller_ext'];
}

/** 電話番号下4桁の表示（例 ***-1234） */
function cb_phone_label(array $p): string
{
    return $p['phone_last4'] !== '' ? '***-' . $p['phone_last4'] : '';
}

/* ---------------- 部署の選択欄 ---------------- */

/**
 * 部署の選択欄（部署マスタの select ＋「その他」のときの部署名入力欄）。
 * 送信される項目名は $name（選んだ部署名）と {$name}_other（その他の部署名）。
 */
function cb_dept_select(array $config, string $name, string $selected, string $other, bool $required = true): void
{
    $id = h($name);
    ?>
    <div class="dept-pick">
      <select id="<?= $id ?>" name="<?= $id ?>"<?= $required ? ' required' : '' ?>>
        <option value="">選んでください</option>
        <?php foreach ($config['departments'] as $code => $dept): ?>
          <option value="<?= h($dept) ?>" <?= $selected === $dept ? 'selected' : '' ?>><?= h($dept) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="<?= $id ?>_other" value="<?= h($other) ?>" maxlength="64"
             placeholder="「<?= h($config['dept_other']) ?>」のときは部署名を記入" aria-label="<?= h($config['dept_other']) ?>の部署名">
    </div>
<?php
}

/**
 * 部署の選択欄の入力を読み取る。
 * @return array{sel: string, other: string, value: string, error: ?string}
 *   value … 保存する部署名（「その他」のときは記入された部署名）
 */
function cb_dept_read(array $config, array $post, string $name, string $label): array
{
    $sel   = trim((string)($post[$name] ?? ''));
    $other = trim(mb_convert_kana((string)($post[$name . '_other'] ?? ''), 's'));
    $r = ['sel' => $sel, 'other' => $other, 'value' => $sel, 'error' => null];
    if ($sel === '' || !in_array($sel, $config['departments'], true)) {
        $r['error'] = "{$label}を選んでください。";
    } elseif ($sel === $config['dept_other']) {
        if ($other === '') {
            $r['error'] = "{$label}で「{$config['dept_other']}」を選んだときは、部署名を記入してください。";
        } elseif (mb_strlen($other) > 64) {
            $r['error'] = "{$label}（{$config['dept_other']}）は64文字以内で入力してください。";
        } else {
            $r['value'] = $other;
        }
    }
    return $r;
}

/* ---------------- レイアウト ---------------- */

/**
 * ページ共通ヘッダ（$active: 'list' | 'new' | 'history' | 'admin'）
 * $refresh > 0 なら、その秒数ごとにページを自動で再読み込みする（一覧画面用）
 */
function cb_layout_header(array $config, string $subtitle, string $active = '', int $refresh = 0): void
{
    $title = $config['title'];
    $nav = [
        'list'    => ['index.php', '折り返し待ち一覧'],
        'new'     => ['new.php', '掲示する'],
        'history' => ['history.php', '履歴'],
        'admin'   => ['admin.php', cb_is_admin() ? '管理（ログイン中）' : '管理'],
    ];
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($refresh > 0): ?><meta http-equiv="refresh" content="<?= (int)$refresh ?>"><?php endif; ?>
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
    $flash = cb_flash_take();
    if ($flash !== null) {
        $kind = $flash['kind'] === 'error' ? 'error' : 'ok';
        echo '<div class="flash ' . $kind . '">' . h($flash['msg']) . '</div>';
    }
}

/** ページ共通フッタ */
function cb_layout_footer(array $config): void
{
    ?>
</main>
</body>
</html>
<?php
}

/** エラー一覧の表示 */
function cb_render_errors(array $errors): void
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
function cb_db_error(string $msg): void
{
    ?>
  <div class="error">
    <strong>データベース(MySQL)に接続できませんでした。</strong><br>
    <?= h($msg) ?><br>
    設定は <code>callback/config.local.php</code>、診断は <a href="check.php">check.php</a> を確認してください。
  </div>
<?php
}
