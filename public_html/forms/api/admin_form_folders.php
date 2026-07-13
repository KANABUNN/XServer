<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
forms_bootstrap();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    api_require_admin();
    json_response([
        'ok' => true,
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
if ($action === 'save') {
    try {
        $folder = forms_save_folder((array)($data['folder'] ?? []));
        forms_admin_audit_log('folder.save', 'managed_form_folder', (int)($folder['id'] ?? 0), [
            'name' => (string)($folder['name'] ?? ''),
            'parent_id' => $folder['parent_id'] ?? null,
        ], $actor);
    } catch (InvalidArgumentException $e) {
        json_response(['ok' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('[forms admin_form_folders save] ' . (string)$e);
        json_response(['ok' => false, 'message' => 'フォルダーの保存に失敗しました。時間をおいて再試行してください。'], 500);
    }

    json_response([
        'ok' => true,
        'message' => 'フォルダーを保存しました。',
        'folder' => $folder,
        'folders' => forms_fetch_folders(),
    ]);
}

if ($action === 'delete') {
    $folderId = (int)($data['folder_id'] ?? 0);
    try {
        forms_delete_folder($folderId);
        forms_admin_audit_log('folder.delete', 'managed_form_folder', $folderId, [], $actor);
    } catch (InvalidArgumentException $e) {
        json_response(['ok' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('[forms admin_form_folders delete] ' . (string)$e);
        json_response(['ok' => false, 'message' => 'フォルダーの削除に失敗しました。時間をおいて再試行してください。'], 500);
    }

    json_response([
        'ok' => true,
        'message' => 'フォルダーを削除しました。',
        'folders' => forms_fetch_folders(),
    ]);
}

json_response(['ok' => false, 'message' => '未対応の操作です。'], 422);
