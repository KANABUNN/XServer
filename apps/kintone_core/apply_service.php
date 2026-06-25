<?php

declare(strict_types=1);

require_once __DIR__ . '/diff_service.php';
require_once __DIR__ . '/mail_organization_sync.php';
require_once __DIR__ . '/audit.php';

function kintone_apply_import(int $batchId, array $changeIds, array $actor): array
{
    $pdo = kintone_pdo('org');
    $changeIds = array_values(array_unique(array_map('intval', $changeIds)));
    if ($changeIds === []) {
        throw new InvalidArgumentException('反映対象が選択されていません。');
    }
    $batchStmt = $pdo->prepare('SELECT * FROM roster_import_batches WHERE id = :id FOR UPDATE');
    $pdo->beginTransaction();
    try {
        $batchStmt->execute([':id' => $batchId]);
        $batch = $batchStmt->fetch();
        if (!$batch) {
            throw new InvalidArgumentException('対象バッチが見つかりません。');
        }
        if ((string)$batch['status'] === 'applied') {
            throw new RuntimeException('このバッチは既に反映済みです。');
        }
        if (!in_array((string)$batch['status'], ['validated', 'needs_review', 'approved'], true)) {
            throw new RuntimeException('このバッチは反映可能な状態ではありません。');
        }
        $pdo->prepare('UPDATE roster_import_batches SET status = "approved", approved_by_account_id = :uid, approved_at = NOW() WHERE id = :id')->execute([
            ':uid' => (int)($actor['id'] ?? 0) ?: null,
            ':id' => $batchId,
        ]);
        $ph = implode(',', array_fill(0, count($changeIds), '?'));
        $stmt = $pdo->prepare('SELECT * FROM organization_change_logs WHERE batch_id = ? AND id IN (' . $ph . ') AND applied_at IS NULL ORDER BY organization_code, id');
        $stmt->execute(array_merge([$batchId], $changeIds));
        $changes = $stmt->fetchAll() ?: [];
        if (count($changes) !== count($changeIds)) {
            throw new RuntimeException('反映対象の一部が見つからない、または反映済みです。');
        }
        $byCode = [];
        foreach ($changes as $change) {
            $byCode[(string)$change['organization_code']][] = $change;
        }
        $result = ['created' => 0, 'updated' => 0, 'mail_synced' => 0, 'high_risk_codes' => []];
        foreach ($byCode as $code => $items) {
            $existingStmt = $pdo->prepare('SELECT * FROM organizations WHERE organization_code = :code LIMIT 1');
            $existingStmt->execute([':code' => $code]);
            $existing = $existingStmt->fetch() ?: null;
            $values = [];
            foreach ($items as $item) {
                $values[(string)$item['field_name']] = (string)$item['new_value'];
                if ((string)$item['risk_level'] === 'high') {
                    $result['high_risk_codes'][$code] = $code;
                }
            }
            if (!isset($values['organization_name'])) {
                $nameStmt = $pdo->prepare('SELECT organization_name, normalized_organization_name, category FROM organization_members WHERE import_batch_id = :batch_id AND organization_code = :code LIMIT 1');
                $nameStmt->execute([':batch_id' => $batchId, ':code' => $code]);
                $row = $nameStmt->fetch() ?: [];
                $values += [
                    'organization_name' => (string)($existing['organization_name'] ?? $row['organization_name'] ?? $code),
                    'normalized_organization_name' => (string)($existing['normalized_organization_name'] ?? $row['normalized_organization_name'] ?? $code),
                    'category' => (string)($existing['category'] ?? $row['category'] ?? ''),
                ];
            }
            $values += [
                'representative_name' => (string)($existing['representative_name'] ?? ''),
                'representative_email' => (string)($existing['representative_email'] ?? ''),
                'rep_source' => (string)($existing['rep_source'] ?? 'unresolved'),
                'activity_status' => (string)($existing['activity_status'] ?? 'unknown'),
                'activity_status_source' => (string)($existing['activity_status_source'] ?? 'unresolved'),
                'member_count' => (string)($existing['member_count'] ?? 0),
                'category' => (string)($values['category'] ?? $existing['category'] ?? ''),
            ];
            if ($existing) {
                $update = $pdo->prepare('UPDATE organizations SET organization_name=:organization_name, normalized_organization_name=:normalized_organization_name, category=:category, representative_name=:representative_name, representative_email=:representative_email, rep_source=:rep_source, activity_status=:activity_status, activity_status_source=:activity_status_source, member_count=:member_count, last_roster_import_batch_id=:batch_id, updated_at=NOW() WHERE id=:id');
                $update->execute([
                    ':organization_name' => $values['organization_name'],
                    ':normalized_organization_name' => $values['normalized_organization_name'],
                    ':category' => $values['category'],
                    ':representative_name' => $values['representative_name'],
                    ':representative_email' => $values['representative_email'],
                    ':rep_source' => $values['rep_source'],
                    ':activity_status' => $values['activity_status'],
                    ':activity_status_source' => $values['activity_status_source'],
                    ':member_count' => (int)$values['member_count'],
                    ':batch_id' => $batchId,
                    ':id' => (int)$existing['id'],
                ]);
                $orgId = (int)$existing['id'];
                $result['updated']++;
            } else {
                $insert = $pdo->prepare('INSERT INTO organizations (organization_code, organization_name, normalized_organization_name, category, representative_name, representative_email, rep_source, activity_status, activity_status_source, member_count, last_roster_import_batch_id) VALUES (:organization_code, :organization_name, :normalized_organization_name, :category, :representative_name, :representative_email, :rep_source, :activity_status, :activity_status_source, :member_count, :batch_id)');
                $insert->execute([
                    ':organization_code' => $code,
                    ':organization_name' => $values['organization_name'],
                    ':normalized_organization_name' => $values['normalized_organization_name'],
                    ':category' => $values['category'],
                    ':representative_name' => $values['representative_name'],
                    ':representative_email' => $values['representative_email'],
                    ':rep_source' => $values['rep_source'],
                    ':activity_status' => $values['activity_status'],
                    ':activity_status_source' => $values['activity_status_source'],
                    ':member_count' => (int)$values['member_count'],
                    ':batch_id' => $batchId,
                ]);
                $orgId = (int)$pdo->lastInsertId();
                $result['created']++;
            }
            $idParams = [];
            $idBinds = [];
            foreach (array_values($items) as $idx => $item) {
                $name = ':change_id_' . $idx;
                $idParams[] = $name;
                $idBinds[$name] = (int)$item['id'];
            }
            $markSql = 'UPDATE organization_change_logs SET organization_id = :org_id, applied_by_account_id = :uid, applied_at = NOW() WHERE batch_id = :batch_id AND organization_code = :code AND id IN (' . implode(',', $idParams) . ')';
            $pdo->prepare($markSql)->execute(array_merge([
                ':org_id' => $orgId,
                ':uid' => (int)($actor['id'] ?? 0) ?: null,
                ':batch_id' => $batchId,
                ':code' => $code,
            ], $idBinds));
            $orgStmt = $pdo->prepare('SELECT * FROM organizations WHERE id = :id');
            $orgStmt->execute([':id' => $orgId]);
            $org = $orgStmt->fetch();
            if ($org) {
                kintone_mail_sync_organization($org);
                $result['mail_synced']++;
            }
        }
        $pdo->prepare('UPDATE roster_import_batches SET status="applied", applied_at=NOW(), file_purge_at=DATE_ADD(NOW(), INTERVAL 14 DAY), retention_purge_at=DATE_ADD(NOW(), INTERVAL 30 DAY), updated_at=NOW() WHERE id=:id')->execute([':id' => $batchId]);
        $pdo->commit();
        $result['high_risk_codes'] = array_values($result['high_risk_codes']);
        kintone_write_audit_log('kintone.roster.apply', 'roster_import_batch', (string)$batchId, $result, $actor);
        return $result;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
