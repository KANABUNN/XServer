<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
forms_bootstrap();
require_post();
api_require_admin();

$data = request_json();
if (!verify_csrf($data['csrf_token'] ?? '')) {
    json_response(['ok' => false, 'message' => 'CSRF トークンが不正です。'], 419);
}

$submissionId = (int)($data['submission_id'] ?? 0);
$status = (string)($data['status'] ?? 'new');
$adminNote = (string)($data['admin_note'] ?? '');

if ($submissionId <= 0) {
    json_response(['ok' => false, 'message' => 'submission_id が必要です。'], 422);
}

try {
    $entry = forms_update_submission_status($submissionId, $status, $adminNote, current_user() ?: []);
} catch (InvalidArgumentException $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => '状態の更新に失敗しました。', 'error' => $e->getMessage()], 500);
}

json_response([
    'ok' => true,
    'message' => '状態を更新しました。',
    'entry' => $entry,
]);
