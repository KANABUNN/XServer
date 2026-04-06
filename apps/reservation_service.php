<?php
declare(strict_types=1);

function reservation_timezone(array $cfg): string
{
    $timezone = trim((string)($cfg['reservation']['timezone'] ?? 'Asia/Tokyo'));
    return $timezone !== '' ? $timezone : 'Asia/Tokyo';
}

function reservation_now(array $cfg): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(reservation_timezone($cfg)));
}

function reservation_allowed_range(array $cfg, ?DateTimeImmutable $base = null): array
{
    $base = $base ?? reservation_now($cfg);
    $baseDay = $base->setTime(0, 0, 0);

    $minDays = max(0, (int)($cfg['reservation']['booking_min_days_before'] ?? 2));
    $maxMonths = max(0, (int)($cfg['reservation']['booking_max_months_ahead'] ?? 2));

    $minDate = $baseDay->modify('+' . $minDays . ' days');
    $maxDate = $baseDay->modify('+' . $maxMonths . ' months');

    return [$minDate, $maxDate];
}

function reservation_validate_form_input(array $cfg, array $source): array
{
    $email = trim((string)($source['email'] ?? ''));
    $organizationName = trim((string)($source['organization_name'] ?? ''));
    $roomCode = trim((string)($source['room_code'] ?? $source['select'] ?? ''));
    $useDate = trim((string)($source['use_date'] ?? ''));
    $agreeTerms = (string)($source['agree_terms'] ?? '') !== '';

    if ($email === '' || $organizationName === '' || $roomCode === '' || $useDate === '') {
        throw new RuntimeException('必須項目が不足しています。');
    }
    if (!$agreeTerms) {
        throw new RuntimeException('利用規約への同意が必要です。');
    }

    $allowedDomain = strtolower(trim((string)($cfg['reservation']['email_domain'] ?? 'bene.fit.ac.jp')));
    if (!preg_match('/^[^@\s]+@([^@\s]+)$/u', $email, $matches)) {
        throw new RuntimeException('メールアドレスの形式が不正です。');
    }
    if (strtolower($matches[1]) !== $allowedDomain) {
        throw new RuntimeException('@' . $allowedDomain . ' のメールアドレスのみ利用できます。');
    }

    if (!in_array($roomCode, ['tamoku', 'orange'], true)) {
        throw new RuntimeException('部屋の指定が不正です。');
    }

    $orgLength = function_exists('mb_strlen') ? mb_strlen($organizationName, 'UTF-8') : strlen($organizationName);
    if ($orgLength > 150) {
        throw new RuntimeException('団体名は150文字以内で入力してください。');
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $useDate, new DateTimeZone(reservation_timezone($cfg)));
    if (!$dt || $dt->format('Y-m-d') !== $useDate) {
        throw new RuntimeException('利用日の形式が不正です。');
    }

    [$minDate, $maxDate] = reservation_allowed_range($cfg);
    if ($dt < $minDate || $dt > $maxDate) {
        throw new RuntimeException(
            '利用日は ' . $minDate->format('Y-m-d') . ' から ' . $maxDate->format('Y-m-d') . ' の範囲で指定してください。'
        );
    }

    return [
        'email' => $email,
        'organization_name' => $organizationName,
        'room_code' => $roomCode,
        'room_label' => reservation_room_label($roomCode),
        'use_date' => $useDate,
    ];
}

function reservation_generate_request_token(): string
{
    return bin2hex(random_bytes(16));
}

function reservation_generate_access_code(PDO $pdo, array $cfg, int $maxAttempts = 50): string
{
    reservation_install_schema($pdo);
    $digits = max(4, (int)($cfg['reservation']['access_code_digits'] ?? 6));
    $min = (int)str_pad('1', $digits, '0');
    $max = (int)str_repeat('9', $digits);

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM reservations '
        . 'WHERE access_code = :access_code '
        . 'AND use_date >= CURDATE() '
        . 'AND reservation_status IN ("pending","confirmed","error")'
    );

    for ($i = 0; $i < $maxAttempts; $i++) {
        $code = (string)random_int($min, $max);
        $stmt->execute([':access_code' => $code]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $code;
        }
    }

    throw new RuntimeException('パスコードの生成に失敗しました。');
}

