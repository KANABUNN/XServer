<?php

declare(strict_types=1);

require_once __DIR__ . '/../apps/kintone_core/bootstrap.php';
require_once __DIR__ . '/../apps/kintone_core/audit.php';

$pdo = kintone_pdo('org');
$purgedMembers = 0;
$purgedFiles = 0;

$fileStmt = $pdo->query('SELECT * FROM roster_import_batches WHERE file_purge_at IS NOT NULL AND file_purge_at <= NOW() AND relative_path IS NOT NULL AND relative_path != "" ORDER BY id ASC LIMIT 100');
foreach ($fileStmt->fetchAll() ?: [] as $batch) {
    $relative = trim((string)($batch['relative_path'] ?? ''));
    if ($relative !== '' && !str_contains($relative, '..')) {
        $path = kintone_roster_upload_root() . '/' . ltrim($relative, '/');
        if (is_file($path) && @unlink($path)) {
            $purgedFiles++;
        }
    }
    $pdo->prepare('UPDATE roster_import_batches SET relative_path=NULL, stored_name=NULL, file_purge_at=NULL, updated_at=NOW() WHERE id=:id')->execute([':id' => (int)$batch['id']]);
}

$memberStmt = $pdo->query('SELECT id FROM roster_import_batches WHERE retention_purge_at IS NOT NULL AND retention_purge_at <= NOW() ORDER BY id ASC LIMIT 100');
foreach ($memberStmt->fetchAll() ?: [] as $batch) {
    $batchId = (int)$batch['id'];
    $del = $pdo->prepare('DELETE FROM organization_members WHERE import_batch_id = :batch_id');
    $del->execute([':batch_id' => $batchId]);
    $purgedMembers += $del->rowCount();
    $pdo->prepare('UPDATE roster_import_batches SET retention_purge_at=NULL, updated_at=NOW() WHERE id=:id')->execute([':id' => $batchId]);
}

kintone_write_audit_log('kintone.roster.purge', 'roster_import_batch', null, ['members' => $purgedMembers, 'files' => $purgedFiles]);
echo sprintf("purged_members=%d purged_files=%d\n", $purgedMembers, $purgedFiles);
