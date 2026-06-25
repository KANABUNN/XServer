<?php

declare(strict_types=1);

require_once __DIR__ . '/kintone_rest_client.php';
require_once __DIR__ . '/audit.php';

final class KintoneSyncException extends RuntimeException {}

function kintone_sync_default_field_map(): array
{
    return [
        'organization_code' => 'organization_code',
        'organization_name' => 'organization_name',
        'organization_kana' => 'organization_kana',
        'category' => 'category',
        'representative_name' => 'representative_name',
        'representative_email' => 'representative_email',
        'activity_status' => 'activity_status',
        'member_count' => 'member_count',
        'last_roster_imported_at' => 'last_roster_imported_at',
        'last_sync_source' => 'last_sync_source',
        'last_sync_status' => 'last_sync_status',
        'xserver_org_id' => 'xserver_org_id',
        'notes' => 'notes',
    ];
}

function kintone_sync_config_field_map(): array
{
    $configured = kintone_config_value('kintone.field_map', []);
    if (!is_array($configured)) {
        $configured = [];
    }
    $map = kintone_sync_default_field_map();
    foreach ($configured as $key => $fieldCode) {
        if (array_key_exists((string)$key, $map)) {
            $map[(string)$key] = trim((string)$fieldCode);
        }
    }
    return $map;
}

function kintone_sync_field_map(): array
{
    return kintone_sync_field_map_for('organizations');
}

