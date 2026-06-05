<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/repository.php';

function mail_normalize_original_filename(string $filename): string
{
    $filename = str_replace(["\0", '/', '\\'], '', $filename);
    $filename = preg_replace('/\s+/u', ' ', $filename) ?: $filename;
    $filename = trim($filename);
    return $filename !== '' ? $filename : 'unnamed';
}

function mail_stored_filename(string $originalName): string
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $suffix = $ext !== '' ? '.' . preg_replace('/[^a-z0-9]+/i', '', $ext) : '';
    return date('Ymd_His') . '_' . bin2hex(random_bytes(12)) . $suffix;
}

function mail_parse_attachment_filename(string $originalName): array
{
    $base = pathinfo(mail_normalize_original_filename($originalName), PATHINFO_FILENAME);
    $parts = preg_split('/__+/u', $base) ?: [];
    $identifier = '';
    $attachmentType = '';

    if (isset($parts[0]) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]{1,40}$/u', (string)$parts[0])) {
        $identifier = (string)$parts[0];
    }
    if (isset($parts[1])) {
        $attachmentType = trim((string)$parts[1]);
    }

    if ($identifier === '' && preg_match('/(^|[^A-Za-z0-9])([A-Za-z][A-Za-z0-9_\-]{1,40})(?=__|_|-|\s|$)/u', $base, $m)) {
        $identifier = (string)$m[2];
    }

    return [
        'identifier' => mail_normalize_identifier($identifier),
        'attachment_type' => $attachmentType,
        'method' => $identifier !== '' ? 'identifier_prefix' : 'manual',
        'confidence' => $identifier !== '' ? 100 : 0,
    ];
}

function mail_validate_attachment_name(array $config, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = $config['security']['allowed_attachment_extensions'] ?? ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip'];
    $allowed = array_map('strtolower', array_map('strval', is_array($allowed) ? $allowed : []));
    if ($ext === '' || !in_array($ext, $allowed, true)) {
        return [false, '許可されていない拡張子です。'];
    }
    return [true, ''];
}

function mail_storage_root(): string
{
    $config = mail_load_config();
    $dir = (string)($config['storage']['attachments_dir'] ?? (mail_apps_dir() . '/storage/mail_attachments'));
    if ($dir === '') {
        $dir = mail_apps_dir() . '/storage/mail_attachments';
    }
    return rtrim($dir, '/\\');
}

function mail_ensure_dir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }
    if (!mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('保存先ディレクトリを作成できません: ' . $dir);
    }
}

function mail_attachment_relative_dir(int $batchId): string
{
    return date('Y') . '/batch_' . $batchId;
}

function mail_manifest_from_csv(string $path): array
{
    $content = file_get_contents($path);
    if ($content === false) {
        return [];
    }
    $content = mail_csv_to_utf8($content);
    $tmp = tempnam(sys_get_temp_dir(), 'mail_manifest_');
    if ($tmp === false) {
        return [];
    }
    file_put_contents($tmp, $content);
    $fh = fopen($tmp, 'rb');
    if (!$fh) {
        @unlink($tmp);
        return [];
    }
    $headerRow = fgetcsv($fh);
    if (!is_array($headerRow)) {
        fclose($fh);
        @unlink($tmp);
        return [];
    }
    $headers = [];
    foreach ($headerRow as $i => $header) {
        $headers[trim((string)$header)] = $i;
    }
    $map = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $filename = mail_header_value($row, $headers, ['filename', 'ファイル名', 'file_name']);
        if ($filename === '') {
            continue;
        }
        $map[mail_normalize_original_filename($filename)] = [
            'identifier' => mail_normalize_identifier(mail_header_value($row, $headers, ['identifier', '識別番号', '団体番号', 'コード'])),
            'attachment_type' => mail_header_value($row, $headers, ['attachment_type', '資料種別', '種別']),
            'required' => mail_header_value($row, $headers, ['required', '必須']),
        ];
    }
    fclose($fh);
    @unlink($tmp);
    return $map;
}

