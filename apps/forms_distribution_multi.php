<?php
declare(strict_types=1);

/**
 * forms: 配布資料の複数ファイル対応ヘルパー。
 *
 * 既存 DB スキーマは変更せず、managed_forms.settings_json に
 * distribution_files 配列を追加保存する。
 * 既存の単一ファイルキー distribution_file_* も互換用に維持する。
 */

function forms_distmulti_allowed_extensions(): array
{
    return ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'zip', 'csv', 'txt'];
}

function forms_distmulti_safe_download_name(string $value, string $fallback = 'file'): string
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

function forms_distmulti_file_id(string $relativePath, string $storedName = ''): string
{
    return substr(hash('sha256', $relativePath . '|' . $storedName), 0, 16);
}

function forms_distmulti_clean_relative_path(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    $path = ltrim($path, '/');

    if ($path === '' || str_contains($path, '..') || preg_match('/^[a-zA-Z]:\//', $path)) {
        throw new RuntimeException('配布ファイルの保存パスが不正です。');
    }

    return $path;
}

function forms_distmulti_normalize_file_item(array $item): ?array
{
    $relativePath = trim((string)($item['relative_path'] ?? $item['distribution_file_relative_path'] ?? ''));
    if ($relativePath === '') {
        return null;
    }

    try {
        $relativePath = forms_distmulti_clean_relative_path($relativePath);
    } catch (Throwable $e) {
        return null;
    }

    $storedName = forms_distmulti_safe_download_name((string)($item['stored_name'] ?? $item['distribution_file_stored_name'] ?? basename($relativePath)), basename($relativePath));
    $originalName = forms_distmulti_safe_download_name((string)($item['original_name'] ?? $item['distribution_file_original_name'] ?? $storedName), $storedName);
    $id = preg_replace('/[^a-zA-Z0-9_-]+/', '', (string)($item['id'] ?? '')) ?? '';
    if ($id === '') {
        $id = forms_distmulti_file_id($relativePath, $storedName);
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

function forms_distmulti_validate_uploaded_file(string $originalName, string $extension, string $tmpName): void
{
    if (function_exists('forms_validate_safe_upload_extension')) {
        forms_validate_safe_upload_extension($extension, '配布ファイル');
    }
    if (function_exists('forms_detect_uploaded_mime') && function_exists('forms_allowed_mimes_for_extension')) {
        $detectedMime = forms_detect_uploaded_mime($tmpName);
        $allowedMimes = forms_allowed_mimes_for_extension($extension);
        if ($detectedMime !== null && $allowedMimes !== [] && !in_array($detectedMime, $allowedMimes, true)) {
            throw new RuntimeException($originalName . ' の種類が拡張子と一致しません。ファイル形式を確認してください。');
        }
    }
}

function forms_distmulti_files_from_settings(array $settings): array
{
    $files = [];

    if (isset($settings['distribution_files']) && is_array($settings['distribution_files'])) {
        foreach ($settings['distribution_files'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $normalized = forms_distmulti_normalize_file_item($item);
            if ($normalized !== null) {
                $files[$normalized['id']] = $normalized;
            }
        }
    }

    // 旧形式の単一ファイルを自動的に配列形式へ読み替える。
    if (!$files && trim((string)($settings['distribution_file_relative_path'] ?? '')) !== '') {
        $legacy = forms_distmulti_normalize_file_item([
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

function forms_distmulti_files_from_form(array $form): array
{
    $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
    return forms_distmulti_files_from_settings($settings);
}

function forms_distmulti_storage_dir(int $formId): string
{
    $dir = __DIR__ . '/forms_uploads/distribution/' . $formId;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('配布ファイル保存先を作成できません。');
    }
    return $dir;
}

function forms_distmulti_relative_path(int $formId, string $storedName): string
{
    return 'forms_uploads/distribution/' . $formId . '/' . $storedName;
}

function forms_distmulti_resolve_path(string $relativePath): ?string
{
    try {
        $relativePath = forms_distmulti_clean_relative_path($relativePath);
    } catch (Throwable $e) {
        return null;
    }

    $candidates = [
        __DIR__ . '/' . $relativePath,
        __DIR__ . '/forms_uploads/' . $relativePath,
        dirname(__DIR__) . '/' . $relativePath,
        dirname(__DIR__) . '/apps/' . $relativePath,
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            $real = realpath($candidate);
            return $real !== false ? $real : $candidate;
        }
    }

    return null;
}

function forms_distmulti_select_file(array $files, ?string $fileId): ?array
{
    if ($files === []) {
        return null;
    }
    $fileId = trim((string)$fileId);
    if ($fileId === '') {
        return $files[0];
    }
    foreach ($files as $file) {
        if ((string)($file['id'] ?? '') === $fileId) {
            return $file;
        }
    }
    return null;
}

function forms_distmulti_mime_type(string $path): string
{
    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
        }
    }
    return $mime;
}

function forms_distmulti_send_file_headers(string $downloadName, string $path): void
{
    $downloadName = forms_distmulti_safe_download_name($downloadName, basename($path));
    $asciiFallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $downloadName) ?? 'file';
    $asciiFallback = trim($asciiFallback, '._-');
    if ($asciiFallback === '') {
        $asciiFallback = 'file';
    }

    if (ob_get_level() > 0) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    header('Content-Type: ' . forms_distmulti_mime_type($path));
    header('Content-Length: ' . (string)filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . addcslashes($asciiFallback, "\\\"") . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
}

function forms_distmulti_output_file_by_id(array $form, ?string $fileId = null): void
{
    $files = forms_distmulti_files_from_form($form);
    $file = forms_distmulti_select_file($files, $fileId);
    if ($file === null) {
        throw new RuntimeException('配布ファイルが見つかりません。');
    }

    $path = forms_distmulti_resolve_path((string)$file['relative_path']);
    if ($path === null) {
        throw new RuntimeException('配布ファイルの実体が見つかりません。');
    }

    forms_distmulti_send_file_headers((string)$file['original_name'], $path);
    readfile($path);
    exit;
}

function forms_distmulti_sync_legacy_settings(array $settings, array $files): array
{
    $files = array_values(array_filter(array_map(static function ($item) {
        return is_array($item) ? forms_distmulti_normalize_file_item($item) : null;
    }, $files)));

    $settings['distribution_files'] = $files;

    if ($files !== []) {
        $first = $files[0];
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

function forms_distmulti_update_form_settings(int $formId, array $settings, array $files): array
{
    $settings = forms_distmulti_sync_legacy_settings($settings, $files);

    $stmt = forms_db()->prepare('UPDATE managed_forms SET settings_json = :settings_json WHERE id = :id');
    $stmt->execute([
        ':settings_json' => forms_encode_json($settings),
        ':id' => $formId,
    ]);

    $updated = forms_load_form($formId, false);
    if (!$updated) {
        throw new RuntimeException('フォーム設定の再読込に失敗しました。');
    }
    return $updated;
}

function forms_distmulti_normalize_uploads(array $input): array
{
    $items = [];
    if (isset($input['name']) && is_array($input['name'])) {
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

function forms_distmulti_append_uploaded_files(int $formId, array $fileInput): array
{
    $form = forms_load_form($formId, false);
    if (!$form) {
        throw new InvalidArgumentException('対象フォームが見つかりません。');
    }

    $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
    $existing = forms_distmulti_files_from_form($form);
    $uploads = forms_distmulti_normalize_uploads($fileInput);
    $allowed = forms_distmulti_allowed_extensions();
    $maxBytes = 30 * 1024 * 1024;
    $added = [];

    foreach ($uploads as $upload) {
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('配布ファイルのアップロードに失敗しました。');
        }

        $originalName = forms_distmulti_safe_download_name((string)($upload['name'] ?? ''), 'distribution-file');
        $size = (int)($upload['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            throw new RuntimeException($originalName . ' のサイズが上限 30MB を超えています。');
        }

        $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $allowed, true)) {
            throw new RuntimeException($originalName . ' は許可されていない拡張子です。');
        }
        forms_distmulti_validate_uploaded_file($originalName, $extension, (string)($upload['tmp_name'] ?? ''));

        $tmpName = (string)($upload['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException($originalName . ' の一時ファイルを確認できません。');
        }

        $dir = forms_distmulti_storage_dir($formId);
        $storedName = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $dest = $dir . '/' . $storedName;
        if (!move_uploaded_file($tmpName, $dest)) {
            throw new RuntimeException($originalName . ' を保存できませんでした。');
        }
        @chmod($dest, 0664);

        $relativePath = forms_distmulti_relative_path($formId, $storedName);
        $added[] = [
            'id' => forms_distmulti_file_id($relativePath, $storedName),
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
    $updated = forms_distmulti_update_form_settings($formId, $settings, $files);

    return [
        'form' => $updated,
        'files' => forms_distmulti_files_from_form($updated),
        'added_files' => $added,
    ];
}

function forms_distmulti_delete_file(int $formId, string $fileId): array
{
    $form = forms_load_form($formId, false);
    if (!$form) {
        throw new InvalidArgumentException('対象フォームが見つかりません。');
    }

    $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
    $files = forms_distmulti_files_from_form($form);
    $fileId = trim($fileId);
    if ($fileId === '') {
        throw new InvalidArgumentException('削除対象の配布ファイルが指定されていません。');
    }

    $kept = [];
    $deletedCount = 0;
    foreach ($files as $file) {
        $matches = ($fileId === 'all') || ((string)$file['id'] === $fileId);
        if (!$matches) {
            $kept[] = $file;
            continue;
        }
        $path = forms_distmulti_resolve_path((string)$file['relative_path']);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
        $deletedCount++;
    }

    if ($deletedCount < 1) {
        throw new RuntimeException('削除対象の配布ファイルが見つかりません。');
    }

    $updated = forms_distmulti_update_form_settings($formId, $settings, $kept);

    return [
        'form' => $updated,
        'files' => forms_distmulti_files_from_form($updated),
        'deleted_count' => $deletedCount,
    ];
}