function kintone_sync_json_object(?string $json): array
{
    $json = trim((string)$json);
    if ($json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function kintone_sync_json_list(?string $json): ?array
{
    $json = trim((string)$json);
    if ($json === '') {
        return null;
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return null;
    }
    $out = [];
    foreach ($decoded as $value) {
        if (is_scalar($value) && trim((string)$value) !== '') {
            $out[] = trim((string)$value);
        }
    }
    return array_values(array_unique($out));
}

function kintone_sync_app(string $appKey): array
{
    $appKey = kintone_normalize_app_key($appKey);
    $stmt = kintone_pdo('org')->prepare('SELECT * FROM kintone_apps WHERE app_key = :app_key AND is_active = 1 LIMIT 1');
    $stmt->execute([':app_key' => $appKey]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('kintoneアプリが登録されていません: ' . $appKey);
    }
    return $row;
}

function kintone_sync_list_apps(bool $activeOnly = true): array
{
    $sql = 'SELECT a.*, c.api_token_encrypted IS NOT NULL AND c.api_token_encrypted <> "" AS has_token ' .
        'FROM kintone_apps a LEFT JOIN kintone_credentials c ON c.connection_key = a.app_key';
    if ($activeOnly) {
        $sql .= ' WHERE a.is_active = 1';
    }
    $sql .= ' ORDER BY FIELD(a.app_key, "organizations") DESC, a.app_key ASC';
    return kintone_pdo('org')->query($sql)->fetchAll() ?: [];
}

function kintone_sync_field_map_for(string $appKey): array
{
    $app = kintone_sync_app($appKey);
    $registryMap = kintone_sync_json_object((string)($app['field_map_json'] ?? ''));
    $map = [];
    foreach ($registryMap as $key => $fieldCode) {
        if (is_scalar($fieldCode)) {
            $map[(string)$key] = trim((string)$fieldCode);
        }
    }
    if ($map !== []) {
        return $map;
    }
    if ($appKey === 'organizations') {
        return kintone_sync_config_field_map();
    }
    return [];
}

function kintone_sync_update_key_field(array $app, array $map): string
{
    $field = trim((string)($app['update_key_field'] ?? ''));
    if ($field === '' && (string)($app['app_key'] ?? '') === 'organizations') {
        $field = trim((string)($map['organization_code'] ?? ''));
    }
    if ($field === '') {
        throw new RuntimeException('update_key_field が未設定です: ' . (string)($app['app_key'] ?? ''));
    }
    return $field;
}

function kintone_sync_required_field(string $key, string $appKey = 'organizations'): string
{
    $map = kintone_sync_field_map_for($appKey);
    $field = trim((string)($map[$key] ?? ''));
    if ($field === '') {
        throw new RuntimeException('kintoneフィールドマップが不完全です: ' . $appKey . '.' . $key);
    }
    return $field;
}

function kintone_sync_optional_field(string $key, string $appKey = 'organizations'): ?string
{
    $map = kintone_sync_field_map_for($appKey);
    $field = trim((string)($map[$key] ?? ''));
    return $field !== '' ? $field : null;
}

function kintone_sync_kintone_datetime(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($value, kintone_timezone());
        return $dt->setTimezone(kintone_timezone())->format('Y-m-d\TH:i:00P');
    } catch (Throwable) {
        return null;
    }
}

function kintone_sync_add_record_field(array &$record, ?string $fieldCode, mixed $value): void
{
    if ($fieldCode === null || trim($fieldCode) === '') {
        return;
    }
    if ($value === null) {
        return;
    }
    $record[$fieldCode] = ['value' => $value];
}

function kintone_sync_record_from_org(array $org, string $appKey = 'organizations'): array
{
    $record = [];
    kintone_sync_add_record_field($record, kintone_sync_required_field('organization_code', $appKey), (string)$org['organization_code']);
    kintone_sync_add_record_field($record, kintone_sync_required_field('organization_name', $appKey), (string)$org['organization_name']);
    kintone_sync_add_record_field($record, kintone_sync_optional_field('organization_kana', $appKey), (string)($org['organization_kana'] ?? ''));
    kintone_sync_add_record_field($record, kintone_sync_optional_field('category', $appKey), (string)($org['category'] ?? ''));
    kintone_sync_add_record_field($record, kintone_sync_optional_field('representative_name', $appKey), (string)($org['representative_name'] ?? ''));
    kintone_sync_add_record_field($record, kintone_sync_optional_field('representative_email', $appKey), (string)($org['representative_email'] ?? ''));
    kintone_sync_add_record_field($record, kintone_sync_required_field('activity_status', $appKey), (string)($org['activity_status'] ?? 'unknown'));
    kintone_sync_add_record_field($record, kintone_sync_optional_field('member_count', $appKey), (int)($org['member_count'] ?? 0));
    kintone_sync_add_record_field($record, kintone_sync_optional_field('last_roster_imported_at', $appKey), kintone_sync_kintone_datetime((string)($org['last_roster_imported_at'] ?? $org['updated_at'] ?? '')));
    kintone_sync_add_record_field($record, kintone_sync_optional_field('last_sync_source', $appKey), 'xserver');
    kintone_sync_add_record_field($record, kintone_sync_optional_field('last_sync_status', $appKey), 'success');
    kintone_sync_add_record_field($record, kintone_sync_optional_field('xserver_org_id', $appKey), (int)$org['id']);
    kintone_sync_add_record_field($record, kintone_sync_optional_field('notes', $appKey), '');
    return $record;
}

function kintone_sync_safe_request_summary(string $appKey, int $appId, string $action, array $keys, array $fieldCodes): string
{
    return kintone_json_encode([
        'app_key' => $appKey,
        'app' => $appId,
        'action' => $action,
        'record_keys' => array_values(array_map('strval', $keys)),
        'field_codes' => array_values(array_filter($fieldCodes, static fn($v): bool => trim((string)$v) !== '')),
        'pii_policy' => 'summary only; no raw field values',
    ]);
}

function kintone_sync_safe_response_summary(string $action, array $response, int $index = 0): string
{
    $payload = ['action' => $action];
    if ($action === 'add') {
        $payload['id'] = (string)($response['ids'][$index] ?? '');
        $payload['revision'] = (string)($response['revisions'][$index] ?? '');
    } elseif (isset($response['records'][$index])) {
        $payload['id'] = (string)($response['records'][$index]['id'] ?? '');
        $payload['revision'] = (string)($response['records'][$index]['revision'] ?? '');
        $payload['operation'] = (string)($response['records'][$index]['operation'] ?? 'UPDATE');
    } else {
        $payload['count'] = (int)($response['count'] ?? 0);
    }
    return kintone_json_encode($payload);
}

function kintone_sync_normalize_ids(array $ids): array
{
    $out = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $out[$id] = $id;
        }
    }
    return array_values($out);
}

