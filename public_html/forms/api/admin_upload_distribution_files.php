<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
forms_bootstrap();

api_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'POST のみ許可されています。'], 405);
}
if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    json_response(['ok' => false, 'message' => 'CSRF トークンが不正です。'], 419);
}

/**
 * このAPIは意図的に forms_distribution_multi.php に依存しない。
 * 旧版ヘルパーの混在・未上書き時でも、アップロード処理だけは fatal error にしないため。
 */
function forms_admin_upload_dist_allowed_extensions(): array
{
    return ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'zip', 'csv', 'txt'];
}

function forms_admin_upload_dist_safe_name(string $value, string $fallback = 'file'): string
{
    if (function_exists('forms_safe_download_name')) {
        return forms_safe_download_name($value, $fallback);
    }
    $value = trim($value);
    if ($value === '') {
        return $fallback;
    }
    $value = preg_replace('/[\\\/\:\*\?"<>\|]+/u', '-', $value) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    $value = trim($value, " .-_\t\n\r\0\x0B");
    return $value !== '' ? $value : $fallback;
}

function forms_admin_upload_dist_clean_relative_path(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    $path = ltrim($path, '/');
    if ($path === '' || str_contains($path, '..') || preg_match('/^[a-zA-Z]:\//', $path)) {
        throw new RuntimeException('配布ファイルの保存パスが不正です。');
    }
    return $path;
}

function forms_admin_upload_dist_file_id(string $relativePath, string $storedName = ''): string
{
    return substr(hash('sha256', $relativePath . '|' . $storedName), 0, 16);
}

function forms_admin_upload_dist_normalize_item(array $item): ?array
{
    $relativePath = trim((string)($item['relative_path'] ?? $item['distribution_file_relative_path'] ?? ''));
    if ($relativePath === '') {
        return null;
    }
    try {
        $relativePath = forms_admin_upload_dist_clean_relative_path($relativePath);
    } catch (Throwable $e) {
        return null;
    }

    $storedName = forms_admin_upload_dist_safe_name((string)($item['stored_name'] ?? $item['distribution_file_stored_name'] ?? basename($relativePath)), basename($relativePath));
    $originalName = forms_admin_upload_dist_safe_name((string)($item['original_name'] ?? $item['distribution_file_original_name'] ?? $storedName), $storedName);
    $id = preg_replace('/[^a-zA-Z0-9_-]+/', '', (string)($item['id'] ?? '')) ?? '';
    if ($id === '') {
        $id = forms_admin_upload_dist_file_id($relativePath, $storedName);
    }

    return [
        'id' => $id,
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'relative_path' => $relativePath,
        'size_bytes' => max(0, (int)($item['size_bytes'] ?? $item['distribution_file_size_bytes'] ?? 0)),
        'uploaded_at' => trim((string)($item['uploaded_at'] ?? $item['distribution_file_uploaded_at'] ?? '')),
    ];
}

function forms_admin_upload_dist_files_from_settings(array $settings): array
{
    $files = [];
    if (isset($settings['distribution_files']) && is_array($settings['distribution_files'])) {
        foreach ($settings['distribution_files'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $normalized = forms_admin_upload_dist_normalize_item($item);
            if ($normalized !== null) {
                $files[$normalized['id']] = $normalized;
            }
        }
    }

    if (!$files && trim((string)($settings['distribution_file_relative_path'] ?? '')) !== '') {
        $legacy = forms_admin_upload_dist_normalize_item([
            'original_name' => $settings['distribution_file_original_name'] ?? '',
            'stored_name' => $settings['distribution_file_stored_name'] ?? '',
            'relative_path' => $settings['distribution_file_relative_path'] ?? '',
            'size_bytes' => $settings['distribution_file_size_bytes'] ?? 0,
            'uploaded_at' => $settings['distribution_file_uploaded_at'] ?? '',
        ]);
        if ($legacy !== null) {
            $files[$legacy['id']] = $legacy;
        }
    }

    return array_values($files);
}

function forms_admin_upload_dist_normalize_uploads(array $input): array
{
    if (isset($input['name']) && is_array($input['name'])) {
        $items = [];
        $count = count($input['name']);
        for ($i = 0; $i < $count; $i++) {
            $items[] = [
                'name' => $input['name'][$i] ?? '',
                'type' => $input['type'][$i] ?? '',
                'tmp_name' => $input['tmp_name'][$i] ?? '',
                'error' => $input['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $input['size'][$i] ?? 0,
            ];
        }
        return $items;
    }

    if (isset($input['name'])) {
        return [[
            'name' => $input['name'] ?? '',
            'type' => $input['type'] ?? '',
            'tmp_name' => $input['tmp_name'] ?? '',
            'error' => $input['error'] ?? UPLOAD_ERR_NO_FILE,
            'size' => $input['size'] ?? 0,
        ]];
    }

    return [];
}

function forms_admin_upload_dist_upload_error_message(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'ファイルサイズがサーバー側の上限を超えています。',
        UPLOAD_ERR_PARTIAL => 'ファイルのアップロードが途中で中断されました。',
        UPLOAD_ERR_NO_TMP_DIR => 'サーバーの一時保存先が設定されていません。',
        UPLOAD_ERR_CANT_WRITE => 'サーバーが一時ファイルを書き込めませんでした。',
        UPLOAD_ERR_EXTENSION => 'PHP拡張によりアップロードが停止されました。',
        default => '配布ファイルのアップロードに失敗しました。',
    };
}

function forms_admin_upload_dist_storage_dir(int $formId): string
{
    $appsDir = realpath(__DIR__ . '/../../../apps');
    if ($appsDir === false) {
        throw new RuntimeException('apps ディレクトリを解決できません。');
    }

    $baseDir = $appsDir . '/forms_uploads';
    $dir = $baseDir . '/distribution/' . $formId;

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('配布ファイル保存先を作成できません。保存先: ' . $dir);
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('配布ファイル保存先に書き込みできません。保存先: ' . $dir);
    }

    $htaccess = $baseDir . '/.htaccess';
    if (!is_file($htaccess) && is_writable($baseDir)) {
        @file_put_contents($htaccess, "Require all denied\n");
    }

    return $dir;
}

