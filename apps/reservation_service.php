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

function reservation_time_step_minutes(array $cfg): int
{
    return max(1, (int)($cfg['reservation']['time_step_minutes'] ?? 15));
}

function reservation_allowed_time_bounds(array $cfg): array
{
    $timeStart = trim((string)($cfg['reservation']['booking_time_start'] ?? '09:00'));
    $timeEnd = trim((string)($cfg['reservation']['booking_time_end'] ?? '20:00'));

    reservation_assert_time_value($timeStart, reservation_time_step_minutes($cfg), false);
    reservation_assert_time_value($timeEnd, reservation_time_step_minutes($cfg), true);

    $startMinutes = reservation_time_to_minutes($timeStart);
    $endMinutes = reservation_time_to_minutes($timeEnd);
    if ($endMinutes <= $startMinutes) {
        throw new RuntimeException('config.php の予約時刻範囲が不正です。');
    }

    return [$timeStart, $timeEnd];
}

function reservation_validate_form_input(array $cfg, array $source): array
{
    $email = trim((string)($source['email'] ?? ''));
    $organizationName = trim((string)($source['organization_name'] ?? ''));
    $roomCode = trim((string)($source['room_code'] ?? $source['select'] ?? ''));
    $agreeTerms = (string)($source['agree_terms'] ?? '') !== '';
    $usageStartTime = trim((string)($source['usage_start_time'] ?? ''));
    $usageEndTime = trim((string)($source['usage_end_time'] ?? ''));
    $useDates = reservation_extract_use_dates($source);

    if ($email === '' || $organizationName === '' || $roomCode === '' || $useDates === [] || $usageStartTime === '' || $usageEndTime === '') {
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

    reservation_assert_time_value($usageStartTime, reservation_time_step_minutes($cfg), false);
    reservation_assert_time_value($usageEndTime, reservation_time_step_minutes($cfg), true);

    [$allowedStartTime, $allowedEndTime] = reservation_allowed_time_bounds($cfg);
    $allowedStartMinutes = reservation_time_to_minutes($allowedStartTime);
    $allowedEndMinutes = reservation_time_to_minutes($allowedEndTime);

    $startMinutes = reservation_time_to_minutes($usageStartTime);
    $endMinutes = reservation_time_to_minutes($usageEndTime);
    if ($endMinutes <= $startMinutes) {
        throw new RuntimeException('利用時間の終了は開始より後にしてください。');
    }
    if ($startMinutes < $allowedStartMinutes || $endMinutes > $allowedEndMinutes) {
        throw new RuntimeException('利用時間は ' . $allowedStartTime . '〜' . $allowedEndTime . ' の範囲で指定してください。');
    }

    [$minDate, $maxDate] = reservation_allowed_range($cfg);
    $timezone = new DateTimeZone(reservation_timezone($cfg));
    foreach ($useDates as $useDate) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $useDate, $timezone);
        if (!$dt || $dt->format('Y-m-d') !== $useDate) {
            throw new RuntimeException('利用日の形式が不正です。');
        }
        if ($dt < $minDate || $dt > $maxDate) {
            throw new RuntimeException(
                '利用日は ' . $minDate->format('Y-m-d') . ' から ' . $maxDate->format('Y-m-d') . ' の範囲で指定してください。'
            );
        }
    }

    sort($useDates, SORT_STRING);

    return [
        'email' => $email,
        'organization_name' => $organizationName,
        'room_code' => $roomCode,
        'room_label' => reservation_room_label($roomCode),
        'use_dates' => $useDates,
        'use_date' => $useDates[0],
        'use_date_end' => $useDates[count($useDates) - 1],
        'selected_dates_count' => count($useDates),
        'usage_start_time' => $usageStartTime,
        'usage_end_time' => $usageEndTime,
        'usage_time' => $usageStartTime . '~' . $usageEndTime,
    ];
}

function reservation_extract_use_dates(array $source): array
{
    $raw = $source['use_dates'] ?? $source['use_date'] ?? [];
    $dates = [];

    if (is_string($raw)) {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            $raw = $decoded;
        } else {
            $raw = array_filter(array_map('trim', preg_split('/\s*,\s*/u', $trimmed) ?: []));
        }
    }

    if (is_array($raw)) {
        foreach ($raw as $value) {
            $date = trim((string)$value);
            if ($date !== '') {
                $dates[$date] = $date;
            }
        }
    }

    return array_values($dates);
}

