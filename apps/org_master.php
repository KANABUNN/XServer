<?php

declare(strict_types=1);

require_once __DIR__ . '/kintone_core/bootstrap.php';

function org_master_normalize_code(string $organizationCode): string
{
    return strtoupper(trim(mb_convert_kana($organizationCode, 'asKV', 'UTF-8')));
}

function org_master_lookup_by_code(string $organizationCode): ?array
{
    $code = org_master_normalize_code($organizationCode);
    if ($code === '') {
        return null;
    }

    try {
        $stmt = kintone_pdo('org')->prepare('SELECT * FROM organizations WHERE organization_code = :code LIMIT 1');
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        error_log('[org_master lookup] ' . $e->getMessage());
        return null;
    }
}

function org_master_is_active(string $organizationCode): bool
{
    try {
        $org = org_master_lookup_by_code($organizationCode);
        if (!is_array($org)) {
            // 団体マスタ障害・未登録時は、既存サブシステムを止めない。
            // inactive の確証がある場合だけ false を返す fail-open 方針。
            return true;
        }
        return (int)($org['is_active'] ?? 0) === 1 && (string)($org['activity_status'] ?? '') !== 'inactive';
    } catch (Throwable $e) {
        error_log('[org_master is_active] ' . $e->getMessage());
        return true;
    }
}

function org_master_list_active(): array
{
    try {
        $stmt = kintone_pdo('org')->query('SELECT * FROM organizations WHERE is_active = 1 AND activity_status != "inactive" ORDER BY organization_name ASC, id ASC');
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('[org_master list_active] ' . $e->getMessage());
        return [];
    }
}
