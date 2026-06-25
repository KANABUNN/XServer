<?php

declare(strict_types=1);

require_once __DIR__ . '/organization_normalizer.php';

function kintone_normalize_requested_csv_encoding(?string $encoding): string
{
    $encoding = trim((string)($encoding ?? 'auto'));
    if ($encoding === '' || strtolower($encoding) === 'auto') {
        return 'auto';
    }
    $upper = strtoupper($encoding);
    if (in_array($upper, ['UTF-8', 'UTF8'], true)) {
        return 'UTF-8';
    }
    if (in_array($upper, ['SJIS-WIN', 'SHIFT_JIS', 'SHIFT-JIS', 'CP932', 'SJIS'], true)) {
        return 'SJIS-win';
    }
    throw new InvalidArgumentException('文字コード指定が不正です。自動、UTF-8、Shift_JIS/CP932 のいずれかを選択してください。');
}

function kintone_detect_csv_encoding(string $bytes): string
{
    if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
        return 'UTF-8';
    }
    $detected = mb_detect_encoding($bytes, ['UTF-8', 'SJIS-win', 'CP932', 'EUC-JP', 'ASCII'], true);
    if (is_string($detected) && $detected !== '') {
        return $detected === 'CP932' ? 'SJIS-win' : $detected;
    }
    return 'SJIS-win';
}

function kintone_normalize_header(string $header): string
{
    $h = kintone_normalize_width($header);
    $h = preg_replace('/\s+/u', '', $h) ?? $h;
    return mb_strtolower($h, 'UTF-8');
}

function kintone_roster_header_map(array $headers): array
{
    $aliases = [
        'organization_code' => ['団体id', '団体ID', '団体コード', 'identifier', 'organization_code'],
        'organization_name' => ['団体名', '組織名', 'organization_name'],
        'member_name' => ['氏名', '名前', '部員名', 'member_name'],
        'member_email' => ['メールアドレス', 'メール', 'mail', 'email', 'member_email'],
        'role_name' => ['役職', 'role', 'role_name'],
        'member_status' => ['在籍状態', '状態', 'ステータス', 'member_status'],
        'activity_status' => ['活動可否', '活動状態', 'activity_status'],
        'representative_flag' => ['代表者フラグ', '代表フラグ', '代表者', 'is_representative'],
        'member_identifier' => ['学籍番号', '会員番号', 'member_identifier'],
        'category' => ['カテゴリ', '分類', 'category'],
    ];
    $normalized = [];
    foreach ($headers as $i => $header) {
        $normalized[$i] = kintone_normalize_header((string)$header);
    }
    $map = [];
    foreach ($aliases as $key => $candidates) {
        foreach ($candidates as $candidate) {
            $needle = kintone_normalize_header((string)$candidate);
            $index = array_search($needle, $normalized, true);
            if ($index !== false) {
                $map[$key] = (int)$index;
                break;
            }
        }
    }
    foreach (['organization_code', 'organization_name', 'member_name'] as $required) {
        if (!array_key_exists($required, $map)) {
            throw new InvalidArgumentException('想定の列が見つかりません: 団体ID, 団体名, 氏名。テンプレートで再作成してください。');
        }
    }
    return $map;
}

function kintone_csv_flag_is_true(string $value): bool
{
    $v = kintone_normalize_width($value);
    return preg_match('/\A(1|true|yes|on|代表|○|有|はい)\z/iu', $v) === 1;
}

function kintone_parse_roster_csv(string $path, ?string $requestedEncoding = 'auto'): array
{
    if (!is_file($path)) {
        throw new RuntimeException('部員名簿CSVが見つかりません。');
    }
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        throw new RuntimeException('部員名簿CSVを読み込めません。');
    }
    $requestedEncoding = kintone_normalize_requested_csv_encoding($requestedEncoding);
    $encoding = $requestedEncoding === 'auto' ? kintone_detect_csv_encoding($raw) : $requestedEncoding;
    $utf8 = mb_convert_encoding($raw, 'UTF-8', $encoding);
    $utf8 = preg_replace('/^\xEF\xBB\xBF/u', '', $utf8) ?? $utf8;
    $fp = fopen('php://temp', 'r+');
    if ($fp === false) {
        throw new RuntimeException('CSV解析用の一時領域を作成できません。');
    }
    fwrite($fp, $utf8);
    rewind($fp);
    $headers = fgetcsv($fp);
    if (!is_array($headers)) {
        throw new InvalidArgumentException('CSVヘッダを読み取れません。');
    }
    $map = kintone_roster_header_map($headers);
    $rows = [];
    $errors = [];
    $line = 1;
    while (($cols = fgetcsv($fp)) !== false) {
        $line++;
        if (!is_array($cols) || implode('', array_map('strval', $cols)) === '') {
            continue;
        }
        $get = static fn(string $key): string => array_key_exists($key, $map) ? trim((string)($cols[$map[$key]] ?? '')) : '';
        $code = kintone_normalize_organization_code($get('organization_code'));
        $orgName = kintone_normalize_organization_name($get('organization_name'));
        $memberName = kintone_normalize_member_name($get('member_name'));
        $email = kintone_normalize_email($get('member_email'));
        if ($code === '' || $orgName === '' || $memberName === '') {
            $errors[] = $line . '行目: 団体ID、団体名、氏名は必須です。';
            continue;
        }
        if ($email !== '' && !kintone_email_is_valid($email)) {
            $errors[] = $line . '行目: メールアドレスの形式が不正です。';
        }
        $role = kintone_normalize_width($get('role_name'));
        $rows[] = [
            'line' => $line,
            'organization_code' => $code,
            'organization_name' => $orgName,
            'normalized_organization_name' => kintone_normalized_organization_key($orgName),
            'category' => mb_substr(kintone_normalize_width($get('category')), 0, 100, 'UTF-8'),
            'member_identifier' => mb_substr(kintone_normalize_width($get('member_identifier')), 0, 100, 'UTF-8'),
            'member_name' => $memberName,
            'member_email' => $email,
            'role_name' => mb_substr($role, 0, 100, 'UTF-8'),
            'role_rank' => kintone_role_rank($role),
            'member_status' => kintone_normalize_member_status($get('member_status')),
            'activity_hint' => kintone_normalize_activity_hint($get('activity_status')),
            'is_representative_candidate' => kintone_csv_flag_is_true($get('representative_flag')) ? 1 : 0,
        ];
    }
    fclose($fp);
    return ['encoding' => $encoding, 'rows' => $rows, 'errors' => $errors, 'total_rows' => count($rows) + count($errors)];
}
