<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/repository.php';

/**
 * Legacy mail-side kintone direct sync compatibility stubs.
 *
 * kintone直接同期は kintone.fit-sc.jp のkintone管理サイトへ移管済み。
 * このファイルは旧cron/旧CLI/旧画面から誤って呼ばれた場合にも、
 * mail_organizations の二重writerや deactivate_missing に到達させないために残している。
 * Phase 2以降で呼び出し元が完全に無いことを確認したら、ファイルごと削除してよい。
 */

function mail_kintone_retired_result(): array
{
    return [
        'inserted' => 0,
        'updated' => 0,
        'deactivated' => 0,
        'skipped' => 0,
        'invalid_email' => 0,
        'missing_representative' => 0,
        'organization_records' => 0,
        'representative_records' => 0,
        'representative_duplicates' => 0,
        'errors' => ['この同期はkintone管理サイトへ移管済みです。'],
        'retired' => true,
    ];
}

function mail_kintone_config(): array
{
    $config = mail_load_config();
    $kintone = $config['kintone'] ?? ($config['mail_kintone'] ?? []);
    return is_array($kintone) ? $kintone : [];
}

function mail_kintone_config_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int)$value === 1;
    }
    $value = strtolower(trim((string)$value));
    return in_array($value, ['1', 'true', 'yes', 'on', 'enabled', '有効'], true);
}

function mail_kintone_enabled(): bool
{
    return false;
}

function mail_kintone_default_field_map(): array
{
    return [
        'identifier' => 'id',
        'name' => 'name',
        'category' => 'rank',
        'email' => 'mail',
        'status' => 'status',
        'representative_group_id' => 'group_id',
        'representative_name' => 'representative_name',
    ];
}

function mail_kintone_field_map(): array
{
    $config = mail_kintone_config();
    $map = $config['field_map'] ?? [];
    return array_merge(mail_kintone_default_field_map(), is_array($map) ? array_map('strval', $map) : []);
}

function mail_kintone_is_configured(): array
{
    return [false, ['kintone直接同期はkintone管理サイトへ移管済みです']];
}

function mail_kintone_base_url(): string
{
    throw new RuntimeException('kintone直接同期はkintone管理サイトへ移管済みです。');
}

function mail_kintone_http_get(string $url, string $apiToken): array
{
    throw new RuntimeException('kintone直接同期はkintone管理サイトへ移管済みです。');
}

function mail_kintone_records_url(int $appId, array $fields, string $query): string
{
    throw new RuntimeException('kintone直接同期はkintone管理サイトへ移管済みです。');
}

function mail_kintone_fetch_records(int $appId, string $apiToken, array $fields, string $baseQuery = ''): array
{
    throw new RuntimeException('kintone直接同期はkintone管理サイトへ移管済みです。');
}

function mail_kintone_field_value(array $record, string $fieldCode): string
{
    if ($fieldCode === '' || !isset($record[$fieldCode]) || !is_array($record[$fieldCode])) {
        return '';
    }
    $value = $record[$fieldCode]['value'] ?? '';
    if (is_array($value)) {
        $parts = [];
        foreach ($value as $item) {
            $parts[] = is_array($item) ? (string)($item['name'] ?? $item['code'] ?? $item['value'] ?? '') : (string)$item;
        }
        return trim(implode('、', array_filter($parts, static fn(string $part): bool => $part !== '')));
    }
    return trim((string)$value);
}

function mail_kintone_configured_fields(string $key, array $defaultFields): array
{
    $config = mail_kintone_config();
    $fields = $config[$key] ?? $defaultFields;
    return is_array($fields) ? array_values(array_filter(array_map('strval', $fields), static fn(string $v): bool => trim($v) !== '')) : $defaultFields;
}

function mail_kintone_quote_query_value(string $value): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\"'], $value) . '"';
}

function mail_kintone_dropdown_in_query(string $fieldCode, string $value): string
{
    $fieldCode = trim($fieldCode);
    if ($fieldCode === '') {
        throw new InvalidArgumentException('kintoneのフィールドコードが空です。');
    }
    return $fieldCode . ' in (' . mail_kintone_quote_query_value($value) . ')';
}

function mail_kintone_default_organization_query(array $map): string
{
    return mail_kintone_dropdown_in_query((string)($map['status'] ?? 'status'), '活動中') . ' order by ' . (string)($map['identifier'] ?? 'id') . ' asc';
}

function mail_kintone_normalize_dropdown_query(string $query, string $fieldCode): string
{
    return $query;
}

function mail_kintone_ensure_schema(PDO $pdo): void
{
    // 既存テーブルの互換列作成はrepository層/DDL側に委譲する。
}

function mail_kintone_representatives_by_group_id(): array
{
    error_log('[mail kintone_client] mail_kintone_representatives_by_group_id() is retired. Use kintone.fit-sc.jp instead.');
    return ['map' => [], 'records' => 0, 'duplicates' => 0];
}

function mail_kintone_find_organization_row(PDO $pdo, string $identifier, string $externalId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM mail_organizations WHERE identifier = :identifier OR (external_source = :source AND external_id = :external_id) ORDER BY id ASC LIMIT 1'
    );
    $stmt->execute([
        ':identifier' => $identifier,
        ':source' => 'kintone',
        ':external_id' => $externalId,
    ]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function mail_kintone_sync_organizations(PDO $pdo, ?array $actor = null): array
{
    error_log('[mail kintone_client] mail_kintone_sync_organizations() is retired. Use kintone.fit-sc.jp instead.');
    try {
        mail_audit_log($pdo, $actor, 'mail.organization.kintone_sync_retired_stub', 'mail_organization', null, [
            'message' => 'kintone直接同期はkintone管理サイトへ移管済みのため実行しません。',
        ]);
    } catch (Throwable $e) {
        error_log('[mail kintone_client audit] ' . $e->getMessage());
    }
    return mail_kintone_retired_result();
}

function mail_kintone_deactivate_missing_organizations(PDO $pdo, array $seenExternalIds): int
{
    error_log('[mail kintone_client] mail_kintone_deactivate_missing_organizations() is retired. No rows deactivated.');
    return 0;
}
