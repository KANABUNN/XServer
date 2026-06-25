<?php

declare(strict_types=1);

require_once __DIR__ . '/representative_resolver.php';
require_once __DIR__ . '/activity_resolver.php';

function kintone_risk_for_change(string $field, ?string $old, ?string $new): string
{
    if ($field === 'representative_email') {
        return 'high';
    }
    if ($field === 'activity_status' && $old === 'active' && $new === 'inactive') {
        return 'high';
    }
    if (in_array($field, ['representative_name', 'organization_name', 'category'], true)) {
        return 'medium';
    }
    return 'low';
}

function kintone_change_type_for(string $field, ?string $old, ?string $new): string
{
    if ($field === 'representative_name') {
        return 'rep_change';
    }
    if ($field === 'representative_email') {
        return 'email_change';
    }
    if ($field === 'activity_status' && $old === 'active' && $new === 'inactive') {
        return 'deactivate';
    }
    if ($field === 'activity_status' && $old === 'inactive' && $new === 'active') {
        return 'reactivate';
    }
    return $old === null ? 'create' : 'update';
}

function kintone_diff_batch_rows(int $batchId): array
{
    $stmt = kintone_pdo('org')->prepare('SELECT * FROM organization_members WHERE import_batch_id = :batch_id ORDER BY organization_code, id');
    $stmt->execute([':batch_id' => $batchId]);
    $groups = [];
    foreach ($stmt->fetchAll() as $row) {
        $code = (string)($row['organization_code'] ?? '');
        if ($code === '') {
            continue;
        }
        $groups[$code][] = $row;
    }
    return $groups;
}

function kintone_fetch_existing_orgs(array $codes): array
{
    if ($codes === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $stmt = kintone_pdo('org')->prepare('SELECT * FROM organizations WHERE organization_code IN (' . $placeholders . ')');
    $stmt->execute(array_values($codes));
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[(string)$row['organization_code']] = $row;
    }
    return $result;
}


