<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
forms_bootstrap();
api_require_admin();

$submissionId = (int)($_GET['submission_id'] ?? 0);
if ($submissionId <= 0) {
    json_response(['ok' => false, 'message' => 'submission_id が必要です。'], 422);
}

json_response([
    'ok' => true,
    'history' => forms_fetch_entry_history($submissionId),
]);
