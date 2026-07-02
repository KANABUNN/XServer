<?php

declare(strict_types=1);

require_once __DIR__ . '/kintone_core/bootstrap.php';

function kintone_registry_json_object(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function kintone_registry_normalize_app_key(string $appKey): string
{
    $appKey = trim($appKey);
    if ($appKey === '' || preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/', $appKey) !== 1) {
        return '';
    }
    return $appKey;
}

function kintone_registry_normalize_org_code(string $organizationCode): string
{
    return strtoupper(trim(mb_convert_kana($organizationCode, 'asKV', 'UTF-8')));
}

function kintone_registry_org_master_record(string $organizationCode): ?array
{
    $code = kintone_registry_normalize_org_code($organizationCode);
    if ($code === '') {
        return null;
    }

    try {
        $stmt = kintone_pdo('org')->prepare('SELECT * FROM organizations WHERE organization_code = :code LIMIT 1');
        $stmt->execute([':code' => $code]);
        $org = $stmt->fetch();
        if (!is_array($org)) {
            return null;
        }

        $logical = [
            'organization_code' => (string)($org['organization_code'] ?? ''),
            'organization_name' => (string)($org['organization_name'] ?? ''),
            'organization_kana' => (string)($org['organization_kana'] ?? ''),
            'normalized_organization_name' => (string)($org['normalized_organization_name'] ?? ''),
            'category' => (string)($org['category'] ?? ''),
            'representative_name' => (string)($org['representative_name'] ?? ''),
            'representative_email' => (string)($org['representative_email'] ?? ''),
            'representative_member_id' => $org['representative_member_id'] ?? null,
            'rep_source' => (string)($org['rep_source'] ?? ''),
            'activity_status' => (string)($org['activity_status'] ?? ''),
            'activity_status_source' => (string)($org['activity_status_source'] ?? ''),
            'member_count' => (int)($org['member_count'] ?? 0),
            'is_active' => (int)($org['is_active'] ?? 0),
            'last_synced_at' => $org['last_synced_at'] ?? null,
            'updated_at' => $org['updated_at'] ?? null,
        ];

        return [
            'record_key' => $logical['organization_code'],
            'record' => $logical,
            'record_logical' => $logical,
            'kintone_record_id' => isset($org['kintone_record_id']) ? (int)$org['kintone_record_id'] : null,
            'kintone_revision' => isset($org['kintone_revision']) ? (int)$org['kintone_revision'] : null,
            'synced_at' => $org['last_synced_at'] ?? $org['updated_at'] ?? null,
            'source' => 'organizations',
        ];
    } catch (Throwable $e) {
        error_log('[kintone_registry org_master] ' . $e->getMessage());
        return null;
    }
}

function kintone_registry_app(string $appKey): ?array
{
    $appKey = kintone_registry_normalize_app_key($appKey);
    if ($appKey === '') {
        return null;
    }
    try {
        $stmt = kintone_pdo('org')->prepare(
            'SELECT app_key, display_name, kintone_app_id, app_role, update_key_field, field_map_json, lookup_map_json, contains_pii, is_active, status, last_synced_at ' .
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

/**
 * record_json の生フィールドコードを、field_map_json の論理名に変換して返す。
 * app_key = organizations は kintone_app_records ではなく、XServer側の団体マスタ
 * organizations テーブルを正本として直接参照する。
 * 未マップのフィールドコードは fail-open でそのまま残す。
 */
function kintone_registry_get_by_logical(string $appKey, string $recordKey): ?array
{
    $appKey = kintone_registry_normalize_app_key($appKey);
    if ($appKey === '') {
        return null;
    }

    if ($appKey === 'organizations') {
        $orgRow = kintone_registry_org_master_record($recordKey);
        if ($orgRow !== null) {
            return $orgRow;
        }
    }

    $row = kintone_registry_get($appKey, $recordKey);
    if ($row === null) {
        return null;
    }

    $app = kintone_registry_app($appKey);
    $fieldMap = is_array($app) ? kintone_registry_json_object((string)($app['field_map_json'] ?? '')) : [];
    $reverseMap = [];
    foreach ($fieldMap as $logicalKey => $fieldCode) {
        if (is_scalar($fieldCode)) {
            $reverseMap[(string)$fieldCode] = (string)$logicalKey;
        }
    }

    $logical = [];
    foreach ((array)($row['record'] ?? []) as $fieldCode => $value) {
        $key = $reverseMap[$fieldCode] ?? $fieldCode;
        $logical[$key] = $value;
    }
    $row['record_logical'] = $logical;
    return $row;
}

/**
 * lookup_map_json に宣言されたルックアップフィールドを再帰的に解決する。
 * depth は再帰の深さ上限であり、循環参照が構成されていても必ず終了する。
 * 参照先が未登録・未同期・値が空の場合は fail-open で null を埋め、例外は投げない。
 */
function kintone_registry_resolve(string $appKey, string $recordKey, int $depth = 1): ?array
{
    $depth = max(0, min(5, $depth));
    $row = kintone_registry_get_by_logical($appKey, $recordKey);
    if ($row === null || $depth < 1) {
        return $row;
    }

    $app = kintone_registry_app($appKey);
    $lookupMap = is_array($app) ? kintone_registry_json_object((string)($app['lookup_map_json'] ?? '')) : [];
    $resolved = [];
    foreach ($lookupMap as $logicalKey => $target) {
        if (!is_array($target)) {
            continue;
        }
        $targetAppKey = trim((string)($target['target_app_key'] ?? ''));
        $targetLogicalKey = trim((string)($target['target_logical_key'] ?? ''));
        $lookupValue = $row['record_logical'][$logicalKey] ?? null;
        if ($targetAppKey === '' || $targetLogicalKey === '' || !is_scalar($lookupValue) || trim((string)$lookupValue) === '') {
            $resolved[$logicalKey] = null;
            continue;
        }
        $resolved[$logicalKey] = kintone_registry_resolve($targetAppKey, trim((string)$lookupValue), $depth - 1);
    }
    $row['resolved'] = $resolved;
    return $row;
}
