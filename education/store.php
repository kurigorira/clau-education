<?php
/**
 * 研修スケジュールアプリの MySQL 処理。
 * 接続情報は education/config.php の 'mysql' 設定を使う（このアプリ単体で完結）。
 * テーブルは初回に自動作成する。
 *
 * edu_events … 研修・勉強会の予定（承認状態・変更ありフラグを含む）
 * edu_logs   … 操作履歴（登録・編集・承認・差戻し・中止・削除・管理者ログイン失敗）
 */
if (!defined('EDU_APP')) { http_response_code(403); exit('Forbidden'); }

/** 予定の項目名 => 表示名（変更履歴の差分表示に使う） */
function edu_field_labels(): array
{
    return [
        'title'          => '研修名',
        'category'       => '種別',
        'organizer_type' => '主催区分',
        'organizer'      => '主催',
        'targets'        => '対象',
        'is_mandatory'   => '必須研修',
        'start_at'       => '開始',
        'end_at'         => '終了',
        'place'          => '会場',
        'capacity'       => '定員',
        'body'           => '内容',
        'contact_name'   => '担当者',
        'contact_dept'   => '担当者所属',
        'contact_ext'    => '連絡先',
    ];
}

/** 設定の MySQL へ接続し、テーブルが無ければ作成して返す */
function edu_mysql(array $config): PDO
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
    edu_ensure_tables($pdo);
    return $pdo;
}

function edu_ensure_tables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS edu_events (
           id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
           title                 VARCHAR(200) NOT NULL,
           category              VARCHAR(32)  NOT NULL,
           organizer_type        VARCHAR(16)  NOT NULL DEFAULT \'\',
           organizer             VARCHAR(100) NOT NULL,
           targets               VARCHAR(500) NOT NULL DEFAULT \'\',
           is_mandatory          TINYINT      NOT NULL DEFAULT 0,
           start_at              DATETIME     NOT NULL,
           end_at                DATETIME     NULL,
           place                 VARCHAR(100) NOT NULL DEFAULT \'\',
           capacity              INT UNSIGNED NULL,
           body                  TEXT         NOT NULL,
           contact_name          VARCHAR(64)  NOT NULL,
           contact_dept          VARCHAR(64)  NOT NULL DEFAULT \'\',
           contact_ext           VARCHAR(64)  NOT NULL DEFAULT \'\',
           edit_pass_hash        VARCHAR(255) NOT NULL,
           status                VARCHAR(16)  NOT NULL DEFAULT \'pending\',
           changed_after_approval TINYINT     NOT NULL DEFAULT 0,
           reject_reason         TEXT         NULL,
           approved_by           VARCHAR(64)  NOT NULL DEFAULT \'\',
           approved_at           DATETIME     NULL,
           created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           updated_at            DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
           PRIMARY KEY (id),
           KEY idx_status_start (status, start_at),
           KEY idx_start (start_at),
           KEY idx_category (category),
           KEY idx_organizer_type (organizer_type)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    // 予定を削除しても履歴は残す（外部キーは張らない）
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS edu_logs (
           id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
           event_id   INT UNSIGNED NULL,
           action     VARCHAR(16)  NOT NULL,
           actor      VARCHAR(64)  NOT NULL DEFAULT \'\',
           detail     TEXT         NULL,
           ip         VARCHAR(45)  NOT NULL DEFAULT \'\',
           created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           PRIMARY KEY (id),
           KEY idx_event (event_id),
           KEY idx_action_ip (action, ip, created_at)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/** 操作履歴を1件記録する */
function edu_log(PDO $pdo, ?int $eventId, string $action, string $actor, string $detail = ''): void
{
    $pdo->prepare('INSERT INTO edu_logs (event_id, action, actor, detail, ip) VALUES (?,?,?,?,?)')
        ->execute([$eventId, $action, mb_substr($actor, 0, 64), $detail, edu_client_ip()]);
}

/** 予定ごとの操作履歴（新しい順） */
function edu_logs_for(PDO $pdo, int $eventId): array
{
    $st = $pdo->prepare('SELECT * FROM edu_logs WHERE event_id = ? ORDER BY id DESC');
    $st->execute([$eventId]);
    return $st->fetchAll();
}

/**
 * 指定IPの直近 $minutes 分のパスワード誤り回数。
 * $action: 'login_fail'（管理者ログイン） / 'unlock_fail'（編集用パスワード）
 */
function edu_fail_count(PDO $pdo, string $action, string $ip, int $minutes): int
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM edu_logs
          WHERE action = ? AND ip = ? AND created_at >= NOW() - INTERVAL ? MINUTE'
    );
    $st->execute([$action, $ip, $minutes]);
    return (int)$st->fetchColumn();
}

