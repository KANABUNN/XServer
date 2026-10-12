<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
require_once __DIR__ . '/../../../apps/forms_distribution_multi.php';
forms_bootstrap();

api_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'POST のみ許可されています。'], 405);
}
if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    json_response(['ok' => false, 'message' => 'CSRF トークンが不正です。'], 419);
}

$formId = (int)($_POST['form_id'] ?? 0);
$fileId = trim((string)($_POST['file_id'] ?? ''));
if ($formId < 1 || $fileId === '') {
    json_response(['ok' => false, 'message' => '削除対象が指定されていません。'], 422);
}

try {
    $result = forms_distmulti_delete_file($formId, $fileId);
    json_response([
        'ok' => true,
        'message' => $result['deleted_count'] . '件の配布ファイルを削除しました。',
        'form' => $result['form'],
        'files' => $result['files'],
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 500);
}
