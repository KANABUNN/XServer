<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_layout.php';
require_once dirname(__DIR__, 3) . '/apps/kintone_core/apply_service.php';
$user = kintone_auth_require_admin_access();
kintone_auth_require_csrf();
$batchId = (int)($_POST['batch_id'] ?? 0);
$changeIds = $_POST['change_ids'] ?? [];
$ref = kintone_reference_id('APL');
try {
    if ($batchId < 1) {
        throw new InvalidArgumentException('対象バッチが不正です。');
    }
    if (!is_array($changeIds)) {
        $changeIds = [];
    }
    $changeIds = array_values(array_unique(array_filter(array_map('intval', $changeIds), static fn(int $id): bool => $id > 0)));
    if ((string)($_POST['confirm_stage'] ?? '') !== '1') {
        throw new InvalidArgumentException('確認画面を経由してから反映してください。');
    }
    if (kintone_batch_has_high_risk_selected($batchId, $changeIds) && (string)($_POST['high_risk_confirm'] ?? '') !== '1') {
        throw new InvalidArgumentException('高リスク変更を含む場合は、確認チェックが必要です。');
    }
    $result = kintone_apply_import($batchId, $changeIds, $user);

    $message = sprintf('反映しました。新規%d件、更新%d件、mail同期%d件。', $result['created'], $result['updated'], $result['mail_synced']);
    if ((int)($result['remaining_changes'] ?? 0) > 0) {
        $message .= ' 未反映の差分が' . (int)$result['remaining_changes'] . '件残っています。';
    }
    if (!empty($result['mail_sync_failed'])) {
        $message .= ' mail同期失敗: ' . implode(', ', array_map('strval', $result['mail_sync_failed'])) . '。';
        kintone_set_flash('warn', $message);
    } else {
        kintone_set_flash((int)($result['remaining_changes'] ?? 0) > 0 ? 'warn' : 'success', $message);
    }
} catch (InvalidArgumentException $e) {
    kintone_set_flash('error', $e->getMessage());
} catch (PDOException $e) {
    error_log('[kintone apply PDO][' . $ref . '] ' . $e->getMessage());
    kintone_set_flash('error', '反映中にDBエラーが発生しました（参照ID: ' . $ref . '）。');
} catch (Throwable $e) {
    error_log('[kintone apply][' . $ref . '] ' . $e->getMessage());
    kintone_set_flash('error', '反映中にエラーが発生しました（参照ID: ' . $ref . '）。');
}
header('Location: ../diff.php?batch_id=' . $batchId, true, 302);
exit;
