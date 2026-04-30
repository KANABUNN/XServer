<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
forms_bootstrap();

require_post();
api_require_admin();

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verify_csrf($csrfToken)) {
    json_response(['ok' => false, 'message' => 'CSRF トークンが不正です。'], 419);
}

$action = (string)($_POST['action'] ?? 'upload');
$formId = (int)($_POST['form_id'] ?? 0);
if ($formId < 1) {
    json_response(['ok' => false, 'message' => '対象フォームが不正です。'], 422);
}

try {
    if ($action === 'upload') {
        $file = $_FILES['distribution_file'] ?? null;
        if (!is_array($file)) {
            json_response(['ok' => false, 'message' => '配布ファイルを選択してください。'], 422);
        }
        $form = forms_save_distribution_file($formId, $file);
        json_response([
            'ok' => true,
            'message' => '配布ファイルを保存しました。',
            'form' => $form,
            'forms' => forms_fetch_forms(false),
        ]);
    }

    if ($action === 'delete') {
        $form = forms_delete_distribution_file($formId);
        json_response([
            'ok' => true,
            'message' => '配布ファイルを削除しました。',
            'form' => $form,
            'forms' => forms_fetch_forms(false),
        ]);
    }

    json_response(['ok' => false, 'message' => '未対応の操作です。'], 422);
} catch (InvalidArgumentException $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => '配布ファイルの操作に失敗しました。', 'error' => $e->getMessage()], 500);
}