function mail_collect_upload_entries(array $uploadedFile): array
{
    $entries = [];
    $names = $uploadedFile['name'] ?? [];
    $tmpNames = $uploadedFile['tmp_name'] ?? [];
    $errors = $uploadedFile['error'] ?? [];
    $sizes = $uploadedFile['size'] ?? [];
    if (!is_array($names)) {
        $names = [$names];
        $tmpNames = [$tmpNames];
        $errors = [$errors];
        $sizes = [$sizes];
    }
    foreach ($names as $i => $name) {
        if ((int)($errors[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $entries[] = [
            'name' => (string)$name,
            'tmp_name' => (string)($tmpNames[$i] ?? ''),
            'error' => (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($sizes[$i] ?? 0),
            'from_upload' => true,
        ];
    }
    return $entries;
}

function mail_extract_zip_entries(string $zipPath, string $originalZipName): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive が利用できないため ZIP を展開できません。');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('ZIPファイルを開けません: ' . $originalZipName);
    }
    $baseDir = sys_get_temp_dir() . '/mail_zip_' . bin2hex(random_bytes(8));
    mail_ensure_dir($baseDir);
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat)) {
            continue;
        }
        $name = (string)($stat['name'] ?? '');
        if ($name === '' || str_ends_with($name, '/')) {
            continue;
        }
        $normalized = str_replace('\\', '/', $name);
        if (str_contains($normalized, '../') || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/u', $normalized)) {
            continue;
        }
        $safeName = mail_normalize_original_filename(basename($normalized));
        $targetPath = $baseDir . '/' . bin2hex(random_bytes(6)) . '_' . $safeName;
        $content = $zip->getFromIndex($i);
        if ($content === false) {
            continue;
        }
        file_put_contents($targetPath, $content);
        $entries[] = [
            'name' => $safeName,
            'tmp_name' => $targetPath,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($targetPath) ?: strlen($content),
            'from_upload' => false,
            'source_zip' => $originalZipName,
        ];
    }
    $zip->close();
    return $entries;
}

function mail_detect_mime(string $path): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
    }
    return 'application/octet-stream';
}

function mail_create_upload_batch(PDO $pdo, int $batchId, string $uploadKind, ?string $originalName, ?array $actor): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO mail_upload_batches (mail_batch_id, upload_kind, original_name, created_by_account_id) ' .
        'VALUES (:mail_batch_id, :upload_kind, :original_name, :created_by)'
    );
    $stmt->execute([
        ':mail_batch_id' => $batchId > 0 ? $batchId : null,
        ':upload_kind' => in_array($uploadKind, ['common','individual','mixed'], true) ? $uploadKind : 'individual',
        ':original_name' => $originalName,
        ':created_by' => $actor !== null ? (int)($actor['id'] ?? 0) : null,
    ]);
    return (int)$pdo->lastInsertId();
}

