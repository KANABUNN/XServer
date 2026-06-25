<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once kintone_apps_dir() . '/response_limit.php';

function kintone_audit_safe_json(array $summary): string
{
    return kintone_json_encode($summary);
}

function kintone_write_audit_log(string $action, ?string $targetType = null, ?string $targetId = null, array $summary = [], ?array $actor = null): void
{
    try {
        $actor = $actor ?? kintone_auth_current_user();
        $stmt = kintone_pdo('org')->prepare(
            'INSERT INTO kintone_audit_logs (actor_account_id, actor_login_id, actor_display_name, action, target_type, target_id, summary_json, ip_address, user_agent) '
            . 'VALUES (:actor_account_id, :actor_login_id, :actor_display_name, :action, :target_type, :target_id, :summary_json, :ip_address, :user_agent)'
        );
        $stmt->execute([
            ':actor_account_id' => (int)($actor['id'] ?? 0) ?: null,
            ':actor_login_id' => (string)($actor['login_id'] ?? ''),
            ':actor_display_name' => (string)($actor['display_name'] ?? ''),
            ':action' => $action,
            ':target_type' => $targetType,
            ':target_id' => $targetId,
            ':summary_json' => $summary !== [] ? kintone_audit_safe_json($summary) : null,
            ':ip_address' => function_exists('get_client_ip') ? get_client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            ':user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        kintone_write_log('warning', 'audit log failed', ['action' => $action, 'error' => $e->getMessage()]);
    }
}
