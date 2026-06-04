<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/storage_maintenance.php';

$dryRun = in_array('--dry-run', $argv, true);
$json = in_array('--json', $argv, true);

try {
    $result = storage_maintenance_run_cleanup($dryRun);

    if ($json) {
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }

    echo 'storage cleanup completed' . PHP_EOL;
    echo 'timestamp: ' . (string)($result['timestamp'] ?? '') . PHP_EOL;
    echo 'dry_run: ' . ($dryRun ? 'yes' : 'no') . PHP_EOL;

    $forms = $result['forms_revision_archives'] ?? [];
    echo 'forms_revision_archives: archived_files=' . (string)($forms['archived_files'] ?? 0)
        . ', archived_bytes=' . (string)($forms['archived_bytes'] ?? 0)
        . ', already_archived=' . (string)($forms['already_archived'] ?? 0)
        . ', missing_live_files=' . (string)($forms['missing_live_files'] ?? 0) . PHP_EOL;

    $zipPurge = $result['forms_archive_purge'] ?? [];
    echo 'forms_archive_purge: deleted_files=' . (string)($zipPurge['deleted_files'] ?? 0)
        . ', deleted_bytes=' . (string)($zipPurge['deleted_bytes'] ?? 0) . PHP_EOL;

    $codeMask = $result['reservation_access_code_mask'] ?? [];
    echo 'reservation_access_code_mask: room_calendar_reservations=' . (string)($codeMask['room_calendar_reservations'] ?? 0)
        . ', reservations=' . (string)($codeMask['reservations'] ?? 0)
        . ', switchbot_passcode_requests=' . (string)($codeMask['switchbot_passcode_requests'] ?? 0)
        . ', mask_after_days=' . (string)($codeMask['mask_after_days'] ?? 0) . PHP_EOL;

    $switchbot = $result['switchbot_detail_cleanup'] ?? [];
    echo 'switchbot_detail_cleanup: deleted_files=' . (string)($switchbot['deleted_files'] ?? 0)
        . ', updated_rows=' . (string)($switchbot['updated_rows'] ?? 0) . PHP_EOL;

    $webhook = $result['switchbot_webhook_trim'] ?? [];
    echo 'switchbot_webhook_trim: deleted_lines=' . (string)($webhook['deleted_lines'] ?? 0)
        . ', kept_lines=' . (string)($webhook['kept_lines'] ?? 0) . PHP_EOL;

    $dbRows = $result['db_prune'] ?? [];
    foreach ($dbRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        echo 'db_prune[' . (string)($row['label'] ?? '') . ']: deleted_rows=' . (string)($row['deleted_rows'] ?? 0) . PHP_EOL;
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[storage_cleanup] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
