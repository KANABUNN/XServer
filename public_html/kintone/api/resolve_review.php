<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_layout.php';
require_once dirname(__DIR__, 3) . '/apps/kintone_core/diff_service.php';
require_once dirname(__DIR__, 3) . '/apps/kintone_core/audit.php';
$user = kintone_auth_require_operator_access();
kintone_auth_require_csrf();
$ref = kintone_reference_id('REV');
$batchId = (int)($_POST['batch_id'] ?? 0);
$changeId = (int)($_POST['change_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
try {
    if (!in_array($action, ['resolve', 'exclude'], true)) {
        http_response_code(405);
        throw new InvalidArgumentException('未対応のレビュー操作です。');
    }
    if ($changeId < 1) {
        throw new InvalidArgumentException('対象の差分が不正です。');
    }
    $pdo = kintone_pdo('org');
    $stmt = $pdo->prepare('SELECT * FROM organization_change_logs WHERE id = :id AND applied_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $changeId]);
    $change = $stmt->fetch();
    if (!$change) {
        throw new InvalidArgumentException('対象の差分が見つからない、または反映済みです。');
    }
    $batchId = (int)($change['batch_id'] ?? $batchId);
    if ($action === 'exclude') {
        $delete = $pdo->prepare('DELETE FROM organization_change_logs WHERE id = :id AND applied_at IS NULL');
        $delete->execute([':id' => $changeId]);
        kintone_write_audit_log('kintone.review.exclude', 'organization_change_log', (string)$changeId, [
            'organization_code' => (string)($change['organization_code'] ?? ''),
            'field_name' => (string)($change['field_name'] ?? ''),
            'old_value' => (string)($change['old_value'] ?? ''),
            'new_value' => (string)($change['new_value'] ?? ''),
        ], $user);
        kintone_set_flash('success', 'レビュー対象の差分を除外しました。');
    } else {
        $field = (string)($change['field_name'] ?? '');
        $newValue = trim((string)($_POST['new_value'] ?? ''));
        if ($field === 'representative_email') {
            $newValue = kintone_normalize_email($newValue);
            if ($newValue === '' || !filter_var($newValue, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('代表者メールアドレスの形式が不正です。');
            }
        } elseif ($field === 'representative_name') {
            $newValue = kintone_normalize_member_name($newValue);
            if ($newValue === '') {
                throw new InvalidArgumentException('代表者氏名を入力してください。');
            }
        } elseif ($field === 'activity_status') {
            if (!in_array($newValue, ['active', 'inactive', 'needs_review', 'unknown'], true)) {
                throw new InvalidArgumentException('活動可否の値が不正です。');
            }
        } elseif (in_array($field, ['organization_name', 'category'], true)) {
            $newValue = mb_substr(kintone_normalize_width($newValue), 0, $field === 'category' ? 100 : 255, 'UTF-8');
            if ($newValue === '') {
                throw new InvalidArgumentException('新しい値を入力してください。');
            }
        } else {
            throw new InvalidArgumentException('この項目はレビュー画面から手動確定できません。');
        }
        $oldValue = $change['old_value'] === null ? null : (string)$change['old_value'];
        $risk = kintone_risk_for_change($field, $oldValue, $newValue);
        $changeType = kintone_change_type_for($field, $oldValue, $newValue);
        $update = $pdo->prepare('UPDATE organization_change_logs SET new_value = :new_value, risk_level = :risk_level, change_type = :change_type WHERE id = :id AND applied_at IS NULL');
        $update->execute([
            ':new_value' => $newValue,
            ':risk_level' => $risk,
            ':change_type' => $changeType,
            ':id' => $changeId,
        ]);
        $auditAction = $field === 'activity_status' ? 'kintone.org.activity_changed' : (str_starts_with($field, 'representative_') ? 'kintone.org.rep_changed' : 'kintone.review.resolve');
        kintone_write_audit_log($auditAction, 'organization_change_log', (string)$changeId, [
            'organization_code' => (string)($change['organization_code'] ?? ''),
            'field_name' => $field,
            'before_new_value' => (string)($change['new_value'] ?? ''),
            'after_new_value' => $newValue,
            'risk_level' => $risk,
        ], $user);
        kintone_set_flash('success', 'レビュー値を更新しました。');
    }
} catch (InvalidArgumentException $e) {
    kintone_set_flash('error', $e->getMessage());
} catch (PDOException $e) {
    error_log('[kintone review PDO][' . $ref . '] ' . $e->getMessage());
    kintone_set_flash('error', 'レビュー処理中にDBエラーが発生しました（参照ID: ' . $ref . '）。');
} catch (Throwable $e) {
    error_log('[kintone review][' . $ref . '] ' . $e->getMessage());
    kintone_set_flash('error', 'レビュー処理中にエラーが発生しました（参照ID: ' . $ref . '）。');
}
header('Location: ../review.php' . ($batchId > 0 ? '?batch_id=' . $batchId : ''), true, 302);
exit;
