<?php

declare(strict_types=1);

require_once __DIR__ . '/kintone_rest_client.php';

function kintone_sync_organizations(array $organizationIds, array $actor = []): array
{
    // Phase 2用の最小実装。組織正本の更新とは分離し、失敗分はジョブアイテムに記録する。
    if ($organizationIds === []) {
        throw new InvalidArgumentException('同期対象が選択されていません。');
    }
    $credentials = kintone_credentials_default();
    if (!is_array($credentials)) {
        throw new RuntimeException('kintone接続情報がありません。');
    }
    $appId = (int)($credentials['kintone_app_id'] ?? 0);
    if ($appId < 1) {
        throw new RuntimeException('kintoneアプリIDが未設定です。');
    }
    $pdo = kintone_pdo('org');
    $jobKey = 'ktn_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO kintone_sync_jobs (job_key, job_type, status, target_app, total_items, created_by_account_id, started_at) VALUES (:job_key, "manual", "running", :target_app, :total, :uid, NOW())')->execute([
        ':job_key' => $jobKey,
        ':target_app' => (string)$appId,
        ':total' => count($organizationIds),
        ':uid' => (int)($actor['id'] ?? 0) ?: null,
    ]);
    $jobId = (int)$pdo->lastInsertId();
    $ph = implode(',', array_fill(0, count($organizationIds), '?'));
    $stmt = $pdo->prepare('SELECT * FROM organizations WHERE id IN (' . $ph . ') ORDER BY id ASC');
    $stmt->execute(array_values($organizationIds));
    $success = 0;
    $failed = 0;
    foreach (array_chunk($stmt->fetchAll() ?: [], 100) as $chunk) {
        foreach ($chunk as $org) {
            $request = [
                'app' => $appId,
                'updateKey' => ['field' => 'organization_code', 'value' => (string)$org['organization_code']],
                'record' => [
                    'organization_name' => ['value' => (string)$org['organization_name']],
                    'category' => ['value' => (string)($org['category'] ?? '')],
                    'representative_name' => ['value' => (string)($org['representative_name'] ?? '')],
                    'representative_email' => ['value' => (string)($org['representative_email'] ?? '')],
                    'activity_status' => ['value' => (string)($org['activity_status'] ?? 'unknown')],
                    'member_count' => ['value' => (int)($org['member_count'] ?? 0)],
                    'last_sync_source' => ['value' => 'xserver'],
                    'last_sync_status' => ['value' => 'success'],
                    'xserver_org_id' => ['value' => (int)$org['id']],
                ],
            ];
            try {
                $response = kintone_rest_request('PUT', '/k/v1/record.json', $request, $credentials);
                $success++;
                $pdo->prepare('INSERT INTO kintone_sync_job_items (job_id, organization_id, organization_code, action_type, status, kintone_record_id, request_json, response_json) VALUES (:job_id,:organization_id,:organization_code,"upsert","success",:record_id,:request_json,:response_json)')->execute([
                    ':job_id' => $jobId,
                    ':organization_id' => (int)$org['id'],
                    ':organization_code' => (string)$org['organization_code'],
                    ':record_id' => (int)($response['id'] ?? 0) ?: null,
                    ':request_json' => kintone_json_encode($request),
                    ':response_json' => kintone_json_encode($response),
                ]);
            } catch (Throwable $e) {
                $failed++;
                $pdo->prepare('INSERT INTO kintone_sync_job_items (job_id, organization_id, organization_code, action_type, status, request_json, error_message) VALUES (:job_id,:organization_id,:organization_code,"upsert","failed",:request_json,:error_message)')->execute([
                    ':job_id' => $jobId,
                    ':organization_id' => (int)$org['id'],
                    ':organization_code' => (string)$org['organization_code'],
                    ':request_json' => kintone_json_encode($request),
                    ':error_message' => mb_substr($e->getMessage(), 0, 1000, 'UTF-8'),
                ]);
            }
        }
    }
    $pdo->prepare('UPDATE kintone_sync_jobs SET status=:status, success_items=:success, failed_items=:failed, finished_at=NOW() WHERE id=:id')->execute([
        ':status' => $failed > 0 ? ($success > 0 ? 'partial' : 'failed') : 'success',
        ':success' => $success,
        ':failed' => $failed,
        ':id' => $jobId,
    ]);
    return ['job_id' => $jobId, 'success' => $success, 'failed' => $failed];
}
