<?php
declare(strict_types=1);

require_once __DIR__ . '/smtp_mailer.php';

function storage_maintenance_array_merge(array $base, array $override): array
{
    foreach ($override as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
            $base[$key] = storage_maintenance_array_merge($base[$key], $value);
            continue;
        }
        $base[$key] = $value;
    }
    return $base;
}

function storage_maintenance_config_candidates(): array
{
    return [
        __DIR__ . '/config.php',
        __DIR__ . '/config_up.php',
        dirname(__DIR__) . '/includes/config.php',
    ];
}

function storage_maintenance_load_base_config(): array
{
    if (isset($GLOBALS['config']) && is_array($GLOBALS['config'])) {
        return $GLOBALS['config'];
    }

    foreach (storage_maintenance_config_candidates() as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }
        $cfg = require $candidate;
        if (is_array($cfg)) {
            return $cfg;
        }
    }

    throw new RuntimeException('storage maintenance 用の設定ファイルを読み込めません。');
}

function storage_maintenance_default_config(array $base): array
{
    $timezone = (string)($base['timezone'] ?? 'Asia/Tokyo');
    $reportTo = trim((string)($base['reservation_admin_notify_to'] ?? $base['from_addr'] ?? ''));

    return [
        'storage_maintenance' => [
            'enabled' => true,
            'timezone' => $timezone,
            'batch_size' => 500,
            'state_dir' => __DIR__ . '/storage/maintenance',
            'log_file' => __DIR__ . '/storage/maintenance/storage_cleanup.log',
            'report_dir' => __DIR__ . '/storage/maintenance/reports',
            'tmp_dir' => __DIR__ . '/storage/maintenance/tmp',
            'temp_file_retention_days' => 2,
            'report_retention_days' => 1095,
            'report_mail_to' => $reportTo,
            'send_report_mail' => true,
            'forms_uploads' => [
                'live_root' => __DIR__ . '/forms_uploads',
                'archive_root' => __DIR__ . '/storage/archives/forms_uploads',
                'archive_after_days' => 180,
                'zip_retention_days' => 1095,
            ],
            'switchbot' => [
                'detail_file_retention_days' => 90,
                'webhook_event_retention_days' => 90,
            ],
            'reservation_access_codes' => [
                'mask_after_days' => 30,
            ],
            'db_retention_tables' => [
                [
                    'connection' => 'account',
                    'table' => 'admin_audit_logs',
                    'date_column' => 'created_at',
                    'retention_days' => 1461,
                    'label' => 'fitsc_account.admin_audit_logs',
                ],
                [
                    'connection' => 'book',
                    'table' => 'admin_audit_logs',
                    'date_column' => 'created_at',
                    'retention_days' => 1461,
                    'label' => 'fitsc_book.admin_audit_logs',
                ],
                [
                    'connection' => 'lend',
                    'table' => 'audit_logs',
                    'date_column' => 'created_at',
                    'retention_days' => 1461,
                    'label' => 'fitsc_lend.audit_logs',
                ],
                [
                    'connection' => 'forms',
                    'table' => 'managed_form_submission_status_logs',
                    'date_column' => 'created_at',
                    'retention_days' => 365,
                    'label' => 'fitsc_forms.managed_form_submission_status_logs',
                ],
                [
                    'connection' => 'book',
                    'table' => 'reservation_mail_logs',
                    'date_column' => 'created_at',
                    'retention_days' => 180,
                    'label' => 'fitsc_book.reservation_mail_logs',
                ],
            ],
        ],
    ];
}

function storage_maintenance_config(): array
{
    static $cfg = null;
    if (is_array($cfg)) {
        return $cfg;
    }

    $base = storage_maintenance_load_base_config();
    $cfg = storage_maintenance_array_merge(
        storage_maintenance_default_config($base),
        $base
    );

    return $cfg;
}

function storage_maintenance_settings(array $cfg): array
{
    $settings = $cfg['storage_maintenance'] ?? [];
    if (!is_array($settings)) {
        $settings = [];
    }
    return $settings;
}

function storage_maintenance_is_enabled(array $cfg): bool
{
    return (bool)(storage_maintenance_settings($cfg)['enabled'] ?? true);
}

function storage_maintenance_resolve_path(string $path): string
{
    $trimmed = trim($path);
    if ($trimmed === '') {
        return __DIR__;
    }

    if ($trimmed[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $trimmed) === 1) {
        return $trimmed;
    }

    return __DIR__ . '/' . ltrim($trimmed, '/');
}

function storage_maintenance_ensure_dir(string $path): string
{
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('ディレクトリを作成できません: ' . $path);
    }
    return $path;
}

function storage_maintenance_state_dir(array $cfg): string
{
    return storage_maintenance_ensure_dir(
        storage_maintenance_resolve_path((string)(storage_maintenance_settings($cfg)['state_dir'] ?? (__DIR__ . '/storage/maintenance')))
    );
}

function storage_maintenance_log_file(array $cfg): string
{
    $settings = storage_maintenance_settings($cfg);
    $path = storage_maintenance_resolve_path((string)($settings['log_file'] ?? (__DIR__ . '/storage/maintenance/storage_cleanup.log')));
    storage_maintenance_ensure_dir(dirname($path));
    return $path;
}

function storage_maintenance_report_dir(array $cfg): string
{
    $settings = storage_maintenance_settings($cfg);
    return storage_maintenance_ensure_dir(
        storage_maintenance_resolve_path((string)($settings['report_dir'] ?? (__DIR__ . '/storage/maintenance/reports')))
    );
}

