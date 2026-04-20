<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
forms_bootstrap();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'POST のみ許可されています。'], 405);
}
if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    json_response(['ok' => false, 'message' => 'CSRF トークンが不正です。'], 419);
}

$formId = (int)($_POST['form_id'] ?? 0);
$form = forms_load_form($formId, true);
if (!$form) {
    json_response(['ok' => false, 'message' => '対象フォームが見つかりません。'], 404);
}

$validation = forms_validate_submission($form, $_POST, $_FILES);
if (!empty($validation['errors'])) {
    json_response(['ok' => false, 'message' => implode("\n", $validation['errors'])], 422);
}

try {
    $saved = forms_save_submission($form, $validation['data']);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => '送信の保存に失敗しました。', 'error' => $e->getMessage()], 500);
}

json_response([
    'ok' => true,
    'status' => $saved['status'],
    'message' => $saved['status'] === 'updated'
        ? '同一メールアドレスまたは団体名のため、履歴を残して最新データへ更新しました。'
        : ((string)($form['settings']['completion_message'] ?? '送信を受け付けました。')),
    'submission_id' => $saved['submission_id'],
    'revision_number' => $saved['revision_number'],
]);