function reservation_assert_time_value(string $time, int $stepMinutes, bool $allow2400 = false): void
{
    if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) {
        throw new RuntimeException('利用時間の形式が不正です。');
    }

    $hour = (int)$matches[1];
    $minute = (int)$matches[2];
    if ($hour === 24 && $minute === 0 && $allow2400) {
        return;
    }
    if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
        throw new RuntimeException('利用時間の値が不正です。');
    }
    if ($minute % $stepMinutes !== 0) {
        throw new RuntimeException('利用時間は ' . $stepMinutes . ' 分刻みで指定してください。');
    }
}

function reservation_time_to_minutes(string $time): int
{
    [$h, $m] = array_map('intval', explode(':', $time));
    return $h * 60 + $m;
}

function reservation_time_to_datetime(string $useDate, string $time, DateTimeZone $tz): DateTimeImmutable
{
    if ($time === '24:00') {
        return (new DateTimeImmutable($useDate . ' 00:00:00', $tz))->modify('+1 day');
    }
    return new DateTimeImmutable($useDate . ' ' . $time . ':00', $tz);
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
        . 'AND use_date_end >= CURDATE() '
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

function reservation_access_code_window(array $cfg, string $useDate, string $usageStartTime, string $usageEndTime): array
{
    $timezone = new DateTimeZone(reservation_timezone($cfg));
    $paddingMinutes = max(0, (int)($cfg['reservation']['access_code_padding_minutes'] ?? 10));

    $usageStart = reservation_time_to_datetime($useDate, $usageStartTime, $timezone);
    $usageEnd = reservation_time_to_datetime($useDate, $usageEndTime, $timezone);

    $accessStart = $usageStart->modify('-' . $paddingMinutes . ' minutes');
    $accessEnd = $usageEnd->modify('+' . $paddingMinutes . ' minutes');

    return [
        $accessStart->format('Y-m-d H:i:s'),
        $accessEnd->format('Y-m-d H:i:s'),
    ];
}

function reservation_google_event_window(array $cfg, string $useDate, string $usageStartTime, string $usageEndTime): array
{
    $timezone = new DateTimeZone(reservation_timezone($cfg));
    $start = reservation_time_to_datetime($useDate, $usageStartTime, $timezone);
    $end = reservation_time_to_datetime($useDate, $usageEndTime, $timezone);
    return [$start, $end];
}

function reservation_issue_switchbot_access_code(array $cfg, string $roomCode, string $organizationName, string $accessCode, string $useDate, string $usageStartTime, string $usageEndTime): array
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

    [$startAt, $endAt] = reservation_access_code_window($cfg, $useDate, $usageStartTime, $usageEndTime);
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
        'usage_start_time' => $usageStartTime,
        'usage_end_time' => $usageEndTime,
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
        'INSERT INTO reservations (request_token, email, organization_name, room_code, room_label, use_date, use_date_end, selected_dates_count, usage_start_time, usage_end_time, usage_time, access_code, reservation_status, status_reason, switchbot_status, switchbot_message, google_sync_status, google_sync_message, user_mail_status, admin_mail_status) '
        . 'VALUES (:request_token, :email, :organization_name, :room_code, :room_label, :use_date, :use_date_end, :selected_dates_count, :usage_start_time, :usage_end_time, :usage_time, :access_code, :reservation_status, :status_reason, :switchbot_status, :switchbot_message, :google_sync_status, :google_sync_message, :user_mail_status, :admin_mail_status)'
    );
    $stmt->execute([
        ':request_token' => (string)$data['request_token'],
        ':email' => (string)$data['email'],
        ':organization_name' => (string)$data['organization_name'],
        ':room_code' => (string)$data['room_code'],
        ':room_label' => (string)$data['room_label'],
        ':use_date' => (string)$data['use_date'],
        ':use_date_end' => (string)$data['use_date_end'],
        ':selected_dates_count' => (int)$data['selected_dates_count'],
        ':usage_start_time' => (string)$data['usage_start_time'],
        ':usage_end_time' => (string)$data['usage_end_time'],
        ':usage_time' => (string)$data['usage_time'],
        ':access_code' => (string)$data['access_code'],
        ':reservation_status' => (string)$data['reservation_status'],
        ':status_reason' => (string)$data['status_reason'] !== '' ? (string)$data['status_reason'] : null,
        ':switchbot_status' => (string)$data['switchbot_status'],
        ':switchbot_message' => (string)$data['switchbot_message'] !== '' ? (string)$data['switchbot_message'] : null,
        ':google_sync_status' => (string)$data['google_sync_status'],
        ':google_sync_message' => (string)$data['google_sync_message'] !== '' ? (string)$data['google_sync_message'] : null,
        ':user_mail_status' => (string)$data['user_mail_status'],
        ':admin_mail_status' => (string)$data['admin_mail_status'],
    ]);

    return (int)$pdo->lastInsertId();
}

