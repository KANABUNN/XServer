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
