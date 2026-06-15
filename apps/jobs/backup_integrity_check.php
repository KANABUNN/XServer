<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/backup_core/integrity_checker.php';

try {
    $result = backup_run_integrity_check('cron');
    echo '[OK] integrity check finished: #' . (int)($result['id'] ?? 0) . ' status=' . (string)($result['status'] ?? '') . PHP_EOL;
} catch (Throwable $e) {
    backup_write_log('error', 'backup integrity check failed', ['error' => $e->getMessage()]);
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
