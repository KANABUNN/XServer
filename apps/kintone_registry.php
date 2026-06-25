<?php

declare(strict_types=1);

require_once __DIR__ . '/kintone_core/bootstrap.php';

function kintone_registry_normalize_app_key(string $appKey): string
{
    $appKey = trim($appKey);
    if ($appKey === '' || preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/', $appKey) !== 1) {
        return '';
    }
    return $appKey;
}

function kintone_registry_app(string $appKey): ?array
{
    $appKey = kintone_registry_normalize_app_key($appKey);
    if ($appKey === '') {
        return null;
    }
    try {
        $stmt = kintone_pdo('org')->prepare(
            'SELECT app_key, display_name, kintone_app_id, app_role, update_key_field, field_map_json, contains_pii, is_active, status, last_synced_at ' .
            'FROM kintone_apps WHERE app_key = :app_key AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([':app_key' => $appKey]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        error_log('[kintone_registry app] ' . $e->getMessage());
        return null;
    }
}

function kintone_registry_get(string $appKey, string $recordKey): ?array
{
    $appKey = kintone_registry_normalize_app_key($appKey);
    $recordKey = trim($recordKey);
    if ($appKey === '' || $recordKey === '') {
        return null;
    }
    try {
        $stmt = kintone_pdo('org')->prepare(
            'SELECT record_key, record_json, kintone_record_id, kintone_revision, synced_at ' .
            'FROM kintone_app_records WHERE app_key = :app_key AND record_key = :record_key ORDER BY synced_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([':app_key' => $appKey, ':record_key' => $recordKey]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $row['record'] = json_decode((string)($row['record_json'] ?? ''), true) ?: [];
        unset($row['record_json']);
        return $row;
    } catch (Throwable $e) {
        error_log('[kintone_registry get] ' . $e->getMessage());
        return null;
    }
}

function kintone_registry_list(string $appKey, int $limit = 500): array
{
    $appKey = kintone_registry_normalize_app_key($appKey);
    if ($appKey === '') {
        return [];
    }
    try {
        $limit = max(1, min(2000, $limit));
        $stmt = kintone_pdo('org')->prepare(
            'SELECT record_key, record_json, kintone_record_id, kintone_revision, synced_at ' .
            'FROM kintone_app_records WHERE app_key = :app_key ORDER BY record_key ASC, id ASC LIMIT ' . $limit
        );
        $stmt->execute([':app_key' => $appKey]);
        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as &$row) {
            $row['record'] = json_decode((string)($row['record_json'] ?? ''), true) ?: [];
            unset($row['record_json']);
        }
        unset($row);
        return $rows;
    } catch (Throwable $e) {
        error_log('[kintone_registry list] ' . $e->getMessage());
        return [];
    }
}
