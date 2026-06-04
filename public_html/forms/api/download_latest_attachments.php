<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
forms_bootstrap();
$actor = api_require_admin();

$formId = (int)($_GET['form_id'] ?? 0);
if ($formId <= 0) {
    json_response(['ok' => false, 'message' => 'form_id が必要です。'], 422);
}

try {
    $archive = forms_build_latest_attachments_archive($formId, [
        'query' => (string)($_GET['query'] ?? ''),
        'status' => (string)($_GET['status'] ?? 'all'),
        'date_from' => (string)($_GET['date_from'] ?? ''),
        'date_to' => (string)($_GET['date_to'] ?? ''),
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => '最新添付ZIPの作成に失敗しました。', 'error_id' => forms_log_exception('download_latest_attachments.build', $e)], 500);
}

if (!$archive || empty($archive['path']) || !is_file($archive['path'])) {
    json_response(['ok' => false, 'message' => 'ダウンロード対象の最新添付ファイルがありません。'], 404);
}

$path = $archive['path'];
$filename = (string)($archive['filename'] ?? ('latest_attachments_' . date('Ymd_His') . '.zip'));
forms_admin_audit_log('export.latest_attachments_zip', 'managed_form', $formId, [
    'filename' => $filename,
    'count' => (int)($archive['count'] ?? 0),
], $actor);

register_shutdown_function(static function () use ($path): void {
    if (is_file($path)) {
        @unlink($path);
    }
});

header('Content-Type: application/zip');
header('Content-Length: ' . filesize($path));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
readfile($path);
exit;