function reservation_access_code_window(array $cfg, string $useDate): array
{
    $from = trim((string)($cfg['reservation']['access_code_valid_from'] ?? '00:00'));
    $until = trim((string)($cfg['reservation']['access_code_valid_until'] ?? '23:59'));

    return [
        $useDate . ' ' . $from . ':00',
        $useDate . ' ' . $until . ':59',
    ];
}

function reservation_issue_switchbot_access_code(array $cfg, string $roomCode, string $organizationName, string $accessCode, string $useDate): array
{
    if (!function_exists('switchbot_is_configured') || !switchbot_is_configured($cfg)) {
        return [
            'ok' => false,
            'status' => 'not_configured',
            'message' => 'SwitchBot の token / secret が未設定です。',
        ];
    }

    $keypads = switchbot_filter_keypads(switchbot_get_devices($cfg));
    $device = switchbot_find_device_for_room($cfg, $roomCode, $keypads);
    if ($device === null) {
        return [
            'ok' => false,
            'status' => 'device_not_found',
            'message' => '対象部屋に対応する SwitchBot キーパッドが見つかりません。',
        ];
    }

    $deviceId = (string)($device['deviceId'] ?? '');
    if ($deviceId === '') {
        return [
            'ok' => false,
            'status' => 'device_not_found',
            'message' => 'SwitchBot deviceId を取得できませんでした。',
        ];
    }

    [$startAt, $endAt] = reservation_access_code_window($cfg, $useDate);
    $localRequestId = switchbot_generate_local_request_id();
    $passcodeNameSource = $useDate . '_' . reservation_room_label($roomCode) . '_' . $organizationName;
    $passcodeName = function_exists('mb_substr') ? mb_substr($passcodeNameSource, 0, 100, 'UTF-8') : substr($passcodeNameSource, 0, 100);

    $baseRecord = [
        'local_request_id' => $localRequestId,
        'command_id' => '',
        'room_code' => $roomCode,
        'room_label' => reservation_room_label($roomCode),
        'device_id' => $deviceId,
        'device_name' => (string)($device['deviceName'] ?? ''),
        'passcode_name' => $passcodeName,
        'passcode' => $accessCode,
        'start_at' => $startAt,
        'end_at' => $endAt,
        'status' => 'queued',
    ];
    $baseDetail = [
        'phase' => 'automatic_reservation_before_api_request',
        'room_code' => $roomCode,
        'room_label' => reservation_room_label($roomCode),
        'organization_name' => $organizationName,
        'use_date' => $useDate,
        'request' => [
            'name' => $passcodeName,
            'password_masked' => str_repeat('*', strlen($accessCode)),
            'start_at' => $startAt,
            'end_at' => $endAt,
        ],
        'device' => [
            'device_id' => $deviceId,
            'device_name' => (string)($device['deviceName'] ?? ''),
            'device_type' => (string)($device['deviceType'] ?? ''),
        ],
    ];

    $storedRecord = switchbot_upsert_request_record($cfg, $baseRecord, $baseDetail);

    try {
        $result = switchbot_create_time_limited_key($cfg, $deviceId, $passcodeName, $accessCode, $startAt, $endAt);
        $statusCode = (int)($result['statusCode'] ?? 0);
        if ($statusCode !== 100) {
            $message = trim((string)($result['message'] ?? 'SwitchBot API error'));
            $storedRecord['status'] = 'api_error';
            $storedRecord['result'] = $message;
            $storedRecord['updated_at'] = switchbot_now_string($cfg);
            switchbot_upsert_request_record($cfg, $storedRecord, [
                'phase' => 'automatic_reservation_api_error',
                'api_response' => $result,
                'base_detail' => $baseDetail,
            ]);

            return [
                'ok' => false,
                'status' => 'api_error',
                'message' => $message,
                'local_request_id' => (string)($storedRecord['local_request_id'] ?? ''),
                'command_id' => '',
            ];
        }

        $commandId = (string)($result['body']['commandId'] ?? '');
        $storedRecord['command_id'] = $commandId;
        $storedRecord['status'] = 'accepted';
        $storedRecord['result'] = trim((string)($result['message'] ?? 'success'));
        $storedRecord['updated_at'] = switchbot_now_string($cfg);
        $storedRecord = switchbot_upsert_request_record($cfg, $storedRecord, [
            'phase' => 'automatic_reservation_accepted',
            'api_response' => $result,
            'base_detail' => $baseDetail,
        ]);

        return [
            'ok' => true,
            'status' => 'requested',
            'message' => $commandId !== '' ? 'SwitchBot へ反映要求を送信しました。' : 'SwitchBot へ反映要求を送信しました（commandId未返却）。',
            'local_request_id' => (string)($storedRecord['local_request_id'] ?? ''),
            'command_id' => $commandId,
        ];
    } catch (Throwable $e) {
        $storedRecord['status'] = 'api_error';
        $storedRecord['result'] = $e->getMessage();
        $storedRecord['updated_at'] = switchbot_now_string($cfg);
        switchbot_upsert_request_record($cfg, $storedRecord, [
            'phase' => 'automatic_reservation_exception',
            'base_detail' => $baseDetail,
            'exception' => [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ],
        ]);

        return [
            'ok' => false,
            'status' => 'api_error',
            'message' => $e->getMessage(),
            'local_request_id' => (string)($storedRecord['local_request_id'] ?? ''),
            'command_id' => '',
        ];
    }
}

