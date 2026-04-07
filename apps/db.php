<?php
declare(strict_types=1);

function db_connect(array $cfg): PDO
{
    if (!isset($cfg['db']) || !is_array($cfg['db'])) {
        throw new RuntimeException('DB設定が config.php にありません。');
    }

    $db = $cfg['db'];
    $dsn = trim((string)($db['dsn'] ?? ''));
    $user = (string)($db['user'] ?? '');
    $password = (string)($db['password'] ?? '');

    if ($dsn === '') {
        throw new RuntimeException('DB接続用の DSN が未設定です。');
    }

    return new PDO(
        $dsn,
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function reservation_room_label(string $roomCode): string
{
    return match ($roomCode) {
        'tamoku' => '多目的室',
        'orange' => 'オレンジの部屋',
        default => $roomCode,
    };
}

function reservation_schema_sql(): string
{
    return <<<SQL
CREATE TABLE IF NOT EXISTS reservations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_token CHAR(32) NOT NULL,
    email VARCHAR(255) NOT NULL,
    organization_name VARCHAR(150) NOT NULL,
    room_code VARCHAR(32) NOT NULL,
    room_label VARCHAR(64) NOT NULL,
    use_date DATE NOT NULL,
    use_date_end DATE NOT NULL,
    selected_dates_count INT UNSIGNED NOT NULL DEFAULT 1,
    usage_start_time CHAR(5) NOT NULL,
    usage_end_time CHAR(5) NOT NULL,
    usage_time VARCHAR(20) NOT NULL,
    access_code CHAR(12) DEFAULT NULL,
    reservation_status VARCHAR(32) NOT NULL DEFAULT 'pending',
    status_reason VARCHAR(500) DEFAULT NULL,
    switchbot_status VARCHAR(32) NOT NULL DEFAULT 'queued',
    switchbot_message VARCHAR(500) DEFAULT NULL,
    google_sync_status VARCHAR(32) NOT NULL DEFAULT 'pending',
    google_sync_message VARCHAR(500) DEFAULT NULL,
    user_mail_status VARCHAR(32) NOT NULL DEFAULT 'pending',
    admin_mail_status VARCHAR(32) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reservations_request_token (request_token),
    KEY idx_reservations_room_date (room_code, use_date),
    KEY idx_reservations_use_date_end (use_date_end),
    KEY idx_reservations_status (reservation_status),
    KEY idx_reservations_email (email),
    KEY idx_reservations_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS room_calendar_reservations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id BIGINT UNSIGNED NOT NULL,
    use_date DATE NOT NULL,
    room_code VARCHAR(32) NOT NULL,
    room_label VARCHAR(64) NOT NULL,
    organization_name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NOT NULL,
    usage_start_time CHAR(5) NOT NULL,
    usage_end_time CHAR(5) NOT NULL,
    usage_time VARCHAR(20) NOT NULL,
    access_code CHAR(12) NOT NULL,
    access_code_start_at DATETIME NOT NULL,
    access_code_end_at DATETIME NOT NULL,
    switchbot_status VARCHAR(32) NOT NULL DEFAULT 'queued',
    switchbot_request_id VARCHAR(120) DEFAULT NULL,
    switchbot_command_id VARCHAR(120) DEFAULT NULL,
    switchbot_message VARCHAR(500) DEFAULT NULL,
    google_event_id VARCHAR(255) DEFAULT NULL,
    google_calendar_id VARCHAR(255) DEFAULT NULL,
    google_sync_status VARCHAR(32) NOT NULL DEFAULT 'pending',
    google_sync_message VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_room_calendar_reservations_room_date (room_code, use_date),
    KEY idx_room_calendar_reservations_reservation_id (reservation_id),
    KEY idx_room_calendar_reservations_use_date (use_date),
    KEY idx_room_calendar_reservations_switchbot_status (switchbot_status),
    KEY idx_room_calendar_reservations_google_sync_status (google_sync_status),
    CONSTRAINT fk_room_calendar_reservations_reservation FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservation_mail_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id BIGINT UNSIGNED NOT NULL,
    mail_kind VARCHAR(32) NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    send_status VARCHAR(32) NOT NULL,
    error_message VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reservation_mail_logs_reservation_id (reservation_id),
    KEY idx_reservation_mail_logs_mail_kind (mail_kind),
    KEY idx_reservation_mail_logs_created_at (created_at),
    CONSTRAINT fk_reservation_mail_logs_reservation FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS switchbot_passcode_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    local_request_id VARCHAR(120) NOT NULL,
    command_id VARCHAR(120) DEFAULT NULL,
    room_code VARCHAR(32) NOT NULL,
    room_label VARCHAR(64) NOT NULL,
    device_id VARCHAR(128) NOT NULL,
    device_name VARCHAR(191) DEFAULT NULL,
    passcode_name VARCHAR(100) NOT NULL,
    passcode VARCHAR(20) DEFAULT NULL,
    start_at DATETIME DEFAULT NULL,
    end_at DATETIME DEFAULT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'queued',
    result VARCHAR(255) DEFAULT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    webhook_received_at DATETIME DEFAULT NULL,
    detail_json_path VARCHAR(255) DEFAULT NULL,
    event_name VARCHAR(100) DEFAULT NULL,
    event_device_type VARCHAR(100) DEFAULT NULL,
    event_device_mac VARCHAR(100) DEFAULT NULL,
    time_of_sample BIGINT DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_switchbot_passcode_requests_local_request_id (local_request_id),
    UNIQUE KEY uq_switchbot_passcode_requests_command_id (command_id),
    KEY idx_switchbot_passcode_requests_room_code (room_code),
    KEY idx_switchbot_passcode_requests_status (status),
    KEY idx_switchbot_passcode_requests_requested_at (requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
}

function reservation_table_exists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name');
    $stmt->execute([':table_name' => $tableName]);
    return (int)$stmt->fetchColumn() > 0;
}

function reservation_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name');
    $stmt->execute([
        ':table_name' => $tableName,
        ':column_name' => $columnName,
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

function reservation_index_exists(PDO $pdo, string $tableName, string $indexName): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name');
    $stmt->execute([
        ':table_name' => $tableName,
        ':index_name' => $indexName,
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

function reservation_add_column_if_missing(PDO $pdo, string $tableName, string $columnName, string $definition): void
{
    if (reservation_column_exists($pdo, $tableName, $columnName)) {
        return;
    }
    $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $tableName, $columnName, $definition));
}

function reservation_add_index_if_missing(PDO $pdo, string $tableName, string $indexName, string $definition): void
{
    if (reservation_index_exists($pdo, $tableName, $indexName)) {
        return;
    }
    $pdo->exec(sprintf('ALTER TABLE `%s` ADD %s', $tableName, $definition));
}

function reservation_migrate_existing_schema(PDO $pdo): void
{
    if (reservation_table_exists($pdo, 'reservations')) {
        reservation_add_column_if_missing($pdo, 'reservations', 'room_code', "VARCHAR(32) NOT NULL DEFAULT '' AFTER `organization_name`");
        reservation_add_column_if_missing($pdo, 'reservations', 'room_label', "VARCHAR(64) NOT NULL DEFAULT '' AFTER `room_code`");
        reservation_add_column_if_missing($pdo, 'reservations', 'use_date_end', "DATE NOT NULL DEFAULT '1970-01-01' AFTER `use_date`");
        reservation_add_column_if_missing($pdo, 'reservations', 'selected_dates_count', "INT UNSIGNED NOT NULL DEFAULT 1 AFTER `use_date_end`");
        reservation_add_column_if_missing($pdo, 'reservations', 'usage_start_time', "CHAR(5) NOT NULL DEFAULT '09:00' AFTER `selected_dates_count`");
        reservation_add_column_if_missing($pdo, 'reservations', 'usage_end_time', "CHAR(5) NOT NULL DEFAULT '10:00' AFTER `usage_start_time`");
        reservation_add_column_if_missing($pdo, 'reservations', 'usage_time', "VARCHAR(20) NOT NULL DEFAULT '09:00~10:00' AFTER `usage_end_time`");
        reservation_add_column_if_missing($pdo, 'reservations', 'access_code', "CHAR(12) DEFAULT NULL AFTER `usage_time`");
        reservation_add_column_if_missing($pdo, 'reservations', 'reservation_status', "VARCHAR(32) NOT NULL DEFAULT 'pending' AFTER `access_code`");
        reservation_add_column_if_missing($pdo, 'reservations', 'status_reason', "VARCHAR(500) DEFAULT NULL AFTER `reservation_status`");
        reservation_add_column_if_missing($pdo, 'reservations', 'switchbot_status', "VARCHAR(32) NOT NULL DEFAULT 'queued' AFTER `status_reason`");
        reservation_add_column_if_missing($pdo, 'reservations', 'switchbot_message', "VARCHAR(500) DEFAULT NULL AFTER `switchbot_status`");
        reservation_add_column_if_missing($pdo, 'reservations', 'google_sync_status', "VARCHAR(32) NOT NULL DEFAULT 'pending' AFTER `switchbot_message`");
        reservation_add_column_if_missing($pdo, 'reservations', 'google_sync_message', "VARCHAR(500) DEFAULT NULL AFTER `google_sync_status`");
        reservation_add_column_if_missing($pdo, 'reservations', 'user_mail_status', "VARCHAR(32) NOT NULL DEFAULT 'pending' AFTER `google_sync_message`");
        reservation_add_column_if_missing($pdo, 'reservations', 'admin_mail_status', "VARCHAR(32) NOT NULL DEFAULT 'pending' AFTER `user_mail_status`");
        reservation_add_column_if_missing($pdo, 'reservations', 'updated_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");

        $pdo->exec("UPDATE `reservations` SET `use_date_end` = `use_date` WHERE `use_date_end` = '1970-01-01' OR `use_date_end` IS NULL");
        $pdo->exec("UPDATE `reservations` SET `room_label` = CASE `room_code` WHEN 'tamoku' THEN '多目的室' WHEN 'orange' THEN 'オレンジの部屋' ELSE `room_code` END WHERE `room_label` = ''");

        reservation_add_index_if_missing($pdo, 'reservations', 'idx_reservations_room_date', 'KEY `idx_reservations_room_date` (`room_code`, `use_date`)');
        reservation_add_index_if_missing($pdo, 'reservations', 'idx_reservations_use_date_end', 'KEY `idx_reservations_use_date_end` (`use_date_end`)');
        reservation_add_index_if_missing($pdo, 'reservations', 'idx_reservations_status', 'KEY `idx_reservations_status` (`reservation_status`)');
        reservation_add_index_if_missing($pdo, 'reservations', 'idx_reservations_email', 'KEY `idx_reservations_email` (`email`)');
        reservation_add_index_if_missing($pdo, 'reservations', 'idx_reservations_created_at', 'KEY `idx_reservations_created_at` (`created_at`)');
    }

    if (reservation_table_exists($pdo, 'room_calendar_reservations')) {
        reservation_add_column_if_missing($pdo, 'room_calendar_reservations', 'reservation_id', 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `id`');
        reservation_add_column_if_missing($pdo, 'room_calendar_reservations', 'usage_start_time', "CHAR(5) NOT NULL DEFAULT '09:00' AFTER `email`");
        reservation_add_column_if_missing($pdo, 'room_calendar_reservations', 'usage_end_time', "CHAR(5) NOT NULL DEFAULT '10:00' AFTER `usage_start_time`");
        reservation_add_column_if_missing($pdo, 'room_calendar_reservations', 'usage_time', "VARCHAR(20) NOT NULL DEFAULT '09:00~10:00' AFTER `usage_end_time`");
        reservation_add_column_if_missing($pdo, 'room_calendar_reservations', 'access_code_start_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `access_code`');
        reservation_add_column_if_missing($pdo, 'room_calendar_reservations', 'access_code_end_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `access_code_start_at`');
        reservation_add_column_if_missing($pdo, 'room_calendar_reservations', 'switchbot_request_id', 'VARCHAR(120) DEFAULT NULL AFTER `switchbot_status`');
        reservation_add_column_if_missing($pdo, 'room_calendar_reservations', 'switchbot_command_id', 'VARCHAR(120) DEFAULT NULL AFTER `switchbot_request_id`');
        reservation_add_column_if_missing($pdo, 'room_calendar_reservations', 'switchbot_message', 'VARCHAR(500) DEFAULT NULL AFTER `switchbot_command_id`');
        reservation_add_column_if_missing($pdo, 'room_calendar_reservations', 'google_event_id', 'VARCHAR(255) DEFAULT NULL AFTER `switchbot_message`');
        reservation_add_column_if_missing($pdo, 'room_calendar_reservations', 'google_calendar_id', 'VARCHAR(255) DEFAULT NULL AFTER `google_event_id`');
        reservation_add_column_if_missing($pdo, 'room_calendar_reservations', 'google_sync_message', 'VARCHAR(500) DEFAULT NULL AFTER `google_sync_status`');
        reservation_add_column_if_missing($pdo, 'room_calendar_reservations', 'updated_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`');

        reservation_add_index_if_missing($pdo, 'room_calendar_reservations', 'idx_room_calendar_reservations_reservation_id', 'KEY `idx_room_calendar_reservations_reservation_id` (`reservation_id`)');
        reservation_add_index_if_missing($pdo, 'room_calendar_reservations', 'idx_room_calendar_reservations_use_date', 'KEY `idx_room_calendar_reservations_use_date` (`use_date`)');
        reservation_add_index_if_missing($pdo, 'room_calendar_reservations', 'idx_room_calendar_reservations_switchbot_status', 'KEY `idx_room_calendar_reservations_switchbot_status` (`switchbot_status`)');
        reservation_add_index_if_missing($pdo, 'room_calendar_reservations', 'idx_room_calendar_reservations_google_sync_status', 'KEY `idx_room_calendar_reservations_google_sync_status` (`google_sync_status`)');
    }
}

function reservation_install_schema(PDO $pdo): void
{
    static $installed = false;
    if ($installed) {
        return;
    }
    $installed = true;

    $statements = preg_split('/;\s*(?:\R|$)/u', reservation_schema_sql()) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }

    reservation_migrate_existing_schema($pdo);
}

function reservation_fetch_detail_rows(PDO $pdo, int $reservationId): array
{
    reservation_install_schema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM room_calendar_reservations WHERE reservation_id = :reservation_id ORDER BY use_date ASC, id ASC');
    $stmt->execute([':reservation_id' => $reservationId]);
    return $stmt->fetchAll();
}

function reservation_fetch_by_token(PDO $pdo, string $token): ?array
{
    reservation_install_schema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM reservations WHERE request_token = :token LIMIT 1');
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }

    $detailRows = reservation_fetch_detail_rows($pdo, (int)$row['id']);
    $row['date_rows'] = $detailRows;
    $row['use_dates'] = array_values(array_map(static fn(array $r): string => (string)($r['use_date'] ?? ''), $detailRows));

    return $row;
}

function reservation_fetch_month_status(PDO $pdo, int $year, int $month): array
{
    reservation_install_schema($pdo);

    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd = (new DateTimeImmutable($monthStart))->modify('+1 month')->format('Y-m-d');

    $stmt = $pdo->prepare(
        'SELECT use_date, room_code FROM room_calendar_reservations '
        . 'WHERE use_date >= :month_start AND use_date < :month_end '
        . 'ORDER BY use_date ASC, room_code ASC'
    );
    $stmt->execute([
        ':month_start' => $monthStart,
        ':month_end' => $monthEnd,
    ]);

    $daysInMonth = (int)(new DateTimeImmutable($monthStart))->format('t');
    $result = [];
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $result[$dateKey] = [
            'tamoku' => false,
            'orange' => false,
        ];
    }

    foreach ($stmt->fetchAll() as $row) {
        $dateKey = (string)($row['use_date'] ?? '');
        $roomCode = (string)($row['room_code'] ?? '');
        if (isset($result[$dateKey][$roomCode])) {
            $result[$dateKey][$roomCode] = true;
        }
    }

    return $result;
}
