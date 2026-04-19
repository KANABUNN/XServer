<?php
require_once __DIR__ . '/../../../apps/lend_core/bootstrap.php';
require_once __DIR__ . '/../../../apps/forms_module.php';
forms_bootstrap();
api_require_admin();

$formId = (int)($_GET['form_id'] ?? 0);
if ($formId <= 0) {
    json_response(['ok' => false, 'message' => 'form_id が必要です。'], 422);
}
$form = forms_load_form($formId);
if (!$form) {
    json_response(['ok' => false, 'message' => 'フォームが見つかりません。'], 404);
}

json_response([
    'ok' => true,
    'form' => $form,
    'entries' => forms_fetch_admin_entries($formId),
]);
