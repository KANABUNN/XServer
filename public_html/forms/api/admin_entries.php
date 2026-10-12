<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
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

$filters = [
    'query' => (string)($_GET['query'] ?? ''),
    'status' => (string)($_GET['status'] ?? 'all'),
    'date_from' => (string)($_GET['date_from'] ?? ''),
    'date_to' => (string)($_GET['date_to'] ?? ''),
    'limit' => (string)($_GET['limit'] ?? '100'),
];

$result = forms_fetch_admin_entries($formId, $filters);

json_response([
    'ok' => true,
    'form' => $form,
    'entries' => $result['entries'],
    'summary' => $result['summary'],
    'filters' => $result['filters'],
]);
