<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_layout.php';
require_once dirname(__DIR__, 3) . '/apps/kintone_core/kintone_sync_service.php';

$user = kintone_auth_require_admin_access();
kintone_auth_require_csrf();
$ref = kintone_reference_id('SYN');
$appKey = 'organizations';

try {
    $action = (string)($_POST['action'] ?? 'sync_app');
    if ($action === 'retry_failed') {
        $sourceJobId = (int)($_POST['source_job_id'] ?? 0);
        $result = kintone_sync_retry_failed_job($sourceJobId, $user);
        $appKey = (string)($result['app_key'] ?? $appKey);
        kintone_set_flash('success', '失敗分の再同期が完了しました。ジョブID: ' . $result['job_id'] . ' / 成功: ' . $result['success'] . ' / 失敗: ' . $result['failed']);
    } else {
        $appKey = kintone_normalize_app_key((string)($_POST['app_key'] ?? 'organizations'));
        if ($action === 'sync_selected') {
            if ($appKey !== 'organizations') {
                throw new InvalidArgumentException('個別団体同期は organizations アプリのみ対応しています。');
            }
            $ids = $_POST['organization_ids'] ?? [];
            if (!is_array($ids)) {
                $ids = [];
            }
            $result = kintone_sync_push_app($appKey, $ids, $user, 'manual');
            kintone_set_flash('success', '選択団体のkintone同期が完了しました。ジョブID: ' . $result['job_id'] . ' / 成功: ' . $result['success'] . ' / 失敗: ' . $result['failed']);
        } else {
            $result = kintone_sync_app_by_role($appKey, [], $user, 'manual');
            kintone_set_flash('success', 'kintone同期が完了しました。app_key: ' . $appKey . ' / ジョブID: ' . $result['job_id'] . ' / 成功: ' . $result['success'] . ' / 失敗: ' . $result['failed']);
        }
    }
} catch (InvalidArgumentException|KintoneSyncException $e) {
    kintone_set_flash('warn', $e->getMessage());
} catch (PDOException $e) {
    error_log('[kintone sync API PDO][' . $ref . '] ' . $e->getMessage());
    kintone_set_flash('error', '同期ジョブ情報の保存に失敗しました（参照ID: ' . $ref . '）。');
} catch (Throwable $e) {
    error_log('[kintone sync API][' . $ref . '] ' . $e->getMessage());
    kintone_set_flash('error', 'kintone同期に失敗しました（参照ID: ' . $ref . '）。ログを確認してください。');
}

header('Location: ../sync.php?app_key=' . rawurlencode($appKey), true, 302);
exit;