function kintone_sync_fetch_target_organizations(array $organizationIds = []): array
{
    $pdo = kintone_pdo('org');
    $select = 'SELECT o.*, COALESCE(b.applied_at, b.created_at, o.updated_at) AS last_roster_imported_at ' .
        'FROM organizations o LEFT JOIN roster_import_batches b ON b.id = o.last_roster_import_batch_id ' .
        'WHERE o.is_active = 1';
    $params = [];
    $ids = kintone_sync_normalize_ids($organizationIds);
    if ($ids !== []) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $select .= ' AND o.id IN (' . $placeholders . ')';
        $params = $ids;
    }
    $select .= ' ORDER BY o.organization_code ASC, o.id ASC';
    $stmt = $pdo->prepare($select);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];
    $valid = [];
    foreach ($rows as $row) {
        if (trim((string)($row['organization_code'] ?? '')) !== '' && trim((string)($row['organization_name'] ?? '')) !== '') {
            $valid[] = $row;
        }
    }
    return $valid;
}

function kintone_sync_failed_organization_ids(int $jobId): array
{
    if ($jobId < 1) {
        throw new InvalidArgumentException('再同期対象ジョブが不正です。');
    }
    $stmt = kintone_pdo('org')->prepare(
        'SELECT DISTINCT organization_id FROM kintone_sync_job_items ' .
        'WHERE job_id = :job_id AND status = "failed" AND organization_id IS NOT NULL ORDER BY organization_id ASC'
    );
    $stmt->execute([':job_id' => $jobId]);
    return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
}

function kintone_sync_create_job(PDO $pdo, string $appKey, string $jobType, int $total, array $actor, array $detail = []): int
{
    $jobKey = 'ktn_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
    $stmt = $pdo->prepare(
        'INSERT INTO kintone_sync_jobs (job_key, job_type, status, target_app, total_items, started_at, detail_json, created_by_account_id) ' .
        'VALUES (:job_key, :job_type, "running", :target_app, :total_items, NOW(), :detail_json, :actor_id)'
    );
    $stmt->execute([
        ':job_key' => $jobKey,
        ':job_type' => $jobType,
        ':target_app' => $appKey,
        ':total_items' => $total,
        ':detail_json' => $detail !== [] ? kintone_json_encode($detail) : null,
        ':actor_id' => (int)($actor['id'] ?? 0) ?: null,
    ]);
    return (int)$pdo->lastInsertId();
}

function kintone_sync_assert_no_running_job(PDO $pdo, string $appKey): void
{
    $stmt = $pdo->prepare(
        'SELECT id FROM kintone_sync_jobs WHERE target_app = :target_app AND status = "running" ' .
        'AND started_at > DATE_SUB(NOW(), INTERVAL 6 HOUR) ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([':target_app' => $appKey]);
    $runningId = (int)($stmt->fetchColumn() ?: 0);
    if ($runningId > 0) {
        throw new KintoneSyncException('すでに実行中のkintone同期ジョブがあります。ジョブID: ' . $runningId);
    }
}

function kintone_sync_fetch_existing_records(string $appKey, int $appId, array $credentials, string $updateKeyField): array
{
    $records = [];
    kintone_rest_iterate_records($appId, [$updateKeyField, '$id', '$revision'], 'order by $id asc', function (array $pageRecords) use (&$records, $updateKeyField): void {
        foreach ($pageRecords as $record) {
            if (!is_array($record)) {
                continue;
            }
            $key = trim((string)($record[$updateKeyField]['value'] ?? ''));
            if ($key === '') {
                continue;
            }
            $records[$key] = [
                'id' => (int)($record['$id']['value'] ?? 0),
                'revision' => (int)($record['$revision']['value'] ?? 0),
            ];
        }
    }, $credentials, true);
    return $records;
}

function kintone_sync_insert_item(PDO $pdo, int $jobId, string $appKey, ?array $org, ?string $recordKey, string $action, string $status, ?int $recordId, ?string $requestJson, ?string $responseJson, ?string $errorMessage): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO kintone_sync_job_items ' .
        '(job_id, app_key, organization_id, organization_code, record_key, action_type, status, kintone_record_id, request_json, response_json, error_message) ' .
        'VALUES (:job_id, :app_key, :organization_id, :organization_code, :record_key, :action_type, :status, :record_id, :request_json, :response_json, :error_message)'
    );
    $stmt->execute([
        ':job_id' => $jobId,
        ':app_key' => $appKey,
        ':organization_id' => is_array($org) ? ((int)$org['id'] ?: null) : null,
        ':organization_code' => is_array($org) ? (string)($org['organization_code'] ?? '') : null,
        ':record_key' => $recordKey,
        ':action_type' => $action,
        ':status' => $status,
        ':record_id' => $recordId,
        ':request_json' => $requestJson,
        ':response_json' => $responseJson,
        ':error_message' => $errorMessage !== null ? mb_substr($errorMessage, 0, 1000, 'UTF-8') : null,
    ]);
}

