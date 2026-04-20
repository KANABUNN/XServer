<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
forms_bootstrap();
require_admin();

$revisionId = (int)($_GET['revision_id'] ?? 0);
if ($revisionId <= 0) {
    http_response_code(400);
    exit('revision_id が必要です。');
}

$download = forms_resolve_revision_download($revisionId);
if (!$download) {
    http_response_code(404);
    exit('対象ファイルが見つかりません。');
}

$filename = $download['filename'];
$path = $download['path'];
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($path));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
readfile($path);
exit;