function reservation_update_status(PDO $pdo, int $reservationId, array $fields): void
{
    $set = [];
    $params = [':id' => $reservationId];
    foreach ($fields as $key => $value) {
        $placeholder = ':' . $key;
        $set[] = $key . ' = ' . $placeholder;
        $params[$placeholder] = $value !== '' ? $value : null;
    }
    if ($set === []) {
        return;
    }
    $sql = 'UPDATE reservations SET ' . implode(', ', $set) . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function reservation_try_insert_slot(PDO $pdo, int $reservationId, array $validated, string $useDate, string $accessCode, array $cfg): int
{
    [$startAt, $endAt] = reservation_access_code_window($cfg, $useDate, $validated['usage_start_time'], $validated['usage_end_time']);

    $stmt = $pdo->prepare(
        'INSERT INTO room_calendar_reservations (reservation_id, use_date, room_code, room_label, organization_name, email, usage_start_time, usage_end_time, usage_time, access_code, access_code_start_at, access_code_end_at, switchbot_status, google_sync_status) '
        . 'VALUES (:reservation_id, :use_date, :room_code, :room_label, :organization_name, :email, :usage_start_time, :usage_end_time, :usage_time, :access_code, :access_code_start_at, :access_code_end_at, :switchbot_status, :google_sync_status)'
    );
    $stmt->execute([
        ':reservation_id' => $reservationId,
        ':use_date' => $useDate,
        ':room_code' => (string)$validated['room_code'],
        ':room_label' => (string)$validated['room_label'],
        ':organization_name' => (string)$validated['organization_name'],
        ':email' => (string)$validated['email'],
        ':usage_start_time' => (string)$validated['usage_start_time'],
        ':usage_end_time' => (string)$validated['usage_end_time'],
        ':usage_time' => (string)$validated['usage_time'],
        ':access_code' => $accessCode,
        ':access_code_start_at' => $startAt,
        ':access_code_end_at' => $endAt,
        ':switchbot_status' => 'queued',
        ':google_sync_status' => function_exists('google_calendar_sync_enabled') && google_calendar_sync_enabled($cfg) ? 'queued' : 'disabled',
    ]);
    return (int)$pdo->lastInsertId();
}

function reservation_release_slot(PDO $pdo, int $reservationId): void
{
    $stmt = $pdo->prepare('DELETE FROM room_calendar_reservations WHERE reservation_id = :reservation_id');
    $stmt->execute([':reservation_id' => $reservationId]);
}

function reservation_update_slot(PDO $pdo, int $slotId, array $fields): void
{
    $set = [];
    $params = [':id' => $slotId];
    foreach ($fields as $key => $value) {
        $placeholder = ':' . $key;
        $set[] = $key . ' = ' . $placeholder;
        $params[$placeholder] = $value !== '' ? $value : null;
    }
    if ($set === []) {
        return;
    }
    $sql = 'UPDATE room_calendar_reservations SET ' . implode(', ', $set) . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function reservation_build_google_payload(array $cfg, array $reservation, array $slot): array
{
    $calendarId = google_calendar_require_room_calendar_id($cfg, (string)$slot['room_code']);
    [$start, $end] = reservation_google_event_window($cfg, (string)$slot['use_date'], (string)$slot['usage_start_time'], (string)$slot['usage_end_time']);

    return [
        'reservation_id' => (int)$reservation['id'],
        'reservation_detail_id' => (int)$slot['id'],
        'use_date' => (string)$slot['use_date'],
        'usage_time' => (string)$slot['usage_time'],
        'room_code' => (string)$slot['room_code'],
        'room_label' => (string)$slot['room_label'],
        'organization_name' => (string)$slot['organization_name'],
        'calendar_id' => $calendarId,
        'start_at' => $start->format(DateTimeInterface::ATOM),
        'end_at' => $end->format(DateTimeInterface::ATOM),
        'email' => (string)$slot['email'],
    ];
}

function reservation_apply_google_sync(array $cfg, PDO $pdo, array $reservation, array $slot): array
{
    if (!function_exists('google_calendar_sync_enabled') || !google_calendar_sync_enabled($cfg)) {
        reservation_update_slot($pdo, (int)$slot['id'], [
            'google_sync_status' => 'disabled',
            'google_sync_message' => 'Google カレンダー連携は無効です。',
        ]);
        return ['ok' => true, 'status' => 'disabled', 'message' => 'disabled'];
    }

    try {
        $payload = reservation_build_google_payload($cfg, $reservation, $slot);
        $result = google_calendar_create_via_gas($cfg, $payload);
        reservation_update_slot($pdo, (int)$slot['id'], [
            'google_event_id' => (string)($result['event_id'] ?? ''),
            'google_calendar_id' => (string)($result['calendar_id'] ?? $payload['calendar_id']),
            'google_sync_status' => 'synced',
            'google_sync_message' => '',
        ]);
        return ['ok' => true, 'status' => 'synced', 'message' => 'synced'];
    } catch (Throwable $e) {
        reservation_update_slot($pdo, (int)$slot['id'], [
            'google_sync_status' => 'failed',
            'google_sync_message' => $e->getMessage(),
        ]);
        return ['ok' => false, 'status' => 'failed', 'message' => $e->getMessage()];
    }
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
        'use_date_end' => $validated['use_date_end'],
        'selected_dates_count' => $validated['selected_dates_count'],
        'usage_start_time' => $validated['usage_start_time'],
        'usage_end_time' => $validated['usage_end_time'],
        'usage_time' => $validated['usage_time'],
        'access_code' => $accessCode,
        'reservation_status' => 'pending',
        'status_reason' => '',
        'switchbot_status' => 'queued',
        'switchbot_message' => '',
        'google_sync_status' => function_exists('google_calendar_sync_enabled') && google_calendar_sync_enabled($cfg) ? 'queued' : 'disabled',
        'google_sync_message' => '',
        'user_mail_status' => 'pending',
        'admin_mail_status' => 'pending',
    ]);

    $conflictDate = null;
    try {
        $pdo->beginTransaction();
        foreach ($validated['use_dates'] as $useDate) {
            $conflictDate = $useDate;
            reservation_try_insert_slot($pdo, $reservationId, $validated, $useDate, $accessCode, $cfg);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $message = str_contains($e->getMessage(), 'uq_room_calendar_reservations_room_date') || str_contains($e->getMessage(), 'Duplicate')
            ? '選択された日付の中に、すでに予約済みの日があります。競合日: ' . (string)$conflictDate
            : $e->getMessage();

        reservation_update_status($pdo, $reservationId, [
            'reservation_status' => 'rejected',
            'status_reason' => $message,
            'switchbot_status' => 'skipped',
            'google_sync_status' => 'skipped',
        ]);

        return reservation_fetch_by_token($pdo, $requestToken) ?? [];
    }

    $slotRows = reservation_fetch_detail_rows($pdo, $reservationId);
    $switchStatuses = [];
    $switchMessages = [];
    $googleStatuses = [];
    $googleMessages = [];

    foreach ($slotRows as $slot) {
        $switchResult = reservation_issue_switchbot_access_code(
            $cfg,
            (string)$validated['room_code'],
            (string)$validated['organization_name'],
            $accessCode,
            (string)$slot['use_date'],
            (string)$validated['usage_start_time'],
            (string)$validated['usage_end_time']
        );

        reservation_update_slot($pdo, (int)$slot['id'], [
            'switchbot_status' => (string)($switchResult['status'] ?? 'failed'),
            'switchbot_request_id' => (string)($switchResult['local_request_id'] ?? ''),
            'switchbot_command_id' => (string)($switchResult['command_id'] ?? ''),
            'switchbot_message' => (string)($switchResult['message'] ?? ''),
        ]);

        $switchStatuses[] = (string)($switchResult['status'] ?? 'failed');
        if (($switchResult['message'] ?? '') !== '') {
            $switchMessages[] = (string)$switchResult['message'];
        }

        $slot['switchbot_status'] = (string)($switchResult['status'] ?? 'failed');
        $googleResult = reservation_apply_google_sync($cfg, $pdo, ['id' => $reservationId], $slot);
        $googleStatuses[] = (string)($googleResult['status'] ?? 'failed');
        if (($googleResult['message'] ?? '') !== '' && ($googleResult['status'] ?? '') === 'failed') {
            $googleMessages[] = (string)$googleResult['message'];
        }
    }

    $allSwitchOk = $switchStatuses !== [] && count(array_filter($switchStatuses, static fn(string $s): bool => $s === 'requested')) === count($switchStatuses);
    $hasSwitchFailure = count(array_filter($switchStatuses, static fn(string $s): bool => !in_array($s, ['requested', 'success', 'completed'], true))) > 0;

    $googleEnabled = function_exists('google_calendar_sync_enabled') && google_calendar_sync_enabled($cfg);
    $googleAllOk = !$googleEnabled || ($googleStatuses !== [] && count(array_filter($googleStatuses, static fn(string $s): bool => $s === 'synced')) === count($googleStatuses));
    $googleFailed = $googleEnabled && count(array_filter($googleStatuses, static fn(string $s): bool => $s === 'failed')) > 0;

    $reservationStatus = $allSwitchOk ? 'confirmed' : ($hasSwitchFailure ? 'error' : 'pending');
    $reason = '';
    if ($hasSwitchFailure) {
        $reason = '一部の日付でパスコード発行に失敗しました。' . ($switchMessages !== [] ? ' ' . implode(' / ', array_unique($switchMessages)) : '');
    }

    $switchSummaryStatus = $allSwitchOk ? 'requested' : ($hasSwitchFailure ? 'partial_error' : 'queued');
    $googleSummaryStatus = !$googleEnabled ? 'disabled' : ($googleAllOk ? 'synced' : ($googleFailed ? 'partial_error' : 'queued'));

    reservation_update_status($pdo, $reservationId, [
        'reservation_status' => $reservationStatus,
        'status_reason' => $reason,
        'switchbot_status' => $switchSummaryStatus,
        'switchbot_message' => $switchMessages !== [] ? implode(' / ', array_unique($switchMessages)) : '',
        'google_sync_status' => $googleSummaryStatus,
        'google_sync_message' => $googleMessages !== [] ? implode(' / ', array_unique($googleMessages)) : '',
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

function reservation_recalculate_summary_status(PDO $pdo, int $reservationId): void
{
    $rows = reservation_fetch_detail_rows($pdo, $reservationId);
    if ($rows === []) {
        return;
    }

    $switchStatuses = array_map(static fn(array $r): string => (string)($r['switchbot_status'] ?? ''), $rows);
    $googleStatuses = array_map(static fn(array $r): string => (string)($r['google_sync_status'] ?? ''), $rows);

    $allSwitchOk = count(array_filter($switchStatuses, static fn(string $s): bool => in_array($s, ['success', 'completed', 'requested'], true))) === count($switchStatuses);
    $hasSwitchFail = count(array_filter($switchStatuses, static fn(string $s): bool => in_array($s, ['failed', 'api_error', 'device_not_found', 'not_configured'], true))) > 0;

    $googleAllOk = count(array_filter($googleStatuses, static fn(string $s): bool => in_array($s, ['synced', 'disabled'], true))) === count($googleStatuses);
    $googleFail = count(array_filter($googleStatuses, static fn(string $s): bool => $s === 'failed')) > 0;

    reservation_update_status($pdo, $reservationId, [
        'reservation_status' => $allSwitchOk ? 'confirmed' : ($hasSwitchFail ? 'error' : 'pending'),
        'switchbot_status' => $allSwitchOk ? 'success' : ($hasSwitchFail ? 'partial_error' : 'queued'),
        'google_sync_status' => $googleAllOk ? 'synced' : ($googleFail ? 'partial_error' : 'queued'),
    ]);
}

function reservation_sync_from_switchbot_request(PDO $pdo, array $requestRow): void
{
    reservation_install_schema($pdo);

    $localRequestId = trim((string)($requestRow['local_request_id'] ?? ''));
    $commandId = trim((string)($requestRow['command_id'] ?? ''));
    $status = trim((string)($requestRow['status'] ?? ''));
    $resultMessage = trim((string)($requestRow['result'] ?? ''));

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

    $sql = 'SELECT id, reservation_id FROM room_calendar_reservations WHERE ' . implode(' OR ', $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if ($rows === []) {
        return;
    }

    $update = $pdo->prepare(
        'UPDATE room_calendar_reservations SET switchbot_status = :switchbot_status, switchbot_message = :switchbot_message, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    );
    $reservationIds = [];
    foreach ($rows as $row) {
        $update->execute([
            ':switchbot_status' => $status !== '' ? $status : 'updated',
            ':switchbot_message' => $resultMessage !== '' ? $resultMessage : null,
            ':id' => (int)$row['id'],
        ]);
        $reservationIds[(int)$row['reservation_id']] = (int)$row['reservation_id'];
    }

    foreach ($reservationIds as $reservationId) {
        reservation_recalculate_summary_status($pdo, $reservationId);
    }
}