function forms_admin_upload_dist_relative_path(int $formId, string $storedName): string
{
    return 'forms_uploads/distribution/' . $formId . '/' . $storedName;
}

function forms_admin_upload_dist_sync_legacy(array $settings, array $files): array
{
    $normalizedFiles = [];
    foreach ($files as $file) {
        if (!is_array($file)) {
            continue;
        }
        $normalized = forms_admin_upload_dist_normalize_item($file);
        if ($normalized !== null) {
            $normalizedFiles[] = $normalized;
        }
    }

    $settings['distribution_files'] = $normalizedFiles;

    if ($normalizedFiles !== []) {
        $first = $normalizedFiles[0];
        $settings['distribution_file_original_name'] = $first['original_name'];
        $settings['distribution_file_stored_name'] = $first['stored_name'];
        $settings['distribution_file_relative_path'] = $first['relative_path'];
        $settings['distribution_file_size_bytes'] = $first['size_bytes'];
        $settings['distribution_file_uploaded_at'] = $first['uploaded_at'];
    } else {
        $settings['distribution_file_original_name'] = '';
        $settings['distribution_file_stored_name'] = '';
        $settings['distribution_file_relative_path'] = '';
        $settings['distribution_file_size_bytes'] = 0;
        $settings['distribution_file_uploaded_at'] = '';
    }

    return $settings;
}

function forms_admin_upload_dist_append_files(int $formId, array $fileInput): array
{
    $form = forms_load_form($formId, false);
    if (!$form) {
        throw new InvalidArgumentException('対象フォームが見つかりません。');
    }

    $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
    $existing = forms_admin_upload_dist_files_from_settings($settings);
    $uploads = forms_admin_upload_dist_normalize_uploads($fileInput);
    $allowed = forms_admin_upload_dist_allowed_extensions();
    $maxBytes = 30 * 1024 * 1024;
    $added = [];

    foreach ($uploads as $upload) {
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(forms_admin_upload_dist_upload_error_message($error));
        }

        $originalName = forms_admin_upload_dist_safe_name((string)($upload['name'] ?? ''), 'distribution-file');
        $size = (int)($upload['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException($originalName . ' のサイズが0バイトです。');
        }
        if ($size > $maxBytes) {
            throw new RuntimeException($originalName . ' のサイズが上限 30MB を超えています。');
        }

        $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $allowed, true)) {
            throw new RuntimeException($originalName . ' は許可されていない拡張子です。');
        }

        $tmpName = (string)($upload['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException($originalName . ' の一時ファイルを確認できません。');
        }

        $dir = forms_admin_upload_dist_storage_dir($formId);
        $storedName = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $dest = $dir . '/' . $storedName;
        if (!move_uploaded_file($tmpName, $dest)) {
            throw new RuntimeException($originalName . ' を保存できませんでした。');
        }
        @chmod($dest, 0664);

        $relativePath = forms_admin_upload_dist_relative_path($formId, $storedName);
        $added[] = [
            'id' => forms_admin_upload_dist_file_id($relativePath, $storedName),
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'relative_path' => $relativePath,
            'size_bytes' => $size,
            'uploaded_at' => date('Y-m-d H:i:s'),
        ];
    }

    if ($added === []) {
        throw new RuntimeException('アップロード対象の配布ファイルがありません。');
    }

    $files = array_merge($existing, $added);
    $settings = forms_admin_upload_dist_sync_legacy($settings, $files);

    $stmt = forms_db()->prepare('UPDATE managed_forms SET settings_json = :settings_json WHERE id = :id');
    $stmt->execute([
        ':settings_json' => forms_encode_json($settings),
        ':id' => $formId,
    ]);

    $updated = forms_load_form($formId, false);
    if (!$updated) {
        throw new RuntimeException('フォーム設定の再読込に失敗しました。');
    }

    return [
        'form' => $updated,
        'files' => forms_admin_upload_dist_files_from_settings($updated['settings'] ?? []),
        'added_files' => $added,
    ];
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
    $result = forms_admin_upload_dist_append_files($formId, $fileInput);
    json_response([
        'ok' => true,
        'message' => count($result['added_files']) . '件の配布ファイルを追加しました。',
        'form' => $result['form'],
        'files' => $result['files'],
    ]);
} catch (InvalidArgumentException $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[forms admin_upload_distribution_files] ' . (string)$e);
    json_response([
        'ok' => false,
        'message' => '配布ファイルのアップロードに失敗しました。時間をおいて再試行してください。',
    ], 500);
}