function storage_maintenance_tmp_dir(array $cfg): string
{
    $settings = storage_maintenance_settings($cfg);
    return storage_maintenance_ensure_dir(
        storage_maintenance_resolve_path((string)($settings['tmp_dir'] ?? (__DIR__ . '/storage/maintenance/tmp')))
    );
}

function storage_maintenance_timezone(array $cfg): DateTimeZone
{
    $tz = (string)(storage_maintenance_settings($cfg)['timezone'] ?? $cfg['timezone'] ?? 'Asia/Tokyo');
    return new DateTimeZone($tz !== '' ? $tz : 'Asia/Tokyo');
}

function storage_maintenance_now(array $cfg): DateTimeImmutable
{
    return new DateTimeImmutable('now', storage_maintenance_timezone($cfg));
}

function storage_maintenance_iso_time(array $cfg): string
{
    return storage_maintenance_now($cfg)->format('Y-m-d H:i:s');
}

function storage_maintenance_log(array $cfg, string $message, array $context = []): void
{
    $line = '[' . storage_maintenance_iso_time($cfg) . '] ' . $message;
    if ($context !== []) {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            $line .= ' | ' . $json;
        }
    }
    file_put_contents(storage_maintenance_log_file($cfg), $line . PHP_EOL, FILE_APPEND);
}

function storage_maintenance_batch_size(array $cfg): int
{
    $size = (int)(storage_maintenance_settings($cfg)['batch_size'] ?? 500);
    return $size > 0 ? $size : 500;
}

function storage_maintenance_db_config(array $cfg, string $connection): array
{
    $all = $cfg['db_connections'] ?? [];
    if (is_array($all) && isset($all[$connection]) && is_array($all[$connection])) {
        return $all[$connection];
    }

    return match ($connection) {
        'account' => (array)($cfg['account_db'] ?? []),
        'book' => (array)($cfg['db'] ?? []),
        'forms' => (array)($cfg['forms_db'] ?? []),
        'lend' => (array)($cfg['lend_db'] ?? []),
        default => [],
    };
}

function storage_maintenance_dsn(array $db, ?string $fallbackDbName = null): string
{
    $dsn = trim((string)($db['dsn'] ?? ''));
    if ($dsn !== '') {
        return $dsn;
    }

    $driver = (string)($db['driver'] ?? 'mysql');
    if ($driver !== 'mysql') {
        throw new RuntimeException('storage maintenance は mysql のみ対応しています。');
    }

    $host = (string)($db['host'] ?? 'localhost');
    $port = (int)($db['port'] ?? 3306);
    $dbname = (string)($db['dbname'] ?? $db['database'] ?? $fallbackDbName ?? '');
    $charset = (string)($db['charset'] ?? 'utf8mb4');

    if ($dbname === '') {
        throw new RuntimeException('DB 名が未設定です。');
    }

    return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbname, $charset);
}

function storage_maintenance_db(array $cfg, string $connection): PDO
{
    static $pool = [];

    if (isset($pool[$connection]) && $pool[$connection] instanceof PDO) {
        return $pool[$connection];
    }

    $db = storage_maintenance_db_config($cfg, $connection);
    if ($db === []) {
        throw new RuntimeException('接続設定が見つかりません: ' . $connection);
    }

    $user = (string)($db['user'] ?? $db['username'] ?? '');
    $pass = (string)($db['pass'] ?? $db['password'] ?? '');
    $dsn = storage_maintenance_dsn($db);

    $pool[$connection] = new PDO(
        $dsn,
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pool[$connection];
}

function storage_maintenance_forms_live_root(array $cfg): string
{
    $settings = storage_maintenance_settings($cfg);
    $forms = is_array($settings['forms_uploads'] ?? null) ? $settings['forms_uploads'] : [];
    return storage_maintenance_ensure_dir(
        storage_maintenance_resolve_path((string)($forms['live_root'] ?? (__DIR__ . '/forms_uploads')))
    );
}

function storage_maintenance_forms_archive_root(array $cfg): string
{
    $settings = storage_maintenance_settings($cfg);
    $forms = is_array($settings['forms_uploads'] ?? null) ? $settings['forms_uploads'] : [];
    return storage_maintenance_ensure_dir(
        storage_maintenance_resolve_path((string)($forms['archive_root'] ?? (__DIR__ . '/storage/archives/forms_uploads')))
    );
}

function storage_maintenance_forms_archive_after_days(array $cfg): int
{
    $settings = storage_maintenance_settings($cfg);
    $forms = is_array($settings['forms_uploads'] ?? null) ? $settings['forms_uploads'] : [];
    $days = (int)($forms['archive_after_days'] ?? 180);
    return $days > 0 ? $days : 180;
}

function storage_maintenance_forms_zip_retention_days(array $cfg): int
{
    $settings = storage_maintenance_settings($cfg);
    $forms = is_array($settings['forms_uploads'] ?? null) ? $settings['forms_uploads'] : [];
    $days = (int)($forms['zip_retention_days'] ?? 1095);
    return $days > 0 ? $days : 1095;
}

function storage_maintenance_forms_archive_zip_path(array $cfg, string $relativePath): string
{
    $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
    if (preg_match('#^(\d{4})/(\d{2})/#', $normalized, $matches) === 1) {
        $year = $matches[1];
        $month = $matches[2];
        $dir = storage_maintenance_ensure_dir(storage_maintenance_forms_archive_root($cfg) . '/' . $year . '/' . $month);
        return $dir . '/forms_revisions_' . $year . '-' . $month . '.zip';
    }

    $dir = storage_maintenance_ensure_dir(storage_maintenance_forms_archive_root($cfg) . '/misc');
    return $dir . '/forms_revisions_misc.zip';
}

function storage_maintenance_forms_archive_contains(string $zipPath, string $relativePath): bool
{
    if (!is_file($zipPath)) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return false;
    }

    try {
        return $zip->locateName(ltrim(str_replace('\\', '/', $relativePath), '/')) !== false;
    } finally {
        $zip->close();
    }
}