function mail_store_and_link_file(PDO $pdo, int $uploadBatchId, int $batchId, string $uploadKind, array $entry, array $manifestMap): array
{
    $config = mail_load_config();
    $max = (int)($config['security']['max_upload_bytes'] ?? (50 * 1024 * 1024));
    $originalName = mail_normalize_original_filename((string)$entry['name']);
    if ((int)$entry['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'name' => $originalName, 'message' => 'アップロードエラー: ' . (int)$entry['error']];
    }
    if ((int)$entry['size'] <= 0) {
        return ['ok' => false, 'name' => $originalName, 'message' => '空ファイルです。'];
    }
    if ((int)$entry['size'] > $max) {
        return ['ok' => false, 'name' => $originalName, 'message' => 'ファイルサイズ上限を超えています。'];
    }
    [$valid, $message] = mail_validate_attachment_name($config, $originalName);
    if (!$valid) {
        return ['ok' => false, 'name' => $originalName, 'message' => $message];
    }
    if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) === 'zip' || strtolower($originalName) === 'manifest.csv') {
        return ['ok' => false, 'name' => $originalName, 'message' => '内部処理対象外ファイルです。'];
    }

    $storedName = mail_stored_filename($originalName);
    $relativeDir = mail_attachment_relative_dir($batchId);
    $storageRoot = mail_storage_root();
    $targetDir = $storageRoot . '/' . $relativeDir;
    mail_ensure_dir($targetDir);
    $targetPath = $targetDir . '/' . $storedName;
    $tmpName = (string)$entry['tmp_name'];
    if (!is_file($tmpName)) {
        return ['ok' => false, 'name' => $originalName, 'message' => '一時ファイルが見つかりません。'];
    }
    if (!empty($entry['from_upload'])) {
        if (!move_uploaded_file($tmpName, $targetPath)) {
            if (!rename($tmpName, $targetPath)) {
                return ['ok' => false, 'name' => $originalName, 'message' => '保存に失敗しました。'];
            }
        }
    } else {
        if (!rename($tmpName, $targetPath)) {
            if (!copy($tmpName, $targetPath)) {
                return ['ok' => false, 'name' => $originalName, 'message' => '保存に失敗しました。'];
            }
            @unlink($tmpName);
        }
    }

    $mime = mail_detect_mime($targetPath);
    $hash = hash_file('sha256', $targetPath) ?: null;
    $relativePath = $relativeDir . '/' . $storedName;
    $stmt = $pdo->prepare(
        'INSERT INTO mail_uploaded_files (upload_batch_id, original_name, stored_name, relative_path, file_size, mime_type, sha256_hash, status) ' .
        'VALUES (:upload_batch_id, :original_name, :stored_name, :relative_path, :file_size, :mime_type, :sha256_hash, :status)'
    );

    $parse = mail_parse_attachment_filename($originalName);
    if (isset($manifestMap[$originalName])) {
        $parse['identifier'] = mail_normalize_identifier((string)($manifestMap[$originalName]['identifier'] ?? ''));
        $parse['attachment_type'] = (string)($manifestMap[$originalName]['attachment_type'] ?? $parse['attachment_type']);
        $parse['method'] = 'manifest';
        $parse['confidence'] = 100;
    }

    $isCommon = $uploadKind === 'common';
    $organization = null;
    $attachmentStatus = 'needs_review';
    $fileStatus = 'needs_review';
    $method = $isCommon ? 'common' : (string)$parse['method'];
    $confidence = $isCommon ? 100 : (int)$parse['confidence'];

    if ($isCommon) {
        $attachmentStatus = 'approved';
        $fileStatus = 'approved';
    } elseif ((string)$parse['identifier'] !== '') {
        $organization = mail_find_organization_by_identifier($pdo, (string)$parse['identifier']);
        if ($organization) {
            $attachmentStatus = $method === 'manifest' || $method === 'identifier_prefix' ? 'approved' : 'needs_review';
            $fileStatus = $attachmentStatus === 'approved' ? 'approved' : 'needs_review';
        } else {
            $attachmentStatus = 'needs_review';
            $fileStatus = 'unmatched';
            $method = 'manual';
            $confidence = 0;
        }
    }

    $stmt->execute([
        ':upload_batch_id' => $uploadBatchId,
        ':original_name' => $originalName,
        ':stored_name' => $storedName,
        ':relative_path' => $relativePath,
        ':file_size' => (int)filesize($targetPath),
        ':mime_type' => $mime,
        ':sha256_hash' => $hash,
        ':status' => $fileStatus,
    ]);
    $fileId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare(
        'INSERT INTO mail_attachments (mail_batch_id, organization_id, uploaded_file_id, attachment_type, is_common, match_method, match_confidence, status) ' .
        'VALUES (:mail_batch_id, :organization_id, :uploaded_file_id, :attachment_type, :is_common, :match_method, :match_confidence, :status)'
    );
    $stmt->execute([
        ':mail_batch_id' => $batchId,
        ':organization_id' => $organization ? (int)$organization['id'] : null,
        ':uploaded_file_id' => $fileId,
        ':attachment_type' => trim((string)$parse['attachment_type']) ?: null,
        ':is_common' => $isCommon ? 1 : 0,
        ':match_method' => $method,
        ':match_confidence' => $confidence,
        ':status' => $attachmentStatus,
    ]);

    return [
        'ok' => true,
        'name' => $originalName,
        'status' => $attachmentStatus,
        'organization' => $organization['name'] ?? null,
        'identifier' => $parse['identifier'],
        'method' => $method,
    ];
}

