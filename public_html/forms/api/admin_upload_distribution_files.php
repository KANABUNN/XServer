<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
require_once __DIR__ . '/../../../apps/forms_distribution_multi.php';
forms_bootstrap();

api_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'POST のみ許可されています。'], 405);
}
if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    json_response(['ok' => false, 'message' => 'CSRF トークンが不正です。'], 419);
}

$formId = (int)($_POST['form_id'] ?? 0);
if ($formId < 1) {
    json_response(['ok' => false, 'message' => 'フォームを選択してください。'], 422);
}

$fileInput = $_FILES['distribution_files'] ?? $_FILES['distribution_file'] ?? null;
if (!is_array($fileInput)) {
    json_response(['ok' => false, 'message' => 'アップロードする配布ファイルを選択してください。'], 422);
}

try {
    $result = forms_distribution_append_uploaded_files($formId, $fileInput);
    json_response([
        'ok' => true,
        'message' => count($result['added_files']) . '件の配布ファイルを追加しました。',
        'form' => $result['form'],
        'files' => $result['files'],
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 500);
}