function storage_maintenance_forms_archive_upload(array $cfg, string $relativePath, string $sourcePath): array
{
    $zipPath = storage_maintenance_forms_archive_zip_path($cfg, $relativePath);
    storage_maintenance_ensure_dir(dirname($zipPath));

    $zip = new ZipArchive();
    $openResult = $zip->open($zipPath, ZipArchive::CREATE);
    if ($openResult !== true) {
        throw new RuntimeException('ZIP を開けませんでした: ' . $zipPath . ' (' . $openResult . ')');
    }

    $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
    $alreadyArchived = false;
    $added = false;

    try {
        if ($zip->locateName($normalized) !== false) {
            $alreadyArchived = true;
        } else {
            if (!$zip->addFile($sourcePath, $normalized)) {
                throw new RuntimeException('ZIP への追加に失敗しました: ' . $normalized);
            }
            $added = true;
        }
    } finally {
        $zip->close();
    }

    return [
        'zip_path' => $zipPath,
        'already_archived' => $alreadyArchived,
        'added' => $added,
    ];
}

function storage_maintenance_remove_empty_dirs(string $path, string $stopAt): void
{
    $path = rtrim($path, DIRECTORY_SEPARATOR);
    $stopAt = rtrim($stopAt, DIRECTORY_SEPARATOR);
    while ($path !== '' && $path !== $stopAt && str_starts_with($path, $stopAt)) {
        if (!is_dir($path)) {
            $path = dirname($path);
            continue;
        }
        $items = scandir($path);
        if ($items === false || count($items) > 2) {
            break;
        }
        @rmdir($path);
        $path = dirname($path);
    }
}

function storage_maintenance_forms_extract_archived_upload(array $cfg, string $relativePath): ?string
{
    $zipPath = storage_maintenance_forms_archive_zip_path($cfg, $relativePath);
    if (!is_file($zipPath)) {
        return null;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return null;
    }

    $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
    $stream = $zip->getStream($normalized);
    if ($stream === false) {
        $zip->close();
        return null;
    }

    $targetDir = storage_maintenance_ensure_dir(storage_maintenance_tmp_dir($cfg) . '/forms_downloads/' . date('Ymd'));
    $targetPath = $targetDir . '/' . bin2hex(random_bytes(12)) . '-' . basename($normalized);
    $out = fopen($targetPath, 'wb');
    if ($out === false) {
        fclose($stream);
        $zip->close();
        throw new RuntimeException('一時展開ファイルを作成できません。');
    }

    try {
        stream_copy_to_stream($stream, $out);
    } finally {
        fclose($stream);
        fclose($out);
        $zip->close();
    }

    return is_file($targetPath) ? $targetPath : null;
}

function storage_maintenance_cleanup_temp_files(array $cfg, bool $dryRun = false): array
{
    $baseDir = storage_maintenance_tmp_dir($cfg);
    $days = (int)(storage_maintenance_settings($cfg)['temp_file_retention_days'] ?? 2);
    $cutoff = storage_maintenance_now($cfg)->modify('-' . max(1, $days) . ' days')->getTimestamp();

    $deletedFiles = 0;
    $deletedBytes = 0;
    $deletedDirs = 0;

    if (!is_dir($baseDir)) {
        return [
            'deleted_files' => 0,
            'deleted_bytes' => 0,
            'deleted_dirs' => 0,
            'path' => $baseDir,
        ];
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $path = $item->getPathname();
        if ($item->isFile()) {
            if ($item->getMTime() >= $cutoff) {
                continue;
            }
            $deletedFiles++;
            $deletedBytes += $item->getSize();
            if (!$dryRun) {
                @unlink($path);
            }
            continue;
        }

        if ($item->isDir()) {
            $dirItems = scandir($path);
            if ($dirItems !== false && count($dirItems) <= 2) {
                $deletedDirs++;
                if (!$dryRun) {
                    @rmdir($path);
                }
            }
        }
    }

    return [
        'deleted_files' => $deletedFiles,
        'deleted_bytes' => $deletedBytes,
        'deleted_dirs' => $deletedDirs,
        'path' => $baseDir,
    ];
}

function storage_maintenance_cleanup_report_files(array $cfg, bool $dryRun = false): array
{
    $reportDir = storage_maintenance_report_dir($cfg);
    $days = (int)(storage_maintenance_settings($cfg)['report_retention_days'] ?? 1095);
    $cutoff = storage_maintenance_now($cfg)->modify('-' . max(1, $days) . ' days')->getTimestamp();

    $deletedFiles = 0;
    $deletedBytes = 0;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($reportDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iter as $item) {
        if (!$item->isFile()) {
            continue;
        }
        if ($item->getMTime() >= $cutoff) {
            continue;
        }
        $deletedFiles++;
        $deletedBytes += $item->getSize();
        if (!$dryRun) {
            @unlink($item->getPathname());
        }
    }

    return [
        'deleted_files' => $deletedFiles,
        'deleted_bytes' => $deletedBytes,
        'path' => $reportDir,
    ];
}