function kintone_sync_update_local_success(PDO $pdo, array $org, int $appId, int $recordId, int $revision): void
{
    $stmt = $pdo->prepare(
        'UPDATE organizations SET kintone_app_id = :app_id, kintone_record_id = :record_id, kintone_revision = :revision, last_synced_at = NOW() WHERE id = :id'
    );
    $stmt->execute([
        ':app_id' => $appId,
        ':record_id' => $recordId > 0 ? $recordId : null,
        ':revision' => $revision > 0 ? $revision : null,
        ':id' => (int)$org['id'],
    ]);
}

function kintone_sync_execute_push_chunk(PDO $pdo, int $jobId, string $appKey, int $appId, array $credentials, string $action, array $chunk, string $updateKeyField, array $fieldMap): array
{
    $records = [];
    $keys = [];
    foreach ($chunk as $org) {
        $keys[] = (string)$org['organization_code'];
        if ($action === 'add') {
            $records[] = kintone_sync_record_from_org($org, $appKey);
        } else {
            $records[] = [
                'updateKey' => [
                    'field' => $updateKeyField,
                    'value' => (string)$org['organization_code'],
                ],
                'record' => kintone_sync_record_from_org($org, $appKey),
            ];
        }
    }
    $safeRequest = kintone_sync_safe_request_summary($appKey, $appId, $action, $keys, array_values($fieldMap));
    try {
        $response = $action === 'add'
            ? kintone_rest_add_records($appId, $records, $credentials)
            : kintone_rest_update_records($appId, $records, $credentials);
        $success = 0;
        foreach (array_values($chunk) as $index => $org) {
            $recordId = $action === 'add'
                ? (int)($response['ids'][$index] ?? 0)
                : (int)($response['records'][$index]['id'] ?? 0);
            $revision = $action === 'add'
                ? (int)($response['revisions'][$index] ?? 0)
                : (int)($response['records'][$index]['revision'] ?? 0);
            kintone_sync_update_local_success($pdo, $org, $appId, $recordId, $revision);
            kintone_sync_insert_item(
                $pdo,
                $jobId,
                $appKey,
                $org,
                (string)$org['organization_code'],
                $action,
                'success',
                $recordId > 0 ? $recordId : null,
                $safeRequest,
                kintone_sync_safe_response_summary($action, $response, $index),
                null
            );
            $success++;
        }
        return ['success' => $success, 'failed' => 0];
    } catch (Throwable $e) {
        foreach ($chunk as $org) {
            kintone_sync_insert_item($pdo, $jobId, $appKey, $org, (string)$org['organization_code'], $action, 'failed', null, $safeRequest, null, $e->getMessage());
        }
        return ['success' => 0, 'failed' => count($chunk)];
    }
}

