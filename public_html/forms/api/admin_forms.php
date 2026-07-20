<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
forms_bootstrap();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    api_require_admin();

    if ((string)($_GET['search_scope'] ?? '') === 'submission_organization') {
        $query = mb_substr(trim((string)($_GET['query'] ?? '')), 0, 200, 'UTF-8');
        try {
            $matchingFormIds = forms_find_form_ids_by_submission_organization($query);
        } catch (Throwable $e) {
            error_log('[forms admin_forms submission_organization search] ' . (string)$e);
            json_response([
                'ok' => false,
                'message' => '提出団体からフォームを検索できませんでした。時間をおいて再試行してください。',
            ], 500);
        }

        json_response([
            'ok' => true,
            'search_scope' => 'submission_organization',
            'query' => $query,
            'matching_form_ids' => $matchingFormIds,
        ]);
    }

    json_response([
        'ok' => true,
        'forms' => forms_fetch_forms(false),
        'folders' => forms_fetch_folders(),
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
        error_log('[forms admin_forms delete] ' . (string)$e);
        json_response(['ok' => false, 'message' => 'フォーム削除に失敗しました。時間をおいて再試行してください。'], 500);
    }

    json_response([
        'ok' => true,
        'message' => 'フォームを削除しました。',
        'deleted_form_id' => $formId,
        'forms' => forms_fetch_forms(false),
        'folders' => forms_fetch_folders(),
    ]);
}

if ($action === 'quick_update') {
    $formId = (int)($data['form_id'] ?? 0);
    $changes = is_array($data['changes'] ?? null) ? $data['changes'] : [];

    try {
        $before = forms_load_form($formId);
        if (!$before) {
            throw new InvalidArgumentException('更新対象のフォームが見つかりません。');
        }
        $form = forms_quick_update_form($formId, $changes);

        $auditChanges = [];
        foreach (['name', 'folder_id', 'is_active'] as $key) {
            if (!array_key_exists($key, $changes)) {
                continue;
            }
            $auditChanges[$key] = [
                'before' => $before[$key] ?? null,
                'after' => $form[$key] ?? null,
            ];
        }
        forms_admin_audit_log('form.quick_update', 'managed_form', $formId, [
            'changes' => $auditChanges,
        ], $actor);
    } catch (InvalidArgumentException $e) {
        json_response(['ok' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('[forms admin_forms quick_update] ' . (string)$e);
        json_response(['ok' => false, 'message' => '簡易操作の保存に失敗しました。時間をおいて再試行してください。'], 500);
    }

    json_response([
        'ok' => true,
        'message' => 'フォームを更新しました。',
        'form' => $form,
        'forms' => forms_fetch_forms(false),
        'folders' => forms_fetch_folders(),
    ]);
}

if ($action === 'copy') {
    $sourceFormId = (int)($data['source_form_id'] ?? 0);
    if ($sourceFormId < 1) {
        json_response(['ok' => false, 'message' => 'コピー元のフォームを選択してください。'], 422);
    }

    try {
        $form = forms_duplicate_form($sourceFormId, [
            'name' => (string)($data['name'] ?? ''),
            'slug' => (string)($data['slug'] ?? ''),
            'folder_id' => $data['folder_id'] ?? null,
        ]);
        forms_admin_audit_log('form.copy', 'managed_form', (int)($form['id'] ?? 0), [
            'source_form_id' => $sourceFormId,
            'slug' => (string)($form['slug'] ?? ''),
            'name' => (string)($form['name'] ?? ''),
        ], $actor);
    } catch (InvalidArgumentException $e) {
        json_response(['ok' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('[forms admin_forms copy] ' . (string)$e);
        json_response(['ok' => false, 'message' => 'フォームのコピーに失敗しました。時間をおいて再試行してください。'], 500);
    }

    json_response([
        'ok' => true,
        'message' => 'フォームを非公開でコピーしました。内容を確認してから公開してください。',
        'form' => $form,
        'forms' => forms_fetch_forms(false),
        'folders' => forms_fetch_folders(),
    ]);
}

if ($action !== 'save') {
    json_response(['ok' => false, 'message' => '未対応の操作です。'], 422);
}

$form = [];
try {
    $form = forms_save_form((array)($data['form'] ?? []), (array)($data['fields'] ?? []));
    forms_admin_audit_log('form.save', 'managed_form', (int)($form['id'] ?? 0), [
        'slug' => (string)($form['slug'] ?? ''),
        'name' => (string)($form['name'] ?? ''),
    ], $actor);
} catch (InvalidArgumentException $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[forms admin_forms save] ' . (string)$e);
    json_response(['ok' => false, 'message' => 'フォーム保存に失敗しました。時間をおいて再試行してください。'], 500);
}

json_response([
    'ok' => true,
    'message' => 'フォームを保存しました。',
    'form' => $form,
    'forms' => forms_fetch_forms(false),
    'folders' => forms_fetch_folders(),
]);