/**
 * 公開中・中止の予定を検索する（一覧画面用）。
 * $filters: period(upcoming|past|all) / month(YYYY-MM) / category / organizer_type / target / q
 * @return array{rows: array, total: int}
 */
function edu_search(PDO $pdo, array $filters, int $page, int $perPage): array
{
    $where  = ["status IN ('approved','cancelled')"];
    $params = [];

    if (($filters['month'] ?? '') !== '') {
        $from = $filters['month'] . '-01 00:00:00';
        $where[]  = 'start_at >= ? AND start_at < ? + INTERVAL 1 MONTH';
        $params[] = $from;
        $params[] = $from;
    } elseif (($filters['period'] ?? 'upcoming') === 'upcoming') {
        $where[] = 'COALESCE(end_at, start_at) >= CURDATE()';
    } elseif ($filters['period'] === 'past') {
        $where[] = 'COALESCE(end_at, start_at) < CURDATE()';
    }
    foreach (['category', 'organizer_type'] as $key) {
        if (($filters[$key] ?? '') !== '') {
            $where[]  = "$key = ?";
            $params[] = $filters[$key];
        }
    }
    if (($filters['target'] ?? '') !== '') {
        // 「全職員」向けの研修は職種で絞っても表示する
        $where[]  = "(FIND_IN_SET(?, targets) > 0 OR FIND_IN_SET('全職員', targets) > 0)";
        $params[] = $filters['target'];
    }
    if (($filters['q'] ?? '') !== '') {
        $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
        $where[] = '(title LIKE ? OR organizer LIKE ? OR body LIKE ? OR place LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $order = ($filters['period'] ?? 'upcoming') === 'past' && ($filters['month'] ?? '') === ''
        ? 'start_at DESC' : 'start_at ASC';

    $st = $pdo->prepare("SELECT COUNT(*) FROM edu_events $whereSql");
    $st->execute($params);
    $total = (int)$st->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $st = $pdo->prepare("SELECT * FROM edu_events $whereSql ORDER BY $order, id LIMIT $perPage OFFSET $offset");
    $st->execute($params);
    return ['rows' => $st->fetchAll(), 'total' => $total];
}

/** カレンダー用：期間内に開催される公開中・中止の予定 */
function edu_events_between(PDO $pdo, string $from, string $to, array $filters = []): array
{
    $where  = ["status IN ('approved','cancelled')", 'start_at < ?', 'COALESCE(end_at, start_at) >= ?'];
    $params = [$to, $from];
    foreach (['category', 'organizer_type'] as $key) {
        if (($filters[$key] ?? '') !== '') {
            $where[]  = "$key = ?";
            $params[] = $filters[$key];
        }
    }
    $st = $pdo->prepare('SELECT * FROM edu_events WHERE ' . implode(' AND ', $where) . ' ORDER BY start_at, id');
    $st->execute($params);
    return $st->fetchAll();
}

/** 管理画面用：ステータス別・変更あり */
function edu_admin_list(PDO $pdo, string $kind): array
{
    $sql = [
        'pending'  => "SELECT * FROM edu_events WHERE status = 'pending' ORDER BY created_at",
        'changed'  => "SELECT * FROM edu_events WHERE status = 'approved' AND changed_after_approval = 1 ORDER BY updated_at",
        'rejected' => "SELECT * FROM edu_events WHERE status = 'rejected' ORDER BY updated_at DESC",
        'all'      => 'SELECT * FROM edu_events ORDER BY start_at DESC LIMIT 300',
    ][$kind];
    return $pdo->query($sql)->fetchAll();
}

/** 一覧上部のサマリ件数 */
function edu_summary_counts(PDO $pdo): array
{
    $fy     = edu_fiscal_year(time());
    $fyFrom = sprintf('%d-04-01', $fy);
    $st = $pdo->prepare(
        "SELECT
           SUM(start_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
               AND start_at < DATE_FORMAT(CURDATE(), '%Y-%m-01') + INTERVAL 1 MONTH)      AS this_month,
           SUM(start_at >= CURDATE() AND start_at < CURDATE() + INTERVAL 30 DAY)           AS next30,
           SUM(start_at >= ? AND start_at < ? + INTERVAL 1 YEAR)                            AS fiscal_year
         FROM edu_events WHERE status = 'approved'"
    );
    $st->execute([$fyFrom, $fyFrom]);
    $r = $st->fetch() ?: [];
    $pending = (int)$pdo->query("SELECT COUNT(*) FROM edu_events WHERE status = 'pending'")->fetchColumn();
    return [
        'this_month'  => (int)($r['this_month'] ?? 0),
        'next30'      => (int)($r['next30'] ?? 0),
        'fiscal_year' => (int)($r['fiscal_year'] ?? 0),
        'fy'          => $fy,
        'pending'     => $pending,
    ];
}

/** 1件取得（無ければ null） */
function edu_find(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM edu_events WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/** 新規登録（承認待ちで保存）。登録番号を返す */
function edu_create(PDO $pdo, array $data, string $passHash): int
{
    $cols = array_keys(edu_field_labels());
    $sql = 'INSERT INTO edu_events (' . implode(',', $cols) . ', edit_pass_hash, status)
            VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ", ?, 'pending')";
    $pdo->beginTransaction();
    try {
        $pdo->prepare($sql)->execute(array_merge(array_map(fn($c) => $data[$c], $cols), [$passHash]));
        $id = (int)$pdo->lastInsertId();
        edu_log($pdo, $id, 'create', $data['contact_name'], '登録申請');
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** 変更前後の差分を「項目: 旧 → 新」の行にする（本文は「変更あり」のみ） */
function edu_diff_text(array $before, array $after): string
{
    $lines = [];
    foreach (edu_field_labels() as $col => $label) {
        $old = (string)($before[$col] ?? '');
        $new = (string)($after[$col] ?? '');
        if ($col === 'start_at' || $col === 'end_at') {
            $old = $old !== '' ? date('Y/n/j G:i', strtotime($old)) : '';
            $new = $new !== '' ? date('Y/n/j G:i', strtotime($new)) : '';
        }
        if ($col === 'is_mandatory') {
            $old = $old === '1' ? 'はい' : 'いいえ';
            $new = $new === '1' ? 'はい' : 'いいえ';
        }
        if ($old === $new) { continue; }
        if ($col === 'body') {
            $lines[] = "{$label}: （変更あり）";
        } else {
            $lines[] = "{$label}: " . ($old === '' ? '（なし）' : $old) . ' → ' . ($new === '' ? '（なし）' : $new);
        }
    }
    return implode("\n", $lines);
}

/**
 * 予定を更新する。
 *  - 担当者が公開中の予定を編集 → 公開は続け「変更あり」にして管理者に知らせる
 *  - 担当者が差戻しの予定を編集 → 承認待ちに戻す
 *  - 管理者の編集 → ステータスはそのまま（変更ありフラグは解除）
 * @return string 差分テキスト（変更なしなら空）
 */
function edu_update(PDO $pdo, array $event, array $data, string $actor, bool $byAdmin): string
{
    $diff = edu_diff_text($event, $data);
    if ($diff === '') { return ''; }

    $cols = array_keys(edu_field_labels());
    $set  = implode(', ', array_map(fn($c) => "$c = ?", $cols));
    $params = array_map(fn($c) => $data[$c], $cols);

    $status  = $event['status'];
    $changed = (int)$event['changed_after_approval'];
    if ($byAdmin) {
        $changed = 0;
    } elseif ($status === 'approved') {
        $changed = 1;
    } elseif ($status === 'rejected') {
        $status = 'pending';
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE edu_events SET $set, status = ?, changed_after_approval = ? WHERE id = ?")
            ->execute(array_merge($params, [$status, $changed, (int)$event['id']]));
        edu_log($pdo, (int)$event['id'], 'edit', $actor, $diff);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $diff;
}

/** 承認（公開）。変更ありの確認済みにも使う */
function edu_approve(PDO $pdo, int $id, string $who): void
{
    $pdo->prepare(
        "UPDATE edu_events
            SET status = 'approved', changed_after_approval = 0, reject_reason = NULL,
                approved_by = ?, approved_at = NOW()
          WHERE id = ?"
    )->execute([$who, $id]);
    edu_log($pdo, $id, 'approve', $who, '承認・公開');
}

/** 変更ありを確認済みにする（公開状態はそのまま） */
function edu_ack_change(PDO $pdo, int $id, string $who): void
{
    $pdo->prepare('UPDATE edu_events SET changed_after_approval = 0 WHERE id = ?')->execute([$id]);
    edu_log($pdo, $id, 'ack', $who, '変更内容を確認');
}

/** 差戻し（理由つき・一覧には出ない） */
function edu_reject(PDO $pdo, int $id, string $who, string $reason): void
{
    $pdo->prepare("UPDATE edu_events SET status = 'rejected', changed_after_approval = 0, reject_reason = ? WHERE id = ?")
        ->execute([$reason, $id]);
    edu_log($pdo, $id, 'reject', $who, $reason);
}

/** 中止（一覧・カレンダーには取り消し線で残す） */
function edu_cancel(PDO $pdo, int $id, string $who, string $reason): void
{
    $pdo->prepare("UPDATE edu_events SET status = 'cancelled', changed_after_approval = 0, reject_reason = ? WHERE id = ?")
        ->execute([$reason, $id]);
    edu_log($pdo, $id, 'cancel', $who, $reason);
}

/** 中止の取り消し（公開中に戻す。管理者のみ） */
function edu_restore(PDO $pdo, int $id, string $who): void
{
    $pdo->prepare("UPDATE edu_events SET status = 'approved', reject_reason = NULL WHERE id = ?")->execute([$id]);
    edu_log($pdo, $id, 'restore', $who, '中止を取り消し');
}

/** 完全削除（重複登録・いたずら等。履歴は残る） */
function edu_delete(PDO $pdo, array $event, string $who): void
{
    $pdo->prepare('DELETE FROM edu_events WHERE id = ?')->execute([(int)$event['id']]);
    edu_log($pdo, (int)$event['id'], 'delete', $who,
        '削除: ' . date('Y/n/j', strtotime($event['start_at'])) . ' ' . $event['title']);
}

/** 担当者の編集用パスワードを再設定する（管理者のみ） */
function edu_reset_pass(PDO $pdo, int $id, string $hash, string $who): void
{
    $pdo->prepare('UPDATE edu_events SET edit_pass_hash = ? WHERE id = ?')->execute([$hash, $id]);
    edu_log($pdo, $id, 'reset_pass', $who, '編集用パスワードを再設定');
}

/** 年度（4月〜翌3月）の公開中・中止の予定（CSV出力用） */
function edu_fiscal_rows(PDO $pdo, int $fy): array
{
    $from = sprintf('%d-04-01', $fy);
    $st = $pdo->prepare(
        "SELECT * FROM edu_events
          WHERE status IN ('approved','cancelled') AND start_at >= ? AND start_at < ? + INTERVAL 1 YEAR
          ORDER BY start_at, id"
    );
    $st->execute([$from, $from]);
    return $st->fetchAll();
}