function kintone_sync_finish_job(PDO $pdo, int $jobId, string $appKey, int $success, int $failed, array $detail = []): void
{
    $status = $failed > 0 ? ($success > 0 ? 'partial' : 'failed') : 'success';
    $message = $failed > 0 ? '一部または全部のkintone同期に失敗しました。失敗分のみ再同期できます。' : 'kintone同期が完了しました。';
    $stmt = $pdo->prepare(
        'UPDATE kintone_sync_jobs SET status = :status, total_items = GREATEST(total_items, :total), success_items = :success, failed_items = :failed, finished_at = NOW(), message = :message, detail_json = :detail_json WHERE id = :id'
    );
    $stmt->execute([
        ':status' => $status,
        ':total' => $success + $failed,
        ':success' => $success,
        ':failed' => $failed,
        ':message' => $message,
        ':detail_json' => $detail !== [] ? kintone_json_encode($detail) : null,
        ':id' => $jobId,
    ]);
    $pdo->prepare('UPDATE kintone_apps SET last_synced_at = NOW(), last_sync_status = :status, status = CASE WHEN :status2 = "failed" THEN "error" ELSE "connected" END, last_error = :last_error WHERE app_key = :app_key')->execute([
        ':status' => $status,
        ':status2' => $status,
        ':last_error' => $failed > 0 ? $message : null,
        ':app_key' => $appKey,
    ]);
}

function kintone_sync_mark_job_failed(PDO $pdo, int $jobId, string $appKey, string $message, array $detail = []): void
{
    $stmt = $pdo->prepare(
        'UPDATE kintone_sync_jobs SET status = "failed", failed_items = GREATEST(total_items, failed_items), finished_at = NOW(), message = :message, detail_json = :detail_json WHERE id = :id'
    );
    $stmt->execute([
        ':message' => mb_substr($message, 0, 1000, 'UTF-8'),
        ':detail_json' => $detail !== [] ? kintone_json_encode($detail) : null,
        ':id' => $jobId,
    ]);
    $pdo->prepare('UPDATE kintone_apps SET status = "error", last_sync_status = "failed", last_error = :error WHERE app_key = :app_key')->execute([
        ':error' => mb_substr($message, 0, 1000, 'UTF-8'),
        ':app_key' => $appKey,
    ]);
}

