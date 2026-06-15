<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/backup_core/manager.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit(1);
}

try {
    $manager = new FitScBackupManager();
    $result = $manager->verifyLatest('cron');
    echo '[OK] verify backup: ' . ($result['message'] ?? 'completed') . PHP_EOL;
    exit(($result['status'] ?? '') === 'success' ? 0 : 2);
} catch (Throwable $e) {
    backup_write_log('error', 'backup_verify.php failed', ['error' => $e->getMessage()]);
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
