<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function kintone_normalize_width(string $value): string
{
    return mb_convert_kana(trim($value), 'asKV', 'UTF-8');
}

function kintone_normalize_organization_code(string $value): string
{
    $value = strtoupper(kintone_normalize_width($value));
    $value = preg_replace('/\s+/u', '', $value) ?? $value;
    return mb_substr($value, 0, 100, 'UTF-8');
}

function kintone_normalize_organization_name(string $value): string
{
    $value = kintone_normalize_width($value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return mb_substr(trim($value), 0, 255, 'UTF-8');
}

function kintone_normalized_organization_key(string $value): string
{
    $value = mb_strtolower(kintone_normalize_organization_name($value), 'UTF-8');
    return preg_replace('/\s+/u', ' ', $value) ?? $value;
}

function kintone_normalize_member_name(string $value): string
{
    $value = kintone_normalize_width($value);
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? $value;
    return mb_substr(trim($value), 0, 150, 'UTF-8');
}

function kintone_normalize_email(string $value): string
{
    $value = mb_convert_kana(trim($value), 'as', 'UTF-8');
    $value = str_replace(['＠', '．'], ['@', '.'], $value);
    return mb_strtolower($value, 'UTF-8');
}

function kintone_email_is_valid(string $email): bool
{
    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function kintone_role_rank_map(): array
{
    $configured = kintone_config_value('kintone.role_rank_map', null);
    if (is_array($configured) && $configured !== []) {
        return array_map('intval', $configured);
    }
    return [
        '代表' => 100,
        '代表者' => 100,
        '部長' => 90,
        '会長' => 90,
        '代表幹事' => 90,
        '委員長' => 80,
        '副代表' => 70,
        '副部長' => 70,
        '会計' => 50,
        '書記' => 50,
    ];
}

function kintone_role_rank(string $roleName): int
{
    $role = kintone_normalize_width($roleName);
    $map = kintone_role_rank_map();
    foreach ($map as $needle => $rank) {
        if ($needle !== '' && mb_strpos($role, (string)$needle, 0, 'UTF-8') !== false) {
            return (int)$rank;
        }
    }
    return $role === '' ? 0 : 10;
}

function kintone_normalize_member_status(string $value): string
{
    $v = kintone_normalize_width($value);
    if ($v === '') {
        return 'unknown';
    }
    if (preg_match('/在籍|所属|活動中|active|有効|○/iu', $v)) {
        return 'active';
    }
    if (preg_match('/卒業|退部|退会|inactive|無効|休止|停止|×/iu', $v)) {
        return 'inactive';
    }
    return 'unknown';
}

function kintone_normalize_activity_hint(string $value): string
{
    $v = kintone_normalize_width($value);
    if ($v === '') {
        return 'unknown';
    }
    if (preg_match('/可|活動中|有効|active|○/iu', $v)) {
        return 'active';
    }
    if (preg_match('/不可|停止|休止|inactive|無効|×/iu', $v)) {
        return 'inactive';
    }
    return 'unknown';
}