function kintone_sync_push_app(string $appKey = 'organizations', array $organizationIds = [], array $actor = [], string $jobType = 'manual'): array
{
    $appKey = kintone_normalize_app_key($appKey);
    $app = kintone_sync_app($appKey);
    if ((string)$app['app_role'] !== 'push') {
        throw new InvalidArgumentException('push同期対象ではありません: ' . $appKey);
    }
    if ($appKey !== 'organizations') {
        throw new KintoneSyncException('現時点のpush同期は団体マスタ organizations のみ実装済みです。');
    }
    $credentials = kintone_credentials_for($appKey);
    $appId = (int)($app['kintone_app_id'] ?? $credentials['kintone_app_id'] ?? 0);
    if ($appId < 1) {
        throw new RuntimeException('kintoneアプリIDが未設定です。');
    }
    $fieldMap = kintone_sync_field_map_for($appKey);
    $updateKeyField = kintone_sync_update_key_field($app, $fieldMap);

    $pdo = kintone_pdo('org');
    kintone_sync_assert_no_running_job($pdo, $appKey);
    $organizations = kintone_sync_fetch_target_organizations($organizationIds);
    if ($organizations === []) {
        throw new InvalidArgumentException('同期対象の活動中団体がありません。');
    }

    $jobId = kintone_sync_create_job($pdo, $appKey, $jobType, count($organizations), $actor, [
        'app_key' => $appKey,
        'app_role' => 'push',
        'mode' => $organizationIds === [] ? 'all_active' : 'selected',
        'pii_policy' => 'request_json/response_json stores summary only',
    ]);

    $success = 0;
    $failed = 0;
    $addCount = 0;
    $updateCount = 0;
    try {
        $existing = kintone_sync_fetch_existing_records($appKey, $appId, $credentials, $updateKeyField);
        $add = [];
        $update = [];
        foreach ($organizations as $org) {
            $code = (string)$org['organization_code'];
            if (isset($existing[$code])) {
                $update[] = $org;
            } else {
                $add[] = $org;
            }
        }
        $addCount = count($add);
        $updateCount = count($update);
        foreach ([['add', $add], ['update', $update]] as [$action, $rows]) {
            foreach (array_chunk($rows, 100) as $chunk) {
                if ($chunk === []) {
                    continue;
                }
                $result = kintone_sync_execute_push_chunk($pdo, $jobId, $appKey, $appId, $credentials, $action, $chunk, $updateKeyField, $fieldMap);
                $success += (int)$result['success'];
                $failed += (int)$result['failed'];
                usleep((int)kintone_config_value('kintone.api_chunk_pause_ms', 200) * 1000);
            }
        }
        kintone_sync_finish_job($pdo, $jobId, $appKey, $success, $failed, [
            'app_key' => $appKey,
            'app_role' => 'push',
            'mode' => $organizationIds === [] ? 'all_active' : 'selected',
            'planned_add' => $addCount,
            'planned_update' => $updateCount,
            'existing_records' => count($existing),
            'page_mode' => 'cursor',
            'pii_policy' => 'no PII in request_json/response_json',
        ]);
        kintone_write_audit_log('kintone.kintone_sync.run', 'kintone_sync_job', (string)$jobId, [
            'job_id' => $jobId,
            'app_key' => $appKey,
            'app_id' => $appId,
            'total' => count($organizations),
            'success' => $success,
            'failed' => $failed,
            'planned_add' => $addCount,
            'planned_update' => $updateCount,
        ], $actor);
        return ['job_id' => $jobId, 'app_key' => $appKey, 'total' => count($organizations), 'success' => $success, 'failed' => $failed, 'planned_add' => $addCount, 'planned_update' => $updateCount];
    } catch (Throwable $e) {
        kintone_sync_mark_job_failed($pdo, $jobId, $appKey, $e->getMessage(), ['safe_error' => true, 'planned_add' => $addCount, 'planned_update' => $updateCount]);
        kintone_write_audit_log('kintone.kintone_sync.run_failed', 'kintone_sync_job', (string)$jobId, [
            'job_id' => $jobId,
            'app_key' => $appKey,
            'error' => mb_substr($e->getMessage(), 0, 500, 'UTF-8'),
        ], $actor);
        throw $e;
    }
}

function kintone_sync_extract_field_value(mixed $fieldPayload): mixed
{
    if (is_array($fieldPayload) && array_key_exists('value', $fieldPayload)) {
        return $fieldPayload['value'];
    }
    return null;
}

function kintone_sync_record_key_from_kintone_record(array $record, string $updateKeyField): ?string
{
    $value = kintone_sync_extract_field_value($record[$updateKeyField] ?? null);
    if (is_scalar($value)) {
        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }
    return null;
}

function kintone_sync_pull_allowed_fields(array $app): ?array
{
    $allowlist = kintone_sync_json_list((string)($app['cache_field_allowlist_json'] ?? ''));
    $containsPii = (int)($app['contains_pii'] ?? 0) === 1;
    if ($containsPii && ($allowlist === null || $allowlist === [])) {
        throw new KintoneSyncException('PIIを含むpullアプリは cache_field_allowlist_json が未設定の場合、取り込みできません。');
    }
    return $allowlist;
}

function kintone_sync_cache_json_from_record(array $record, ?array $allowlist): array
{
    $fields = $allowlist ?? array_keys($record);
    $out = [];
    foreach ($fields as $fieldCode) {
        $fieldCode = trim((string)$fieldCode);
        if ($fieldCode === '' || str_starts_with($fieldCode, '$')) {
            continue;
        }
        if (!array_key_exists($fieldCode, $record)) {
            continue;
        }
        $out[$fieldCode] = kintone_sync_extract_field_value($record[$fieldCode]);
    }
    return $out;
}

