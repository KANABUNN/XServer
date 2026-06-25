<?php

declare(strict_types=1);

require_once __DIR__ . '/kintone_core/bootstrap.php';

function org_master_lookup_by_code(string $organizationCode): ?array
{
    $code = strtoupper(trim(mb_convert_kana($organizationCode, 'asKV', 'UTF-8')));
    if ($code === '') {
        return null;
    }
    $stmt = kintone_pdo('org')->prepare('SELECT * FROM organizations WHERE organization_code = :code LIMIT 1');
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function org_master_is_active(string $organizationCode): bool
{
    $org = org_master_lookup_by_code($organizationCode);
    return is_array($org) && (int)($org['is_active'] ?? 0) === 1 && (string)($org['activity_status'] ?? '') !== 'inactive';
}

function org_master_list_active(): array
{
    $stmt = kintone_pdo('org')->query('SELECT * FROM organizations WHERE is_active = 1 AND activity_status != "inactive" ORDER BY organization_name ASC, id ASC');
    return $stmt->fetchAll() ?: [];
}
