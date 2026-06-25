<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once kintone_apps_dir() . '/forms_module.php';

function kintone_upload_error_message(int $errorCode, string $label = 'ファイル'): string
{
    if (function_exists('forms_upload_error_message')) {
        return forms_upload_error_message($errorCode, $label);
    }
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE => $label . 'のサイズがサーバー設定 upload_max_filesize を超えています。',
        UPLOAD_ERR_FORM_SIZE => $label . 'のサイズがフォーム側の上限を超えています。',
        UPLOAD_ERR_PARTIAL => $label . 'のアップロードが途中で中断されました。',
        UPLOAD_ERR_NO_FILE => $label . 'が選択されていません。',
        UPLOAD_ERR_NO_TMP_DIR => 'サーバー側の一時保存ディレクトリが利用できません。',
        UPLOAD_ERR_CANT_WRITE => 'サーバー側で' . $label . 'を書き込めませんでした。',
        UPLOAD_ERR_EXTENSION => 'PHP拡張機能により' . $label . 'のアップロードが停止されました。',
        default => $label . 'のアップロードに失敗しました。',
    };
}

function kintone_detect_uploaded_mime(string $path): ?string
{
    if (function_exists('forms_detect_uploaded_mime')) {
        return forms_detect_uploaded_mime($path);
    }
    if (!is_file($path) || !class_exists('finfo')) {
        return null;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($path);
    return is_string($mime) ? $mime : null;
}

function kintone_validate_roster_upload(array $file): void
{
    $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        $message = kintone_upload_error_message($errorCode, '部員名簿CSV');
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            throw new InvalidArgumentException($message);
        }
        throw new RuntimeException($message);
    }
    $originalName = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension !== 'csv') {
        throw new InvalidArgumentException('部員名簿はCSVファイルのみアップロードできます。');
    }
    $maxMb = (int)kintone_config_value('kintone.max_roster_upload_mb', 10);
    $maxBytes = max(1, min(30, $maxMb)) * 1024 * 1024;
    if ((int)($file['size'] ?? 0) > $maxBytes) {
        throw new InvalidArgumentException('部員名簿CSVのサイズが上限を超えています。');
    }
    $tmpPath = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmpPath)) {
        throw new RuntimeException('アップロードされた一時ファイルを確認できません。');
    }
    // CSV/TXTはfinfo判定揺れが大きいため、PartB-3に従いMIMEで拒否しない。
}

function kintone_store_roster_upload(array $file): array
{
    kintone_validate_roster_upload($file);
    $originalName = (string)($file['name'] ?? '');
    $subdir = date('Y/m');
    $root = kintone_roster_upload_root();
    $targetDir = $root . '/' . $subdir;
    kintone_ensure_dir($targetDir, 0700);
    $stored = bin2hex(random_bytes(16)) . '.csv';
    $targetPath = $targetDir . '/' . $stored;
    if (!move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
        throw new RuntimeException('部員名簿CSVの保存に失敗しました。');
    }
    @chmod($targetPath, 0600);
    $mime = kintone_detect_uploaded_mime($targetPath);
    return [
        'original_name' => $originalName,
        'stored_name' => $stored,
        'relative_path' => $subdir . '/' . $stored,
        'full_path' => $targetPath,
        'file_size' => (int)filesize($targetPath),
        'mime_type' => $mime,
        'sha256_hash' => hash_file('sha256', $targetPath),
    ];
}

function kintone_csv_safe_cell(string $value): string
{
    if (function_exists('forms_csv_safe_cell')) {
        return forms_csv_safe_cell($value);
    }
    return preg_match('/\A[=+\-@\t\r\n]/u', $value) === 1 ? "'" . $value : $value;
}