function kintone_sync_upsert_app_record(PDO $pdo, string $appKey, array $record, string $updateKeyField, ?array $allowlist): array
{
    $recordId = (int)($record['$id']['value'] ?? 0);
    if ($recordId < 1) {
        throw new RuntimeException('kintoneレコードIDを取得できませんでした。');
    }
    $revision = (int)($record['$revision']['value'] ?? 0);
    $recordKey = kintone_sync_record_key_from_kintone_record($record, $updateKeyField);
    $safeRecord = kintone_sync_cache_json_from_record($record, $allowlist);
    $stmt = $pdo->prepare(
        'INSERT INTO kintone_app_records (app_key, kintone_record_id, record_key, record_json, kintone_revision, synced_at) ' .
        'VALUES (:app_key, :record_id, :record_key, :record_json, :revision, NOW()) ' .
        'ON DUPLICATE KEY UPDATE record_key = VALUES(record_key), record_json = VALUES(record_json), kintone_revision = VALUES(kintone_revision), synced_at = NOW()'
    );
    $stmt->execute([
        ':app_key' => $appKey,
        ':record_id' => $recordId,
        ':record_key' => $recordKey,
        ':record_json' => kintone_json_encode($safeRecord),
        ':revision' => $revision > 0 ? $revision : null,
    ]);
    return ['record_id' => $recordId, 'revision' => $revision, 'record_key' => $recordKey];
}

function kintone_sync_pull_app(string $appKey, array $actor = [], string $jobType = 'manual', string $query = ''): array
{
    $appKey = kintone_normalize_app_key($appKey);
    $app = kintone_sync_app($appKey);
    if ((string)$app['app_role'] !== 'pull') {
        throw new InvalidArgumentException('pull同期対象ではありません: ' . $appKey);
    }
    $credentials = kintone_credentials_for($appKey);
    $appId = (int)($app['kintone_app_id'] ?? $credentials['kintone_app_id'] ?? 0);
    if ($appId < 1) {
        throw new RuntimeException('kintoneアプリIDが未設定です。');
    }
    $updateKeyField = kintone_sync_update_key_field($app, []);
    $allowlist = kintone_sync_pull_allowed_fields($app);
    $fields = $allowlist ?? [];
    foreach ([$updateKeyField, '$id', '$revision'] as $required) {
        if (!in_array($required, $fields, true)) {
            $fields[] = $required;
        }
    }
    $query = trim($query !== '' ? $query : 'order by $id asc');

    $pdo = kintone_pdo('org');
    kintone_sync_assert_no_running_job($pdo, $appKey);
    $jobId = kintone_sync_create_job($pdo, $appKey, $jobType, 0, $actor, [
        'app_key' => $appKey,
        'app_role' => 'pull',
        'contains_pii' => (int)$app['contains_pii'],
        'allowlist_count' => $allowlist === null ? null : count($allowlist),
        'pii_policy' => 'record_json stores only allowlisted field values',
    ]);

    $success = 0;
    $failed = 0;
    $chunks = 0;
    $pageMode = 'cursor';
    try {
        $result = kintone_rest_iterate_records($appId, $fields, $query, function (array $pageRecords) use ($pdo, $jobId, $appKey, $appId, $updateKeyField, $allowlist, &$success, &$failed, &$chunks): void {
            $chunks++;
            $safeRequest = kintone_sync_safe_request_summary($appKey, $appId, 'pull', ['chunk_' . $chunks], $allowlist ?? ['ALL_NON_PII_FIELDS']);
            foreach ($pageRecords as $record) {
                if (!is_array($record)) {
                    continue;
                }
                try {
                    $result = kintone_sync_upsert_app_record($pdo, $appKey, $record, $updateKeyField, $allowlist);
                    kintone_sync_insert_item(
                        $pdo,
                        $jobId,
                        $appKey,
                        null,
                        $result['record_key'],
                        'upsert',
                        'success',
                        (int)$result['record_id'],
                        $safeRequest,
                        kintone_json_encode(['action' => 'pull_upsert', 'id' => (string)$result['record_id'], 'revision' => (string)$result['revision']]),
                        null
                    );
                    $success++;
                } catch (Throwable $e) {
                    $recordId = (int)($record['$id']['value'] ?? 0) ?: null;
                    $recordKey = kintone_sync_record_key_from_kintone_record($record, $updateKeyField);
                    kintone_sync_insert_item($pdo, $jobId, $appKey, null, $recordKey, 'upsert', 'failed', $recordId, $safeRequest, null, $e->getMessage());
                    $failed++;
                }
            }
        }, $credentials, true);
        $pageMode = (string)($result['mode'] ?? 'cursor');
        kintone_sync_finish_job($pdo, $jobId, $appKey, $success, $failed, [
            'app_key' => $appKey,
            'app_role' => 'pull',
            'page_mode' => $pageMode,
            'chunks' => $chunks,
            'contains_pii' => (int)$app['contains_pii'],
            'allowlist_count' => $allowlist === null ? null : count($allowlist),
            'pii_policy' => 'cache_field_allowlist_json applied before record_json write',
        ]);
        kintone_write_audit_log('kintone.kintone_sync.run', 'kintone_sync_job', (string)$jobId, [
            'job_id' => $jobId,
            'app_key' => $appKey,
            'app_id' => $appId,
            'role' => 'pull',
            'success' => $success,
            'failed' => $failed,
            'page_mode' => $pageMode,
        ], $actor);
        return ['job_id' => $jobId, 'app_key' => $appKey, 'total' => $success + $failed, 'success' => $success, 'failed' => $failed, 'page_mode' => $pageMode];
    } catch (Throwable $e) {
        kintone_sync_mark_job_failed($pdo, $jobId, $appKey, $e->getMessage(), ['safe_error' => true, 'page_mode' => $pageMode]);
        kintone_write_audit_log('kintone.kintone_sync.run_failed', 'kintone_sync_job', (string)$jobId, [
            'job_id' => $jobId,
            'app_key' => $appKey,
            'error' => mb_substr($e->getMessage(), 0, 500, 'UTF-8'),
        ], $actor);
        throw $e;
    }
}

