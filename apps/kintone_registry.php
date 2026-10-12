<?php

declare(strict_types=1);

require_once __DIR__ . '/kintone_core/bootstrap.php';
require_once __DIR__ . '/org_master.php';

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
 * 未マップのフィールドコードは fail-open でそのまま残す(データを捨てない)。
 */
function kintone_registry_get_by_logical(string $appKey, string $recordKey): ?array
{
    $appKey = kintone_registry_normalize_app_key($appKey);
    if ($appKey === '') {
        return null;
    }

    if ($appKey === 'organizations') {
        return kintone_registry_get_local_organizations($recordKey);
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
 * app_key = organizations は push 運用の団体マスタであり、kintone_app_records には
 * キャッシュされない(kintone_sync_pull_app が app_role !== 'pull' を拒否するため)。
 * 既存の fail-open ヘルパー org_master_lookup_by_code() を再利用し、
 * XServer側 organizations テーブルを正本として直接参照する。
 * PII最小化のため、代表者個人の氏名・メールは解決結果に含めない。
 * 必要になった場合のみ、下記 $safe の配列キーに
 * 'representative_name', 'representative_email' を明示的に追加すること。
 */
function kintone_registry_get_local_organizations(string $organizationCode): ?array
{
    $org = org_master_lookup_by_code($organizationCode);
    if (!is_array($org)) {
        return null;
    }

    $safe = array_intersect_key($org, array_flip([
        'organization_code',
        'organization_name',
        'organization_kana',
        'activity_status',
        'member_count',
    ]));

    return [
        'record_key' => $organizationCode,
        'record' => $safe,
        'record_logical' => $safe,
        'synced_at' => null,
        'source' => 'local_table:organizations',
    ];
}

/**
 * lookup_map_json に宣言されたルックアップフィールドを再帰的に解決する。
 * depth は再帰の深さ上限であり、循環参照が構成されていても必ず終了する
 * (依存グラフの形に関わらず depth 回で必ず 0 になるため)。
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
