<?php

declare(strict_types=1);

require_once __DIR__ . '/../../apps/admin_auth.php';
require_once __DIR__ . '/../../apps/db.php';
if (is_file(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}
require_once __DIR__ . '/../../apps/switchbot_api.php';
require_once __DIR__ . '/../../apps/google_calendar_sync.php';
require_once __DIR__ . '/../../apps/smtp_mailer.php';
require_once __DIR__ . '/../../apps/mail_html_templates.php';
require_once __DIR__ . '/../../apps/reservation_service.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    admin_auth_bootstrap();
    $user = admin_auth_require_login();
    admin_auth_require_csrf();

    /** @var mixed $cfg */
    $cfg = require __DIR__ . '/../../apps/config.php';
    if (!is_array($cfg)) {
        throw new RuntimeException('config.php の形式が不正です。');
    }

    $pdo = db_connect($cfg);
    reservation_install_schema($pdo);

    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($input)) {
        $input = [];
    }
    $action = trim((string)($input['action'] ?? ''));

    switch ($action) {
        case 'dashboard_list':
            manage_require_permission($user, 'application.view');
            manage_dashboard_list($pdo, $input);
            break;
        case 'calendar_month':
            manage_require_permission($user, 'calendar.view');
            manage_calendar_month($pdo, $input);
            break;
        case 'calendar_manual_create':
            manage_require_permission($user, 'calendar.create');
            manage_calendar_manual_create($pdo, $cfg, $user, $input);
            break;
        case 'calendar_slot_delete':
            manage_require_permission($user, 'calendar.delete');
            manage_calendar_slot_delete($pdo, $cfg, $user, $input);
            break;
        case 'passcode_list':
            manage_require_permission($user, 'access.view');
            manage_passcode_list($pdo);
            break;
        case 'admin_user_list':
            manage_require_permission($user, 'admin.user.manage');
            admin_auth_install_schema($pdo);
            json_response([
                'ok' => true,
                'rows' => admin_auth_list_users($pdo),
                'message' => '管理者アカウントを取得しました。',
            ]);
            break;
        case 'admin_user_create':
            manage_require_permission($user, 'admin.user.manage');
            admin_auth_install_schema($pdo);
            $createdId = admin_auth_create_user($pdo, [
                'login_id' => trim((string)($input['login_id'] ?? '')),
                'display_name' => trim((string)($input['display_name'] ?? '')),
                'email' => trim((string)($input['email'] ?? '')),
                'role_key' => trim((string)($input['role_key'] ?? 'viewer')),
                'password' => (string)($input['password'] ?? ''),
                'is_active' => (int)($input['is_active'] ?? 1),
            ]);
            admin_auth_write_audit_log($pdo, $user, 'admin.user.create', 'admin_user', $createdId, [
                'login_id' => (string)($input['login_id'] ?? ''),
                'role_key' => (string)($input['role_key'] ?? ''),
            ]);
            json_response(['ok' => true, 'message' => '管理者アカウントを作成しました。']);
            break;
        case 'admin_user_update':
            manage_require_permission($user, 'admin.user.manage');
            admin_auth_install_schema($pdo);
            $id = (int)($input['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('更新対象IDが不正です。');
            }
            admin_auth_update_user($pdo, $id, [
                'login_id' => trim((string)($input['login_id'] ?? '')),
                'display_name' => trim((string)($input['display_name'] ?? '')),
                'email' => trim((string)($input['email'] ?? '')),
                'role_key' => trim((string)($input['role_key'] ?? 'viewer')),
                'password' => (string)($input['password'] ?? ''),
                'is_active' => (int)($input['is_active'] ?? 1),
            ]);
            admin_auth_write_audit_log($pdo, $user, 'admin.user.update', 'admin_user', $id, [
                'login_id' => (string)($input['login_id'] ?? ''),
                'role_key' => (string)($input['role_key'] ?? ''),
            ]);
            json_response(['ok' => true, 'message' => '管理者アカウントを更新しました。']);
            break;
        case 'audit_log_list':
            manage_require_permission($user, 'admin.audit.view');
            admin_auth_install_schema($pdo);
            json_response([
                'ok' => true,
                'rows' => admin_auth_list_audit_logs($pdo, 100),
                'message' => '監査ログを取得しました。',
            ]);
            break;
        default:
            throw new RuntimeException('未対応の action です。');
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function json_response(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function manage_require_permission(array $user, string $permission): void
{
    if (!admin_auth_has_permission($user, $permission)) {
        http_response_code(403);
        json_response(['ok' => false, 'message' => 'この操作を行う権限がありません。']);
    }
}

function manage_dashboard_list(PDO $pdo, array $input): void
{
    $month = trim((string)($input['month'] ?? ''));
    $roomCode = trim((string)($input['room_code'] ?? ''));
    $reservationStatus = trim((string)($input['reservation_status'] ?? ''));
    $keyword = trim((string)($input['keyword'] ?? ''));

    $where = [];
    $params = [];

    if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $where[] = 'EXISTS (SELECT 1 FROM room_calendar_reservations d WHERE d.reservation_id = r.id AND DATE_FORMAT(d.use_date, "%Y-%m") = :month)';
        $params[':month'] = $month;
    }
    if ($roomCode !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM room_calendar_reservations d WHERE d.reservation_id = r.id AND d.room_code = :room_code)';
        $params[':room_code'] = $roomCode;
    }
    if ($reservationStatus !== '') {
        $where[] = 'r.reservation_status = :reservation_status';
        $params[':reservation_status'] = $reservationStatus;
    }
    if ($keyword !== '') {
        $where[] = '(r.email LIKE :keyword_email OR r.organization_name LIKE :keyword_org)';
        $params[':keyword_email'] = '%' . $keyword . '%';
        $params[':keyword_org'] = '%' . $keyword . '%';
    }

    $sql = 'SELECT r.*, '
        . '(SELECT GROUP_CONCAT(DISTINCT d.room_label ORDER BY d.room_label SEPARATOR " / ") FROM room_calendar_reservations d WHERE d.reservation_id = r.id) AS room_labels, '
        . '(SELECT GROUP_CONCAT(CONCAT(d.use_date, " ", d.room_label, " ", d.usage_time) ORDER BY d.use_date SEPARATOR "\n") FROM room_calendar_reservations d WHERE d.reservation_id = r.id) AS usage_summary, '
        . '(SELECT GROUP_CONCAT(CONCAT(d.use_date, " ", COALESCE(NULLIF(d.access_code, ""), "-") ) ORDER BY d.use_date SEPARATOR "\n") FROM room_calendar_reservations d WHERE d.reservation_id = r.id) AS access_code_summary '
        . 'FROM reservations r';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY r.created_at DESC LIMIT 200';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $summary = [
        'confirmed_upcoming_count' => manage_scalar($pdo, 'SELECT COUNT(*) FROM reservations WHERE reservation_status = "confirmed" AND use_date_end >= CURDATE()'),
        'switchbot_issue_count' => manage_scalar($pdo, 'SELECT COUNT(*) FROM reservations WHERE reservation_status = "error" OR switchbot_status IN ("partial_error","failed") OR google_sync_status IN ("partial_error","failed")'),
        'today_count' => manage_scalar($pdo, 'SELECT COUNT(*) FROM reservations WHERE DATE(created_at) = CURDATE()'),
        'mail_issue_count' => manage_scalar($pdo, 'SELECT COUNT(*) FROM reservations WHERE user_mail_status NOT IN ("sent","skipped") OR admin_mail_status NOT IN ("sent","skipped")'),
    ];

    json_response([
        'ok' => true,
        'rows' => $rows,
        'summary' => $summary,
        'message' => '予約一覧を取得しました。',
    ]);
}