function storage_maintenance_archive_forms_revision_uploads(array $cfg, bool $dryRun = false): array
{
    $pdo = storage_maintenance_db($cfg, 'forms');
    $liveRoot = storage_maintenance_forms_live_root($cfg);
    $cutoff = storage_maintenance_now($cfg)->modify('-' . storage_maintenance_forms_archive_after_days($cfg) . ' days')->format('Y-m-d H:i:s');

    $currentPaths = [];
    $currentStmt = $pdo->query('SELECT uploaded_relative_path FROM managed_form_submissions WHERE uploaded_relative_path IS NOT NULL AND uploaded_relative_path != ""');
    foreach ($currentStmt as $row) {
        $path = trim((string)($row['uploaded_relative_path'] ?? ''));
        if ($path !== '') {
            $currentPaths[str_replace('\\', '/', $path)] = true;
        }
    }

    $stmt = $pdo->prepare(
        'SELECT uploaded_relative_path, MAX(created_at) AS last_revision_at, COUNT(*) AS revision_refs
         FROM managed_form_submission_revisions
         WHERE uploaded_relative_path IS NOT NULL
           AND uploaded_relative_path != ""
         GROUP BY uploaded_relative_path
         HAVING MAX(created_at) < :cutoff'
    );
    $stmt->execute([':cutoff' => $cutoff]);

    $candidates = 0;
    $archivedFiles = 0;
    $archivedBytes = 0;
    $alreadyArchived = 0;
    $missingLiveFiles = 0;
    $skippedCurrent = 0;

    foreach ($stmt as $row) {
        $relativePath = str_replace('\\', '/', trim((string)($row['uploaded_relative_path'] ?? '')));
        if ($relativePath === '') {
            continue;
        }

        $candidates++;
        if (isset($currentPaths[$relativePath])) {
            $skippedCurrent++;
            continue;
        }

        $livePath = $liveRoot . '/' . ltrim($relativePath, '/');
        if (!is_file($livePath)) {
            $zipPath = storage_maintenance_forms_archive_zip_path($cfg, $relativePath);
            if (storage_maintenance_forms_archive_contains($zipPath, $relativePath)) {
                $alreadyArchived++;
            } else {
                $missingLiveFiles++;
            }
            continue;
        }

        $size = filesize($livePath) ?: 0;
        if ($dryRun) {
            $archivedFiles++;
            $archivedBytes += $size;
            continue;
        }

        $archiveResult = storage_maintenance_forms_archive_upload($cfg, $relativePath, $livePath);
        if ($archiveResult['already_archived']) {
            $alreadyArchived++;
        } elseif ($archiveResult['added']) {
            $archivedFiles++;
            $archivedBytes += $size;
        }

        if (is_file($livePath) && !@unlink($livePath)) {
            throw new RuntimeException('アーカイブ後の旧版添付削除に失敗しました: ' . $livePath);
        }
        storage_maintenance_remove_empty_dirs(dirname($livePath), $liveRoot);
    }

    return [
        'candidates' => $candidates,
        'archived_files' => $archivedFiles,
        'archived_bytes' => $archivedBytes,
        'already_archived' => $alreadyArchived,
        'missing_live_files' => $missingLiveFiles,
        'skipped_current_paths' => $skippedCurrent,
        'archive_after_days' => storage_maintenance_forms_archive_after_days($cfg),
        'live_root' => $liveRoot,
        'archive_root' => storage_maintenance_forms_archive_root($cfg),
    ];
}

function storage_maintenance_purge_old_form_archives(array $cfg, bool $dryRun = false): array
{
    $archiveRoot = storage_maintenance_forms_archive_root($cfg);
    $days = storage_maintenance_forms_zip_retention_days($cfg);
    $cutoff = storage_maintenance_now($cfg)->modify('-' . $days . ' days')->getTimestamp();

    $deletedFiles = 0;
    $deletedBytes = 0;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($archiveRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iter as $item) {
        $path = $item->getPathname();
        if ($item->isFile() && strtolower($item->getExtension()) === 'zip') {
            if ($item->getMTime() >= $cutoff) {
                continue;
            }
            $deletedFiles++;
            $deletedBytes += $item->getSize();
            if (!$dryRun) {
                @unlink($path);
            }
            continue;
        }

        if ($item->isDir()) {
            $dirItems = scandir($path);
            if ($dirItems !== false && count($dirItems) <= 2 && !$dryRun) {
                @rmdir($path);
            }
        }
    }

    return [
        'deleted_files' => $deletedFiles,
        'deleted_bytes' => $deletedBytes,
        'retention_days' => $days,
        'archive_root' => $archiveRoot,
    ];
}

function storage_maintenance_reservation_access_code_mask_after_days(array $cfg): int
{
    $settings = storage_maintenance_settings($cfg);
    $reservation = is_array($settings['reservation_access_codes'] ?? null) ? $settings['reservation_access_codes'] : [];
    $days = (int)($reservation['mask_after_days'] ?? 30);
    return $days >= 0 ? $days : 30;
}

