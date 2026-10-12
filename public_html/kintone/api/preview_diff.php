<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_layout.php';
require_once dirname(__DIR__, 3) . '/apps/kintone_core/diff_service.php';
require_once dirname(__DIR__, 3) . '/apps/kintone_core/audit.php';
$user = kintone_auth_require_operator_access();
kintone_auth_require_csrf();
$batchId = (int)($_POST['batch_id'] ?? $_GET['batch_id'] ?? 0);
try {
    if ($batchId < 1) {
        throw new InvalidArgumentException('対象バッチが不正です。');
    }
    $summary = kintone_diff_generate_for_batch($batchId);
    kintone_write_audit_log('kintone.diff.preview_generated', 'roster_import_batch', (string)$batchId, $summary, $user);
    kintone_set_flash('success', '差分を再生成しました。');
} catch (InvalidArgumentException $e) {
    kintone_set_flash('error', $e->getMessage());
} catch (PDOException $e) {
    $ref = kintone_reference_id('DIF');
    error_log('[kintone preview_diff PDO][' . $ref . '] ' . $e->getMessage());
    kintone_set_flash('error', '差分生成中にエラーが発生しました（参照ID: ' . $ref . '）。');
} catch (Throwable $e) {
    $ref = kintone_reference_id('DIF');
    error_log('[kintone preview_diff][' . $ref . '] ' . $e->getMessage());
    kintone_set_flash('error', '差分生成中にエラーが発生しました（参照ID: ' . $ref . '）。');
}
header('Location: ../diff.php?batch_id=' . $batchId, true, 302);
exit;
