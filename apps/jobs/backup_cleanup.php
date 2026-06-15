<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/backup_core/manager.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

try {
    $manager = new FitScBackupManager();
    $result = $manager->runRetentionCleanup('cron');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(((string)($result['status'] ?? 'success') === 'success') ? 0 : 1);
} catch (Throwable $e) {
    backup_write_log('error', 'backup cleanup job failed', ['error' => $e->getMessage()]);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
