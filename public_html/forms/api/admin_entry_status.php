<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
forms_bootstrap();
require_post();
$actor = api_require_admin();

$data = request_json();
if (!verify_csrf($data['csrf_token'] ?? '')) {
    json_response(['ok' => false, 'message' => 'CSRF トークンが不正です。'], 419);
}

$submissionId = (int)($data['submission_id'] ?? 0);
$status = (string)($data['status'] ?? 'new');
$adminNote = (string)($data['admin_note'] ?? '');
$entry = [];

if ($submissionId <= 0) {
    json_response(['ok' => false, 'message' => 'submission_id が必要です。'], 422);
}

try {
    $entry = forms_update_submission_status($submissionId, $status, $adminNote, $actor ?: []);
    forms_admin_audit_log('submission.status.update', 'managed_form_submission', $submissionId, [
        'status' => $status,
    ], $actor);
} catch (InvalidArgumentException $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[forms admin_entry_status] ' . (string)$e);
    json_response(['ok' => false, 'message' => '状態の更新に失敗しました。時間をおいて再試行してください。'], 500);
}

json_response([
    'ok' => true,
    'message' => '状態を更新しました。',
    'entry' => $entry,
]);
