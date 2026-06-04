<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
require_once __DIR__ . '/../../../apps/forms_distribution_multi.php';
forms_bootstrap();

$actor = api_require_admin();

$downloadToken = (string)($_GET['csrf_token'] ?? '');
if (!verify_csrf($downloadToken)) {
    json_response(['ok' => false, 'message' => 'CSRF トークンが不正です。画面を再読み込みしてから再度お試しください。'], 419);
}

$formId = (int)($_GET['form_id'] ?? 0);
$fileId = trim((string)($_GET['file_id'] ?? ''));
if ($formId < 1) {
    http_response_code(404);
    echo '配布ファイルが見つかりません。';
    exit;
}

$form = forms_load_form($formId, false);
if (!$form || forms_distmulti_files_from_form($form) === []) {
    http_response_code(404);
    echo '配布ファイルが設定されていません。';
    exit;
}

try {
    forms_admin_audit_log('distribution_file.download', 'managed_form', $formId, ['file_id' => $fileId], $actor);
    forms_distmulti_output_file_by_id($form, $fileId !== '' ? $fileId : null);
} catch (Throwable $e) {
    http_response_code(404);
    echo '配布ファイルが見つかりません。';
    exit;
}
