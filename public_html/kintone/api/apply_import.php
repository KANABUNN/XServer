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
    $result = kintone_apply_import($batchId, $changeIds, $user);
    kintone_set_flash('success', sprintf('反映しました。新規%d件、更新%d件、mail同期%d件。', $result['created'], $result['updated'], $result['mail_synced']));
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
