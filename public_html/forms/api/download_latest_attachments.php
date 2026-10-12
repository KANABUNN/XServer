<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
forms_bootstrap();
$actor = api_require_admin();

$downloadToken = (string)($_GET['csrf_token'] ?? '');
if (!verify_csrf($downloadToken)) {
    json_response(['ok' => false, 'message' => 'CSRF トークンが不正です。画面を再読み込みしてから再度お試しください。'], 419);
}

$formId = (int)($_GET['form_id'] ?? 0);
if ($formId <= 0) {
    json_response(['ok' => false, 'message' => 'form_id が必要です。'], 422);
}

$archive = null;
try {
    $archive = forms_build_latest_attachments_archive($formId, [
        'query' => (string)($_GET['query'] ?? ''),
        'status' => (string)($_GET['status'] ?? 'all'),
        'date_from' => (string)($_GET['date_from'] ?? ''),
        'date_to' => (string)($_GET['date_to'] ?? ''),
    ]);
} catch (Throwable $e) {
    error_log('[forms download_latest_attachments] ' . (string)$e);
    json_response(['ok' => false, 'message' => '最新添付ZIPの作成に失敗しました。時間をおいて再試行してください。'], 500);
}

$path = is_array($archive) ? (string)($archive['path'] ?? '') : '';
if ($path === '' || !is_file($path)) {
    json_response(['ok' => false, 'message' => 'ダウンロード対象の最新添付ファイルがありません。'], 404);
}

$defaultFilename = 'latest_attachments_' . date('Ymd_His') . '.zip';
$filename = is_array($archive) ? (string)($archive['filename'] ?? $defaultFilename) : $defaultFilename;
forms_admin_audit_log('export.latest_attachments_zip', 'managed_form', $formId, [
    'filename' => $filename,
    'count' => is_array($archive) ? (int)($archive['count'] ?? 0) : 0,
], $actor);

register_shutdown_function(static function () use ($path): void {
    if (is_file($path)) {
        @unlink($path);
    }
});

header('Content-Type: application/zip');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
readfile($path);
exit;