function reservation_create_row(PDO $pdo, array $data): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO reservations (request_token, email, organization_name, room_code, room_label, use_date, access_code, reservation_status, status_reason, switchbot_status, switchbot_request_id, switchbot_command_id, switchbot_message, user_mail_status, admin_mail_status) '
        . 'VALUES (:request_token, :email, :organization_name, :room_code, :room_label, :use_date, :access_code, :reservation_status, :status_reason, :switchbot_status, :switchbot_request_id, :switchbot_command_id, :switchbot_message, :user_mail_status, :admin_mail_status)'
    );
    $stmt->execute([
        ':request_token' => (string)$data['request_token'],
        ':email' => (string)$data['email'],
        ':organization_name' => (string)$data['organization_name'],
        ':room_code' => (string)$data['room_code'],
        ':room_label' => (string)$data['room_label'],
        ':use_date' => (string)$data['use_date'],
        ':access_code' => $data['access_code'] !== '' ? (string)$data['access_code'] : null,
        ':reservation_status' => (string)$data['reservation_status'],
        ':status_reason' => ($data['status_reason'] ?? '') !== '' ? (string)$data['status_reason'] : null,
        ':switchbot_status' => (string)$data['switchbot_status'],
        ':switchbot_request_id' => ($data['switchbot_request_id'] ?? '') !== '' ? (string)$data['switchbot_request_id'] : null,
        ':switchbot_command_id' => ($data['switchbot_command_id'] ?? '') !== '' ? (string)$data['switchbot_command_id'] : null,
        ':switchbot_message' => ($data['switchbot_message'] ?? '') !== '' ? (string)$data['switchbot_message'] : null,
        ':user_mail_status' => (string)($data['user_mail_status'] ?? 'pending'),
        ':admin_mail_status' => (string)($data['admin_mail_status'] ?? 'pending'),
    ]);

    return (int)$pdo->lastInsertId();
}

