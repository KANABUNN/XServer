<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/backup_core/alert_rules.php';

try {
    $result = backup_run_alert_rules('cron');
    echo '[OK] alert rules finished: raised=' . (int)($result['raised_count'] ?? 0) . PHP_EOL;
} catch (Throwable $e) {
    backup_write_log('error', 'backup alert check failed', ['error' => $e->getMessage()]);
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
