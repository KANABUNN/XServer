<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
forms_bootstrap();

api_require_admin();

$formId = (int)($_GET['form_id'] ?? 0);
if ($formId < 1) {
    http_response_code(404);
    echo '配布ファイルが見つかりません。';
    exit;
}

$form = forms_load_form($formId, false);
$settings = $form['settings'] ?? [];
if (!$form || trim((string)($settings['distribution_file_relative_path'] ?? '')) === '') {
    http_response_code(404);
    echo '配布ファイルが設定されていません。';
    exit;
}

try {
    forms_output_distribution_file($form);
} catch (Throwable $e) {
    http_response_code(404);
    echo '配布ファイルが見つかりません。';
    exit;
}