function storage_maintenance_mask_expired_reservation_access_codes(array $cfg, bool $dryRun = false): array
{
    $pdo = storage_maintenance_db($cfg, 'book');
    $days = storage_maintenance_reservation_access_code_mask_after_days($cfg);
    $cutoff = storage_maintenance_now($cfg)->modify('-' . $days . ' days')->format('Y-m-d H:i:s');

    $slotCountStmt = $pdo->prepare(
        'SELECT COUNT(*)
           FROM room_calendar_reservations
          WHERE access_code IS NOT NULL
            AND access_code != ""
            AND access_code_end_at < :cutoff'
    );
    $slotCountStmt->execute([':cutoff' => $cutoff]);
    $slotMatches = (int)$slotCountStmt->fetchColumn();

    $reservationCountStmt = $pdo->prepare(
        'SELECT COUNT(*)
           FROM reservations r
          WHERE r.access_code IS NOT NULL
            AND r.access_code != ""
            AND NOT EXISTS (
                SELECT 1
                  FROM room_calendar_reservations d
                 WHERE d.reservation_id = r.id
                   AND d.access_code IS NOT NULL
                   AND d.access_code != ""
                   AND d.access_code_end_at >= :cutoff
            )'
    );
    $reservationCountStmt->execute([':cutoff' => $cutoff]);
    $reservationMatches = (int)$reservationCountStmt->fetchColumn();

    $switchbotCountStmt = $pdo->prepare(
        'SELECT COUNT(*)
           FROM switchbot_passcode_requests
          WHERE passcode IS NOT NULL
            AND passcode != ""
            AND end_at IS NOT NULL
            AND end_at < :cutoff'
    );
    $switchbotCountStmt->execute([':cutoff' => $cutoff]);
    $switchbotMatches = (int)$switchbotCountStmt->fetchColumn();

    if (!$dryRun) {
        $slotUpdate = $pdo->prepare(
            'UPDATE room_calendar_reservations
                SET access_code = NULL,
                    updated_at = CURRENT_TIMESTAMP
              WHERE access_code IS NOT NULL
                AND access_code != ""
                AND access_code_end_at < :cutoff'
        );
        $slotUpdate->execute([':cutoff' => $cutoff]);

        $reservationUpdate = $pdo->prepare(
            'UPDATE reservations r
                SET r.access_code = NULL,
                    r.updated_at = CURRENT_TIMESTAMP
              WHERE r.access_code IS NOT NULL
                AND r.access_code != ""
                AND NOT EXISTS (
                    SELECT 1
                      FROM room_calendar_reservations d
                     WHERE d.reservation_id = r.id
                       AND d.access_code IS NOT NULL
                       AND d.access_code != ""
                       AND d.access_code_end_at >= :cutoff
                )'
        );
        $reservationUpdate->execute([':cutoff' => $cutoff]);

        $switchbotUpdate = $pdo->prepare(
            'UPDATE switchbot_passcode_requests
                SET passcode = NULL,
                    updated_at = CURRENT_TIMESTAMP
              WHERE passcode IS NOT NULL
                AND passcode != ""
                AND end_at IS NOT NULL
                AND end_at < :cutoff'
        );
        $switchbotUpdate->execute([':cutoff' => $cutoff]);
    }

    return [
        'cutoff' => $cutoff,
        'mask_after_days' => $days,
        'room_calendar_reservations' => $slotMatches,
        'reservations' => $reservationMatches,
        'switchbot_passcode_requests' => $switchbotMatches,
        'dry_run' => $dryRun,
    ];
}

function storage_maintenance_switchbot_storage_dir(array $cfg): string
{
    $switchbot = $cfg['switchbot'] ?? [];
    if (!is_array($switchbot)) {
        $switchbot = [];
    }

    return storage_maintenance_ensure_dir(
        storage_maintenance_resolve_path((string)($switchbot['storage_dir'] ?? (__DIR__ . '/storage/switchbot')))
    );
}

function storage_maintenance_switchbot_detail_dir(array $cfg): string
{
    $switchbot = $cfg['switchbot'] ?? [];
    if (!is_array($switchbot)) {
        $switchbot = [];
    }

    $detailDir = trim((string)($switchbot['detail_dir'] ?? ''));
    if ($detailDir === '') {
        $detailDir = storage_maintenance_switchbot_storage_dir($cfg) . '/requests';
    }
    return storage_maintenance_ensure_dir(storage_maintenance_resolve_path($detailDir));
}

function storage_maintenance_switchbot_retention_days(array $cfg): int
{
    $settings = storage_maintenance_settings($cfg);
    $switchbot = is_array($settings['switchbot'] ?? null) ? $settings['switchbot'] : [];
    $days = (int)($switchbot['detail_file_retention_days'] ?? 90);
    return $days > 0 ? $days : 90;
}

function storage_maintenance_switchbot_webhook_retention_days(array $cfg): int
{
    $settings = storage_maintenance_settings($cfg);
    $switchbot = is_array($settings['switchbot'] ?? null) ? $settings['switchbot'] : [];
    $days = (int)($switchbot['webhook_event_retention_days'] ?? 90);
    return $days > 0 ? $days : 90;
}

