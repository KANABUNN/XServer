<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/storage_maintenance.php';

$sendMail = !in_array('--no-mail', $argv, true);
$json = in_array('--json', $argv, true);

try {
    $result = storage_maintenance_run_monthly_report($sendMail);

    if ($json) {
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }

    echo 'storage monthly report completed' . PHP_EOL;
    echo 'generated_at: ' . (string)($result['generated_at'] ?? '') . PHP_EOL;
    echo 'report_path: ' . (string)($result['report_path'] ?? '') . PHP_EOL;

    $mail = $result['mail'] ?? [];
    echo 'mail_sent: ' . (((bool)($mail['sent'] ?? false)) ? 'yes' : 'no') . PHP_EOL;
    if (!empty($mail['reason'])) {
        echo 'mail_reason: ' . (string)$mail['reason'] . PHP_EOL;
    }
    if (!empty($mail['to'])) {
        echo 'mail_to: ' . (string)$mail['to'] . PHP_EOL;
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[storage_monthly_report] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