function reservation_update_status(PDO $pdo, int $reservationId, array $fields): void
{
    $allowed = [
        'access_code', 'reservation_status', 'status_reason', 'switchbot_status',
        'switchbot_request_id', 'switchbot_command_id', 'switchbot_message',
        'user_mail_status', 'admin_mail_status',
    ];
    $set = [];
    $params = [':id' => $reservationId];

    foreach ($allowed as $key) {
        if (array_key_exists($key, $fields)) {
            $placeholder = ':' . $key;
            $set[] = $key . ' = ' . $placeholder;
            $params[$placeholder] = $fields[$key] !== '' ? $fields[$key] : null;
        }
    }

    if ($set === []) {
        return;
    }

    $sql = 'UPDATE reservations SET ' . implode(', ', $set) . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function reservation_try_insert_slot(PDO $pdo, int $reservationId, array $validated, string $accessCode, array $cfg): bool
{
    [$startAt, $endAt] = reservation_access_code_window($cfg, $validated['use_date']);

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO room_calendar_reservations (reservation_id, use_date, room_code, room_label, organization_name, email, access_code, access_code_start_at, access_code_end_at, switchbot_status) '
            . 'VALUES (:reservation_id, :use_date, :room_code, :room_label, :organization_name, :email, :access_code, :start_at, :end_at, :switchbot_status)'
        );
        $stmt->execute([
            ':reservation_id' => $reservationId,
            ':use_date' => $validated['use_date'],
            ':room_code' => $validated['room_code'],
            ':room_label' => $validated['room_label'],
            ':organization_name' => $validated['organization_name'],
            ':email' => $validated['email'],
            ':access_code' => $accessCode,
            ':start_at' => $startAt,
            ':end_at' => $endAt,
            ':switchbot_status' => 'queued',
        ]);

        return true;
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return false;
        }
        throw $e;
    }
}

function reservation_release_slot(PDO $pdo, int $reservationId): void
{
    $stmt = $pdo->prepare('DELETE FROM room_calendar_reservations WHERE reservation_id = :reservation_id');
    $stmt->execute([':reservation_id' => $reservationId]);
}