function storage_maintenance_cleanup_switchbot_detail_json(array $cfg, bool $dryRun = false): array
{
    $pdo = storage_maintenance_db($cfg, 'book');
    $detailDir = storage_maintenance_switchbot_detail_dir($cfg);
    $storageDir = storage_maintenance_switchbot_storage_dir($cfg);
    $cutoff = storage_maintenance_now($cfg)->modify('-' . storage_maintenance_switchbot_retention_days($cfg) . ' days')->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'SELECT id, detail_json_path
         FROM switchbot_passcode_requests
         WHERE detail_json_path IS NOT NULL
           AND detail_json_path != ""
           AND requested_at < :cutoff'
    );
    $stmt->execute([':cutoff' => $cutoff]);

    $deletedFiles = 0;
    $deletedBytes = 0;
    $updatedRows = 0;
    $missingFiles = 0;

    $updateStmt = $pdo->prepare('UPDATE switchbot_passcode_requests SET detail_json_path = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id');

    foreach ($stmt as $row) {
        $relative = trim((string)($row['detail_json_path'] ?? ''));
        if ($relative === '') {
            continue;
        }

        $candidate = str_starts_with($relative, 'requests/')
            ? $storageDir . '/' . ltrim($relative, '/')
            : $detailDir . '/' . basename($relative);

        if (is_file($candidate)) {
            $deletedFiles++;
            $deletedBytes += filesize($candidate) ?: 0;
            if (!$dryRun) {
                @unlink($candidate);
            }
        } else {
            $missingFiles++;
        }

        $updatedRows++;
        if (!$dryRun) {
            $updateStmt->execute([':id' => (int)$row['id']]);
        }
    }

    return [
        'deleted_files' => $deletedFiles,
        'deleted_bytes' => $deletedBytes,
        'updated_rows' => $updatedRows,
        'missing_files' => $missingFiles,
        'retention_days' => storage_maintenance_switchbot_retention_days($cfg),
        'detail_dir' => $detailDir,
    ];
}

function storage_maintenance_trim_switchbot_webhook_events(array $cfg, bool $dryRun = false): array
{
    $path = storage_maintenance_switchbot_storage_dir($cfg) . '/webhook_events.jsonl';
    $days = storage_maintenance_switchbot_webhook_retention_days($cfg);
    $cutoff = storage_maintenance_now($cfg)->modify('-' . $days . ' days');

    if (!is_file($path)) {
        return [
            'file' => $path,
            'kept_lines' => 0,
            'deleted_lines' => 0,
            'before_bytes' => 0,
            'after_bytes' => 0,
            'retention_days' => $days,
        ];
    }

    $beforeBytes = filesize($path) ?: 0;
    $input = fopen($path, 'rb');
    if ($input === false) {
        throw new RuntimeException('webhook_events.jsonl を開けませんでした。');
    }

    $tmpPath = $path . '.tmp';
    $output = fopen($tmpPath, 'wb');
    if ($output === false) {
        fclose($input);
        throw new RuntimeException('一時 JSONL を作成できませんでした。');
    }

    $kept = 0;
    $deleted = 0;

    try {
        while (($line = fgets($input)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $decoded = json_decode($trimmed, true);
            if (!is_array($decoded)) {
                $deleted++;
                continue;
            }

            $receivedAt = trim((string)($decoded['received_at'] ?? ''));
            $keep = true;
            if ($receivedAt !== '') {
                try {
                    $received = new DateTimeImmutable($receivedAt, storage_maintenance_timezone($cfg));
                    $keep = $received >= $cutoff;
                } catch (Throwable) {
                    $keep = false;
                }
            }

            if ($keep) {
                $kept++;
                fwrite($output, $trimmed . PHP_EOL);
            } else {
                $deleted++;
            }
        }
    } finally {
        fclose($input);
        fclose($output);
    }

    $afterBytes = filesize($tmpPath) ?: 0;
    if ($dryRun) {
        @unlink($tmpPath);
    } else {
        if (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new RuntimeException('webhook_events.jsonl の置換に失敗しました。');
        }
    }

    return [
        'file' => $path,
        'kept_lines' => $kept,
        'deleted_lines' => $deleted,
        'before_bytes' => $beforeBytes,
        'after_bytes' => ($dryRun ? $afterBytes : (filesize($path) ?: 0)),
        'retention_days' => $days,
    ];
}

function storage_maintenance_db_retention_tables(array $cfg): array
{
    $settings = storage_maintenance_settings($cfg);
    $tables = $settings['db_retention_tables'] ?? [];
    return is_array($tables) ? array_values($tables) : [];
}

function storage_maintenance_quote_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function storage_maintenance_prune_db_logs(array $cfg, bool $dryRun = false): array
{
    $tables = storage_maintenance_db_retention_tables($cfg);
    $results = [];
    $batchSize = storage_maintenance_batch_size($cfg);

    foreach ($tables as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $connection = (string)($entry['connection'] ?? '');
        $table = (string)($entry['table'] ?? '');
        $dateColumn = (string)($entry['date_column'] ?? 'created_at');
        $retentionDays = (int)($entry['retention_days'] ?? 0);
        $label = (string)($entry['label'] ?? ($connection . '.' . $table));

        if ($connection === '' || $table === '' || $retentionDays <= 0) {
            continue;
        }

        $pdo = storage_maintenance_db($cfg, $connection);
        $cutoff = storage_maintenance_now($cfg)->modify('-' . $retentionDays . ' days')->format('Y-m-d H:i:s');

        $tableSql = storage_maintenance_quote_identifier($table);
        $columnSql = storage_maintenance_quote_identifier($dateColumn);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$tableSql} WHERE {$columnSql} < :cutoff");
        $countStmt->execute([':cutoff' => $cutoff]);
        $matching = (int)$countStmt->fetchColumn();

        $deleted = 0;
        if (!$dryRun && $matching > 0) {
            do {
                $deleteSql = "DELETE FROM {$tableSql} WHERE {$columnSql} < :cutoff LIMIT {$batchSize}";
                $deleteStmt = $pdo->prepare($deleteSql);
                $deleteStmt->execute([':cutoff' => $cutoff]);
                $affected = $deleteStmt->rowCount();
                $deleted += $affected;
            } while ($affected === $batchSize);
        } else {
            $deleted = $matching;
        }

        $results[] = [
            'label' => $label,
            'connection' => $connection,
            'table' => $table,
            'date_column' => $dateColumn,
            'retention_days' => $retentionDays,
            'matched_rows' => $matching,
            'deleted_rows' => $deleted,
            'dry_run' => $dryRun,
        ];
    }

    return $results;
}

