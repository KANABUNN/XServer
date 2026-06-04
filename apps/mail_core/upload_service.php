<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

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
        'identifier' => $identifier,
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
