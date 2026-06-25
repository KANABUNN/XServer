<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function kintone_mail_sync_organization(array $org): void
{
    $stmt = kintone_pdo('mail')->prepare(
        'INSERT INTO mail_organizations (category, identifier, name, representative_name, email, is_active, external_source, external_id, synced_at) '
        . 'VALUES (:category, :identifier, :name, :representative_name, :email, :is_active, :external_source, :external_id, NOW()) '
        . 'ON DUPLICATE KEY UPDATE category=VALUES(category), name=VALUES(name), representative_name=VALUES(representative_name), email=VALUES(email), is_active=VALUES(is_active), external_source=VALUES(external_source), external_id=VALUES(external_id), synced_at=NOW()'
    );
    $stmt->execute([
        ':category' => (string)($org['category'] ?? ''),
        ':identifier' => (string)$org['organization_code'],
        ':name' => (string)$org['organization_name'],
        ':representative_name' => (string)($org['representative_name'] ?? ''),
        ':email' => (string)($org['representative_email'] ?? ''),
        ':is_active' => ((string)($org['activity_status'] ?? 'unknown') === 'inactive') ? 0 : 1,
        ':external_source' => 'kintone',
        ':external_id' => (string)($org['kintone_record_id'] ?? $org['organization_code']),
    ]);
}