function storage_maintenance_run_cleanup(bool $dryRun = false): array
{
    $cfg = storage_maintenance_config();
    if (!storage_maintenance_is_enabled($cfg)) {
        return [
            'ok' => true,
            'skipped' => true,
            'reason' => 'disabled',
        ];
    }

    $summary = [
        'ok' => true,
        'timestamp' => storage_maintenance_iso_time($cfg),
        'dry_run' => $dryRun,
    ];

    $summary['forms_revision_archives'] = storage_maintenance_archive_forms_revision_uploads($cfg, $dryRun);
    $summary['forms_archive_purge'] = storage_maintenance_purge_old_form_archives($cfg, $dryRun);
    $summary['reservation_access_code_mask'] = storage_maintenance_mask_expired_reservation_access_codes($cfg, $dryRun);
    $summary['switchbot_detail_cleanup'] = storage_maintenance_cleanup_switchbot_detail_json($cfg, $dryRun);
    $summary['switchbot_webhook_trim'] = storage_maintenance_trim_switchbot_webhook_events($cfg, $dryRun);
    $summary['db_prune'] = storage_maintenance_prune_db_logs($cfg, $dryRun);
    $summary['temp_cleanup'] = storage_maintenance_cleanup_temp_files($cfg, $dryRun);
    $summary['report_cleanup'] = storage_maintenance_cleanup_report_files($cfg, $dryRun);

    storage_maintenance_log($cfg, 'storage cleanup completed', $summary);
    return $summary;
}

function storage_maintenance_directory_stats(string $path): array
{
    if (!is_dir($path)) {
        return [
            'path' => $path,
            'exists' => false,
            'files' => 0,
            'dirs' => 0,
            'bytes' => 0,
        ];
    }

    $files = 0;
    $dirs = 0;
    $bytes = 0;

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iter as $item) {
        if ($item->isDir()) {
            $dirs++;
            continue;
        }
        if ($item->isFile()) {
            $files++;
            $bytes += $item->getSize();
        }
    }

    return [
        'path' => $path,
        'exists' => true,
        'files' => $files,
        'dirs' => $dirs,
        'bytes' => $bytes,
    ];
}

function storage_maintenance_format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float)$bytes;
    $unitIndex = 0;
    while ($value >= 1024 && $unitIndex < count($units) - 1) {
        $value /= 1024;
        $unitIndex++;
    }
    return number_format($value, $unitIndex === 0 ? 0 : 2) . ' ' . $units[$unitIndex];
}

function storage_maintenance_collect_db_counts(array $cfg): array
{
    $rows = [];

    foreach (storage_maintenance_db_retention_tables($cfg) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $connection = (string)($entry['connection'] ?? '');
        $table = (string)($entry['table'] ?? '');
        $dateColumn = (string)($entry['date_column'] ?? 'created_at');
        $retentionDays = (int)($entry['retention_days'] ?? 0);
        $label = (string)($entry['label'] ?? ($connection . '.' . $table));
        if ($connection === '' || $table === '' || $retentionDays <= 0) {
            continue;
        }

        $pdo = storage_maintenance_db($cfg, $connection);
        $tableSql = storage_maintenance_quote_identifier($table);
        $columnSql = storage_maintenance_quote_identifier($dateColumn);
        $cutoff = storage_maintenance_now($cfg)->modify('-' . $retentionDays . ' days')->format('Y-m-d H:i:s');

        $total = (int)$pdo->query("SELECT COUNT(*) FROM {$tableSql}")->fetchColumn();
        $oldStmt = $pdo->prepare("SELECT COUNT(*) FROM {$tableSql} WHERE {$columnSql} < :cutoff");
        $oldStmt->execute([':cutoff' => $cutoff]);
        $old = (int)$oldStmt->fetchColumn();

        $rows[] = [
            'label' => $label,
            'total_rows' => $total,
            'rows_older_than_retention' => $old,
            'retention_days' => $retentionDays,
        ];
    }

    return $rows;
}

function storage_maintenance_collect_usage_report(): array
{
    $cfg = storage_maintenance_config();

    $sections = [
        'forms_live' => storage_maintenance_directory_stats(storage_maintenance_forms_live_root($cfg)),
        'forms_archive' => storage_maintenance_directory_stats(storage_maintenance_forms_archive_root($cfg)),
        'switchbot_storage' => storage_maintenance_directory_stats(storage_maintenance_switchbot_storage_dir($cfg)),
        'report_dir' => storage_maintenance_directory_stats(storage_maintenance_report_dir($cfg)),
        'tmp_dir' => storage_maintenance_directory_stats(storage_maintenance_tmp_dir($cfg)),
    ];

    return [
        'generated_at' => storage_maintenance_iso_time($cfg),
        'directories' => $sections,
        'db_counts' => storage_maintenance_collect_db_counts($cfg),
        'policies' => [
            'forms_archive_after_days' => storage_maintenance_forms_archive_after_days($cfg),
            'forms_zip_retention_days' => storage_maintenance_forms_zip_retention_days($cfg),
            'reservation_access_code_mask_after_days' => storage_maintenance_reservation_access_code_mask_after_days($cfg),
            'switchbot_detail_retention_days' => storage_maintenance_switchbot_retention_days($cfg),
            'switchbot_webhook_retention_days' => storage_maintenance_switchbot_webhook_retention_days($cfg),
            'report_retention_days' => (int)(storage_maintenance_settings($cfg)['report_retention_days'] ?? 1095),
        ],
    ];
}