function reservation_sync_slot_status(PDO $pdo, int $reservationId, array $fields): void
{
    $allowed = ['switchbot_status', 'switchbot_request_id', 'switchbot_command_id', 'switchbot_message'];
    $set = [];
    $params = [':reservation_id' => $reservationId];

    foreach ($allowed as $key) {
        if (array_key_exists($key, $fields)) {
            $placeholder = ':' . $key;
            $set[] = $key . ' = ' . $placeholder;
            $params[$placeholder] = $fields[$key] !== '' ? $fields[$key] : null;
        }
    }

    if ($set === []) {
        return;
    }

    $sql = 'UPDATE room_calendar_reservations SET ' . implode(', ', $set) . ', updated_at = CURRENT_TIMESTAMP WHERE reservation_id = :reservation_id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function reservation_process_submission(array $cfg, PDO $pdo, array $validated): array
{
    reservation_install_schema($pdo);

    $requestToken = reservation_generate_request_token();
    $accessCode = reservation_generate_access_code($pdo, $cfg);

    $reservationId = reservation_create_row($pdo, [
        'request_token' => $requestToken,
        'email' => $validated['email'],
        'organization_name' => $validated['organization_name'],
        'room_code' => $validated['room_code'],
        'room_label' => $validated['room_label'],
        'use_date' => $validated['use_date'],
        'access_code' => $accessCode,
        'reservation_status' => 'pending',
        'status_reason' => '',
        'switchbot_status' => 'queued',
        'switchbot_request_id' => '',
        'switchbot_command_id' => '',
        'switchbot_message' => '',
        'user_mail_status' => 'pending',
        'admin_mail_status' => 'pending',
    ]);

    $slotInserted = reservation_try_insert_slot($pdo, $reservationId, $validated, $accessCode, $cfg);
    if (!$slotInserted) {
        reservation_update_status($pdo, $reservationId, [
            'reservation_status' => 'rejected',
            'status_reason' => '選択された日付は、すでに予約されています。',
            'switchbot_status' => 'skipped',
        ]);

        return reservation_fetch_by_token($pdo, $requestToken) ?? [];
    }

    $switchbotResult = reservation_issue_switchbot_access_code(
        $cfg,
        $validated['room_code'],
        $validated['organization_name'],
        $accessCode,
        $validated['use_date']
    );

    $switchbotRequired = (bool)($cfg['reservation']['switchbot_required_for_confirmation'] ?? true);

    if (!$switchbotResult['ok']) {
        if ($switchbotRequired) {
            reservation_release_slot($pdo, $reservationId);
            reservation_update_status($pdo, $reservationId, [
                'reservation_status' => 'rejected',
                'status_reason' => (string)($switchbotResult['message'] ?? 'パスコード発行に失敗しました。'),
                'switchbot_status' => (string)($switchbotResult['status'] ?? 'failed'),
                'switchbot_request_id' => (string)($switchbotResult['local_request_id'] ?? ''),
                'switchbot_command_id' => (string)($switchbotResult['command_id'] ?? ''),
                'switchbot_message' => (string)($switchbotResult['message'] ?? ''),
            ]);
        } else {
            reservation_update_status($pdo, $reservationId, [
                'reservation_status' => 'confirmed',
                'switchbot_status' => (string)($switchbotResult['status'] ?? 'not_configured'),
                'switchbot_request_id' => (string)($switchbotResult['local_request_id'] ?? ''),
                'switchbot_command_id' => (string)($switchbotResult['command_id'] ?? ''),
                'switchbot_message' => (string)($switchbotResult['message'] ?? ''),
                'status_reason' => '',
            ]);
            reservation_sync_slot_status($pdo, $reservationId, [
                'switchbot_status' => (string)($switchbotResult['status'] ?? 'not_configured'),
                'switchbot_request_id' => (string)($switchbotResult['local_request_id'] ?? ''),
                'switchbot_command_id' => (string)($switchbotResult['command_id'] ?? ''),
                'switchbot_message' => (string)($switchbotResult['message'] ?? ''),
            ]);
        }

        return reservation_fetch_by_token($pdo, $requestToken) ?? [];
    }

    reservation_update_status($pdo, $reservationId, [
        'reservation_status' => 'confirmed',
        'switchbot_status' => (string)($switchbotResult['status'] ?? 'requested'),
        'switchbot_request_id' => (string)($switchbotResult['local_request_id'] ?? ''),
        'switchbot_command_id' => (string)($switchbotResult['command_id'] ?? ''),
        'switchbot_message' => (string)($switchbotResult['message'] ?? ''),
        'status_reason' => '',
    ]);
    reservation_sync_slot_status($pdo, $reservationId, [
        'switchbot_status' => (string)($switchbotResult['status'] ?? 'requested'),
        'switchbot_request_id' => (string)($switchbotResult['local_request_id'] ?? ''),
        'switchbot_command_id' => (string)($switchbotResult['command_id'] ?? ''),
        'switchbot_message' => (string)($switchbotResult['message'] ?? ''),
    ]);

    return reservation_fetch_by_token($pdo, $requestToken) ?? [];
}

function reservation_record_mail_log(PDO $pdo, int $reservationId, string $mailKind, string $recipient, string $subject, string $sendStatus, string $errorMessage = ''): void
{
    reservation_install_schema($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO reservation_mail_logs (reservation_id, mail_kind, recipient, subject, send_status, error_message) '
        . 'VALUES (:reservation_id, :mail_kind, :recipient, :subject, :send_status, :error_message)'
    );
    $stmt->execute([
        ':reservation_id' => $reservationId,
        ':mail_kind' => $mailKind,
        ':recipient' => $recipient,
        ':subject' => $subject,
        ':send_status' => $sendStatus,
        ':error_message' => $errorMessage !== '' ? $errorMessage : null,
    ]);
}

function reservation_send_emails(array $cfg, PDO $pdo, array $reservation): array
{
    $reservationId = (int)($reservation['id'] ?? 0);
    if ($reservationId < 1) {
        throw new RuntimeException('予約IDを取得できませんでした。');
    }

    $userMail = build_user_result_mail($reservation);
    $adminMail = build_admin_notice_mail($reservation);
    $adminMail['to'] = trim((string)($cfg['reservation_admin_notify_to'] ?? ''));

    $result = [
        'user' => ['ok' => false, 'message' => ''],
        'admin' => ['ok' => false, 'message' => ''],
    ];

    try {
        send_mail_smtp($cfg, $userMail);
        reservation_record_mail_log($pdo, $reservationId, 'user_result', (string)$userMail['to'], (string)$userMail['subject'], 'sent');
        reservation_update_status($pdo, $reservationId, ['user_mail_status' => 'sent']);
        $result['user'] = ['ok' => true, 'message' => 'sent'];
    } catch (Throwable $e) {
        reservation_record_mail_log($pdo, $reservationId, 'user_result', (string)$userMail['to'], (string)$userMail['subject'], 'failed', $e->getMessage());
        reservation_update_status($pdo, $reservationId, ['user_mail_status' => 'failed']);
        $result['user'] = ['ok' => false, 'message' => $e->getMessage()];
    }

    if ($adminMail['to'] !== '') {
        try {
            send_mail_smtp($cfg, $adminMail);
            reservation_record_mail_log($pdo, $reservationId, 'admin_notice', (string)$adminMail['to'], (string)$adminMail['subject'], 'sent');
            reservation_update_status($pdo, $reservationId, ['admin_mail_status' => 'sent']);
            $result['admin'] = ['ok' => true, 'message' => 'sent'];
        } catch (Throwable $e) {
            reservation_record_mail_log($pdo, $reservationId, 'admin_notice', (string)$adminMail['to'], (string)$adminMail['subject'], 'failed', $e->getMessage());
            reservation_update_status($pdo, $reservationId, ['admin_mail_status' => 'failed']);
            $result['admin'] = ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    return $result;
}

function reservation_sync_from_switchbot_request(PDO $pdo, array $requestRow): void
{
    reservation_install_schema($pdo);

    $localRequestId = trim((string)($requestRow['local_request_id'] ?? ''));
    $commandId = trim((string)($requestRow['command_id'] ?? ''));
    $status = trim((string)($requestRow['status'] ?? ''));
    $resultMessage = trim((string)($requestRow['result'] ?? ''));
    $reservationStatus = in_array($status, ['success', 'completed'], true) ? 'confirmed' : ((in_array($status, ['failed', 'api_error'], true)) ? 'error' : null);
    $slotStatus = in_array($status, ['success', 'completed'], true) ? 'success' : ((in_array($status, ['failed', 'api_error'], true)) ? 'failed' : $status);

    $where = [];
    $params = [];
    if ($localRequestId !== '') {
        $where[] = 'switchbot_request_id = :local_request_id';
        $params[':local_request_id'] = $localRequestId;
    }
    if ($commandId !== '') {
        $where[] = 'switchbot_command_id = :command_id';
        $params[':command_id'] = $commandId;
    }
    if ($where === []) {
        return;
    }

    $reservationSql = 'UPDATE reservations SET switchbot_status = :switchbot_status, switchbot_message = :switchbot_message'
        . ($reservationStatus !== null ? ', reservation_status = :reservation_status, status_reason = :status_reason' : '')
        . ' WHERE ' . implode(' OR ', $where);
    $reservationStmt = $pdo->prepare($reservationSql);
    $reservationParams = $params + [
        ':switchbot_status' => $status !== '' ? $status : $slotStatus,
        ':switchbot_message' => $resultMessage !== '' ? $resultMessage : null,
    ];
    if ($reservationStatus !== null) {
        $reservationParams[':reservation_status'] = $reservationStatus;
        $reservationParams[':status_reason'] = $resultMessage !== '' ? $resultMessage : null;
    }
    $reservationStmt->execute($reservationParams);

    $slotStmt = $pdo->prepare(
        'UPDATE room_calendar_reservations SET switchbot_status = :switchbot_status, switchbot_message = :switchbot_message WHERE '
        . implode(' OR ', $where)
    );
    $slotStmt->execute($params + [
        ':switchbot_status' => $slotStatus !== '' ? $slotStatus : $status,
        ':switchbot_message' => $resultMessage !== '' ? $resultMessage : null,
    ]);
}
