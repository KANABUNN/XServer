<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function kintone_mail_sync_organization(array $org): void
{
    $organizationCode = (string)($org['organization_code'] ?? '');
    if ($organizationCode === '') {
        throw new InvalidArgumentException('mail同期対象の団体IDが空です。');
    }

    $stmt = kintone_pdo('mail')->prepare(
        'INSERT INTO mail_organizations (category, identifier, name, representative_name, email, is_active, external_source, external_id, synced_at) '
        . 'VALUES (:category, :identifier, :name, :representative_name, :email, :is_active, :external_source, :external_id, NOW()) '
        . 'ON DUPLICATE KEY UPDATE category=VALUES(category), name=VALUES(name), representative_name=VALUES(representative_name), email=VALUES(email), is_active=VALUES(is_active), external_source=VALUES(external_source), external_id=VALUES(external_id), synced_at=NOW()'
    );
    $stmt->execute([
        ':category' => (string)($org['category'] ?? ''),
        ':identifier' => $organizationCode,
        ':name' => (string)$org['organization_name'],
        ':representative_name' => (string)($org['representative_name'] ?? ''),
        ':email' => (string)($org['representative_email'] ?? ''),
        ':is_active' => ((string)($org['activity_status'] ?? 'unknown') === 'inactive') ? 0 : 1,
        ':external_source' => 'kintone',
        // Phase 2 で kintone_record_id が入っても external_id は変えない。
        // mail側の安定キーは identifier / organization_code に固定する。
        ':external_id' => $organizationCode,
    ]);
}

function kintone_mail_sync_organizations_by_codes(array $organizationCodes): array
{
    $codes = [];
    foreach ($organizationCodes as $code) {
        $code = trim((string)$code);
        if ($code !== '') {
            $codes[$code] = $code;
        }
    }
    if ($codes === []) {
        return ['synced' => 0, 'failed' => []];
    }

    $pdo = kintone_pdo('org');
    $synced = 0;
    $failed = [];
    $stmt = $pdo->prepare('SELECT * FROM organizations WHERE organization_code = :code LIMIT 1');
    foreach (array_values($codes) as $code) {
        try {
            $stmt->execute([':code' => $code]);
            $org = $stmt->fetch();
            if (!is_array($org)) {
                $failed[] = $code;
                error_log('[kintone mail_sync] organization not found: ' . $code);
                continue;
            }
            kintone_mail_sync_organization($org);
            $synced++;
        } catch (Throwable $e) {
            $failed[] = $code;
            error_log('[kintone mail_sync] ' . $code . ' ' . $e->getMessage());
        }
    }

    return ['synced' => $synced, 'failed' => array_values(array_unique($failed))];
}
