<?php
/**
 * 外線折り返し連絡板の MySQL 処理。
 * 接続情報は callback/config.php の 'mysql' 設定を使う（このアプリ単体で完結）。
 * テーブルは初回に自動作成する。
 *
 * cb_posts  … 掲示（外線をかけた職員・相手先の概要・状態・期限）
 * cb_logs   … 操作履歴（掲示・折り返し受電の記録・完了・取り下げ・再掲示・削除・管理者ログイン失敗）
 * cb_admins … 管理者アカウント。管理画面で追加・パスワード変更する
 * cb_depts  … 部署マスタ（掲示する人・折り返しを受けた人の部署の選択肢）。管理画面で編集する
 */
if (!defined('CB_APP')) { http_response_code(403); exit('Forbidden'); }

function cb_mysql(array &$config): PDO
{
    $m = $config['mysql'];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $m['host'], $m['port'], $m['database']
    );
    $pdo = new PDO($dsn, $m['user'], $m['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '+09:00'");
    cb_ensure_tables($pdo);
    cb_load_depts($pdo, $config);
    cb_refresh_admin($pdo);
    return $pdo;
}

function cb_ensure_tables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cb_posts (
           id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
           caller_name    VARCHAR(64)  NOT NULL,
           caller_dept    VARCHAR(64)  NOT NULL,
           caller_ext     VARCHAR(32)  NOT NULL,
           alt_contact    VARCHAR(100) NOT NULL DEFAULT \'\',
           party_type     VARCHAR(32)  NOT NULL,
           party_name     VARCHAR(32)  NOT NULL DEFAULT \'\',
           phone_last4    VARCHAR(4)   NOT NULL DEFAULT \'\',
           purpose        VARCHAR(200) NOT NULL,
           called_at      DATETIME     NOT NULL,
           expires_at     DATETIME     NOT NULL,
           status         VARCHAR(16)  NOT NULL DEFAULT \'open\',
           closed_note    VARCHAR(200) NOT NULL DEFAULT \'\',
           closed_by      VARCHAR(64)  NOT NULL DEFAULT \'\',
           closed_at      DATETIME     NULL,
           created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
           PRIMARY KEY (id),
           KEY idx_open (status, expires_at),
           KEY idx_created (created_at),
           KEY idx_last4 (phone_last4)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    // 以前の版の簡易パスワード列は使わなくなったので削除する
    if ($pdo->query("SHOW COLUMNS FROM cb_posts LIKE 'edit_pass_hash'")->fetch()) {
        $pdo->exec('ALTER TABLE cb_posts DROP COLUMN edit_pass_hash');
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cb_logs (
           id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
           post_id    INT UNSIGNED NULL,
           action     VARCHAR(16)  NOT NULL,
           actor      VARCHAR(64)  NOT NULL DEFAULT \'\',
           detail     TEXT         NULL,
           ip         VARCHAR(45)  NOT NULL DEFAULT \'\',
           created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           PRIMARY KEY (id),
           KEY idx_post (post_id),
           KEY idx_action_ip (action, ip, created_at)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cb_depts (
           id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
           code       VARCHAR(8)   NOT NULL,
           name       VARCHAR(64)  NOT NULL,
           sort_order INT          NOT NULL DEFAULT 0,
           active     TINYINT      NOT NULL DEFAULT 1,
           created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           PRIMARY KEY (id),
           UNIQUE KEY uq_code (code),
           UNIQUE KEY uq_name (name),
           KEY idx_order (active, sort_order)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cb_admins (
           id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
           login_id      VARCHAR(32)  NOT NULL,
           name          VARCHAR(64)  NOT NULL,
           pass_hash     VARCHAR(255) NOT NULL,
           active        TINYINT      NOT NULL DEFAULT 1,
           last_login_at DATETIME     NULL,
           created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           PRIMARY KEY (id),
           UNIQUE KEY uq_login (login_id)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/* ---------------- 部署マスタ ---------------- */

/**
 * DB の部署マスタを $config['departments']（コード => 名称、有効なもののみ・並び順）に反映する。
 * DB が空なら config.php / config.local.php の値を初期値として投入する。
 */
function cb_load_depts(PDO $pdo, array &$config): void
{
    if ((int)$pdo->query('SELECT COUNT(*) FROM cb_depts')->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT IGNORE INTO cb_depts (code, name, sort_order) VALUES (?,?,?)');
        $i = 0;
        foreach ($config['departments'] as $code => $name) {
            $ins->execute([(string)$code, (string)$name, ++$i * 10]);
        }
    }
    $config['departments'] = [];
    foreach ($pdo->query('SELECT code, name FROM cb_depts WHERE active = 1 ORDER BY sort_order, id') as $r) {
        $config['departments'][$r['code']] = $r['name'];
    }
}

/** 部署一覧（管理画面用・使用停止も含む） */
function cb_dept_rows(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM cb_depts ORDER BY sort_order, id')->fetchAll();
}

function cb_dept_find(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM cb_depts WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** 同じコードか同じ名称の部署がほかにあるか */
function cb_dept_exists(PDO $pdo, string $code, string $name, int $exceptId = 0): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM cb_depts WHERE (code = ? OR name = ?) AND id <> ?');
    $st->execute([$code, $name, $exceptId]);
    return (int)$st->fetchColumn() > 0;
}

/** 部署を末尾に追加する（コード・名称が重複していれば false） */
function cb_dept_add(PDO $pdo, string $code, string $name): bool
{
    if (cb_dept_exists($pdo, $code, $name)) { return false; }
    $order = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM cb_depts')->fetchColumn();
    $pdo->prepare('INSERT INTO cb_depts (code, name, sort_order) VALUES (?,?,?)')->execute([$code, $name, $order]);
    return true;
}

/** コード・名称を変更する（重複があれば false）。登録済みの掲示の表記は変えない */
function cb_dept_rename(PDO $pdo, int $id, string $code, string $name): bool
{
    if (cb_dept_exists($pdo, $code, $name, $id)) { return false; }
    $pdo->prepare('UPDATE cb_depts SET code = ?, name = ? WHERE id = ?')->execute([$code, $name, $id]);
    return true;
}

function cb_dept_set_active(PDO $pdo, int $id, bool $active): void
{
    $pdo->prepare('UPDATE cb_depts SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
}

/** 並び順を1つ上／下へ */
function cb_dept_move(PDO $pdo, int $id, int $dir): void
{
    $ids = array_map(fn($r) => (int)$r['id'], cb_dept_rows($pdo));
    $i = array_search($id, $ids, true);
    $j = $i === false ? false : $i + $dir;
    if ($j === false || $j < 0 || $j >= count($ids)) { return; }
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    $up = $pdo->prepare('UPDATE cb_depts SET sort_order = ? WHERE id = ?');
    foreach ($ids as $k => $did) { $up->execute([($k + 1) * 10, $did]); }
}

/* ---------------- 管理者アカウント ---------------- */

function cb_admin_count(PDO $pdo, bool $activeOnly = true): int
{
    return (int)$pdo->query('SELECT COUNT(*) FROM cb_admins' . ($activeOnly ? ' WHERE active = 1' : ''))->fetchColumn();
}

function cb_admin_rows(PDO $pdo): array
{
    return $pdo->query('SELECT id, login_id, name, active, last_login_at, created_at FROM cb_admins ORDER BY active DESC, id')->fetchAll();
}

function cb_admin_find(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM cb_admins WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function cb_admin_find_login(PDO $pdo, string $loginId): ?array
{
    $st = $pdo->prepare('SELECT * FROM cb_admins WHERE login_id = ?');
    $st->execute([$loginId]);
    return $st->fetch() ?: null;
}

/** 管理者を追加する（ログインIDが重複していれば false） */
function cb_admin_add(PDO $pdo, string $loginId, string $name, string $pass): bool
{
    if (cb_admin_find_login($pdo, $loginId) !== null) { return false; }
    $pdo->prepare('INSERT INTO cb_admins (login_id, name, pass_hash) VALUES (?,?,?)')
        ->execute([$loginId, $name, password_hash($pass, PASSWORD_DEFAULT)]);
    return true;
}

function cb_admin_set_pass(PDO $pdo, int $id, string $pass): void
{
    $pdo->prepare('UPDATE cb_admins SET pass_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
}

function cb_admin_set_active(PDO $pdo, int $id, bool $active): void
{
    $pdo->prepare('UPDATE cb_admins SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
}

/** ログイン中の管理者が無効化・削除されていればログアウトし、氏名の変更を反映する */
function cb_refresh_admin(PDO $pdo): void
{
    $id = (int)($_SESSION['cb_admin_id'] ?? 0);
    if ($id <= 0) { return; }
    $a = cb_admin_find($pdo, $id);
    if ($a === null || !(int)$a['active']) {
        unset($_SESSION['cb_admin_id'], $_SESSION['cb_admin_name']);
        return;
    }
    $_SESSION['cb_admin_name'] = $a['name'];
}

/* ---------------- 操作履歴 ---------------- */

/** 操作履歴を1件記録する */
function cb_log(PDO $pdo, ?int $postId, string $action, string $actor, string $detail = ''): void
{
    $pdo->prepare('INSERT INTO cb_logs (post_id, action, actor, detail, ip) VALUES (?,?,?,?,?)')
        ->execute([$postId, $action, mb_substr($actor, 0, 64), $detail, cb_client_ip()]);
}

/** 掲示ごとの操作履歴（新しい順） */
function cb_logs_for(PDO $pdo, int $postId): array
{
    $st = $pdo->prepare('SELECT * FROM cb_logs WHERE post_id = ? AND action NOT LIKE \'%fail\' ORDER BY id DESC');
    $st->execute([$postId]);
    return $st->fetchAll();
}

/** 操作コード => 表示名（操作履歴の表示用） */
function cb_action_label(string $action): string
{
    return [
        'create'   => '掲示',
        'receive'  => '折り返し受電',
        'done'     => '連絡がついた',
        'withdraw' => '取り下げ',
        'reopen'   => '再掲示・延長',
        'delete'   => '削除',
    ][$action] ?? $action;
}

/**
 * 掲示ごとの「折り返しを受けた」記録（一覧のカード表示用）。
 * @return array<int, array> 掲示ID => 記録の配列（新しい順）
 */
function cb_receipts_for(PDO $pdo, array $postIds): array
{
    if (!$postIds) { return []; }
    $in = implode(',', array_fill(0, count($postIds), '?'));
    $st = $pdo->prepare("SELECT * FROM cb_logs WHERE action = 'receive' AND post_id IN ($in) ORDER BY id DESC");
    $st->execute(array_values($postIds));
    $map = [];
    foreach ($st->fetchAll() as $r) {
        $map[(int)$r['post_id']][] = $r;
    }
    return $map;
}

/**
 * 指定IPの直近 $minutes 分のパスワード誤り回数。
 * $action: 'login_fail'（管理者ログイン）
 */
function cb_fail_count(PDO $pdo, string $action, string $ip, int $minutes): int
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM cb_logs
          WHERE action = ? AND ip = ? AND created_at >= NOW() - INTERVAL ? MINUTE'
    );
    $st->execute([$action, $ip, $minutes]);
    return (int)$st->fetchColumn();
}

/* ---------------- 掲示 ---------------- */

/** キーワード検索の条件（相手先名・下4桁・担当者・部署・用件） */
function cb_keyword_where(string $q, array &$params): string
{
    $q = trim(mb_convert_kana($q, 'as'));
    if ($q === '') { return ''; }
    $sql = '';
    foreach (preg_split('/\s+/', $q) as $word) {
        $like = '%' . addcslashes($word, '%_\\') . '%';
        $sql .= ' AND (party_name LIKE ? OR phone_last4 LIKE ? OR caller_name LIKE ? OR caller_dept LIKE ?
                       OR purpose LIKE ? OR party_type LIKE ? OR caller_ext LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    return $sql;
}

/** 折り返し待ち（期限内）の掲示（新しい順） */
function cb_open_posts(PDO $pdo, string $q = ''): array
{
    $params = [];
    $where = cb_keyword_where($q, $params);
    $st = $pdo->prepare(
        "SELECT * FROM cb_posts WHERE status = 'open' AND expires_at > NOW() $where ORDER BY called_at DESC, id DESC"
    );
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * 履歴の検索。
 * $filters: from(Y-m-d) / to(Y-m-d) / state(open|expired|done|withdrawn) / q
 * @return array{rows: array, total: int}
 */
function cb_history(PDO $pdo, array $filters, int $page, int $perPage): array
{
    $params = [];
    $where = 'WHERE 1=1';
    if (($filters['from'] ?? '') !== '') {
        $where .= ' AND called_at >= ?';
        $params[] = $filters['from'] . ' 00:00:00';
    }
    if (($filters['to'] ?? '') !== '') {
        $where .= ' AND called_at < ? + INTERVAL 1 DAY';
        $params[] = $filters['to'] . ' 00:00:00';
    }
    switch ($filters['state'] ?? '') {
        case 'open':      $where .= " AND status = 'open' AND expires_at > NOW()"; break;
        case 'expired':   $where .= " AND status = 'open' AND expires_at <= NOW()"; break;
        case 'done':      $where .= " AND status = 'done'"; break;
        case 'withdrawn': $where .= " AND status = 'withdrawn'"; break;
    }
    $where .= cb_keyword_where((string)($filters['q'] ?? ''), $params);

    $st = $pdo->prepare("SELECT COUNT(*) FROM cb_posts $where");
    $st->execute($params);
    $total = (int)$st->fetchColumn();

    $offset = max(0, ($page - 1) * $perPage);
    $st = $pdo->prepare("SELECT * FROM cb_posts $where ORDER BY called_at DESC, id DESC LIMIT $perPage OFFSET $offset");
    $st->execute($params);
    return ['rows' => $st->fetchAll(), 'total' => $total];
}

/** 一覧上部の件数（折り返し待ち・今日連絡がついた） */
function cb_summary_counts(PDO $pdo): array
{
    return [
        'open'       => (int)$pdo->query("SELECT COUNT(*) FROM cb_posts WHERE status = 'open' AND expires_at > NOW()")->fetchColumn(),
        'done_today' => (int)$pdo->query("SELECT COUNT(*) FROM cb_posts WHERE status = 'done' AND closed_at >= CURDATE()")->fetchColumn(),
    ];
}

function cb_find(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM cb_posts WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** 掲示を登録し、IDを返す */
function cb_create(PDO $pdo, array $d, int $hours): int
{
    $pdo->prepare(
        'INSERT INTO cb_posts (caller_name, caller_dept, caller_ext, alt_contact, party_type, party_name,
                               phone_last4, purpose, called_at, expires_at)
         VALUES (?,?,?,?,?,?,?,?,?, NOW() + INTERVAL ? HOUR)'
    )->execute([
        $d['caller_name'], $d['caller_dept'], $d['caller_ext'], $d['alt_contact'], $d['party_type'],
        $d['party_name'], $d['phone_last4'], $d['purpose'], $d['called_at'], $hours,
    ]);
    $id = (int)$pdo->lastInsertId();
    cb_log($pdo, $id, 'create', $d['caller_name'], "掲示期間 {$hours}時間");
    return $id;
}

/** 「折り返しを受けた」記録を残す（掲示は閉じない。誰でも記録できる） */
function cb_receive(PDO $pdo, int $id, string $who, string $memo): void
{
    cb_log($pdo, $id, 'receive', $who, $memo);
}

/** 掲示を終える（$status: 'done' 連絡がついた / 'withdrawn' 取り下げ） */
function cb_close(PDO $pdo, int $id, string $status, string $who, string $note): void
{
    $pdo->prepare('UPDATE cb_posts SET status = ?, closed_note = ?, closed_by = ?, closed_at = NOW() WHERE id = ?')
        ->execute([$status, $note, mb_substr($who, 0, 64), $id]);
    cb_log($pdo, $id, $status === 'done' ? 'done' : 'withdraw', $who, $note);
}

/** 掲示を延長する（終えた・期限切れの掲示は折り返し待ちに戻す）。期限は今から $hours 時間後 */
function cb_reopen(PDO $pdo, int $id, int $hours, string $who): void
{
    $pdo->prepare(
        "UPDATE cb_posts SET status = 'open', closed_note = '', closed_by = '', closed_at = NULL,
                expires_at = NOW() + INTERVAL ? HOUR WHERE id = ?"
    )->execute([$hours, $id]);
    cb_log($pdo, $id, 'reopen', $who, "今から{$hours}時間");
}

/** 掲示を削除する（相手先の情報を含む記録も消し、削除した事実だけ残す） */
function cb_delete(PDO $pdo, int $id, string $who): void
{
    $pdo->prepare('DELETE FROM cb_posts WHERE id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM cb_logs WHERE post_id = ?')->execute([$id]);
    cb_log($pdo, $id, 'delete', $who, "掲示 #{$id} を削除");
}

/** 保存期間を過ぎた掲示と、その操作履歴を削除する */
function cb_purge_old(PDO $pdo, int $days): void
{
    if ($days <= 0) { return; }
    $pdo->prepare(
        'DELETE l FROM cb_logs l JOIN cb_posts p ON p.id = l.post_id WHERE p.created_at < NOW() - INTERVAL ? DAY'
    )->execute([$days]);
    $pdo->prepare('DELETE FROM cb_posts WHERE created_at < NOW() - INTERVAL ? DAY')->execute([$days]);
}
