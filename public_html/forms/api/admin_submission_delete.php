<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
forms_bootstrap();

require_post();
$actor = api_require_admin();

$data = request_json();
if (!verify_csrf($data['csrf_token'] ?? '')) {
    json_response(['ok' => false, 'message' => 'CSRF トークンが不正です。'], 419);
}

$action = (string)($data['action'] ?? '');
$submissionId = (int)($data['submission_id'] ?? 0);

if ($submissionId <= 0) {
    json_response(['ok' => false, 'message' => 'submission_id が必要です。'], 422);
}

try {
    if ($action === 'delete_submission') {
        $result = forms_delete_submission($submissionId, $actor);

        json_response([
            'ok' => true,
            'message' => '回答を削除しました。',
            'result' => $result,
        ]);
    }

    if ($action === 'delete_file') {
        $result = forms_delete_submission_file($submissionId, $actor);

        json_response([
            'ok' => true,
            'message' => '提出ファイルを削除しました。',
            'result' => $result,
        ]);
    }

    json_response(['ok' => false, 'message' => '未対応の操作です。'], 422);
} catch (InvalidArgumentException $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[forms admin_submission_delete] ' . (string)$e);
    json_response(['ok' => false, 'message' => '削除処理に失敗しました。時間をおいて再試行してください。'], 500);
}