function storage_maintenance_render_report_markdown(array $report): string
{
    $lines = [];
    $lines[] = '# ストレージ利用レポート';
    $lines[] = '';
    $lines[] = '- 生成日時: ' . (string)($report['generated_at'] ?? '');
    $lines[] = '';

    $lines[] = '## 保持ポリシー';
    $lines[] = '';
    $policies = is_array($report['policies'] ?? null) ? $report['policies'] : [];
    foreach ($policies as $key => $value) {
        $lines[] = '- ' . $key . ': ' . (string)$value;
    }
    $lines[] = '';

    $lines[] = '## ディレクトリ使用量';
    $lines[] = '';
    $dirs = is_array($report['directories'] ?? null) ? $report['directories'] : [];
    foreach ($dirs as $name => $stats) {
        if (!is_array($stats)) {
            continue;
        }
        $lines[] = '### ' . $name;
        $lines[] = '';
        $lines[] = '- path: ' . (string)($stats['path'] ?? '');
        $lines[] = '- exists: ' . (((bool)($stats['exists'] ?? false)) ? 'yes' : 'no');
        $lines[] = '- files: ' . (string)($stats['files'] ?? 0);
        $lines[] = '- dirs: ' . (string)($stats['dirs'] ?? 0);
        $lines[] = '- bytes: ' . (string)($stats['bytes'] ?? 0) . ' (' . storage_maintenance_format_bytes((int)($stats['bytes'] ?? 0)) . ')';
        $lines[] = '';
    }

    $lines[] = '## DB 蓄積状況';
    $lines[] = '';
    $dbCounts = is_array($report['db_counts'] ?? null) ? $report['db_counts'] : [];
    if ($dbCounts === []) {
        $lines[] = '- 対象テーブルなし';
        $lines[] = '';
    } else {
        foreach ($dbCounts as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lines[] = '- ' . (string)($row['label'] ?? '') . ': total=' . (string)($row['total_rows'] ?? 0)
                . ', older_than_retention=' . (string)($row['rows_older_than_retention'] ?? 0)
                . ', retention_days=' . (string)($row['retention_days'] ?? 0);
        }
        $lines[] = '';
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

function storage_maintenance_write_monthly_report(array $report): string
{
    $cfg = storage_maintenance_config();
    $dir = storage_maintenance_report_dir($cfg);
    $filename = 'storage_report_' . storage_maintenance_now($cfg)->format('Y-m') . '.md';
    $path = $dir . '/' . $filename;
    file_put_contents($path, storage_maintenance_render_report_markdown($report));
    return $path;
}

function storage_maintenance_send_monthly_report(array $report, string $reportPath): array
{
    $cfg = storage_maintenance_config();
    $settings = storage_maintenance_settings($cfg);
    $to = trim((string)($settings['report_mail_to'] ?? ''));
    if ($to === '') {
        return [
            'sent' => false,
            'reason' => 'report_mail_to is empty',
        ];
    }

    $subject = '【月次】ストレージ利用レポート ' . storage_maintenance_now($cfg)->format('Y-m');
    $body = storage_maintenance_render_report_markdown($report);
    $htmlBody = '<pre style="font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; white-space: pre-wrap;">'
        . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</pre>'
        . '<p>保存先: ' . htmlspecialchars($reportPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';

    send_mail_smtp($cfg, [
        'to' => $to,
        'subject' => $subject,
        'body' => $body,
        'html_body' => $htmlBody,
    ]);

    return [
        'sent' => true,
        'to' => $to,
        'subject' => $subject,
    ];
}

function storage_maintenance_run_monthly_report(bool $sendMail = true): array
{
    $cfg = storage_maintenance_config();
    if (!storage_maintenance_is_enabled($cfg)) {
        return [
            'ok' => true,
            'skipped' => true,
            'reason' => 'disabled',
        ];
    }

    $report = storage_maintenance_collect_usage_report();
    $reportPath = storage_maintenance_write_monthly_report($report);

    $mailResult = [
        'sent' => false,
        'reason' => 'send disabled',
    ];
    $settings = storage_maintenance_settings($cfg);
    if ($sendMail && (bool)($settings['send_report_mail'] ?? true)) {
        try {
            $mailResult = storage_maintenance_send_monthly_report($report, $reportPath);
        } catch (Throwable $e) {
            $mailResult = [
                'sent' => false,
                'reason' => $e->getMessage(),
            ];
        }
    }

    $summary = [
        'ok' => true,
        'generated_at' => (string)($report['generated_at'] ?? ''),
        'report_path' => $reportPath,
        'mail' => $mailResult,
        'report' => $report,
    ];

    storage_maintenance_log($cfg, 'storage monthly report completed', [
        'report_path' => $reportPath,
        'mail' => $mailResult,
    ]);

    return $summary;
}
