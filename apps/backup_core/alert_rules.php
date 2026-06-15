<?php

declare(strict_types=1);

require_once __DIR__ . '/manager.php';

function backup_db_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
        $stmt->execute([':table' => $table, ':column' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

function backup_alert_raise(string $alertKey, string $level, string $message, ?int $jobId = null, array $detail = []): int
{
    $pdo = backup_pdo('backup');
    backup_install_schema($pdo);
    $stmt = $pdo->prepare('SELECT id FROM backup_alerts WHERE alert_key = :alert_key AND is_resolved = 0 ORDER BY id DESC LIMIT 1');
    $stmt->execute([':alert_key' => $alertKey]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    $message = mb_substr($message, 0, 1000, 'UTF-8');

    if ($id > 0) {
        $sets = ['level = :level', 'message = :message'];
        $params = [':level' => $level, ':message' => $message, ':id' => $id];
        if (backup_db_has_column($pdo, 'backup_alerts', 'last_seen_at')) {
            $sets[] = 'last_seen_at = NOW()';
        }
        if (backup_db_has_column($pdo, 'backup_alerts', 'occurrence_count')) {
            $sets[] = 'occurrence_count = occurrence_count + 1';
        }
        if (backup_db_has_column($pdo, 'backup_alerts', 'detail_json')) {
            $sets[] = 'detail_json = :detail_json';
            $params[':detail_json'] = $detail === [] ? null : backup_json_encode($detail);
        }
        $pdo->prepare('UPDATE backup_alerts SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
        return $id;
    }

    $columns = ['job_id', 'level', 'alert_key', 'message'];
    $values = [':job_id', ':level', ':alert_key', ':message'];
    $params = [
        ':job_id' => $jobId,
        ':level' => $level,
        ':alert_key' => $alertKey,
        ':message' => $message,
    ];
    if (backup_db_has_column($pdo, 'backup_alerts', 'detail_json')) {
        $columns[] = 'detail_json';
        $values[] = ':detail_json';
        $params[':detail_json'] = $detail === [] ? null : backup_json_encode($detail);
    }
    if (backup_db_has_column($pdo, 'backup_alerts', 'first_seen_at')) {
        $columns[] = 'first_seen_at';
        $values[] = 'NOW()';
    }
    if (backup_db_has_column($pdo, 'backup_alerts', 'last_seen_at')) {
        $columns[] = 'last_seen_at';
        $values[] = 'NOW()';
    }

    $stmt = $pdo->prepare('INSERT INTO backup_alerts (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')');
    $stmt->execute($params);
    return (int)$pdo->lastInsertId();
}

function backup_alert_resolve(int $alertId, array $actor, string $note = ''): bool
{
    if ($alertId <= 0) {
        return false;
    }
    $pdo = backup_pdo('backup');
    backup_install_schema($pdo);
    $sets = ['is_resolved = 1', 'resolved_at = NOW()'];
    $params = [':id' => $alertId];
    if (backup_db_has_column($pdo, 'backup_alerts', 'resolved_by_account_id')) {
        $sets[] = 'resolved_by_account_id = :resolved_by_account_id';
        $params[':resolved_by_account_id'] = (int)($actor['id'] ?? 0) ?: null;
    }
    if (backup_db_has_column($pdo, 'backup_alerts', 'resolved_by_login_id')) {
        $sets[] = 'resolved_by_login_id = :resolved_by_login_id';
        $params[':resolved_by_login_id'] = (string)($actor['login_id'] ?? '');
    }
    if (backup_db_has_column($pdo, 'backup_alerts', 'resolve_note')) {
        $sets[] = 'resolve_note = :resolve_note';
        $params[':resolve_note'] = mb_substr($note, 0, 1000, 'UTF-8');
    }
    $stmt = $pdo->prepare('UPDATE backup_alerts SET ' . implode(', ', $sets) . ' WHERE id = :id AND is_resolved = 0');
    $stmt->execute($params);
    $ok = $stmt->rowCount() > 0;
    if ($ok) {
        backup_operation_log('alert.resolve', $actor, 'backup_alert', (string)$alertId, ['note' => $note]);
    }
    return $ok;
}

function backup_list_alerts(bool $includeResolved = false, int $limit = 100): array
{
    $pdo = backup_pdo('backup');
    backup_install_schema($pdo);
    $limit = max(1, min(300, $limit));
    $sql = 'SELECT * FROM backup_alerts ' . ($includeResolved ? '' : 'WHERE is_resolved = 0 ') . 'ORDER BY is_resolved ASC, id DESC LIMIT :limit';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function backup_operation_log(string $action, array $actor = [], string $targetType = '', string $targetId = '', array $detail = []): void
{
    try {
        $pdo = backup_pdo('backup');
        backup_install_schema($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO backup_operation_logs (actor_account_id, actor_login_id, actor_display_name, action, target_type, target_id, detail_json, ip_address, user_agent) ' .
            'VALUES (:actor_account_id, :actor_login_id, :actor_display_name, :action, :target_type, :target_id, :detail_json, :ip_address, :user_agent)'
        );
        $stmt->execute([
            ':actor_account_id' => (int)($actor['id'] ?? 0) ?: null,
            ':actor_login_id' => (string)($actor['login_id'] ?? ''),
            ':actor_display_name' => (string)($actor['display_name'] ?? ''),
            ':action' => mb_substr($action, 0, 100, 'UTF-8'),
            ':target_type' => mb_substr($targetType, 0, 100, 'UTF-8'),
            ':target_id' => mb_substr($targetId, 0, 191, 'UTF-8'),
            ':detail_json' => $detail === [] ? null : backup_json_encode($detail),
            ':ip_address' => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64, 'UTF-8'),
            ':user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255, 'UTF-8'),
        ]);
    } catch (Throwable $e) {
        backup_write_log('warning', 'operation log failed', ['action' => $action, 'error' => $e->getMessage()]);
    }
}

function backup_latest_successful_job(PDO $pdo, string $jobType): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM backup_jobs WHERE job_type = :job_type AND status = 'success' ORDER BY id DESC LIMIT 1");
    $stmt->execute([':job_type' => $jobType]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function backup_hours_since(?string $datetime): ?float
{
    if (!$datetime) {
        return null;
    }
    try {
        $then = new DateTimeImmutable($datetime, backup_timezone());
        return max(0, (backup_now()->getTimestamp() - $then->getTimestamp()) / 3600);
    } catch (Throwable) {
        return null;
    }
}

function backup_run_alert_rules(string $triggerType = 'cron'): array
{
    $pdo = backup_pdo('backup');
    backup_install_schema($pdo);
    $raised = [];

    $daily = backup_latest_successful_job($pdo, 'daily');
    $dailyHours = backup_hours_since((string)($daily['finished_at'] ?? $daily['started_at'] ?? ''));
    if ($daily === null || $dailyHours === null || $dailyHours > (float)backup_config_value('backup_manager.alerts.daily_success_hours', 36)) {
        $raised[] = backup_alert_raise('daily_backup_stale', 'critical', '24〜36時間以内の成功した日次バックアップが確認できません。', null, ['latest_daily' => $daily]);
    }

    $weekly = backup_latest_successful_job($pdo, 'weekly');
    $weeklyHours = backup_hours_since((string)($weekly['finished_at'] ?? $weekly['started_at'] ?? ''));
    if ($weekly === null || $weeklyHours === null || $weeklyHours > (float)backup_config_value('backup_manager.alerts.weekly_success_hours', 192)) {
        $raised[] = backup_alert_raise('weekly_backup_stale', 'warning', '8日以内の成功した週次バックアップが確認できません。', null, ['latest_weekly' => $weekly]);
    }

    $verify = backup_latest_successful_job($pdo, 'verify');
    $verifyHours = backup_hours_since((string)($verify['finished_at'] ?? $verify['started_at'] ?? ''));
    if ($verify === null || $verifyHours === null || $verifyHours > (float)backup_config_value('backup_manager.alerts.verify_success_hours', 36)) {
        $raised[] = backup_alert_raise('verify_stale', 'warning', '直近のバックアップ検証成功が確認できません。backup_verify.php の実行状況を確認してください。', null, ['latest_verify' => $verify]);
    }

    $stmt = $pdo->query('SELECT * FROM backup_storage_snapshots ORDER BY id DESC LIMIT 1');
    $snapshot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($snapshot)) {
        $payload = json_decode((string)($snapshot['payload_json'] ?? ''), true);
        $disk = is_array($payload['disk'] ?? null) ? $payload['disk'] : [];
        $ratio = $disk['used_ratio'] ?? null;
        if (is_float($ratio) || is_int($ratio)) {
            $warning = (float)backup_config_value('backup_manager.disk_warning_ratio', 0.80);
            $critical = (float)backup_config_value('backup_manager.disk_critical_ratio', 0.90);
            if ((float)$ratio >= $critical) {
                $raised[] = backup_alert_raise('disk_usage_critical', 'critical', 'ディスク使用率が危険域です: ' . round((float)$ratio * 100, 1) . '%', null, ['snapshot_id' => (int)$snapshot['id']]);
            } elseif ((float)$ratio >= $warning) {
                $raised[] = backup_alert_raise('disk_usage_warning', 'warning', 'ディスク使用率が警告域です: ' . round((float)$ratio * 100, 1) . '%', null, ['snapshot_id' => (int)$snapshot['id']]);
            }
        }
    }

    backup_operation_log('alert_rules.run', [], 'system', '', ['trigger_type' => $triggerType, 'raised_count' => count($raised)]);
    return ['raised_count' => count($raised), 'alert_ids' => $raised];
}
