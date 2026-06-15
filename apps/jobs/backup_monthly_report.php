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
    $result = $manager->generateMonthlyReport('cron');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    backup_write_log('error', 'backup monthly report job failed', ['error' => $e->getMessage()]);
    backup_notify_on_failure('[FIT-SC Backup] 月次バックアップレポート生成に失敗しました', $e->getMessage());
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