function manage_calendar_month(PDO $pdo, array $input): void
{
    $month = trim((string)($input['month'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }

    $monthStart = $month . '-01';
    $monthEnd = (new DateTimeImmutable($monthStart))->modify('+1 month')->format('Y-m-d');

    $stmt = $pdo->prepare(
        'SELECT id, reservation_id, use_date, room_code, room_label, organization_name, email, usage_start_time, usage_end_time, usage_time, access_code, switchbot_status, google_sync_status '
        . 'FROM room_calendar_reservations '
        . 'WHERE use_date >= :month_start AND use_date < :month_end '
        . 'ORDER BY use_date ASC, room_code ASC'
    );
    $stmt->execute([
        ':month_start' => $monthStart,
        ':month_end' => $monthEnd,
    ]);
    $rows = $stmt->fetchAll();

    $dayMap = [];
    foreach ($rows as $row) {
        $dateKey = (string)($row['use_date'] ?? '');
        if (!isset($dayMap[$dateKey])) {
            $dayMap[$dateKey] = [];
        }
        $dayMap[$dateKey][] = $row;
    }

    json_response([
        'ok' => true,
        'month' => $month,
        'rows' => $rows,
        'day_map' => $dayMap,
        'message' => '月間カレンダーを取得しました。',
    ]);
}

function manage_calendar_manual_create(PDO $pdo, array $cfg, array $user, array $input): void
{
    reservation_install_schema($pdo);
    admin_auth_install_schema($pdo);

    $data = manage_validate_manual_create_input($input);
    [$accessCodeStartAt, $accessCodeEndAt] = reservation_access_code_window($cfg, $data['use_date'], $data['usage_start_time'], $data['usage_end_time']);
    $accessCode = $data['issue_switchbot'] ? reservation_generate_access_code($pdo, $cfg) : '';
    $requestToken = reservation_generate_request_token();

    $conflicts = [];
    $reservationId = 0;

    try {
        $pdo->beginTransaction();

        $reservationId = reservation_create_row($pdo, [
            'request_token' => $requestToken,
            'email' => $data['email'],
            'organization_name' => $data['organization_name'],
            'room_code' => $data['room_code'],
            'room_label' => reservation_room_label($data['room_code']),
            'use_date' => $data['use_date'],
            'use_date_end' => $data['use_date'],
            'selected_dates_count' => 1,
            'usage_start_time' => $data['usage_start_time'],
            'usage_end_time' => $data['usage_end_time'],
            'usage_time' => $data['usage_time'],
            'access_code' => $accessCode !== '' ? $accessCode : null,
            'reservation_status' => 'pending',
            'status_reason' => '',
            'switchbot_status' => $data['issue_switchbot'] ? 'queued' : 'skipped',
            'switchbot_message' => '',
            'google_sync_status' => $data['sync_google'] ? (google_calendar_sync_enabled($cfg) ? 'queued' : 'disabled') : 'skipped',
            'google_sync_message' => '',
            'user_mail_status' => $data['email'] !== '' ? 'pending' : 'skipped',
            'admin_mail_status' => ($data['issue_switchbot'] && $data['sync_google']) ? 'pending' : 'skipped',
        ]);

        $conflicts = manage_delete_conflicting_slots($pdo, $data['use_date'], $data['room_code']);
        manage_insert_manual_slot($pdo, [
            'reservation_id' => $reservationId,
            'use_date' => $data['use_date'],
            'room_code' => $data['room_code'],
            'room_label' => reservation_room_label($data['room_code']),
            'organization_name' => $data['organization_name'],
            'email' => $data['email'],
            'usage_start_time' => $data['usage_start_time'],
            'usage_end_time' => $data['usage_end_time'],
            'usage_time' => $data['usage_time'],
            'access_code' => $accessCode,
            'access_code_start_at' => $accessCodeStartAt,
            'access_code_end_at' => $accessCodeEndAt,
            'switchbot_status' => $data['issue_switchbot'] ? 'queued' : 'skipped',
            'google_sync_status' => $data['sync_google'] ? (google_calendar_sync_enabled($cfg) ? 'queued' : 'disabled') : 'skipped',
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $affectedReservationIds = [];
    foreach ($conflicts as $conflict) {
        $affectedId = (int)($conflict['reservation_id'] ?? 0);
        if ($affectedId > 0 && $affectedId !== $reservationId) {
            $affectedReservationIds[$affectedId] = $affectedId;
        }
    }
    foreach ($affectedReservationIds as $affectedReservationId) {
        manage_refresh_reservation_from_slots($pdo, $affectedReservationId, '管理者画面の上書き登録により、この日付の予約枠は置き換えられました。');
    }

    $slotRows = reservation_fetch_detail_rows($pdo, $reservationId);
    if ($slotRows === []) {
        throw new RuntimeException('登録した予約明細を取得できませんでした。');
    }
    $slot = $slotRows[0];

    if ($data['issue_switchbot']) {
        $switchResult = reservation_issue_switchbot_access_code(
            $cfg,
            $data['room_code'],
            $data['organization_name'],
            $accessCode,
            $data['use_date'],
            $data['usage_start_time'],
            $data['usage_end_time']
        );
        reservation_update_slot($pdo, (int)$slot['id'], [
            'switchbot_status' => (string)($switchResult['status'] ?? 'failed'),
            'switchbot_request_id' => (string)($switchResult['local_request_id'] ?? ''),
            'switchbot_command_id' => (string)($switchResult['command_id'] ?? ''),
            'switchbot_message' => (string)($switchResult['message'] ?? ''),
        ]);
    }

    $slot = reservation_fetch_detail_rows($pdo, $reservationId)[0] ?? $slot;
    if ($data['sync_google']) {
        reservation_apply_google_sync($cfg, $pdo, ['id' => $reservationId], $slot);
    }

    manage_refresh_reservation_from_slots($pdo, $reservationId);
    $reservation = manage_fetch_reservation_by_id($pdo, $reservationId);
    if ($reservation === null) {
        throw new RuntimeException('登録結果を取得できませんでした。');
    }

    $mailResult = manage_send_manual_emails($cfg, $pdo, $reservation, $user, $data['issue_switchbot'] && $data['sync_google']);
    $reservation = manage_fetch_reservation_by_id($pdo, $reservationId) ?? $reservation;

    admin_auth_write_audit_log($pdo, $user, 'calendar.manual_create', 'reservation', $reservationId, [
        'use_date' => $data['use_date'],
        'room_code' => $data['room_code'],
        'room_label' => reservation_room_label($data['room_code']),
        'organization_name' => $data['organization_name'],
        'email' => $data['email'],
        'issue_switchbot' => $data['issue_switchbot'],
        'sync_google' => $data['sync_google'],
        'overwritten_count' => count($conflicts),
        'overwritten_reservation_ids' => array_values($affectedReservationIds),
        'user_mail_status' => $reservation['user_mail_status'] ?? '',
        'admin_mail_status' => $reservation['admin_mail_status'] ?? '',
    ]);

    $message = count($conflicts) > 0
        ? '予約を追加し、既存の予約枠を上書きしました。'
        : '予約を追加しました。';

    json_response([
        'ok' => true,
        'message' => $message,
        'reservation_id' => $reservationId,
        'overwritten_count' => count($conflicts),
        'reservation_status' => (string)($reservation['reservation_status'] ?? ''),
        'switchbot_status' => (string)($reservation['switchbot_status'] ?? ''),
        'google_sync_status' => (string)($reservation['google_sync_status'] ?? ''),
        'user_mail_status' => (string)($reservation['user_mail_status'] ?? ''),
        'admin_mail_status' => (string)($reservation['admin_mail_status'] ?? ''),
        'mail_result' => $mailResult,
    ]);
}


function manage_calendar_slot_delete(PDO $pdo, array $cfg, array $user, array $input): void
{
    reservation_install_schema($pdo);
    admin_auth_install_schema($pdo);

    $slotId = manage_validate_slot_delete_input($input);
    $slot = manage_fetch_calendar_slot_by_id($pdo, $slotId);
    if ($slot === null) {
        throw new RuntimeException('削除対象の予約枠が見つかりません。');
    }

    $reservationId = (int)($slot['reservation_id'] ?? 0);
    if ($reservationId < 1) {
        throw new RuntimeException('削除対象の予約IDが不正です。');
    }

    $googleDelete = manage_delete_google_event_for_slot($cfg, $slot);

    try {
        $pdo->beginTransaction();

        $delete = $pdo->prepare('DELETE FROM room_calendar_reservations WHERE id = :id');
        $delete->execute([':id' => $slotId]);

        manage_refresh_reservation_after_slot_delete($pdo, $reservationId, $slot);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $reservation = manage_fetch_reservation_by_id($pdo, $reservationId);

    admin_auth_write_audit_log($pdo, $user, 'calendar.slot_delete', 'reservation', $reservationId, [
        'slot_id' => $slotId,
        'use_date' => (string)($slot['use_date'] ?? ''),
        'room_code' => (string)($slot['room_code'] ?? ''),
        'room_label' => (string)($slot['room_label'] ?? ''),
        'organization_name' => (string)($slot['organization_name'] ?? ''),
        'google_delete_status' => (string)($googleDelete['status'] ?? ''),
        'google_delete_message' => (string)($googleDelete['message'] ?? ''),
        'reservation_status' => (string)($reservation['reservation_status'] ?? ''),
    ]);

    $message = '予約を削除しました。';
    if (($googleDelete['status'] ?? '') === 'missing_ignored') {
        $message .= ' Google カレンダー上に予定が見つからなかったため、そのままスルーしました。';
    } elseif (($googleDelete['status'] ?? '') === 'warning') {
        $message .= ' ただし Google カレンダー削除で確認事項があります。' . ((string)($googleDelete['message'] ?? '') !== '' ? ' ' . (string)$googleDelete['message'] : '');
    }

    json_response([
        'ok' => true,
        'message' => $message,
        'slot_id' => $slotId,
        'google_delete_status' => (string)($googleDelete['status'] ?? ''),
        'google_delete_message' => (string)($googleDelete['message'] ?? ''),
        'reservation_status' => (string)($reservation['reservation_status'] ?? 'deleted'),
    ]);
}

function manage_validate_slot_delete_input(array $input): int
{
    $slotId = (int)($input['slot_id'] ?? 0);
    if ($slotId < 1) {
        throw new RuntimeException('削除対象の予約枠IDが不正です。');
    }
    return $slotId;
}

function manage_fetch_calendar_slot_by_id(PDO $pdo, int $slotId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM room_calendar_reservations WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $slotId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function manage_refresh_reservation_after_slot_delete(PDO $pdo, int $reservationId, array $deletedSlot): void
{
    $remaining = reservation_fetch_detail_rows($pdo, $reservationId);
    if ($remaining === []) {
        reservation_update_status($pdo, $reservationId, [
            'room_code' => (string)($deletedSlot['room_code'] ?? ''),
            'room_label' => (string)($deletedSlot['room_label'] ?? ''),
            'use_date' => (string)($deletedSlot['use_date'] ?? date('Y-m-d')),
            'use_date_end' => (string)($deletedSlot['use_date'] ?? date('Y-m-d')),
            'selected_dates_count' => 0,
            'usage_start_time' => (string)($deletedSlot['usage_start_time'] ?? '09:00'),
            'usage_end_time' => (string)($deletedSlot['usage_end_time'] ?? '10:00'),
            'usage_time' => (string)($deletedSlot['usage_time'] ?? '09:00~10:00'),
            'access_code' => null,
            'reservation_status' => 'deleted',
            'status_reason' => '管理者画面から削除されました。',
            'switchbot_status' => 'deleted',
            'google_sync_status' => 'deleted',
        ]);
        return;
    }

    manage_refresh_reservation_from_slots($pdo, $reservationId);
}

function manage_delete_google_event_for_slot(array $cfg, array $slot): array
{
    $eventId = trim((string)($slot['google_event_id'] ?? ''));
    $calendarId = trim((string)($slot['google_calendar_id'] ?? ''));

    if ($eventId === '' || $calendarId === '') {
        return ['status' => 'skipped', 'message' => 'Google カレンダー未登録のため削除をスキップしました。'];
    }

    if (!function_exists('google_calendar_sync_enabled') || !google_calendar_sync_enabled($cfg)) {
        return ['status' => 'skipped', 'message' => 'Google カレンダー連携が無効のため削除をスキップしました。'];
    }

    try {
        google_calendar_delete_via_gas($cfg, [
            'reservation_id' => (int)($slot['reservation_id'] ?? 0),
            'reservation_detail_id' => (int)($slot['id'] ?? 0),
            'calendar_id' => $calendarId,
            'event_id' => $eventId,
            'use_date' => (string)($slot['use_date'] ?? ''),
            'room_code' => (string)($slot['room_code'] ?? ''),
            'room_label' => (string)($slot['room_label'] ?? ''),
            'organization_name' => (string)($slot['organization_name'] ?? ''),
        ]);
        return ['status' => 'deleted', 'message' => 'deleted'];
    } catch (Throwable $e) {
        $message = trim($e->getMessage());
        if (manage_is_google_missing_event_error($message)) {
            return ['status' => 'missing_ignored', 'message' => $message];
        }
        return ['status' => 'warning', 'message' => $message];
    }
}

function manage_is_google_missing_event_error(string $message): bool
{
    $normalized = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);

    $needles = [
        'event not found',
        'no event found',
        'event does not exist',
        'calendar event not found',
        '指定された予定が見つかりません',
        'イベントが見つかりません',
        '予定が見つかりません',
        '該当する予定が見つかりません',
    ];
    foreach ($needles as $needle) {
        if (str_contains($normalized, $needle)) {
            return true;
        }
    }

    if (str_contains($normalized, '404') && (str_contains($normalized, 'event') || str_contains($normalized, '予定') || str_contains($normalized, 'イベント'))) {
        return true;
    }

    return false;
}

function manage_passcode_list(PDO $pdo): void
{
    $stmt = $pdo->query(
        'SELECT use_date, usage_time, room_label, organization_name, email, access_code, access_code_start_at, access_code_end_at, switchbot_status, switchbot_request_id '
        . 'FROM room_calendar_reservations '
        . 'ORDER BY use_date DESC, id DESC LIMIT 200'
    );
    json_response([
        'ok' => true,
        'rows' => $stmt->fetchAll(),
        'message' => 'パスコード状況を取得しました。',
    ]);
}

function manage_scalar(PDO $pdo, string $sql): int
{
    $stmt = $pdo->query($sql);
    return (int)$stmt->fetchColumn();
}

function manage_truthy(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    $value = strtolower(trim((string)$value));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function manage_validate_manual_create_input(array $input): array
{
    $useDate = trim((string)($input['use_date'] ?? ''));
    $roomCode = trim((string)($input['room_code'] ?? ''));
    $organizationName = trim((string)($input['organization_name'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $usageStartTime = trim((string)($input['usage_start_time'] ?? ''));
    $usageEndTime = trim((string)($input['usage_end_time'] ?? ''));
    $syncGoogle = manage_truthy($input['sync_google'] ?? false);
    $issueSwitchbot = manage_truthy($input['issue_switchbot'] ?? false);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $useDate)) {
        throw new RuntimeException('利用日は YYYY-MM-DD 形式で入力してください。');
    }
    if (!in_array($roomCode, ['tamoku', 'orange'], true)) {
        throw new RuntimeException('部屋の指定が不正です。');
    }
    if ($organizationName === '') {
        throw new RuntimeException('団体名を入力してください。');
    }
    $orgLength = function_exists('mb_strlen') ? mb_strlen($organizationName, 'UTF-8') : strlen($organizationName);
    if ($orgLength > 150) {
        throw new RuntimeException('団体名は150文字以内で入力してください。');
    }
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('利用者向けメールアドレスの形式が不正です。');
    }

    reservation_assert_time_value($usageStartTime, 15, false);
    reservation_assert_time_value($usageEndTime, 15, true);
    if (reservation_time_to_minutes($usageEndTime) <= reservation_time_to_minutes($usageStartTime)) {
        throw new RuntimeException('利用終了時刻は利用開始時刻より後にしてください。');
    }

    return [
        'use_date' => $useDate,
        'room_code' => $roomCode,
        'organization_name' => $organizationName,
        'email' => $email,
        'usage_start_time' => $usageStartTime,
        'usage_end_time' => $usageEndTime,
        'usage_time' => $usageStartTime . '~' . $usageEndTime,
        'sync_google' => $syncGoogle,
        'issue_switchbot' => $issueSwitchbot,
    ];
}

function manage_delete_conflicting_slots(PDO $pdo, string $useDate, string $roomCode): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM room_calendar_reservations WHERE use_date = :use_date AND room_code = :room_code ORDER BY id ASC'
    );
    $stmt->execute([
        ':use_date' => $useDate,
        ':room_code' => $roomCode,
    ]);
    $rows = $stmt->fetchAll();
    if ($rows === []) {
        return [];
    }

    $delete = $pdo->prepare('DELETE FROM room_calendar_reservations WHERE id = :id');
    foreach ($rows as $row) {
        $delete->execute([':id' => (int)$row['id']]);
    }

    return $rows;
}

function manage_insert_manual_slot(PDO $pdo, array $data): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO room_calendar_reservations (reservation_id, use_date, room_code, room_label, organization_name, email, usage_start_time, usage_end_time, usage_time, access_code, access_code_start_at, access_code_end_at, switchbot_status, google_sync_status) '
        . 'VALUES (:reservation_id, :use_date, :room_code, :room_label, :organization_name, :email, :usage_start_time, :usage_end_time, :usage_time, :access_code, :access_code_start_at, :access_code_end_at, :switchbot_status, :google_sync_status)'
    );
    $stmt->execute([
        ':reservation_id' => (int)$data['reservation_id'],
        ':use_date' => (string)$data['use_date'],
        ':room_code' => (string)$data['room_code'],
        ':room_label' => (string)$data['room_label'],
        ':organization_name' => (string)$data['organization_name'],
        ':email' => (string)$data['email'],
        ':usage_start_time' => (string)$data['usage_start_time'],
        ':usage_end_time' => (string)$data['usage_end_time'],
        ':usage_time' => (string)$data['usage_time'],
        ':access_code' => (string)$data['access_code'],
        ':access_code_start_at' => (string)$data['access_code_start_at'],
        ':access_code_end_at' => (string)$data['access_code_end_at'],
        ':switchbot_status' => (string)$data['switchbot_status'],
        ':google_sync_status' => (string)$data['google_sync_status'],
    ]);
    return (int)$pdo->lastInsertId();
}

function manage_fetch_reservation_by_id(PDO $pdo, int $reservationId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $reservationId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }

    $detailRows = reservation_fetch_detail_rows($pdo, $reservationId);
    $row['date_rows'] = $detailRows;
    $row['use_dates'] = array_values(array_map(static fn(array $r): string => (string)($r['use_date'] ?? ''), $detailRows));
    return $row;
}

function manage_refresh_reservation_from_slots(PDO $pdo, int $reservationId, string $emptyReason = '管理者画面の上書き登録により、この予約枠は置き換えられました。'): void
{
    $rows = reservation_fetch_detail_rows($pdo, $reservationId);
    if ($rows === []) {
        reservation_update_status($pdo, $reservationId, [
            'selected_dates_count' => 0,
            'access_code' => null,
            'reservation_status' => 'replaced',
            'status_reason' => $emptyReason,
            'switchbot_status' => 'replaced',
            'google_sync_status' => 'replaced',
        ]);
        return;
    }

    $first = $rows[0];
    $last = $rows[count($rows) - 1];

    $switchStatuses = array_map(static fn(array $row): string => (string)($row['switchbot_status'] ?? ''), $rows);
    $googleStatuses = array_map(static fn(array $row): string => (string)($row['google_sync_status'] ?? ''), $rows);

    $switchMessages = [];
    $googleMessages = [];
    foreach ($rows as $row) {
        $switchMessage = trim((string)($row['switchbot_message'] ?? ''));
        if ($switchMessage !== '') {
            $switchMessages[] = $switchMessage;
        }
        $googleMessage = trim((string)($row['google_sync_message'] ?? ''));
        if ($googleMessage !== '') {
            $googleMessages[] = $googleMessage;
        }
    }

    $switchFailures = ['failed', 'api_error', 'device_not_found', 'not_configured'];
    $switchOk = ['success', 'completed', 'requested', 'skipped', 'disabled'];
    $googleOk = ['synced', 'disabled', 'skipped'];

    $hasSwitchFail = count(array_filter($switchStatuses, static fn(string $status): bool => in_array($status, $switchFailures, true))) > 0;
    $allSwitchOk = count(array_filter($switchStatuses, static fn(string $status): bool => in_array($status, $switchOk, true))) === count($switchStatuses);
    $allSwitchSkipped = count(array_filter($switchStatuses, static fn(string $status): bool => in_array($status, ['skipped', 'disabled'], true))) === count($switchStatuses);

    $hasGoogleFail = count(array_filter($googleStatuses, static fn(string $status): bool => $status === 'failed')) > 0;
    $allGoogleOk = count(array_filter($googleStatuses, static fn(string $status): bool => in_array($status, $googleOk, true))) === count($googleStatuses);
    $allGoogleSkipped = count(array_filter($googleStatuses, static fn(string $status): bool => in_array($status, ['skipped', 'disabled'], true))) === count($googleStatuses);

    if ($allSwitchSkipped) {
        $switchSummary = 'skipped';
    } elseif ($hasSwitchFail) {
        $switchSummary = 'partial_error';
    } elseif ($allSwitchOk) {
        $switchSummary = 'success';
    } else {
        $switchSummary = 'queued';
    }

    if ($allGoogleSkipped) {
        $googleSummary = 'skipped';
    } elseif ($hasGoogleFail) {
        $googleSummary = 'partial_error';
    } elseif ($allGoogleOk) {
        $googleSummary = 'synced';
    } else {
        $googleSummary = 'queued';
    }

    if ($hasSwitchFail) {
        $reservationStatus = 'error';
    } elseif ($allSwitchOk) {
        $reservationStatus = 'confirmed';
    } else {
        $reservationStatus = 'pending';
    }

    $statusReason = '';
    if ($hasSwitchFail) {
        $statusReason = '一部の日付でパスコード発行に失敗しました。';
        if ($switchMessages !== []) {
            $statusReason .= ' ' . implode(' / ', array_values(array_unique($switchMessages)));
        }
    } elseif ($hasGoogleFail) {
        $statusReason = 'Google カレンダー連携で確認事項があります。';
        if ($googleMessages !== []) {
            $statusReason .= ' ' . implode(' / ', array_values(array_unique($googleMessages)));
        }
    }

    reservation_update_status($pdo, $reservationId, [
        'room_code' => (string)$first['room_code'],
        'room_label' => (string)$first['room_label'],
        'use_date' => (string)$first['use_date'],
        'use_date_end' => (string)$last['use_date'],
        'selected_dates_count' => count($rows),
        'usage_start_time' => (string)$first['usage_start_time'],
        'usage_end_time' => (string)$first['usage_end_time'],
        'usage_time' => (string)$first['usage_time'],
        'access_code' => (string)$first['access_code'] !== '' ? (string)$first['access_code'] : null,
        'reservation_status' => $reservationStatus,
        'status_reason' => $statusReason,
        'switchbot_status' => $switchSummary,
        'google_sync_status' => $googleSummary,
    ]);
}

function manage_send_manual_emails(array $cfg, PDO $pdo, array $reservation, array $user, bool $sendAdminNotice): array
{
    $reservationId = (int)($reservation['id'] ?? 0);
    if ($reservationId < 1) {
        throw new RuntimeException('予約IDを取得できませんでした。');
    }

    $result = [
        'user' => ['ok' => false, 'message' => 'skipped'],
        'admin' => ['ok' => false, 'message' => 'skipped'],
    ];

    $userTo = trim((string)($reservation['email'] ?? ''));
    if ($userTo !== '') {
        $userMail = build_user_result_mail($reservation);
        try {
            send_mail_smtp($cfg, $userMail);
            reservation_record_mail_log($pdo, $reservationId, 'user_manual_result', (string)$userMail['to'], (string)$userMail['subject'], 'sent');
            reservation_update_status($pdo, $reservationId, ['user_mail_status' => 'sent']);
            $result['user'] = ['ok' => true, 'message' => 'sent'];
        } catch (Throwable $e) {
            reservation_record_mail_log($pdo, $reservationId, 'user_manual_result', (string)$userMail['to'], (string)$userMail['subject'], 'failed', $e->getMessage());
            reservation_update_status($pdo, $reservationId, ['user_mail_status' => 'failed']);
            $result['user'] = ['ok' => false, 'message' => $e->getMessage()];
        }
    } else {
        reservation_update_status($pdo, $reservationId, ['user_mail_status' => 'skipped']);
    }

    if ($sendAdminNotice) {
        $adminMail = manage_build_admin_manual_notice_mail($reservation, $user);
        $adminMail['to'] = trim((string)($cfg['reservation_admin_notify_to'] ?? 'sogokanri@bene.fit.ac.jp'));
        if ($adminMail['to'] !== '') {
            try {
                send_mail_smtp($cfg, $adminMail);
                reservation_record_mail_log($pdo, $reservationId, 'admin_manual_notice', (string)$adminMail['to'], (string)$adminMail['subject'], 'sent');
                reservation_update_status($pdo, $reservationId, ['admin_mail_status' => 'sent']);
                $result['admin'] = ['ok' => true, 'message' => 'sent'];
            } catch (Throwable $e) {
                reservation_record_mail_log($pdo, $reservationId, 'admin_manual_notice', (string)$adminMail['to'], (string)$adminMail['subject'], 'failed', $e->getMessage());
                reservation_update_status($pdo, $reservationId, ['admin_mail_status' => 'failed']);
                $result['admin'] = ['ok' => false, 'message' => $e->getMessage()];
            }
        } else {
            reservation_update_status($pdo, $reservationId, ['admin_mail_status' => 'skipped']);
        }
    } else {
        reservation_update_status($pdo, $reservationId, ['admin_mail_status' => 'skipped']);
    }

    return $result;
}

function manage_build_admin_manual_notice_mail(array $reservation, array $user): array
{
    $title = '【貸し部屋予約】管理者画面から予約を追加しました';
    $dateRows = reservation_mail_rows($reservation);
    $operator = trim((string)($user['display_name'] ?? '')) !== ''
        ? (string)$user['display_name']
        : (string)($user['login_id'] ?? '');

    $rows = [
        ['操作種別', '管理者画面からの追加 / 上書き登録'],
        ['操作者', reservation_mail_escape($operator !== '' ? $operator : '-')],
        ['利用者向けメール', reservation_mail_escape((string)($reservation['email'] ?? '') !== '' ? (string)$reservation['email'] : '未入力')],
        ['団体名', reservation_mail_escape((string)($reservation['organization_name'] ?? ''))],
        ['予約内容', reservation_mail_dates_html($dateRows, true)],
        ['予約状態', reservation_mail_escape((string)($reservation['reservation_status'] ?? ''))],
        ['SwitchBot状態', reservation_mail_escape((string)($reservation['switchbot_status'] ?? ''))],
        ['Google連携', reservation_mail_escape((string)($reservation['google_sync_status'] ?? ''))],
        ['備考', nl2br(reservation_mail_escape((string)($reservation['status_reason'] ?? '')))],
    ];

    $rowsHtml = '';
    foreach ($rows as [$label, $valueHtml]) {
        $rowsHtml .= <<<HTML
<tr>
  <td style="width:180px;padding:12px 14px;background:#f9fafb;border-bottom:1px solid #e5e7eb;font-size:14px;font-weight:700;vertical-align:top;">{$label}</td>
  <td style="padding:12px 14px;border-bottom:1px solid #e5e7eb;font-size:14px;line-height:1.8;">{$valueHtml}</td>
</tr>
HTML;
    }

    $bodyHtml = <<<HTML
<p style="margin:0 0 16px;font-size:16px;line-height:1.9;">管理者画面から予約が追加されました。Google カレンダー連携と SwitchBot パスコード発行の両方が選択されていたため、この通知を送信しています。</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
  {$rowsHtml}
</table>
HTML;

    return [
        'to' => '',
        'subject' => $title,
        'body' => trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $bodyHtml))),
        'html_body' => reservation_mail_card($title, $bodyHtml),
    ];
}
