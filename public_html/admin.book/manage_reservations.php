<?php
declare(strict_types=1);

require_once __DIR__ . '/../../apps/admin_auth.php';
require_once __DIR__ . '/../../apps/db.php';

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
        . '(SELECT GROUP_CONCAT(CONCAT(d.use_date, " ", d.access_code) ORDER BY d.use_date SEPARATOR "\n") FROM room_calendar_reservations d WHERE d.reservation_id = r.id) AS access_code_summary '
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
        'mail_issue_count' => manage_scalar($pdo, 'SELECT COUNT(*) FROM reservations WHERE user_mail_status <> "sent" OR admin_mail_status <> "sent"'),
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
        'SELECT use_date, room_code, room_label, organization_name, email, usage_time, access_code, switchbot_status, google_sync_status '
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