function kintone_pick_first_non_empty(array $rows, string $key): string
{
    foreach ($rows as $row) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function kintone_diff_generate_for_batch(int $batchId): array
{
    $pdo = kintone_pdo('org');
    $groups = kintone_diff_batch_rows($batchId);
    // CSVに登場したorganization_codeのみを処理する。
    // 既存団体がCSVに未登場でも、ここでは差分・無効化を生成しない。
    $existing = kintone_fetch_existing_orgs(array_keys($groups));
    $pdo->prepare('DELETE FROM organization_change_logs WHERE batch_id = :batch_id AND applied_at IS NULL')->execute([':batch_id' => $batchId]);
    $insert = $pdo->prepare('INSERT INTO organization_change_logs (organization_id, organization_code, batch_id, field_name, old_value, new_value, change_type, risk_level) VALUES (:organization_id, :organization_code, :batch_id, :field_name, :old_value, :new_value, :change_type, :risk_level)');
    $summary = ['low' => 0, 'medium' => 0, 'high' => 0, 'organizations' => count($groups), 'needs_review' => 0];
    foreach ($groups as $code => $rows) {
        $current = $existing[$code] ?? null;
        $rep = kintone_resolve_representative($rows);
        $activity = kintone_resolve_activity($rows, $rep);
        $candidate = [
            'organization_name' => kintone_pick_first_non_empty($rows, 'organization_name'),
            'normalized_organization_name' => kintone_pick_first_non_empty($rows, 'normalized_organization_name'),
            'category' => kintone_pick_first_non_empty($rows, 'category'),
            'representative_name' => $rep['status'] === 'decided' ? (string)($rep['member']['member_name'] ?? '') : null,
            'representative_email' => $rep['status'] === 'decided' ? (string)($rep['member']['member_email'] ?? '') : null,
            'rep_source' => (string)($rep['source'] ?? 'unresolved'),
            'activity_status' => (string)($activity['status'] ?? 'needs_review'),
            'activity_status_source' => (string)($activity['source'] ?? 'unresolved'),
            'member_count' => (string)count($rows),
        ];
        if (($rep['status'] ?? '') !== 'decided' || ($activity['status'] ?? '') === 'needs_review') {
            $summary['needs_review']++;
        }
        foreach ($candidate as $field => $newValue) {
            if ($newValue === null || $newValue === '') {
                continue;
            }
            $oldValue = $current[$field] ?? null;
            $oldComparable = $oldValue === null ? null : (string)$oldValue;
            if ($oldComparable === (string)$newValue) {
                continue;
            }
            $risk = kintone_risk_for_change($field, $oldComparable, (string)$newValue);
            $summary[$risk]++;
            $insert->execute([
                ':organization_id' => $current ? (int)$current['id'] : null,
                ':organization_code' => $code,
                ':batch_id' => $batchId,
                ':field_name' => $field,
                ':old_value' => $oldComparable,
                ':new_value' => (string)$newValue,
                ':change_type' => kintone_change_type_for($field, $oldComparable, (string)$newValue),
                ':risk_level' => $risk,
            ]);
        }
    }
    $status = $summary['high'] > 0 || $summary['needs_review'] > 0 ? 'needs_review' : 'validated';
    $pdo->prepare('UPDATE roster_import_batches SET status = :status, summary_json = :summary_json, updated_at = NOW() WHERE id = :id')->execute([
        ':status' => $status,
        ':summary_json' => kintone_json_encode($summary),
        ':id' => $batchId,
    ]);
    return $summary;
}

function kintone_fetch_batch_changes(int $batchId): array
{
    $stmt = kintone_pdo('org')->prepare('SELECT * FROM organization_change_logs WHERE batch_id = :batch_id ORDER BY risk_level DESC, organization_code ASC, id ASC');
    $stmt->execute([':batch_id' => $batchId]);
    return $stmt->fetchAll() ?: [];
}


function kintone_fetch_changes_by_ids(int $batchId, array $changeIds): array
{
    $changeIds = array_values(array_unique(array_filter(array_map('intval', $changeIds), static fn(int $id): bool => $id > 0)));
    if ($changeIds === []) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($changeIds), '?'));
    $stmt = kintone_pdo('org')->prepare('SELECT * FROM organization_change_logs WHERE batch_id = ? AND id IN (' . $ph . ') AND applied_at IS NULL ORDER BY risk_level DESC, organization_code ASC, id ASC');
    $stmt->execute(array_merge([$batchId], $changeIds));
    return $stmt->fetchAll() ?: [];
}

function kintone_fetch_safe_change_ids(int $batchId): array
{
    $stmt = kintone_pdo('org')->prepare('SELECT id FROM organization_change_logs WHERE batch_id = :batch_id AND applied_at IS NULL AND risk_level != "high" ORDER BY risk_level DESC, organization_code ASC, id ASC');
    $stmt->execute([':batch_id' => $batchId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function kintone_change_summary(array $changes): array
{
    $summary = [
        'high' => 0,
        'medium' => 0,
        'low' => 0,
        'email_changes' => 0,
        'active_to_inactive' => 0,
        'total' => count($changes),
    ];
    foreach ($changes as $change) {
        $risk = (string)($change['risk_level'] ?? 'low');
        if (isset($summary[$risk])) {
            $summary[$risk]++;
        }
        if ((string)($change['field_name'] ?? '') === 'representative_email') {
            $summary['email_changes']++;
        }
        if ((string)($change['field_name'] ?? '') === 'activity_status' && (string)($change['old_value'] ?? '') === 'active' && (string)($change['new_value'] ?? '') === 'inactive') {
            $summary['active_to_inactive']++;
        }
    }
    return $summary;
}

function kintone_batch_has_high_risk_selected(int $batchId, array $changeIds): bool
{
    $changes = kintone_fetch_changes_by_ids($batchId, $changeIds);
    foreach ($changes as $change) {
        if ((string)($change['risk_level'] ?? '') === 'high') {
            return true;
        }
    }
    return false;
}
