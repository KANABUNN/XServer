<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
forms_bootstrap();
$actor = api_require_admin();

$formId = (int)($_GET['form_id'] ?? 0);
if ($formId <= 0) {
    http_response_code(422);
    echo 'form_id が必要です。';
    exit;
}

$result = forms_csv_rows($formId, [
    'query' => (string)($_GET['query'] ?? ''),
    'status' => (string)($_GET['status'] ?? 'all'),
    'date_from' => (string)($_GET['date_from'] ?? ''),
    'date_to' => (string)($_GET['date_to'] ?? ''),
    'limit' => (string)($_GET['limit'] ?? '100'),
]);

$form = $result['form'] ?? null;
if (!$form) {
    http_response_code(404);
    echo 'フォームが見つかりません。';
    exit;
}

$rawName = trim((string)($form['slug'] ?? $form['name'] ?? 'forms-export'));
$rawName = preg_replace('/[^A-Za-z0-9_\-]+/u', '_', $rawName) ?: 'forms-export';
$filename = $rawName . '_' . date('Ymd_His') . '.csv';

forms_admin_audit_log('export.csv', 'managed_form', $formId, [
    'filename' => $filename,
    'filters' => $result['filters'] ?? [],
], $actor);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$fp = fopen('php://output', 'wb');
if ($fp === false) {
    exit;
}

fwrite($fp, "\xEF\xBB\xBF");
fputcsv($fp, $result['headers'] ?? []);
foreach (($result['rows'] ?? []) as $row) {
    fputcsv($fp, $row);
}
fclose($fp);
exit;