function mail_handle_attachment_upload(PDO $pdo, int $batchId, string $uploadKind, array $uploadedFile, ?array $actor = null): array
{
    if ($batchId <= 0 || !mail_get_batch($pdo, $batchId)) {
        throw new InvalidArgumentException('対象バッチが見つかりません。');
    }
    if (!in_array($uploadKind, ['common','individual','mixed'], true)) {
        $uploadKind = 'individual';
    }

    $entries = mail_collect_upload_entries($uploadedFile);
    if ($entries === []) {
        throw new InvalidArgumentException('アップロード対象ファイルがありません。');
    }

    $expanded = [];
    $manifestMap = [];
    $originalNames = [];
    foreach ($entries as $entry) {
        $originalNames[] = (string)$entry['name'];
        $ext = strtolower(pathinfo((string)$entry['name'], PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            foreach (mail_extract_zip_entries((string)$entry['tmp_name'], (string)$entry['name']) as $zipEntry) {
                if (strtolower($zipEntry['name']) === 'manifest.csv') {
                    $manifestMap = array_merge($manifestMap, mail_manifest_from_csv((string)$zipEntry['tmp_name']));
                    @unlink((string)$zipEntry['tmp_name']);
                    continue;
                }
                $expanded[] = $zipEntry;
            }
        } elseif (strtolower((string)$entry['name']) === 'manifest.csv') {
            $manifestMap = array_merge($manifestMap, mail_manifest_from_csv((string)$entry['tmp_name']));
        } else {
            $expanded[] = $entry;
        }
    }
    if ($expanded === []) {
        throw new InvalidArgumentException('保存対象の添付ファイルがありません。');
    }

    $uploadBatchId = mail_create_upload_batch($pdo, $batchId, $uploadKind, implode(', ', array_slice($originalNames, 0, 5)), $actor);
    $results = [];
    $saved = 0;
    $needsReview = 0;
    $pdo->beginTransaction();
    try {
        foreach ($expanded as $entry) {
            $result = mail_store_and_link_file($pdo, $uploadBatchId, $batchId, $uploadKind === 'mixed' ? 'individual' : $uploadKind, $entry, $manifestMap);
            $results[] = $result;
            if (!empty($result['ok'])) {
                $saved++;
                if (($result['status'] ?? '') === 'needs_review') {
                    $needsReview++;
                }
            }
        }
        $status = $needsReview > 0 ? 'needs_review' : 'approved';
        $stmt = $pdo->prepare('UPDATE mail_upload_batches SET file_count = :file_count, status = :status WHERE id = :id');
        $stmt->execute([':file_count' => $saved, ':status' => $status, ':id' => $uploadBatchId]);
        mail_refresh_batch_attachment_counts($pdo, $batchId);
        mail_audit_log($pdo, $actor, 'mail.attachment.upload', 'mail_batch', (string)$batchId, [
            'upload_batch_id' => $uploadBatchId,
            'saved' => $saved,
            'needs_review' => $needsReview,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'upload_batch_id' => $uploadBatchId,
        'saved' => $saved,
        'needs_review' => $needsReview,
        'results' => $results,
    ];
}