function kintone_sync_app_by_role(string $appKey, array $organizationIds = [], array $actor = [], string $jobType = 'manual'): array
{
    $app = kintone_sync_app($appKey);
    return match ((string)$app['app_role']) {
        'push' => kintone_sync_push_app($appKey, $organizationIds, $actor, $jobType),
        'pull' => kintone_sync_pull_app($appKey, $actor, $jobType),
        default => throw new InvalidArgumentException('manualアプリは自動同期対象ではありません: ' . $appKey),
    };
}

function kintone_sync_organizations(array $organizationIds = [], array $actor = [], string $jobType = 'manual'): array
{
    return kintone_sync_push_app('organizations', $organizationIds, $actor, $jobType);
}

function kintone_sync_retry_failed_job(int $sourceJobId, array $actor = []): array
{
    if ($sourceJobId < 1) {
        throw new InvalidArgumentException('再同期対象ジョブが不正です。');
    }
    $stmt = kintone_pdo('org')->prepare('SELECT * FROM kintone_sync_jobs WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $sourceJobId]);
    $job = $stmt->fetch();
    if (!is_array($job)) {
        throw new InvalidArgumentException('再同期元ジョブが見つかりません。');
    }
    $appKey = kintone_normalize_app_key((string)$job['target_app']);
    $app = kintone_sync_app($appKey);
    if ((string)$app['app_role'] === 'push') {
        $ids = kintone_sync_failed_organization_ids($sourceJobId);
        if ($ids === []) {
            throw new InvalidArgumentException('このジョブには再同期対象の失敗団体がありません。');
        }
        return kintone_sync_push_app($appKey, $ids, $actor, 'repair');
    }
    if ((string)$app['app_role'] === 'pull') {
        return kintone_sync_pull_app($appKey, $actor, 'repair');
    }
    throw new InvalidArgumentException('manualアプリは再同期できません。');
}
