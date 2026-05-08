<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
require_once __DIR__ . '/../../../apps/forms_distribution_multi.php';
forms_bootstrap();

$formId = (int)($_GET['form_id'] ?? 0);
$fileId = trim((string)($_GET['file_id'] ?? ''));
if ($formId < 1) {
    http_response_code(404);
    echo '配布ファイルが見つかりません。';
    exit;
}

$form = forms_load_form($formId, true);
if (!$form || !forms_is_publicly_available($form)) {
    http_response_code(404);
    echo '配布ファイルが見つかりません。';
    exit;
}

$settings = $form['settings'] ?? [];
if (empty($settings['distribution_enabled']) || forms_distribution_files_from_form($form) === []) {
    http_response_code(404);
    echo '配布ファイルが設定されていません。';
    exit;
}

try {
    forms_output_distribution_file_by_id($form, $fileId !== '' ? $fileId : null);
} catch (Throwable $e) {
    http_response_code(404);
    echo '配布ファイルが見つかりません。';
    exit;
}
