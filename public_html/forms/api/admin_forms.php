<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
forms_bootstrap();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    api_require_admin();
    json_response([
        'ok' => true,
        'forms' => forms_fetch_forms(false),
    ]);
}

require_post();
$actor = api_require_admin();
$data = request_json();
if (!verify_csrf($data['csrf_token'] ?? '')) {
    json_response(['ok' => false, 'message' => 'CSRF トークンが不正です。'], 419);
}

$action = (string)($data['action'] ?? 'save');

if ($action === 'delete') {
    $formId = (int)($data['form_id'] ?? 0);
    if ($formId < 1) {
        json_response(['ok' => false, 'message' => '削除対象のフォームが不正です。'], 422);
    }

    try {
        forms_delete_form($formId);
        forms_admin_audit_log('form.delete', 'managed_form', $formId, [], $actor);
    } catch (InvalidArgumentException $e) {
        json_response(['ok' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => 'フォーム削除に失敗しました。', 'error_id' => forms_log_exception('admin_forms.delete', $e)], 500);
    }

    json_response([
        'ok' => true,
        'message' => 'フォームを削除しました。',
        'deleted_form_id' => $formId,
        'forms' => forms_fetch_forms(false),
    ]);
}

if ($action !== 'save') {
    json_response(['ok' => false, 'message' => '未対応の操作です。'], 422);
}

try {
    $form = forms_save_form((array)($data['form'] ?? []), (array)($data['fields'] ?? []));
    forms_admin_audit_log('form.save', 'managed_form', (int)($form['id'] ?? 0), [
        'slug' => (string)($form['slug'] ?? ''),
        'name' => (string)($form['name'] ?? ''),
    ], $actor);
} catch (InvalidArgumentException $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => 'フォーム保存に失敗しました。', 'error_id' => forms_log_exception('admin_forms.save', $e)], 500);
}

json_response([
    'ok' => true,
    'message' => 'フォームを保存しました。',
    'form' => $form,
    'forms' => forms_fetch_forms(false),
]);
