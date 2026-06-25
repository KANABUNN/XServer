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

function kintone_sync_field_map(): array
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

function kintone_sync_required_field(string $key): string
{
    $map = kintone_sync_field_map();
    $field = trim((string)($map[$key] ?? ''));
    if ($field === '') {
        throw new RuntimeException('kintoneフィールドマップが不完全です: ' . $key);
    }
    return $field;
}

function kintone_sync_optional_field(string $key): ?string
{
    $map = kintone_sync_field_map();
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

function kintone_sync_record_from_org(array $org): array
{
    $record = [];
    kintone_sync_add_record_field($record, kintone_sync_required_field('organization_code'), (string)$org['organization_code']);
    kintone_sync_add_record_field($record, kintone_sync_required_field('organization_name'), (string)$org['organization_name']);
    kintone_sync_add_record_field($record, kintone_sync_optional_field('organization_kana'), (string)($org['organization_kana'] ?? ''));
    kintone_sync_add_record_field($record, kintone_sync_optional_field('category'), (string)($org['category'] ?? ''));
    kintone_sync_add_record_field($record, kintone_sync_optional_field('representative_name'), (string)($org['representative_name'] ?? ''));
    kintone_sync_add_record_field($record, kintone_sync_optional_field('representative_email'), (string)($org['representative_email'] ?? ''));
    kintone_sync_add_record_field($record, kintone_sync_required_field('activity_status'), (string)($org['activity_status'] ?? 'unknown'));
    kintone_sync_add_record_field($record, kintone_sync_optional_field('member_count'), (int)($org['member_count'] ?? 0));
    kintone_sync_add_record_field($record, kintone_sync_optional_field('last_roster_imported_at'), kintone_sync_kintone_datetime((string)($org['last_roster_imported_at'] ?? $org['updated_at'] ?? '')));
    kintone_sync_add_record_field($record, kintone_sync_optional_field('last_sync_source'), 'xserver');
    kintone_sync_add_record_field($record, kintone_sync_optional_field('last_sync_status'), 'success');
    kintone_sync_add_record_field($record, kintone_sync_optional_field('xserver_org_id'), (int)$org['id']);
    kintone_sync_add_record_field($record, kintone_sync_optional_field('notes'), '');
    return $record;
}

function kintone_sync_safe_request_summary(int $appId, string $action, array $codes): string
{
    $summary = [
        'app' => $appId,
        'action' => $action,
        'organization_codes' => array_values(array_map('strval', $codes)),
        'field_codes' => array_values(array_filter(kintone_sync_field_map(), static fn($v): bool => trim((string)$v) !== '')),
    ];
    return kintone_json_encode($summary);
}

function kintone_sync_safe_response_summary(string $action, array $response, int $index = 0): string
{
    $payload = ['action' => $action];
    if ($action === 'add') {
        $payload['id'] = (string)($response['ids'][$index] ?? '');
        $payload['revision'] = (string)($response['revisions'][$index] ?? '');
    } else {
        $payload['id'] = (string)($response['records'][$index]['id'] ?? '');
        $payload['revision'] = (string)($response['records'][$index]['revision'] ?? '');
        $payload['operation'] = (string)($response['records'][$index]['operation'] ?? 'UPDATE');
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

function kintone_sync_create_job(PDO $pdo, int $appId, string $jobType, int $total, array $actor, array $detail = []): int
{
    $jobKey = 'ktn_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
    $stmt = $pdo->prepare(
        'INSERT INTO kintone_sync_jobs (job_key, job_type, status, target_app, total_items, started_at, detail_json, created_by_account_id) ' .
        'VALUES (:job_key, :job_type, "running", :target_app, :total_items, NOW(), :detail_json, :actor_id)'
    );
    $stmt->execute([
        ':job_key' => $jobKey,
        ':job_type' => $jobType,
        ':target_app' => (string)$appId,
        ':total_items' => $total,
        ':detail_json' => $detail !== [] ? kintone_json_encode($detail) : null,
        ':actor_id' => (int)($actor['id'] ?? 0) ?: null,
    ]);
    return (int)$pdo->lastInsertId();
}

function kintone_sync_assert_no_running_job(PDO $pdo, int $appId): void
{
    $stmt = $pdo->prepare(
        'SELECT id FROM kintone_sync_jobs WHERE target_app = :target_app AND status = "running" ' .
        'AND started_at > DATE_SUB(NOW(), INTERVAL 6 HOUR) ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([':target_app' => (string)$appId]);
    $runningId = (int)($stmt->fetchColumn() ?: 0);
    if ($runningId > 0) {
        throw new KintoneSyncException('すでに実行中のkintone同期ジョブがあります。ジョブID: ' . $runningId);
    }
}

function kintone_sync_fetch_existing_records(int $appId, array $credentials): array
{
    $codeField = kintone_sync_required_field('organization_code');
    $records = [];
    $offset = 0;
    $limit = 500;
    while (true) {
        if ($offset > 9500) {
            throw new KintoneSyncException('kintone既存レコードが多すぎるためoffset上限に近づきました。カーソルAPI方式への切替が必要です。');
        }
        $query = 'order by $id asc limit ' . $limit . ' offset ' . $offset;
        $response = kintone_rest_get_records($appId, [$codeField, '$id', '$revision'], $query, $credentials);
        $pageRecords = is_array($response['records'] ?? null) ? $response['records'] : [];
        foreach ($pageRecords as $record) {
            if (!is_array($record)) {
                continue;
            }
            $code = trim((string)($record[$codeField]['value'] ?? ''));
            if ($code === '') {
                continue;
            }
            $records[$code] = [
                'id' => (int)($record['$id']['value'] ?? 0),
                'revision' => (int)($record['$revision']['value'] ?? 0),
            ];
        }
        if (count($pageRecords) < $limit) {
            break;
        }
        $offset += $limit;
        usleep((int)kintone_config_value('kintone.api_chunk_pause_ms', 200) * 1000);
    }
    return $records;
}

function kintone_sync_insert_item(PDO $pdo, int $jobId, array $org, string $action, string $status, ?int $recordId, ?string $requestJson, ?string $responseJson, ?string $errorMessage): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO kintone_sync_job_items ' .
        '(job_id, organization_id, organization_code, action_type, status, kintone_record_id, request_json, response_json, error_message) ' .
        'VALUES (:job_id, :organization_id, :organization_code, :action_type, :status, :record_id, :request_json, :response_json, :error_message)'
    );
    $stmt->execute([
        ':job_id' => $jobId,
        ':organization_id' => (int)$org['id'],
        ':organization_code' => (string)$org['organization_code'],
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

function kintone_sync_execute_chunk(PDO $pdo, int $jobId, int $appId, array $credentials, string $action, array $chunk): array
{
    $records = [];
    $codes = [];
    foreach ($chunk as $org) {
        $codes[] = (string)$org['organization_code'];
        if ($action === 'add') {
            $records[] = kintone_sync_record_from_org($org);
        } else {
            $records[] = [
                'updateKey' => [
                    'field' => kintone_sync_required_field('organization_code'),
                    'value' => (string)$org['organization_code'],
                ],
                'record' => kintone_sync_record_from_org($org),
            ];
        }
    }
    $safeRequest = kintone_sync_safe_request_summary($appId, $action, $codes);
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
                $org,
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
            kintone_sync_insert_item($pdo, $jobId, $org, $action, 'failed', null, $safeRequest, null, $e->getMessage());
        }
        return ['success' => 0, 'failed' => count($chunk)];
    }
}

function kintone_sync_finish_job(PDO $pdo, int $jobId, int $success, int $failed, array $detail = []): void
{
    $status = $failed > 0 ? ($success > 0 ? 'partial' : 'failed') : 'success';
    $message = $failed > 0 ? '一部または全部のkintone同期に失敗しました。失敗分のみ再同期できます。' : 'kintone同期が完了しました。';
    $stmt = $pdo->prepare(
        'UPDATE kintone_sync_jobs SET status = :status, success_items = :success, failed_items = :failed, finished_at = NOW(), message = :message, detail_json = :detail_json WHERE id = :id'
    );
    $stmt->execute([
        ':status' => $status,
        ':success' => $success,
        ':failed' => $failed,
        ':message' => $message,
        ':detail_json' => $detail !== [] ? kintone_json_encode($detail) : null,
        ':id' => $jobId,
    ]);
}

function kintone_sync_mark_job_failed(PDO $pdo, int $jobId, string $message, array $detail = []): void
{
    $stmt = $pdo->prepare(
        'UPDATE kintone_sync_jobs SET status = "failed", failed_items = GREATEST(total_items, failed_items), finished_at = NOW(), message = :message, detail_json = :detail_json WHERE id = :id'
    );
    $stmt->execute([
        ':message' => mb_substr($message, 0, 1000, 'UTF-8'),
        ':detail_json' => $detail !== [] ? kintone_json_encode($detail) : null,
        ':id' => $jobId,
    ]);
}

function kintone_sync_organizations(array $organizationIds = [], array $actor = [], string $jobType = 'manual'): array
{
    $credentials = kintone_credentials_require_default();
    $appId = (int)($credentials['kintone_app_id'] ?? 0);
    if ($appId < 1) {
        throw new RuntimeException('kintoneアプリIDが未設定です。');
    }
    $pdo = kintone_pdo('org');
    kintone_sync_assert_no_running_job($pdo, $appId);

    $organizations = kintone_sync_fetch_target_organizations($organizationIds);
    if ($organizations === []) {
        throw new InvalidArgumentException('同期対象の活動中団体がありません。');
    }

    $jobId = kintone_sync_create_job($pdo, $appId, $jobType, count($organizations), $actor, [
        'mode' => $organizationIds === [] ? 'all_active' : 'selected',
        'pii_policy' => 'request_json/response_json stores summary only',
    ]);

    $success = 0;
    $failed = 0;
    $addCount = 0;
    $updateCount = 0;
    try {
        $existing = kintone_sync_fetch_existing_records($appId, $credentials);
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
                $result = kintone_sync_execute_chunk($pdo, $jobId, $appId, $credentials, $action, $chunk);
                $success += (int)$result['success'];
                $failed += (int)$result['failed'];
                usleep((int)kintone_config_value('kintone.api_chunk_pause_ms', 200) * 1000);
            }
        }
        kintone_sync_finish_job($pdo, $jobId, $success, $failed, [
            'mode' => $organizationIds === [] ? 'all_active' : 'selected',
            'planned_add' => $addCount,
            'planned_update' => $updateCount,
            'existing_records' => count($existing),
            'pii_policy' => 'no PII in request_json/response_json',
        ]);
        kintone_write_audit_log('kintone.kintone_sync.run', 'kintone_sync_job', (string)$jobId, [
            'job_id' => $jobId,
            'app_id' => $appId,
            'total' => count($organizations),
            'success' => $success,
            'failed' => $failed,
            'planned_add' => $addCount,
            'planned_update' => $updateCount,
        ], $actor);
        return ['job_id' => $jobId, 'total' => count($organizations), 'success' => $success, 'failed' => $failed, 'planned_add' => $addCount, 'planned_update' => $updateCount];
    } catch (Throwable $e) {
        kintone_sync_mark_job_failed($pdo, $jobId, $e->getMessage(), [
            'planned_add' => $addCount,
            'planned_update' => $updateCount,
            'safe_error' => true,
        ]);
        kintone_write_audit_log('kintone.kintone_sync.run_failed', 'kintone_sync_job', (string)$jobId, [
            'job_id' => $jobId,
            'error' => mb_substr($e->getMessage(), 0, 500, 'UTF-8'),
        ], $actor);
        throw $e;
    }
}

function kintone_sync_retry_failed_job(int $sourceJobId, array $actor = []): array
{
    $ids = kintone_sync_failed_organization_ids($sourceJobId);
    if ($ids === []) {
        throw new InvalidArgumentException('このジョブには再同期対象の失敗レコードがありません。');
    }
    return kintone_sync_organizations($ids, $actor, 'repair');
}
