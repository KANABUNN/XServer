<?php

declare(strict_types=1);

foreach ([__DIR__ . '/../../apps/admin_auth.php', __DIR__ . '/../apps/admin_auth.php', __DIR__ . '/apps/admin_auth.php'] as $__adminAuthHelper) {
    if (is_file($__adminAuthHelper)) {
        require_once $__adminAuthHelper;
        break;
    }
}

admin_auth_require_db_helpers();
$__adminUser = admin_auth_require_login();

foreach ([__DIR__ . '/../../apps/switchbot_api.php', __DIR__ . '/../apps/switchbot_api.php', __DIR__ . '/apps/switchbot_api.php'] as $__switchbotHelper) {
    if (is_file($__switchbotHelper)) {
        require_once $__switchbotHelper;
        break;
    }
}


try {
    $cfg = load_config();
    load_google_calendar_sync_helpers();
    $requestPayload = get_request_payload();
    $requestData = is_array($requestPayload) ? array_merge($_REQUEST, $requestPayload) : $_REQUEST;
    $action = (string)($requestData['action'] ?? 'list');

    $requiredPermission = manage_required_permission_for_action($action, $requestData);
    if ($requiredPermission !== null) {
        admin_auth_require_permission($requiredPermission, $__adminUser);
    }
    if (manage_action_requires_csrf($action, $_SERVER['REQUEST_METHOD'] ?? 'GET')) {
        admin_auth_require_csrf();
    }

    switch ($action) {
        case 'list':
            $pdo = db_connect($cfg);
            handle_list($pdo);
            break;

        case 'detail':
            $pdo = db_connect($cfg);
            handle_detail($pdo, $cfg);
            break;

        case 'rooms':
            $pdo = db_connect($cfg);
            handle_rooms($pdo);
            break;

        case 'download':
            $pdo = db_connect($cfg);
            handle_download($pdo, $cfg);
            break;

        case 'delete':
            $pdo = db_connect($cfg);
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => '削除は POST で呼び出してください。'], 405);
            }
            handle_delete($pdo, $cfg);
            break;

        case 'calendar_list':
            $pdo = db_connect($cfg);
            handle_calendar_list($pdo);
            break;

        case 'calendar_add':
            $pdo = db_connect($cfg);
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => '登録は POST で呼び出してください。'], 405);
            }
            handle_calendar_add($pdo, $cfg);
            break;

        case 'calendar_delete':
            $pdo = db_connect($cfg);
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => '削除は POST で呼び出してください。'], 405);
            }
            handle_calendar_delete($pdo, $cfg);
            break;

        case 'calendar_update':
            $pdo = db_connect($cfg);
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => '更新は POST で呼び出してください。'], 405);
            }
            handle_calendar_update($pdo, $cfg);
            break;

        case 'application_status_update':
            $pdo = db_connect($cfg);
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => '更新は POST で呼び出してください。'], 405);
            }
            handle_application_status_update($pdo);
            break;

        case 'mail_form_options':
            $pdo = db_connect($cfg);
            handle_mail_form_options($pdo, $cfg);
            break;

        case 'mail_history_list':
            $pdo = db_connect($cfg);
            handle_mail_history_list($pdo);
            break;

        case 'export_csv':
            $pdo = db_connect($cfg);
            handle_export_csv($pdo);
            break;

        case 'reservation_mail_send':
            $pdo = db_connect($cfg);
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => '送信は POST で呼び出してください。'], 405);
            }
            handle_reservation_mail_send($pdo, $cfg);
            break;

        case 'switchbot_status':
            handle_switchbot_status($cfg);
            break;

        case 'switchbot_create_key':
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => '登録は POST で呼び出してください。'], 405);
            }
            handle_switchbot_create_key($cfg);
            break;

        case 'switchbot_webhook_sync':
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => 'Webhook 設定は POST で呼び出してください。'], 405);
            }
            handle_switchbot_webhook_sync($cfg);
            break;

        case 'switchbot_webhook_toggle':
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => 'Webhook 設定は POST で呼び出してください。'], 405);
            }
            handle_switchbot_webhook_toggle($cfg);
            break;

        case 'switchbot_command_detail':
            handle_switchbot_command_detail($cfg);
            break;

        case 'admin_user_list':
            $pdo = db_connect($cfg);
            handle_admin_user_list($pdo);
            break;

        case 'admin_user_create':
            $pdo = db_connect($cfg);
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => '作成は POST で呼び出してください。'], 405);
            }
            handle_admin_user_create($pdo);
            break;

        case 'admin_user_update':
            $pdo = db_connect($cfg);
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => '更新は POST で呼び出してください。'], 405);
            }
            handle_admin_user_update($pdo);
            break;

        case 'audit_log_list':
            $pdo = db_connect($cfg);
            handle_audit_log_list($pdo);
            break;

        default:
            json_response(['ok' => false, 'message' => '不正な action です。'], 400);
    }
} catch (Throwable $e) {
    error_log('[manage_reservations] ' . $e->getMessage());
    error_log('[manage_reservations] ' . $e->getFile() . ':' . $e->getLine());

    $actionForError = (string)($requestData['action'] ?? ($_REQUEST['action'] ?? ''));
    $isSwitchBotAction = str_starts_with($actionForError, 'switchbot_');

    if (!headers_sent()) {
        json_response([
            'ok' => false,
            'message' => $isSwitchBotAction
                ? $e->getMessage()
                : '管理処理でエラーが発生しました。ログを確認してください。',
        ], 500);
    }
}





function manage_required_permission_for_action(string $action, array $request): ?string
{
    return match ($action) {
        'list', 'detail', 'rooms' => 'application.view',
        'download' => 'application.download',
        'delete' => 'application.delete',
        'calendar_list' => 'calendar.view',
        'calendar_add' => 'calendar.create',
        'calendar_delete' => 'calendar.delete',
        'calendar_update' => 'calendar.update',
        'application_status_update' => 'application.status.update',
        'mail_form_options' => 'mail.form.view',
        'mail_history_list' => 'mail.view',
        'reservation_mail_send' => 'mail.send',
        'switchbot_status', 'switchbot_command_detail' => 'access.view',
        'switchbot_create_key', 'switchbot_webhook_sync', 'switchbot_webhook_toggle' => 'access.edit',
        'admin_user_list', 'admin_user_create', 'admin_user_update' => 'admin.user.manage',
        'audit_log_list' => 'admin.audit.view',
        'export_csv' => manage_required_permission_for_export((string)($request['type'] ?? '')),
        default => null,
    };
}

function manage_required_permission_for_export(string $type): ?string
{
    return match ($type) {
        'applications' => 'application.export',
        'calendar' => 'calendar.export',
        'mail_history' => 'mail.export',
        default => null,
    };
}

function manage_action_requires_csrf(string $action, string $method): bool
{
    if (strtoupper($method) !== 'POST') {
        return false;
    }

    return in_array($action, [
        'delete',
        'calendar_add',
        'calendar_delete',
        'calendar_update',
        'application_status_update',
        'reservation_mail_send',
        'switchbot_create_key',
        'switchbot_webhook_sync',
        'switchbot_webhook_toggle',
        'admin_user_create',
        'admin_user_update',
    ], true);
}

function manage_write_audit(PDO $pdo, string $action, ?string $targetType = null, string|int|null $targetId = null, array $summary = []): void
{
    try {
        $actor = admin_auth_current_user();
        admin_auth_write_audit_log($pdo, $actor, $action, $targetType, $targetId, $summary);
    } catch (Throwable $e) {
        error_log('[manage_reservations][audit] ' . $e->getMessage());
    }
}

function manage_write_audit_via_auth_db(string $action, ?string $targetType = null, string|int|null $targetId = null, array $summary = []): void
{
    try {
        $auditPdo = admin_auth_db_connect();
        manage_write_audit($auditPdo, $action, $targetType, $targetId, $summary);
    } catch (Throwable $e) {
        error_log('[manage_reservations][audit-db] ' . $e->getMessage());
    }
}

function handle_admin_user_list(PDO $pdo): void
{
    $rows = admin_auth_list_users($pdo);
    json_response([
        'ok' => true,
        'rows' => array_map(static function (array $row): array {
            unset($row['password_hash']);
            return $row;
        }, $rows),
        'count' => count($rows),
        'message' => '管理者アカウント一覧を取得しました。',
    ]);
}

function handle_admin_user_create(PDO $pdo): void
{
    $input = get_request_payload();
    $userId = admin_auth_create_user($pdo, [
        'login_id' => trim((string)($input['login_id'] ?? '')),
        'display_name' => trim((string)($input['display_name'] ?? '')),
        'email' => trim((string)($input['email'] ?? '')),
        'password' => (string)($input['password'] ?? ''),
        'role_key' => trim((string)($input['role_key'] ?? 'viewer')),
    ]);
    $created = admin_auth_fetch_user_by_id($pdo, $userId);
    manage_write_audit($pdo, 'admin.user.create', 'admin_user', $userId, [
        'login_id' => (string)($created['login_id'] ?? ''),
        'display_name' => (string)($created['display_name'] ?? ''),
        'role_key' => (string)($created['role_key'] ?? ''),
        'is_active' => (int)($created['is_active'] ?? 0),
    ]);

    json_response([
        'ok' => true,
        'message' => '管理者アカウントを作成しました。',
        'row' => $created,
    ]);
}

function handle_admin_user_update(PDO $pdo): void
{
    $input = get_request_payload();
    $id = max(0, (int)($input['id'] ?? 0));
    if ($id <= 0) {
        json_response(['ok' => false, 'message' => '更新対象のユーザーIDが不正です。'], 400);
    }

    $before = admin_auth_fetch_user_by_id($pdo, $id);
    if ($before === null) {
        json_response(['ok' => false, 'message' => '更新対象のユーザーが見つかりません。'], 404);
    }

    $updated = admin_auth_update_user($pdo, $id, [
        'login_id' => trim((string)($input['login_id'] ?? '')),
        'display_name' => trim((string)($input['display_name'] ?? '')),
        'email' => trim((string)($input['email'] ?? '')),
        'password' => (string)($input['password'] ?? ''),
        'role_key' => trim((string)($input['role_key'] ?? 'viewer')),
        'is_active' => filter_var($input['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
    ], admin_auth_current_user());

    manage_write_audit($pdo, 'admin.user.update', 'admin_user', $id, [
        'before' => [
            'login_id' => (string)($before['login_id'] ?? ''),
            'display_name' => (string)($before['display_name'] ?? ''),
            'role_key' => (string)($before['role_key'] ?? ''),
            'is_active' => (int)($before['is_active'] ?? 0),
        ],
        'after' => [
            'login_id' => (string)($updated['login_id'] ?? ''),
            'display_name' => (string)($updated['display_name'] ?? ''),
            'role_key' => (string)($updated['role_key'] ?? ''),
            'is_active' => (int)($updated['is_active'] ?? 0),
        ],
        'password_changed' => ((string)($input['password'] ?? '') !== ''),
    ]);

    $currentUser = admin_auth_current_user();
    json_response([
        'ok' => true,
        'message' => '管理者アカウントを更新しました。',
        'row' => $updated,
        'current_user' => $currentUser,
    ]);
}

function handle_audit_log_list(PDO $pdo): void
{
    $limit = max(1, min(300, (int)($_GET['limit'] ?? 100)));
    $rows = admin_auth_list_audit_logs($pdo, $limit);
    json_response([
        'ok' => true,
        'rows' => $rows,
        'count' => count($rows),
        'message' => '監査ログを取得しました。',
    ]);
}

function load_google_calendar_sync_helpers(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $candidates = [
        __DIR__ . '/../../apps/google_calendar_sync.php',
        __DIR__ . '/../apps/google_calendar_sync.php',
        __DIR__ . '/apps/google_calendar_sync.php',
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            require_once $path;
            $loaded = true;
            return;
        }
    }

    throw new RuntimeException('google_calendar_sync.php が見つかりません。apps 配下へ配置してください。');
}


function handle_switchbot_status(array $cfg): void
{
    $webhook = switchbot_basic_webhook_overview($cfg, $_SERVER);
    $recentCommands = switchbot_list_recent_commands($cfg, 20);

    if (switchbot_is_configured($cfg)) {
        try {
            $webhook = switchbot_get_webhook_overview($cfg, $_SERVER);
        } catch (Throwable $e) {
            $webhook['query_error'] = $e->getMessage();
        }
    }

    if (!switchbot_is_configured($cfg)) {
        json_response([
            'ok' => true,
            'configured' => false,
            'available_keypads_count' => 0,
            'rooms' => [
                'tamoku' => switchbot_build_room_status($cfg, 'tamoku', []),
                'orange' => switchbot_build_room_status($cfg, 'orange', []),
            ],
            'webhook' => $webhook,
            'recent_commands' => $recentCommands,
            'message' => 'SwitchBot の token / secret が未設定です。config.php を確認してください。',
        ]);
    }

    $keypads = switchbot_filter_keypads(switchbot_get_devices($cfg));

    json_response([
        'ok' => true,
        'configured' => true,
        'available_keypads_count' => count($keypads),
        'rooms' => [
            'tamoku' => switchbot_build_room_status($cfg, 'tamoku', $keypads),
            'orange' => switchbot_build_room_status($cfg, 'orange', $keypads),
        ],
        'webhook' => $webhook,
        'recent_commands' => $recentCommands,
        'message' => 'SwitchBot 状態を更新しました。',
    ]);
}

function handle_switchbot_create_key(array $cfg): void
{
    if (!switchbot_is_configured($cfg)) {
        json_response(['ok' => false, 'message' => 'SwitchBot の token / secret が未設定です。'], 400);
    }

    $input = get_request_payload();
    $roomCode = trim((string)($input['room_code'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    $password = trim((string)($input['password'] ?? ''));
    $startAt = trim((string)($input['start_at'] ?? ''));
    $endAt = trim((string)($input['end_at'] ?? ''));

    if (!in_array($roomCode, ['tamoku', 'orange'], true)) {
        json_response(['ok' => false, 'message' => '部屋の指定が不正です。'], 400);
    }
    if ($name === '') {
        json_response(['ok' => false, 'message' => 'パスワード名を入力してください。'], 400);
    }
    if (mb_strlen($name, 'UTF-8') > 100) {
        json_response(['ok' => false, 'message' => 'パスワード名は100文字以内で入力してください。'], 400);
    }

    $keypads = switchbot_filter_keypads(switchbot_get_devices($cfg));
    $device = switchbot_find_device_for_room($cfg, $roomCode, $keypads);
    if ($device === null) {
        json_response(['ok' => false, 'message' => '対象部屋に対応する SwitchBot キーパッドが見つかりません。config.php の device_id / device_name を確認してください。'], 400);
    }

    $deviceId = (string)($device['deviceId'] ?? '');
    if ($deviceId === '') {
        throw new RuntimeException('SwitchBot deviceId を取得できませんでした。');
    }

    $localRequestId = switchbot_generate_local_request_id();
    $baseRecord = [
        'local_request_id' => $localRequestId,
        'command_id' => '',
        'room_code' => $roomCode,
        'room_label' => switchbot_room_label($roomCode),
        'device_id' => $deviceId,
        'device_name' => (string)($device['deviceName'] ?? ''),
        'passcode_name' => $name,
        'passcode' => $password,
        'start_at' => $startAt,
        'end_at' => $endAt,
        'status' => 'queued',
    ];

    $baseDetail = [
        'phase' => 'before_api_request',
        'room_code' => $roomCode,
        'room_label' => switchbot_room_label($roomCode),
        'device' => [
            'device_id' => $deviceId,
            'device_name' => (string)($device['deviceName'] ?? ''),
            'device_type' => (string)($device['deviceType'] ?? ''),
        ],
        'request' => [
            'name' => $name,
            'password_masked' => str_repeat('*', max(6, min(12, strlen($password)))),
            'start_at' => $startAt,
            'end_at' => $endAt,
        ],
    ];

    $storedRecord = switchbot_upsert_request_record($cfg, $baseRecord, $baseDetail);

    try {
        $result = switchbot_create_time_limited_key($cfg, $deviceId, $name, $password, $startAt, $endAt);
        $statusCode = (int)($result['statusCode'] ?? 0);
        if ($statusCode !== 100) {
            $message = (string)($result['message'] ?? 'SwitchBot API error');
            $failureRecord = $storedRecord;
            $failureRecord['status'] = 'api_error';
            $failureRecord['result'] = $message;
            $failureRecord['updated_at'] = switchbot_now_string($cfg);
            switchbot_upsert_request_record($cfg, $failureRecord, [
                'phase' => 'api_error',
                'api_response' => $result,
                'request' => $baseDetail['request'],
                'device' => $baseDetail['device'],
            ]);
            json_response(['ok' => false, 'message' => 'SwitchBot API でエラーが返されました: ' . $message], 502);
        }

        $commandId = (string)($result['body']['commandId'] ?? '');
        $storedRecord['command_id'] = $commandId;
        $storedRecord['status'] = 'accepted';
        $storedRecord['updated_at'] = switchbot_now_string($cfg);
        $storedRecord['result'] = trim((string)($result['message'] ?? 'success'));
        $storedRecord = switchbot_upsert_request_record($cfg, $storedRecord, [
            'phase' => 'accepted',
            'request' => $baseDetail['request'],
            'device' => $baseDetail['device'],
            'api_response' => $result,
        ]);

        $message = $commandId !== ''
            ? 'SwitchBot へパスワード追加要求を送信しました。Webhook で最終結果を追跡します。'
            : 'SwitchBot へパスワード追加要求を送信しました。commandId は未返却だったため、local_request_id を基準に管理します。';

        manage_write_audit_via_auth_db('switchbot.key.create', 'switchbot_request', (int)($storedRecord['id'] ?? 0), [
            'room_code' => $roomCode,
            'device_id' => $deviceId,
            'local_request_id' => $localRequestId,
            'command_id' => $commandId,
            'passcode_name' => $name,
        ]);

        json_response([
            'ok' => true,
            'room_code' => $roomCode,
            'room_label' => switchbot_room_label($roomCode),
            'device_id' => $deviceId,
            'device_name' => (string)($device['deviceName'] ?? ''),
            'local_request_id' => $localRequestId,
            'command_id' => $commandId,
            'db_id' => (int)($storedRecord['id'] ?? 0),
            'message' => $message,
        ]);
    } catch (Throwable $e) {
        $failureRecord = $storedRecord;
        $failureRecord['status'] = 'api_error';
        $failureRecord['result'] = $e->getMessage();
        $failureRecord['updated_at'] = switchbot_now_string($cfg);
        switchbot_upsert_request_record($cfg, $failureRecord, [
            'phase' => 'exception',
            'request' => $baseDetail['request'],
            'device' => $baseDetail['device'],
            'exception' => [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ],
        ]);
        throw $e;
    }
}



function handle_switchbot_command_detail(array $cfg): void
{
    $localRequestId = trim((string)($_GET['local_request_id'] ?? ''));
    $id = (int)($_GET['id'] ?? 0);

    if ($localRequestId === '' && $id < 1) {
        json_response(['ok' => false, 'message' => 'local_request_id または id を指定してください。'], 400);
    }

    $detail = switchbot_get_request_detail($cfg, $localRequestId, $id);
    if (!is_array($detail)) {
        json_response(['ok' => false, 'message' => '指定した発行履歴が見つかりません。'], 404);
    }

    json_response([
        'ok' => true,
        'record' => $detail['record'] ?? null,
        'detail_json' => $detail['detail_json'] ?? null,
        'detail_json_path' => (string)($detail['detail_json_path'] ?? ''),
        'detail_json_exists' => (bool)($detail['detail_json_exists'] ?? false),
        'message' => '発行済みパスワード詳細を取得しました。',
    ]);
}

function handle_switchbot_webhook_sync(array $cfg): void
{
    if (!switchbot_is_configured($cfg)) {
        json_response(['ok' => false, 'message' => 'SwitchBot の token / secret が未設定です。'], 400);
    }

    $overview = switchbot_sync_webhook_to_current_url($cfg, $_SERVER);
    manage_write_audit_via_auth_db('switchbot.webhook.sync', 'switchbot_webhook', null, [
        'enabled' => (bool)($overview['enabled'] ?? false),
        'url' => (string)($overview['url'] ?? ''),
    ]);
    json_response([
        'ok' => true,
        'webhook' => $overview,
        'message' => '現在の URL で SwitchBot webhook を登録または更新しました。',
    ]);
}

function handle_switchbot_webhook_toggle(array $cfg): void
{
    if (!switchbot_is_configured($cfg)) {
        json_response(['ok' => false, 'message' => 'SwitchBot の token / secret が未設定です。'], 400);
    }

    $input = get_request_payload();
    $enable = filter_var($input['enable'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($enable === null) {
        json_response(['ok' => false, 'message' => 'enable に true / false を指定してください。'], 400);
    }

    $overview = switchbot_set_webhook_enabled_for_current_url($cfg, $_SERVER, $enable);
    manage_write_audit_via_auth_db('switchbot.webhook.toggle', 'switchbot_webhook', null, [
        'enabled' => $enable,
        'url' => (string)($overview['url'] ?? ''),
    ]);
    json_response([
        'ok' => true,
        'webhook' => $overview,
        'message' => $enable ? 'Webhook を有効化しました。' : 'Webhook を無効化しました。',
    ]);
}

function load_config(): array
{
    $candidates = [
        __DIR__ . '/../../apps/config.php',
        __DIR__ . '/../apps/config.php',
        __DIR__ . '/apps/config.php',
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            $cfg = require $path;
            if (!is_array($cfg)) {
                throw new RuntimeException('config.php が配列を返していません。');
            }
            return $cfg;
        }
    }

    throw new RuntimeException('config.php が見つかりません。配置先に合わせて load_config() の候補パスを調整してください。');
}

function handle_list(PDO $pdo): void
{
    $q = trim((string)($_GET['q'] ?? ''));
    $room = trim((string)($_GET['room'] ?? ''));
    $status = normalize_application_status_input($_GET['status'] ?? '');
    $dateFrom = trim((string)($_GET['date_from'] ?? ''));
    $dateTo = trim((string)($_GET['date_to'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(200, (int)($_GET['per_page'] ?? 50)));

    $statusColumn = get_reservation_status_column($pdo);

    $sortMap = [
        'id' => 'id',
        'created_at' => 'created_at',
        'email' => 'email',
        'room' => 'room',
        'original_name' => 'original_name',
        'stored_name' => 'stored_name',
        'note' => 'note',
    ];
    if ($statusColumn !== null) {
        $sortMap['application_status'] = $statusColumn;
    }
    $sortKey = (string)($_GET['sort'] ?? 'created_at');
    $sortColumn = $sortMap[$sortKey] ?? 'created_at';

    $dir = strtolower((string)($_GET['dir'] ?? 'desc'));
    $dir = $dir === 'asc' ? 'ASC' : 'DESC';

    $params = [];
    $whereSql = build_where_sql($q, $room, $status, $dateFrom, $dateTo, $statusColumn, $params);
    $statusSelect = reservation_status_select_expr($statusColumn);

    $countSql = 'SELECT COUNT(*) FROM reservations' . $whereSql;
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    if ($offset >= $total && $total > 0) {
        $page = (int)max(1, (int)ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
    }

    $listSql = <<<SQL
SELECT
    id,
    email,
    room,
    {$statusSelect} AS application_status,
    original_name,
    stored_name,
    file_path,
    note,
    created_at
FROM reservations
{$whereSql}
ORDER BY {$sortColumn} {$dir}, id DESC
LIMIT :limit OFFSET :offset
SQL;

    $stmt = $pdo->prepare($listSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    json_response([
        'ok' => true,
        'rows' => $rows,
        'count' => count($rows),
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => max(1, (int)ceil($total / $perPage)),
        'filters' => [
            'q' => $q,
            'room' => $room,
            'status' => $status,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'sort' => $sortKey,
            'dir' => strtolower($dir),
        ],
    ]);
}

function handle_detail(PDO $pdo, array $cfg): void
{
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_response(['ok' => false, 'message' => '詳細対象の id が不正です。'], 400);
    }

    $row = find_reservation($pdo, $id);
    if ($row === null) {
        json_response(['ok' => false, 'message' => '対象データが見つかりません。'], 404);
    }

    $file = resolve_storage_file($cfg, $row);
    $mimeType = null;
    $fileSize = null;
    $fileExists = false;

    if ($file !== null && is_file($file) && is_readable($file)) {
        $fileExists = true;
        $fileSize = filesize($file);
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($file);
        $mimeType = is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
    }

    $row['file_exists'] = $fileExists;
    $row['mime_type'] = $mimeType;
    $row['file_size'] = $fileSize;

    json_response([
        'ok' => true,
        'row' => $row,
    ]);
}

function handle_rooms(PDO $pdo): void
{
    $stmt = $pdo->query("SELECT DISTINCT room FROM reservations WHERE room IS NOT NULL AND room <> '' ORDER BY room ASC");
    $rooms = $stmt->fetchAll(PDO::FETCH_COLUMN);

    json_response([
        'ok' => true,
        'rooms' => array_values(array_filter(array_map('strval', $rooms), static fn(string $room): bool => $room !== '')),
    ]);
}

function handle_download(PDO $pdo, array $cfg): void
{
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('ダウンロード対象の id が不正です。');
    }

    $row = find_reservation($pdo, $id);
    if ($row === null) {
        http_response_code(404);
        exit('対象データが見つかりません。');
    }

    $file = resolve_storage_file($cfg, $row);
    if ($file === null || !is_file($file) || !is_readable($file)) {
        http_response_code(404);
        exit('保存ファイルが見つからないか、読み取れません。');
    }

    $downloadName = trim((string)($row['original_name'] ?? ''));
    if ($downloadName === '') {
        $downloadName = basename($file);
    }

    $mime = 'application/octet-stream';
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected = $finfo->file($file);
    if (is_string($detected) && $detected !== '') {
        $mime = $detected;
    }

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($file));
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($downloadName));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-cache, must-revalidate');

    readfile($file);
    exit;
}

function handle_delete(PDO $pdo, array $cfg): void
{
    $input = get_request_payload();
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        json_response(['ok' => false, 'message' => '削除対象の id が不正です。'], 400);
    }

    $pdo->beginTransaction();
    try {
        $row = find_reservation_for_update($pdo, $id);
        if ($row === null) {
            $pdo->rollBack();
            json_response(['ok' => false, 'message' => '対象データが見つかりません。'], 404);
        }

        $file = resolve_storage_file($cfg, $row);
        $fileDeleted = false;
        $fileMissing = false;

        if ($file !== null) {
            if (is_file($file)) {
                if (!@unlink($file)) {
                    throw new RuntimeException('保存ファイルの削除に失敗しました。権限を確認してください。');
                }
                $fileDeleted = true;
            } else {
                $fileMissing = true;
            }
        }

        $stmt = $pdo->prepare('DELETE FROM reservations WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $pdo->commit();

        $message = $fileMissing
            ? 'DBレコードを削除しました。保存ファイルは既に存在しませんでした。'
            : 'DBレコードと保存ファイルを削除しました。';

        manage_write_audit($pdo, 'application.delete', 'reservation', $id, [
            'email' => (string)($row['email'] ?? ''),
            'room' => (string)($row['room'] ?? ''),
            'stored_name' => (string)($row['stored_name'] ?? ''),
            'file_deleted' => $fileDeleted,
            'file_missing' => $fileMissing,
        ]);

        json_response([
            'ok' => true,
            'message' => $message,
            'file_deleted' => $fileDeleted,
            'file_missing' => $fileMissing,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function handle_calendar_list(PDO $pdo): void
{
    $month = trim((string)($_GET['month'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        json_response(['ok' => false, 'message' => 'month は YYYY-MM 形式で指定してください。'], 400);
    }

    $firstDay = DateTimeImmutable::createFromFormat('Y-m-d', $month . '-01');
    if (!$firstDay) {
        json_response(['ok' => false, 'message' => 'month が不正です。'], 400);
    }
    $nextMonth = $firstDay->modify('first day of next month');

    $table = get_calendar_table_name($pdo);
    $columns = get_table_columns($pdo, $table);
    $map = resolve_calendar_column_map($columns);

    $sql = sprintf(
        'SELECT %s AS id, %s AS use_date, %s AS room_code, %s AS organization_name, %s AS people_count, %s AS usage_time
         FROM %s
         WHERE %s >= :from_date AND %s < :to_date
         ORDER BY %s ASC, %s ASC, %s ASC',
        $map['id_select'],
        $map['date_column'],
        $map['room_column'],
        $map['org_column'],
        $map['people_count_select'],
        $map['usage_time_select'],
        $table,
        $map['date_column'],
        $map['date_column'],
        $map['date_column'],
        $map['room_column'],
        $map['org_column']
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':from_date' => $firstDay->format('Y-m-d'),
        ':to_date' => $nextMonth->format('Y-m-d'),
    ]);
    $rows = $stmt->fetchAll();

    json_response([
        'ok' => true,
        'rows' => $rows,
        'count' => count($rows),
    ]);
}

function handle_calendar_add(PDO $pdo, array $cfg): void
{
    $input = get_request_payload();

    $useDate = trim((string)($input['use_date'] ?? ''));
    $roomCode = trim((string)($input['room_code'] ?? ''));
    $orgName = trim((string)($input['organization_name'] ?? ''));
    $peopleCount = normalize_optional_people_count($input['people_count'] ?? null);
    $usageTime = normalize_optional_usage_time($input['usage_time'] ?? null);
    $reservationId = max(0, (int)($input['reservation_id'] ?? 0));

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $useDate);
    if (!$dt || $dt->format('Y-m-d') !== $useDate) {
        json_response(['ok' => false, 'message' => '使用日が不正です。YYYY-MM-DD 形式で指定してください。'], 400);
    }

    $allowedRooms = ['tamoku', 'orange'];
    if (!in_array($roomCode, $allowedRooms, true)) {
        json_response(['ok' => false, 'message' => '部屋の指定が不正です。'], 400);
    }

    if ($orgName === '') {
        json_response(['ok' => false, 'message' => '団体名を入力してください。'], 400);
    }
    if (mb_strlen($orgName, 'UTF-8') > 255) {
        json_response(['ok' => false, 'message' => '団体名が長すぎます（最大255文字）。'], 400);
    }

    $table = get_calendar_table_name($pdo);
    $columns = get_table_columns($pdo, $table);
    $map = resolve_calendar_column_map($columns);

    $googleSyncEnabled = function_exists('google_calendar_sync_enabled') && google_calendar_sync_enabled($cfg);
    $googleResult = null;

    try {
        $pdo->beginTransaction();

        if ($reservationId > 0 && find_reservation_for_update($pdo, $reservationId) === null) {
            json_response(['ok' => false, 'message' => '元の申請データが見つかりません。'], 404);
        }

        $conflict = find_calendar_conflict($pdo, $table, $map, $useDate, $roomCode, $usageTime, null);
        if ($conflict !== null) {
            json_response([
                'ok' => false,
                'message' => build_calendar_conflict_message($conflict),
            ], 409);
        }

        $insertColumns = [
            $map['date_column'],
            $map['room_column'],
            $map['org_column'],
        ];
        $placeholders = [
            ':use_date',
            ':room_code',
            ':organization_name',
        ];

        if ($map['reservation_id_column'] !== null) {
            $insertColumns[] = $map['reservation_id_column'];
            $placeholders[] = ':reservation_id';
        }

        if ($map['people_count_column'] !== null) {
            $insertColumns[] = $map['people_count_column'];
            $placeholders[] = ':people_count';
        }

        if ($map['usage_time_column'] !== null) {
            $insertColumns[] = $map['usage_time_column'];
            $placeholders[] = ':usage_time';
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $insertColumns),
            implode(', ', $placeholders)
        );

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':use_date', $useDate, PDO::PARAM_STR);
        $stmt->bindValue(':room_code', $roomCode, PDO::PARAM_STR);
        $stmt->bindValue(':organization_name', $orgName, PDO::PARAM_STR);

        if ($map['reservation_id_column'] !== null) {
            if ($reservationId > 0) {
                $stmt->bindValue(':reservation_id', $reservationId, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':reservation_id', null, PDO::PARAM_NULL);
            }
        }

        if ($map['people_count_column'] !== null) {
            if ($peopleCount === null) {
                $stmt->bindValue(':people_count', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':people_count', $peopleCount, PDO::PARAM_INT);
            }
        }

        if ($map['usage_time_column'] !== null) {
            if ($usageTime === null) {
                $stmt->bindValue(':usage_time', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':usage_time', $usageTime, PDO::PARAM_STR);
            }
        }

        $stmt->execute();

        $insertedId = $map['id_column'] !== null ? (int)$pdo->lastInsertId() : null;

        if ($reservationId > 0) {
            update_reservation_application_status($pdo, $reservationId, 'confirmed');
        }

        if ($googleSyncEnabled) {
            $calendarId = google_calendar_require_room_calendar_id($cfg, $roomCode);
            $googleResult = google_calendar_create_via_gas($cfg, [
                'reservation_id' => $insertedId,
                'use_date' => $useDate,
                'usage_time' => $usageTime,
                'room_code' => $roomCode,
                'room_label' => room_code_to_label($roomCode),
                'organization_name' => $orgName,
                'calendar_id' => $calendarId,
            ]);

            update_calendar_google_sync_state($pdo, $table, $map, $insertedId, $useDate, $roomCode, $orgName, [
                'google_event_id' => $googleResult['event_id'] ?? null,
                'google_calendar_id' => $googleResult['calendar_id'] ?? $calendarId,
                'google_sync_status' => 'synced',
                'google_sync_error' => null,
            ]);
        }

        $pdo->commit();

        $message = $googleSyncEnabled
            ? '確定予約を登録し、Googleカレンダーにも反映しました。'
            : '確定予約として登録しました。';

        manage_write_audit($pdo, 'calendar.create', 'calendar_reservation', $insertedId ?? null, [
            'use_date' => $useDate,
            'room_code' => $roomCode,
            'organization_name' => $orgName,
            'reservation_id' => $reservationId,
            'usage_time' => $usageTime,
            'google_synced' => $googleSyncEnabled,
        ]);

        json_response([
            'ok' => true,
            'message' => $message,
            'google_synced' => $googleSyncEnabled,
            'google_event_id' => $googleResult['event_id'] ?? null,
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23000') {
            json_response([
                'ok' => false,
                'message' => 'この日時・部屋では重複する予約を登録できません。',
            ], 409);
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function handle_calendar_delete(PDO $pdo, array $cfg): void
{
    $input = get_request_payload();
    $table = get_calendar_table_name($pdo);
    $columns = get_table_columns($pdo, $table);
    $map = resolve_calendar_column_map($columns);

    $id = (int)($input['id'] ?? 0);
    $useDate = trim((string)($input['use_date'] ?? ''));
    $roomCode = trim((string)($input['room_code'] ?? ''));
    $orgName = trim((string)($input['organization_name'] ?? ''));

    $googleSyncEnabled = function_exists('google_calendar_sync_enabled') && google_calendar_sync_enabled($cfg);

    $pdo->beginTransaction();
    try {
        $row = find_calendar_reservation_for_update($pdo, $table, $map, $id, $useDate, $roomCode, $orgName);
        if ($row === null) {
            $pdo->rollBack();
            json_response(['ok' => false, 'message' => '削除対象が見つかりませんでした。'], 404);
        }

        $storedRoomCode = trim((string)($row['room_code'] ?? ''));
        $storedUseDate = trim((string)($row['use_date'] ?? ''));
        $storedOrgName = trim((string)($row['organization_name'] ?? ''));
        $storedUsageTime = trim((string)($row['usage_time'] ?? ''));
        $storedGoogleEventId = trim((string)($row['google_event_id'] ?? ''));
        $storedGoogleCalendarId = trim((string)($row['google_calendar_id'] ?? ''));
        $linkedReservationId = max(0, (int)($row['reservation_id'] ?? 0));

        $googleDeleted = false;
        if ($googleSyncEnabled) {
            $calendarId = $storedGoogleCalendarId !== ''
                ? $storedGoogleCalendarId
                : google_calendar_require_room_calendar_id($cfg, $storedRoomCode);

            $deleteResult = google_calendar_delete_via_gas($cfg, [
                'reservation_id' => $row['id'] ?? null,
                'use_date' => $storedUseDate,
                'usage_time' => $storedUsageTime,
                'room_code' => $storedRoomCode,
                'room_label' => room_code_to_label($storedRoomCode),
                'organization_name' => $storedOrgName,
                'calendar_id' => $calendarId,
                'event_id' => $storedGoogleEventId,
            ]);
            $googleDeleted = (bool)($deleteResult['deleted'] ?? false);
        }

        if ($id > 0 && $map['id_column'] !== null) {
            $stmt = $pdo->prepare(sprintf('DELETE FROM %s WHERE %s = :id', $table, $map['id_column']));
            $stmt->execute([':id' => $id]);
        } else {
            $sql = sprintf(
                'DELETE FROM %s WHERE %s = :use_date AND %s = :room_code AND %s = :organization_name LIMIT 1',
                $table,
                $map['date_column'],
                $map['room_column'],
                $map['org_column']
            );
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':use_date' => $storedUseDate,
                ':room_code' => $storedRoomCode,
                ':organization_name' => $storedOrgName,
            ]);
        }

        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('削除対象が見つかりませんでした。');
        }

        if ($linkedReservationId > 0 && !calendar_reservation_exists_for_source($pdo, $table, $map, $linkedReservationId)) {
            update_reservation_application_status($pdo, $linkedReservationId, 'reviewing');
        }

        $pdo->commit();

        $message = ($googleSyncEnabled && $googleDeleted)
            ? '確定予約を削除し、Googleカレンダーからも削除しました。'
            : '確定予約を削除しました。';

        manage_write_audit($pdo, 'calendar.delete', 'calendar_reservation', (int)($row['id'] ?? $id), [
            'use_date' => $storedUseDate,
            'room_code' => $storedRoomCode,
            'organization_name' => $storedOrgName,
            'usage_time' => $storedUsageTime,
            'linked_reservation_id' => $linkedReservationId,
            'google_deleted' => $googleDeleted,
        ]);

        json_response([
            'ok' => true,
            'message' => $message,
            'google_deleted' => $googleDeleted,
            'linked_reservation_status' => $linkedReservationId > 0 ? 'reviewing' : null,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function handle_calendar_update(PDO $pdo, array $cfg): void
{
    $input = get_request_payload();
    $table = get_calendar_table_name($pdo);
    $columns = get_table_columns($pdo, $table);
    $map = resolve_calendar_column_map($columns);

    $id = max(0, (int)($input['id'] ?? 0));
    if ($id <= 0) {
        json_response(['ok' => false, 'message' => '更新対象の id が不正です。'], 400);
    }

    $useDate = trim((string)($input['use_date'] ?? ''));
    $roomCode = trim((string)($input['room_code'] ?? ''));
    $orgName = trim((string)($input['organization_name'] ?? ''));
    $peopleCount = normalize_optional_people_count($input['people_count'] ?? null);
    $usageTime = normalize_optional_usage_time($input['usage_time'] ?? null);

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $useDate);
    if (!$dt || $dt->format('Y-m-d') !== $useDate) {
        json_response(['ok' => false, 'message' => '使用日が不正です。YYYY-MM-DD 形式で指定してください。'], 400);
    }

    if (!in_array($roomCode, ['tamoku', 'orange'], true)) {
        json_response(['ok' => false, 'message' => '部屋の指定が不正です。'], 400);
    }
    if ($orgName === '') {
        json_response(['ok' => false, 'message' => '団体名を入力してください。'], 400);
    }
    if (mb_strlen($orgName, 'UTF-8') > 255) {
        json_response(['ok' => false, 'message' => '団体名が長すぎます（最大255文字）。'], 400);
    }

    $googleSyncEnabled = function_exists('google_calendar_sync_enabled') && google_calendar_sync_enabled($cfg);

    $pdo->beginTransaction();
    try {
        $current = find_calendar_reservation_for_update($pdo, $table, $map, $id, '', '', '');
        if ($current === null) {
            $pdo->rollBack();
            json_response(['ok' => false, 'message' => '更新対象が見つかりませんでした。'], 404);
        }

        $conflict = find_calendar_conflict($pdo, $table, $map, $useDate, $roomCode, $usageTime, $id);
        if ($conflict !== null) {
            json_response([
                'ok' => false,
                'message' => build_calendar_conflict_message($conflict),
            ], 409);
        }

        $sets = [
            $map['date_column'] . ' = :use_date',
            $map['room_column'] . ' = :room_code',
            $map['org_column'] . ' = :organization_name',
        ];
        if ($map['people_count_column'] !== null) {
            $sets[] = $map['people_count_column'] . ' = :people_count';
        }
        if ($map['usage_time_column'] !== null) {
            $sets[] = $map['usage_time_column'] . ' = :usage_time';
        }

        $sql = sprintf('UPDATE %s SET %s WHERE %s = :id', $table, implode(', ', $sets), $map['id_column']);
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':use_date', $useDate, PDO::PARAM_STR);
        $stmt->bindValue(':room_code', $roomCode, PDO::PARAM_STR);
        $stmt->bindValue(':organization_name', $orgName, PDO::PARAM_STR);
        if ($map['people_count_column'] !== null) {
            if ($peopleCount === null) {
                $stmt->bindValue(':people_count', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':people_count', $peopleCount, PDO::PARAM_INT);
            }
        }
        if ($map['usage_time_column'] !== null) {
            if ($usageTime === null) {
                $stmt->bindValue(':usage_time', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':usage_time', $usageTime, PDO::PARAM_STR);
            }
        }
        $stmt->execute();

        if ($googleSyncEnabled) {
            $previousRoomCode = trim((string)($current['room_code'] ?? ''));
            $previousUseDate = trim((string)($current['use_date'] ?? ''));
            $previousOrgName = trim((string)($current['organization_name'] ?? ''));
            $previousUsageTime = trim((string)($current['usage_time'] ?? ''));
            $previousGoogleEventId = trim((string)($current['google_event_id'] ?? ''));
            $previousGoogleCalendarId = trim((string)($current['google_calendar_id'] ?? ''));
            $newCalendarId = google_calendar_require_room_calendar_id($cfg, $roomCode);

            $result = function_exists('google_calendar_update_via_gas')
                ? google_calendar_update_via_gas($cfg, [
                    'reservation_id' => $id,
                    'use_date' => $useDate,
                    'usage_time' => $usageTime,
                    'room_code' => $roomCode,
                    'room_label' => room_code_to_label($roomCode),
                    'organization_name' => $orgName,
                    'calendar_id' => $newCalendarId,
                    'event_id' => $previousGoogleEventId,
                    'previous_use_date' => $previousUseDate,
                    'previous_usage_time' => $previousUsageTime,
                    'previous_room_code' => $previousRoomCode,
                    'previous_room_label' => room_code_to_label($previousRoomCode),
                    'previous_organization_name' => $previousOrgName,
                    'previous_calendar_id' => $previousGoogleCalendarId,
                ])
                : google_calendar_create_via_gas($cfg, [
                    'reservation_id' => $id,
                    'use_date' => $useDate,
                    'usage_time' => $usageTime,
                    'room_code' => $roomCode,
                    'room_label' => room_code_to_label($roomCode),
                    'organization_name' => $orgName,
                    'calendar_id' => $newCalendarId,
                ]);

            update_calendar_google_sync_state($pdo, $table, $map, $id, $useDate, $roomCode, $orgName, [
                'google_event_id' => $result['event_id'] ?? $previousGoogleEventId,
                'google_calendar_id' => $result['calendar_id'] ?? $newCalendarId,
                'google_sync_status' => 'synced',
                'google_sync_error' => null,
            ]);
        }

        $linkedReservationId = max(0, (int)($current['reservation_id'] ?? 0));
        if ($linkedReservationId > 0) {
            update_reservation_application_status($pdo, $linkedReservationId, 'confirmed');
        }

        $pdo->commit();
        manage_write_audit($pdo, 'calendar.update', 'calendar_reservation', $id, [
            'before' => [
                'use_date' => (string)($current['use_date'] ?? ''),
                'room_code' => (string)($current['room_code'] ?? ''),
                'organization_name' => (string)($current['organization_name'] ?? ''),
                'usage_time' => (string)($current['usage_time'] ?? ''),
            ],
            'after' => [
                'use_date' => $useDate,
                'room_code' => $roomCode,
                'organization_name' => $orgName,
                'usage_time' => $usageTime,
            ],
            'google_synced' => $googleSyncEnabled,
        ]);
        json_response([
            'ok' => true,
            'message' => $googleSyncEnabled
                ? '確定予約を更新し、Googleカレンダーにも反映しました。'
                : '確定予約を更新しました。',
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function find_calendar_reservation_for_update(PDO $pdo, string $table, array $map, int $id, string $useDate, string $roomCode, string $orgName): ?array
{
    $select = sprintf(
        'SELECT %s AS id, %s AS use_date, %s AS room_code, %s AS organization_name, %s AS reservation_id, %s AS people_count, %s AS usage_time, %s AS google_event_id, %s AS google_calendar_id FROM %s',
        $map['id_select'],
        $map['date_column'],
        $map['room_column'],
        $map['org_column'],
        $map['reservation_id_select'],
        $map['people_count_select'],
        $map['usage_time_select'],
        $map['google_event_id_select'],
        $map['google_calendar_id_select'],
        $table
    );

    if ($id > 0 && $map['id_column'] !== null) {
        $stmt = $pdo->prepare($select . sprintf(' WHERE %s = :id FOR UPDATE', $map['id_column']));
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    if ($useDate === '' || $roomCode === '' || $orgName === '') {
        return null;
    }

    $sql = $select . sprintf(
        ' WHERE %s = :use_date AND %s = :room_code AND %s = :organization_name LIMIT 1 FOR UPDATE',
        $map['date_column'],
        $map['room_column'],
        $map['org_column']
    );
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':use_date' => $useDate,
        ':room_code' => $roomCode,
        ':organization_name' => $orgName,
    ]);
    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

function update_calendar_google_sync_state(PDO $pdo, string $table, array $map, ?int $id, string $useDate, string $roomCode, string $orgName, array $state): void
{
    $sets = [];
    $params = [];

    if ($map['google_event_id_column'] !== null) {
        $sets[] = $map['google_event_id_column'] . ' = :google_event_id';
        $params[':google_event_id'] = $state['google_event_id'] ?? null;
    }

    if ($map['google_calendar_id_column'] !== null) {
        $sets[] = $map['google_calendar_id_column'] . ' = :google_calendar_id';
        $params[':google_calendar_id'] = $state['google_calendar_id'] ?? null;
    }

    if ($map['google_sync_status_column'] !== null) {
        $sets[] = $map['google_sync_status_column'] . ' = :google_sync_status';
        $params[':google_sync_status'] = $state['google_sync_status'] ?? null;
    }

    if ($map['google_sync_error_column'] !== null) {
        $sets[] = $map['google_sync_error_column'] . ' = :google_sync_error';
        $params[':google_sync_error'] = $state['google_sync_error'] ?? null;
    }

    if ($map['google_synced_at_column'] !== null) {
        $sets[] = $map['google_synced_at_column'] . ' = NOW()';
    }

    if (!$sets) {
        return;
    }

    if ($id !== null && $id > 0 && $map['id_column'] !== null) {
        $whereSql = $map['id_column'] . ' = :target_id';
        $params[':target_id'] = $id;
    } else {
        $whereSql = sprintf(
            '%s = :use_date AND %s = :room_code AND %s = :organization_name',
            $map['date_column'],
            $map['room_column'],
            $map['org_column']
        );
        $params[':use_date'] = $useDate;
        $params[':room_code'] = $roomCode;
        $params[':organization_name'] = $orgName;
    }

    $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $sets), $whereSql);
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        if ($value === null) {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    $stmt->execute();
}

function room_code_to_label(string $roomCode): string
{
    return match ($roomCode) {
        'tamoku' => '多目的室',
        'orange' => 'オレンジの部屋',
        default => $roomCode,
    };
}


function handle_mail_form_options(PDO $pdo, array $cfg): void
{
    $reservations = fetch_calendar_mail_options($pdo);
    [$passcodes, $passcodeSourceNote] = fetch_passcode_mail_options($cfg);

    json_response([
        'ok' => true,
        'reservations' => $reservations,
        'passcodes' => $passcodes,
        'passcode_source_note' => $passcodeSourceNote,
        'message' => '予約通知メール用の候補を取得しました。',
    ]);
}

function handle_reservation_mail_send(PDO $pdo, array $cfg): void
{
    $input = get_request_payload();
    $to = trim((string)($input['to'] ?? ''));
    $reservationTokens = normalize_mail_selection_tokens($input['reservation_tokens'] ?? ($input['reservation_token'] ?? []));
    $passcodeTokens = normalize_mail_selection_tokens($input['passcode_tokens'] ?? ($input['passcode_token'] ?? []));

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        json_response(['ok' => false, 'message' => '宛先メールアドレスが不正です。'], 400);
    }
    if ($reservationTokens === []) {
        json_response(['ok' => false, 'message' => '確定済み予約を 1 件以上選択してください。'], 400);
    }
    if ($passcodeTokens === []) {
        json_response(['ok' => false, 'message' => '発行済みパスコードを 1 件以上選択してください。'], 400);
    }

    $reservations = [];
    foreach ($reservationTokens as $token) {
        $selector = decode_mail_selection_token($token, 'reservation');
        $row = find_calendar_reservation_for_mail($pdo, $selector);
        if (!is_array($row)) {
            json_response(['ok' => false, 'message' => '選択された確定済み予約の一部が見つかりませんでした。'], 404);
        }
        $rowId = (int)($row['id'] ?? 0);
        if ($rowId > 0) {
            $reservations[$rowId] = $row;
        } else {
            $reservations[] = $row;
        }
    }
    $reservations = array_values($reservations);

    $passcodes = [];
    foreach ($passcodeTokens as $token) {
        $selector = decode_mail_selection_token($token, 'passcode');
        $row = find_passcode_for_mail($cfg, $selector);
        if (!is_array($row)) {
            json_response(['ok' => false, 'message' => '選択された発行済みパスコードの一部が見つかりませんでした。'], 404);
        }
        $status = trim((string)($row['status'] ?? ''));
        if (in_array($status, ['error', 'api_error'], true)) {
            json_response(['ok' => false, 'message' => '失敗状態のパスコードは送信できません。'], 400);
        }
        $passcodeValue = trim((string)($row['passcode'] ?? ''));
        if ($passcodeValue === '') {
            json_response(['ok' => false, 'message' => '選択したパスコードの一部に通知用の値が保存されていません。'], 400);
        }
        $rowId = (int)($row['id'] ?? 0);
        if ($rowId > 0) {
            $passcodes[$rowId] = $row;
        } else {
            $passcodes[] = $row;
        }
    }
    $passcodes = array_values($passcodes);

    $organizationNames = array_values(array_unique(array_filter(array_map(
        static fn(array $row): string => trim((string)($row['organization_name'] ?? '')),
        $reservations
    ), static fn(string $v): bool => $v !== '')));
    if (count($organizationNames) > 1) {
        json_response(['ok' => false, 'message' => '複数団体の予約が混在しています。同一団体の予約のみ選択してください。'], 400);
    }

    $matchedItems = [];
    $missingLabels = [];
    foreach ($reservations as $reservation) {
        $matchedPasscode = find_best_passcode_for_reservation($passcodes, $reservation);
        if (!is_array($matchedPasscode)) {
            $missingLabels[] = sprintf(
                '%s / %s',
                trim((string)($reservation['use_date'] ?? '日付未設定')),
                room_code_to_label_for_mail(trim((string)($reservation['room_code'] ?? '')))
            );
            continue;
        }

        $reservationRoomCode = trim((string)($reservation['room_code'] ?? ''));
        $passcodeRoomCode = trim((string)($matchedPasscode['room_code'] ?? ''));
        $roomCode = $reservationRoomCode !== '' ? $reservationRoomCode : $passcodeRoomCode;
        $matchedItems[] = [
            'reservation' => $reservation,
            'passcode' => $matchedPasscode,
            'room_code' => $roomCode,
            'room_name' => room_code_to_label_for_mail($roomCode),
            'organization_name' => trim((string)($reservation['organization_name'] ?? '')),
            'use_date' => trim((string)($reservation['use_date'] ?? '')),
            'usage_time' => trim((string)($reservation['usage_time'] ?? '')),
            'people_count' => trim((string)($reservation['people_count'] ?? '')),
            'passcode_name' => trim((string)($matchedPasscode['passcode_name'] ?? '')),
            'passcode' => trim((string)($matchedPasscode['passcode'] ?? '')),
            'passcode_start_at' => trim((string)($matchedPasscode['start_at'] ?? '')),
            'passcode_end_at' => trim((string)($matchedPasscode['end_at'] ?? '')),
            'passcode_period' => build_passcode_period_label(
                trim((string)($matchedPasscode['start_at'] ?? '')),
                trim((string)($matchedPasscode['end_at'] ?? ''))
            ),
        ];
    }

    if ($missingLabels !== []) {
        json_response([
            'ok' => false,
            'message' => '対応するパスコードが見つからない予約があります: ' . implode(' / ', array_values(array_unique($missingLabels))),
        ], 400);
    }
    if ($matchedItems === []) {
        json_response(['ok' => false, 'message' => '送信対象の予約がありません。'], 400);
    }

    usort($matchedItems, static function (array $a, array $b): int {
        $dateCmp = strcmp((string)($a['use_date'] ?? ''), (string)($b['use_date'] ?? ''));
        if ($dateCmp !== 0) {
            return $dateCmp;
        }
        $roomCmp = strcmp((string)($a['room_code'] ?? ''), (string)($b['room_code'] ?? ''));
        if ($roomCmp !== 0) {
            return $roomCmp;
        }
        return strcmp((string)($a['usage_time'] ?? ''), (string)($b['usage_time'] ?? ''));
    });

    ensure_mailer_dependencies();

    $timezone = new DateTimeZone((string)($cfg['switchbot']['timezone'] ?? 'Asia/Tokyo'));
    $sentAt = (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s');

    $organizationName = $organizationNames[0] ?? trim((string)($matchedItems[0]['organization_name'] ?? ''));
    $roomCodes = array_values(array_unique(array_filter(array_map(static fn(array $item): string => trim((string)($item['room_code'] ?? '')), $matchedItems), static fn(string $v): bool => $v !== '')));
    $roomNames = array_values(array_unique(array_map(static fn(array $item): string => trim((string)($item['room_name'] ?? '')), $matchedItems)));
    $singleRoomName = count($roomNames) === 1 ? $roomNames[0] : '';

    $htmlBody = build_reservation_completion_bulk_html([
        'organization_name' => $organizationName,
        'room_name' => $singleRoomName,
        'items' => array_map(static function (array $item): array {
            return [
                'room_name' => (string)($item['room_name'] ?? ''),
                'use_date' => (string)($item['use_date'] ?? ''),
                'usage_time' => (string)($item['usage_time'] ?? ''),
                'people_count' => (string)($item['people_count'] ?? ''),
                'passcode_name' => (string)($item['passcode_name'] ?? ''),
                'passcode' => (string)($item['passcode'] ?? ''),
                'passcode_period' => (string)($item['passcode_period'] ?? ''),
            ];
        }, $matchedItems),
        'sent_at' => $sentAt,
    ]);

    if (count($matchedItems) === 1 && $singleRoomName !== '') {
        $subject = $singleRoomName . ' の予約確定のお知らせ';
    } elseif ($singleRoomName !== '') {
        $subject = $singleRoomName . ' の予約確定のお知らせ（複数日程）';
    } else {
        $subject = '予約確定のお知らせ（複数日程）';
    }

    $plainLines = [];
    $plainLines[] = ($organizationName !== '' ? $organizationName . ' 様の' : '') . '予約が確定しました。';
    $plainLines[] = '';
    $plainLines[] = '【予約内容】';
    foreach ($matchedItems as $index => $item) {
        $plainLines[] = sprintf('%d件目', $index + 1);
        $plainLines[] = '予約部屋: ' . ((string)($item['room_name'] ?? '') !== '' ? (string)$item['room_name'] : '—');
        $plainLines[] = '使用日: ' . ((string)($item['use_date'] ?? '') !== '' ? (string)$item['use_date'] : '—');
        $plainLines[] = '利用時間: ' . ((string)($item['usage_time'] ?? '') !== '' ? (string)$item['usage_time'] : '未登録');
        $plainLines[] = '人数: ' . ((string)($item['people_count'] ?? '') !== '' ? (string)$item['people_count'] . '人' : '未登録');
        $plainLines[] = 'パスワード名: ' . ((string)($item['passcode_name'] ?? '') !== '' ? (string)$item['passcode_name'] : '—');
        $plainLines[] = 'パスワード: ' . (string)($item['passcode'] ?? '');
        $plainLines[] = '有効期間: ' . ((string)($item['passcode_period'] ?? '') !== '' ? (string)$item['passcode_period'] : '—');
        $plainLines[] = '';
    }
    $plainLines[] = '送信日時: ' . $sentAt;
    $plainLines[] = '';
    $plainLines[] = '※ このメールは自動送信です。';
    $plainLines[] = '※ 入室用パスワードは第三者へ共有しないでください。';

    $historyRoomCode = count($roomCodes) === 1 ? $roomCodes[0] : 'multiple';
    $historyRoomLabel = count($roomNames) === 1 ? $roomNames[0] : '複数部屋';
    $historyUseDate = count($matchedItems) === 1 ? trim((string)($matchedItems[0]['use_date'] ?? '')) : '複数';
    $historyUsageTimeValues = array_values(array_unique(array_filter(array_map(static fn(array $item): string => trim((string)($item['usage_time'] ?? '')), $matchedItems), static fn(string $v): bool => $v !== '')));
    $historyPeopleValues = array_values(array_unique(array_filter(array_map(static fn(array $item): string => trim((string)($item['people_count'] ?? '')), $matchedItems), static fn(string $v): bool => $v !== '')));
    $selectedPasscodeIds = array_values(array_unique(array_filter(array_map(static fn(array $item): int => (int)(($item['passcode']['id'] ?? 0)), $matchedItems))));
    $selectedPasscodeNames = array_values(array_unique(array_filter(array_map(static fn(array $item): string => trim((string)($item['passcode_name'] ?? '')), $matchedItems), static fn(string $v): bool => $v !== '')));
    $selectedPasscodeValues = array_values(array_unique(array_filter(array_map(static fn(array $item): string => trim((string)($item['passcode'] ?? '')), $matchedItems), static fn(string $v): bool => $v !== '')));
    $selectedLocalRequestIds = array_values(array_unique(array_filter(array_map(static fn(array $item): string => trim((string)($item['passcode']['local_request_id'] ?? '')), $matchedItems), static fn(string $v): bool => $v !== '')));

    $historyPayload = [
        'to_email' => $to,
        'mail_subject' => $subject,
        'send_status' => 'sent',
        'error_message' => null,
        'reservation_calendar_id' => count($matchedItems) === 1 ? max(0, (int)($matchedItems[0]['reservation']['id'] ?? 0)) : null,
        'reservation_source_id' => count($matchedItems) === 1 ? max(0, (int)($matchedItems[0]['reservation']['reservation_id'] ?? 0)) : null,
        'room_code' => $historyRoomCode,
        'room_label' => $historyRoomLabel,
        'use_date' => $historyUseDate,
        'organization_name' => $organizationName !== '' ? $organizationName : (count($organizationNames) > 1 ? '複数団体' : ''),
        'people_count' => count($historyPeopleValues) === 1 ? (int)$historyPeopleValues[0] : null,
        'usage_time' => count($historyUsageTimeValues) === 1 ? $historyUsageTimeValues[0] : (count($matchedItems) > 1 ? '複数' : null),
        'passcode_request_id' => count($selectedPasscodeIds) === 1 ? $selectedPasscodeIds[0] : null,
        'passcode_local_request_id' => count($selectedLocalRequestIds) === 1 ? $selectedLocalRequestIds[0] : (count($selectedLocalRequestIds) > 1 ? 'multiple' : null),
        'passcode_name' => count($selectedPasscodeNames) === 1 ? $selectedPasscodeNames[0] : (count($selectedPasscodeNames) > 1 ? '複数' : null),
        'passcode' => count($selectedPasscodeValues) === 1 ? $selectedPasscodeValues[0] : (count($selectedPasscodeValues) > 1 ? '複数' : null),
        'passcode_start_at' => count($matchedItems) === 1 ? ((string)($matchedItems[0]['passcode_start_at'] ?? '') !== '' ? (string)$matchedItems[0]['passcode_start_at'] : null) : null,
        'passcode_end_at' => count($matchedItems) === 1 ? ((string)($matchedItems[0]['passcode_end_at'] ?? '') !== '' ? (string)$matchedItems[0]['passcode_end_at'] : null) : null,
        'sent_at' => $sentAt,
    ];

    try {
        send_mail_smtp($cfg, [
            'to' => $to,
            'subject' => $subject,
            'body' => implode("
", $plainLines),
            'html_body' => $htmlBody,
        ]);

        $historySaved = save_mail_send_history($pdo, $historyPayload);

        manage_write_audit($pdo, 'mail.send', 'reservation_mail', null, [
            'to_email' => $to,
            'reservation_count' => count($matchedItems),
            'room_codes' => $roomCodes,
            'use_dates' => array_map(static fn(array $item): string => (string)($item['use_date'] ?? ''), $matchedItems),
            'history_saved' => $historySaved,
        ]);

        json_response([
            'ok' => true,
            'message' => $historySaved
                ? '予約通知メールを一括送信し、送信履歴も保存しました。'
                : '予約通知メールを一括送信しました。送信履歴テーブルが未作成のため、履歴保存は行われていません。',
            'history_saved' => $historySaved,
            'matched_count' => count($matchedItems),
        ]);
    } catch (Throwable $e) {
        $historyPayload['send_status'] = 'failed';
        $historyPayload['error_message'] = trim((string)$e->getMessage()) !== '' ? trim((string)$e->getMessage()) : 'メール送信に失敗しました。';
        $historySaved = save_mail_send_history($pdo, $historyPayload);

        manage_write_audit($pdo, 'mail.send.failed', 'reservation_mail', null, [
            'to_email' => $to,
            'reservation_count' => count($matchedItems),
            'room_codes' => $roomCodes,
            'use_dates' => array_map(static fn(array $item): string => (string)($item['use_date'] ?? ''), $matchedItems),
            'error_message' => $historyPayload['error_message'],
            'history_saved' => $historySaved,
        ]);

        json_response([
            'ok' => false,
            'message' => $historyPayload['error_message'],
            'history_saved' => $historySaved,
        ], 500);
    }
}

function normalize_mail_selection_tokens(mixed $value): array
{
    if (is_string($value)) {
        $value = $value === '' ? [] : [$value];
    }
    if (!is_array($value)) {
        return [];
    }

    $tokens = [];
    foreach ($value as $item) {
        $token = trim((string)$item);
        if ($token !== '') {
            $tokens[] = $token;
        }
    }

    return array_values(array_unique($tokens));
}

function find_best_passcode_for_reservation(array $passcodes, array $reservation): ?array
{
    $candidates = [];
    foreach ($passcodes as $passcode) {
        if (passcode_matches_reservation($passcode, $reservation)) {
            $candidates[] = $passcode;
        }
    }
    if ($candidates === []) {
        return null;
    }

    usort($candidates, static function (array $a, array $b): int {
        $rangeA = passcode_date_span_days((string)($a['start_at'] ?? ''), (string)($a['end_at'] ?? ''));
        $rangeB = passcode_date_span_days((string)($b['start_at'] ?? ''), (string)($b['end_at'] ?? ''));
        if ($rangeA !== $rangeB) {
            return $rangeA <=> $rangeB;
        }
        $updatedCmp = strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
        if ($updatedCmp !== 0) {
            return $updatedCmp;
        }
        return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
    });

    return $candidates[0];
}

function passcode_matches_reservation(array $passcode, array $reservation): bool
{
    $reservationRoomCode = trim((string)($reservation['room_code'] ?? ''));
    $passcodeRoomCode = trim((string)($passcode['room_code'] ?? ''));
    if ($reservationRoomCode !== '' && $passcodeRoomCode !== '' && $reservationRoomCode !== $passcodeRoomCode) {
        return false;
    }

    $useDate = normalize_mail_date((string)($reservation['use_date'] ?? ''));
    if ($useDate === null) {
        return false;
    }

    $startDate = normalize_mail_date((string)($passcode['start_at'] ?? ''));
    $endDate = normalize_mail_date((string)($passcode['end_at'] ?? ''));
    if ($startDate === null || $endDate === null) {
        return false;
    }

    return $startDate <= $useDate && $useDate <= $endDate;
}

function normalize_mail_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches) === 1) {
        return $matches[1];
    }

    try {
        return (new DateTimeImmutable($value))->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

function passcode_date_span_days(string $startAt, string $endAt): int
{
    $start = normalize_mail_date($startAt);
    $end = normalize_mail_date($endAt);
    if ($start === null || $end === null) {
        return PHP_INT_MAX;
    }

    try {
        $startDt = new DateTimeImmutable($start . ' 00:00:00');
        $endDt = new DateTimeImmutable($end . ' 00:00:00');
        return (int)$startDt->diff($endDt)->format('%a');
    } catch (Throwable $e) {
        return PHP_INT_MAX;
    }
}

function handle_mail_history_list(PDO $pdo): void
{
    $limit = max(1, min(300, (int)($_GET['limit'] ?? 100)));
    $rows = fetch_mail_history_rows($pdo, $limit);
    $available = get_mail_history_table_name($pdo) !== null;

    json_response([
        'ok' => true,
        'rows' => $rows,
        'count' => count($rows),
        'available' => $available,
        'message' => $available
            ? '送信履歴を取得しました。'
            : '送信履歴テーブルが未作成のため、履歴はまだありません。SQL を適用してください。',
    ]);
}

function fetch_mail_history_rows(PDO $pdo, int $limit = 100): array
{
    $table = get_mail_history_table_name($pdo);
    if ($table === null) {
        return [];
    }

    $sql = sprintf(
        'SELECT id, to_email, mail_subject, send_status, error_message, reservation_calendar_id, reservation_source_id, room_code, room_label, use_date, organization_name, people_count, usage_time, passcode_request_id, passcode_local_request_id, passcode_name, passcode, passcode_start_at, passcode_end_at, sent_at
         FROM %s
         ORDER BY sent_at DESC, id DESC
         LIMIT :limit',
        $table
    );

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    return array_map(static function (array $row): array {
        $status = trim((string)($row['send_status'] ?? 'sent'));
        $row['send_status'] = $status;
        $row['send_status_label'] = mail_history_status_label($status);
        return $row;
    }, $rows);
}

function get_mail_history_table_name(PDO $pdo): ?string
{
    static $tableNameResolved = false;
    static $tableName = null;

    if ($tableNameResolved) {
        return $tableName;
    }

    $tableNameResolved = true;
    $candidate = 'reservation_mail_send_history';
    $stmt = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
    $stmt->execute([':table' => $candidate]);
    $found = $stmt->fetchColumn();
    if (is_string($found) && $found !== '') {
        $tableName = $candidate;
    }

    return $tableName;
}

function save_mail_send_history(PDO $pdo, array $row): bool
{
    $table = get_mail_history_table_name($pdo);
    if ($table === null) {
        return false;
    }

    $sql = sprintf(
        'INSERT INTO %s (
            to_email, mail_subject, send_status, error_message,
            reservation_calendar_id, reservation_source_id,
            room_code, room_label, use_date, organization_name, people_count, usage_time,
            passcode_request_id, passcode_local_request_id, passcode_name, passcode,
            passcode_start_at, passcode_end_at, sent_at
        ) VALUES (
            :to_email, :mail_subject, :send_status, :error_message,
            :reservation_calendar_id, :reservation_source_id,
            :room_code, :room_label, :use_date, :organization_name, :people_count, :usage_time,
            :passcode_request_id, :passcode_local_request_id, :passcode_name, :passcode,
            :passcode_start_at, :passcode_end_at, :sent_at
        )',
        $table
    );

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':to_email', trim((string)($row['to_email'] ?? '')), PDO::PARAM_STR);
    $stmt->bindValue(':mail_subject', trim((string)($row['mail_subject'] ?? '')), PDO::PARAM_STR);
    $stmt->bindValue(':send_status', trim((string)($row['send_status'] ?? 'sent')), PDO::PARAM_STR);
    bind_nullable_string($stmt, ':error_message', $row['error_message'] ?? null);
    bind_nullable_int($stmt, ':reservation_calendar_id', $row['reservation_calendar_id'] ?? null);
    bind_nullable_int($stmt, ':reservation_source_id', $row['reservation_source_id'] ?? null);
    bind_nullable_string($stmt, ':room_code', $row['room_code'] ?? null);
    bind_nullable_string($stmt, ':room_label', $row['room_label'] ?? null);
    bind_nullable_string($stmt, ':use_date', $row['use_date'] ?? null);
    bind_nullable_string($stmt, ':organization_name', $row['organization_name'] ?? null);
    bind_nullable_int($stmt, ':people_count', $row['people_count'] ?? null);
    bind_nullable_string($stmt, ':usage_time', $row['usage_time'] ?? null);
    bind_nullable_int($stmt, ':passcode_request_id', $row['passcode_request_id'] ?? null);
    bind_nullable_string($stmt, ':passcode_local_request_id', $row['passcode_local_request_id'] ?? null);
    bind_nullable_string($stmt, ':passcode_name', $row['passcode_name'] ?? null);
    bind_nullable_string($stmt, ':passcode', $row['passcode'] ?? null);
    bind_nullable_string($stmt, ':passcode_start_at', $row['passcode_start_at'] ?? null);
    bind_nullable_string($stmt, ':passcode_end_at', $row['passcode_end_at'] ?? null);
    bind_nullable_string($stmt, ':sent_at', $row['sent_at'] ?? null);
    $stmt->execute();

    return true;
}

function mail_history_status_label(string $status): string
{
    return match ($status) {
        'sent' => '送信成功',
        'failed' => '送信失敗',
        default => $status !== '' ? $status : '不明',
    };
}

function handle_export_csv(PDO $pdo): void
{
    $type = trim((string)($_GET['type'] ?? ''));
    switch ($type) {
        case 'applications':
            export_applications_csv($pdo);
            return;
        case 'calendar':
            export_calendar_csv($pdo);
            return;
        case 'mail_history':
            export_mail_history_csv($pdo);
            return;
        default:
            throw new RuntimeException('CSV 出力種別が不正です。');
    }
}

function export_applications_csv(PDO $pdo): void
{
    $q = trim((string)($_GET['q'] ?? ''));
    $room = trim((string)($_GET['room'] ?? ''));
    $status = normalize_application_status_input($_GET['status'] ?? '');
    $dateFrom = trim((string)($_GET['date_from'] ?? ''));
    $dateTo = trim((string)($_GET['date_to'] ?? ''));
    $statusColumn = get_reservation_status_column($pdo);

    $sortMap = [
        'id' => 'id',
        'created_at' => 'created_at',
        'email' => 'email',
        'room' => 'room',
        'original_name' => 'original_name',
        'stored_name' => 'stored_name',
        'note' => 'note',
    ];
    if ($statusColumn !== null) {
        $sortMap['application_status'] = $statusColumn;
    }
    $sortKey = (string)($_GET['sort'] ?? 'created_at');
    $sortColumn = $sortMap[$sortKey] ?? 'created_at';
    $dir = strtolower((string)($_GET['dir'] ?? 'desc'));
    $dir = $dir === 'asc' ? 'ASC' : 'DESC';

    $whereSql = build_where_sql($q, $room, $status, $dateFrom, $dateTo, $statusColumn, $params);
    $statusSelect = reservation_status_select_expr($statusColumn);

    $sql = <<<SQL
SELECT
    id,
    created_at,
    email,
    room,
    {$statusSelect} AS application_status,
    original_name,
    stored_name,
    file_path,
    note
FROM reservations
{$whereSql}
ORDER BY {$sortColumn} {$dir}, id DESC
SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $csvRows = [];
    foreach ($rows as $row) {
        $csvRows[] = [
            (string)($row['id'] ?? ''),
            (string)($row['created_at'] ?? ''),
            (string)($row['email'] ?? ''),
            (string)($row['room'] ?? ''),
            application_status_label_csv((string)($row['application_status'] ?? 'pending')),
            (string)($row['original_name'] ?? ''),
            (string)($row['stored_name'] ?? ''),
            (string)($row['file_path'] ?? ''),
            (string)($row['note'] ?? ''),
        ];
    }

    output_csv_download(
        'reservation_applications_' . date('Ymd_His') . '.csv',
        ['ID', '受付日時', 'メールアドレス', '予約部屋', '申請ステータス', '元のファイル名', '保存後ファイル名', '保存パス', '備考'],
        $csvRows
    );
}

function export_calendar_csv(PDO $pdo): void
{
    $month = trim((string)($_GET['month'] ?? ''));
    $roomFilter = trim((string)($_GET['room_code'] ?? ''));

    $table = get_calendar_table_name($pdo);
    $columns = get_table_columns($pdo, $table);
    $map = resolve_calendar_column_map($columns);

    $where = [];
    $params = [];

    if ($month !== '') {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new RuntimeException('month は YYYY-MM 形式で指定してください。');
        }
        $firstDay = DateTimeImmutable::createFromFormat('Y-m-d', $month . '-01');
        if (!$firstDay) {
            throw new RuntimeException('month が不正です。');
        }
        $nextMonth = $firstDay->modify('first day of next month');
        $where[] = sprintf('%s >= :from_date AND %s < :to_date', $map['date_column'], $map['date_column']);
        $params[':from_date'] = $firstDay->format('Y-m-d');
        $params[':to_date'] = $nextMonth->format('Y-m-d');
    }

    if ($roomFilter !== '') {
        $where[] = sprintf('%s = :room_code', $map['room_column']);
        $params[':room_code'] = $roomFilter;
    }

    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
    $sql = sprintf(
        'SELECT %s AS id, %s AS use_date, %s AS room_code, %s AS organization_name, %s AS people_count, %s AS usage_time, %s AS reservation_id, %s AS google_sync_status
         FROM %s%s
         ORDER BY %s ASC, %s ASC, %s ASC',
        $map['id_select'],
        $map['date_column'],
        $map['room_column'],
        $map['org_column'],
        $map['people_count_select'],
        $map['usage_time_select'],
        $map['reservation_id_select'],
        $map['google_sync_status_column'] !== null ? $map['google_sync_status_column'] : 'NULL',
        $table,
        $whereSql,
        $map['date_column'],
        $map['room_column'],
        $map['org_column']
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $csvRows = [];
    foreach ($rows as $row) {
        $csvRows[] = [
            (string)($row['id'] ?? ''),
            (string)($row['use_date'] ?? ''),
            room_code_to_label((string)($row['room_code'] ?? '')),
            (string)($row['room_code'] ?? ''),
            (string)($row['organization_name'] ?? ''),
            ($row['people_count'] ?? null) === null ? '' : (string)$row['people_count'],
            (string)($row['usage_time'] ?? ''),
            ($row['reservation_id'] ?? null) === null ? '' : (string)$row['reservation_id'],
            (string)($row['google_sync_status'] ?? ''),
        ];
    }

    output_csv_download(
        'confirmed_reservations_' . ($month !== '' ? str_replace('-', '', $month) . '_' : '') . date('Ymd_His') . '.csv',
        ['確定予約ID', '使用日', '部屋名', 'room_code', '団体名', '人数', '利用時間', '元申請ID', 'Google同期状態'],
        $csvRows
    );
}

function export_mail_history_csv(PDO $pdo): void
{
    $rows = fetch_mail_history_rows($pdo, 1000000);
    $csvRows = [];
    foreach ($rows as $row) {
        $csvRows[] = [
            (string)($row['id'] ?? ''),
            (string)($row['sent_at'] ?? ''),
            mail_history_status_label((string)($row['send_status'] ?? '')),
            (string)($row['to_email'] ?? ''),
            (string)($row['mail_subject'] ?? ''),
            (string)($row['room_label'] ?? ''),
            (string)($row['room_code'] ?? ''),
            (string)($row['use_date'] ?? ''),
            (string)($row['organization_name'] ?? ''),
            ($row['people_count'] ?? null) === null ? '' : (string)$row['people_count'],
            (string)($row['usage_time'] ?? ''),
            (string)($row['passcode_name'] ?? ''),
            (string)($row['passcode'] ?? ''),
            build_passcode_period_label((string)($row['passcode_start_at'] ?? ''), (string)($row['passcode_end_at'] ?? '')),
            ($row['reservation_calendar_id'] ?? null) === null ? '' : (string)$row['reservation_calendar_id'],
            ($row['reservation_source_id'] ?? null) === null ? '' : (string)$row['reservation_source_id'],
            ($row['passcode_request_id'] ?? null) === null ? '' : (string)$row['passcode_request_id'],
            (string)($row['passcode_local_request_id'] ?? ''),
            (string)($row['error_message'] ?? ''),
        ];
    }

    output_csv_download(
        'reservation_mail_history_' . date('Ymd_His') . '.csv',
        ['履歴ID', '送信日時', '送信状態', '宛先', '件名', '部屋名', 'room_code', '使用日', '団体名', '人数', '利用時間', 'パスワード名', 'パスワード', '有効期間', '確定予約ID', '元申請ID', 'パスコード要求ID', 'local_request_id', 'エラー内容'],
        $csvRows
    );
}

function output_csv_download(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Cache-Control: private, no-store, no-cache, must-revalidate');

    $fp = fopen('php://output', 'wb');
    if ($fp === false) {
        throw new RuntimeException('CSV 出力を開始できませんでした。');
    }

    fwrite($fp, "\xEF\xBB\xBF");
    fputcsv($fp, $headers);
    foreach ($rows as $row) {
        fputcsv($fp, array_map(static fn($v) => $v === null ? '' : (string)$v, $row));
    }
    fclose($fp);
    exit;
}

function application_status_label_csv(string $status): string
{
    return match ($status) {
        'pending' => '未確認',
        'reviewing' => '確認中',
        'confirmed' => '確定',
        'rejected' => '却下',
        default => $status !== '' ? $status : '未確認',
    };
}

function bind_nullable_string(PDOStatement $stmt, string $param, mixed $value): void
{
    $text = trim((string)($value ?? ''));
    if ($text === '') {
        $stmt->bindValue($param, null, PDO::PARAM_NULL);
        return;
    }
    $stmt->bindValue($param, $text, PDO::PARAM_STR);
}

function bind_nullable_int(PDOStatement $stmt, string $param, mixed $value): void
{
    if ($value === null || $value === '') {
        $stmt->bindValue($param, null, PDO::PARAM_NULL);
        return;
    }
    $stmt->bindValue($param, (int)$value, PDO::PARAM_INT);
}


function fetch_calendar_mail_options(PDO $pdo): array
{
    $table = get_calendar_table_name($pdo);
    $columns = get_table_columns($pdo, $table);
    $map = resolve_calendar_column_map($columns);

    $sql = sprintf(
        'SELECT %s AS id, %s AS use_date, %s AS room_code, %s AS organization_name, %s AS people_count, %s AS usage_time
         FROM %s
         ORDER BY CASE WHEN %s >= CURRENT_DATE() THEN 0 ELSE 1 END ASC, %s ASC, %s ASC, %s ASC
         LIMIT 300',
        $map['id_select'],
        $map['date_column'],
        $map['room_column'],
        $map['org_column'],
        $map['people_count_select'],
        $map['usage_time_select'],
        $table,
        $map['date_column'],
        $map['date_column'],
        $map['room_column'],
        $map['org_column']
    );

    $rows = $pdo->query($sql)->fetchAll();
    return array_map(static function (array $row): array {
        $payload = [
            'id' => (int)($row['id'] ?? 0),
            'use_date' => (string)($row['use_date'] ?? ''),
            'room_code' => (string)($row['room_code'] ?? ''),
            'organization_name' => (string)($row['organization_name'] ?? ''),
            'usage_time' => (string)($row['usage_time'] ?? ''),
        ];
        return [
            'id' => (int)($row['id'] ?? 0),
            'use_date' => (string)($row['use_date'] ?? ''),
            'room_code' => (string)($row['room_code'] ?? ''),
            'organization_name' => (string)($row['organization_name'] ?? ''),
            'people_count' => ($row['people_count'] ?? null) === null ? '' : trim((string)$row['people_count']),
            'usage_time' => (string)($row['usage_time'] ?? ''),
            'selection_token' => encode_mail_selection_token('reservation', $payload),
        ];
    }, $rows);
}

function fetch_passcode_mail_options(array $cfg): array
{
    try {
        $pdo = switchbot_db_connect($cfg);
        $table = switchbot_request_table_name($cfg);
        $stmt = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
        $stmt->execute([':table' => $table]);
        $found = $stmt->fetchColumn();
        if (!is_string($found) || $found === '') {
            return [[], 'SwitchBot パスコードテーブルが見つかりません。'];
        }

        $sql = "SELECT id, local_request_id, room_code, room_label, passcode_name, passcode, start_at, end_at, status, result, webhook_received_at, updated_at
                FROM `{$table}`
                WHERE passcode IS NOT NULL AND passcode <> '' AND status NOT IN ('error', 'api_error')
                ORDER BY updated_at DESC, id DESC
                LIMIT 300";
        $rows = $pdo->query($sql)->fetchAll();

        $items = array_map(static function (array $row): array {
            $payload = [
                'id' => (int)($row['id'] ?? 0),
                'local_request_id' => (string)($row['local_request_id'] ?? ''),
            ];
            $status = (string)($row['status'] ?? '');
            return [
                'id' => (int)($row['id'] ?? 0),
                'local_request_id' => (string)($row['local_request_id'] ?? ''),
                'room_code' => (string)($row['room_code'] ?? ''),
                'room_label' => (string)($row['room_label'] ?? ''),
                'passcode_name' => (string)($row['passcode_name'] ?? ''),
                'passcode' => (string)($row['passcode'] ?? ''),
                'start_at' => (string)($row['start_at'] ?? ''),
                'end_at' => (string)($row['end_at'] ?? ''),
                'status' => $status,
                'status_label' => passcode_status_label_for_mail($status, (string)($row['result'] ?? '')),
                'webhook_received_at' => (string)($row['webhook_received_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
                'selection_token' => encode_mail_selection_token('passcode', $payload),
            ];
        }, $rows);

        return [$items, 'SwitchBot パスコード DB を参照しています。'];
    } catch (Throwable $e) {
        return [[], 'SwitchBot パスコード候補を取得できませんでした。'];
    }
}

function encode_mail_selection_token(string $kind, array $payload): string
{
    $json = json_encode([
        'kind' => $kind,
        'payload' => $payload,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('選択トークンを生成できませんでした。');
    }
    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
}

function decode_mail_selection_token(string $token, string $expectedKind): array
{
    $padding = strlen($token) % 4;
    if ($padding > 0) {
        $token .= str_repeat('=', 4 - $padding);
    }
    $raw = base64_decode(strtr($token, '-_', '+/'), true);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('選択トークンの形式が不正です。');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || ($decoded['kind'] ?? '') !== $expectedKind || !is_array($decoded['payload'] ?? null)) {
        throw new RuntimeException('選択トークンの内容が不正です。');
    }
    return $decoded['payload'];
}

function find_calendar_reservation_for_mail(PDO $pdo, array $selector): ?array
{
    $table = get_calendar_table_name($pdo);
    $columns = get_table_columns($pdo, $table);
    $map = resolve_calendar_column_map($columns);

    $selectSql = sprintf(
        'SELECT %s AS id, %s AS use_date, %s AS room_code, %s AS organization_name, %s AS people_count, %s AS usage_time, %s AS reservation_id FROM %s',
        $map['id_select'],
        $map['date_column'],
        $map['room_column'],
        $map['org_column'],
        $map['people_count_select'],
        $map['usage_time_select'],
        $map['reservation_id_select'],
        $table
    );

    $id = (int)($selector['id'] ?? 0);
    if ($id > 0 && $map['id_column'] !== null) {
        $stmt = $pdo->prepare($selectSql . sprintf(' WHERE %s = :id LIMIT 1', $map['id_column']));
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    $useDate = trim((string)($selector['use_date'] ?? ''));
    $roomCode = trim((string)($selector['room_code'] ?? ''));
    $organizationName = trim((string)($selector['organization_name'] ?? ''));
    $usageTime = trim((string)($selector['usage_time'] ?? ''));

    if ($useDate === '' || $roomCode === '' || $organizationName === '') {
        return null;
    }

    $sql = $selectSql . sprintf(
        ' WHERE %s = :use_date AND %s = :room_code AND %s = :organization_name',
        $map['date_column'],
        $map['room_column'],
        $map['org_column']
    );
    $params = [
        ':use_date' => $useDate,
        ':room_code' => $roomCode,
        ':organization_name' => $organizationName,
    ];
    if ($usageTime !== '' && $map['usage_time_column'] !== null) {
        $sql .= sprintf(' AND %s = :usage_time', $map['usage_time_column']);
        $params[':usage_time'] = $usageTime;
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function find_passcode_for_mail(array $cfg, array $selector): ?array
{
    $pdo = switchbot_db_connect($cfg);
    $table = switchbot_request_table_name($cfg);

    $stmt = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
    $stmt->execute([':table' => $table]);
    $found = $stmt->fetchColumn();
    if (!is_string($found) || $found === '') {
        return null;
    }

    $id = (int)($selector['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    $localRequestId = trim((string)($selector['local_request_id'] ?? ''));
    if ($localRequestId === '') {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE local_request_id = :local_request_id LIMIT 1");
    $stmt->execute([':local_request_id' => $localRequestId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function ensure_mailer_dependencies(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $autoloadCandidates = [
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
    ];
    $autoloadFound = false;
    foreach ($autoloadCandidates as $path) {
        if (is_file($path)) {
            require_once $path;
            $autoloadFound = true;
            break;
        }
    }
    if (!$autoloadFound) {
        throw new RuntimeException('PHPMailer の autoload.php が見つかりません。vendor の配置を確認してください。');
    }

    $templateCandidates = [
        __DIR__ . '/../../apps/mail_html_templates.php',
        __DIR__ . '/../apps/mail_html_templates.php',
        __DIR__ . '/apps/mail_html_templates.php',
    ];
    $templateFound = false;
    foreach ($templateCandidates as $path) {
        if (is_file($path)) {
            require_once $path;
            $templateFound = true;
            break;
        }
    }
    if (!$templateFound) {
        throw new RuntimeException('mail_html_templates.php が見つかりません。');
    }

    $mailerCandidates = [
        __DIR__ . '/../../apps/smtp_mailer.php',
        __DIR__ . '/../apps/smtp_mailer.php',
        __DIR__ . '/apps/smtp_mailer.php',
    ];
    $mailerFound = false;
    foreach ($mailerCandidates as $path) {
        if (is_file($path)) {
            require_once $path;
            $mailerFound = true;
            break;
        }
    }
    if (!$mailerFound) {
        throw new RuntimeException('smtp_mailer.php が見つかりません。');
    }

    $loaded = true;
}

function room_code_to_label_for_mail(string $roomCode): string
{
    return match ($roomCode) {
        'tamoku' => '多目的室',
        'orange' => 'オレンジの部屋',
        default => $roomCode,
    };
}

function passcode_status_label_for_mail(string $status, string $result = ''): string
{
    return match ($status) {
        'success' => '成功',
        'accepted' => '受付済み',
        'queued' => '処理待ち',
        'error' => $result !== '' ? '失敗: ' . $result : '失敗',
        'api_error' => $result !== '' ? 'APIエラー: ' . $result : 'APIエラー',
        default => $status !== '' ? $status : '不明',
    };
}

function build_passcode_period_label(string $startAt, string $endAt): string
{
    if ($startAt !== '' && $endAt !== '') {
        return $startAt . ' 〜 ' . $endAt;
    }
    if ($startAt !== '') {
        return $startAt;
    }
    if ($endAt !== '') {
        return $endAt;
    }
    return '';
}


function get_reservation_status_column(PDO $pdo): ?string
{
    static $cacheInitialized = false;
    static $cache = null;
    if ($cacheInitialized) {
        return $cache;
    }

    $cacheInitialized = true;
    $columns = get_table_columns($pdo, 'reservations');
    $cache = first_existing_column($columns, ['application_status', 'status']);
    return $cache;
}

function reservation_status_select_expr(?string $statusColumn): string
{
    return $statusColumn !== null ? $statusColumn : "'pending'";
}

function normalize_application_status_input(mixed $value): string
{
    $status = trim((string)$value);
    if ($status === '') {
        return '';
    }
    if (!in_array($status, ['pending', 'reviewing', 'confirmed', 'rejected'], true)) {
        json_response(['ok' => false, 'message' => '申請ステータスが不正です。'], 400);
    }
    return $status;
}

function handle_application_status_update(PDO $pdo): void
{
    $input = get_request_payload();
    $id = max(0, (int)($input['id'] ?? 0));
    $status = normalize_application_status_input($input['application_status'] ?? '');

    if ($id <= 0) {
        json_response(['ok' => false, 'message' => '更新対象の id が不正です。'], 400);
    }
    if ($status === '') {
        json_response(['ok' => false, 'message' => '申請ステータスを選択してください。'], 400);
    }
    if (get_reservation_status_column($pdo) === null) {
        json_response(['ok' => false, 'message' => 'reservations テーブルに申請ステータス列がありません。SQL を適用してください。'], 400);
    }

    $pdo->beginTransaction();
    try {
        $row = find_reservation_for_update($pdo, $id);
        if ($row === null) {
            $pdo->rollBack();
            json_response(['ok' => false, 'message' => '対象申請が見つかりませんでした。'], 404);
        }
        update_reservation_application_status($pdo, $id, $status);
        $pdo->commit();

        manage_write_audit($pdo, 'application.status.update', 'reservation', $id, [
            'before' => (string)($row['application_status'] ?? ''),
            'after' => $status,
            'email' => (string)($row['email'] ?? ''),
            'room' => (string)($row['room'] ?? ''),
        ]);

        json_response([
            'ok' => true,
            'message' => '申請ステータスを更新しました。',
            'application_status' => $status,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function update_reservation_application_status(PDO $pdo, int $id, string $status): void
{
    $column = get_reservation_status_column($pdo);
    if ($column === null || $id <= 0) {
        return;
    }

    $stmt = $pdo->prepare(sprintf('UPDATE reservations SET %s = :status WHERE id = :id', $column));
    $stmt->execute([
        ':status' => $status,
        ':id' => $id,
    ]);
}

function calendar_reservation_exists_for_source(PDO $pdo, string $table, array $map, int $reservationId): bool
{
    if ($reservationId <= 0 || $map['reservation_id_column'] === null) {
        return false;
    }

    $stmt = $pdo->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE %s = :reservation_id', $table, $map['reservation_id_column']));
    $stmt->execute([':reservation_id' => $reservationId]);
    return (int)$stmt->fetchColumn() > 0;
}

function find_calendar_conflict(PDO $pdo, string $table, array $map, string $useDate, string $roomCode, ?string $usageTime, ?int $excludeId): ?array
{
    $sql = sprintf(
        'SELECT %s AS id, %s AS use_date, %s AS room_code, %s AS organization_name, %s AS usage_time FROM %s WHERE %s = :use_date AND %s = :room_code',
        $map['id_select'],
        $map['date_column'],
        $map['room_column'],
        $map['org_column'],
        $map['usage_time_select'],
        $table,
        $map['date_column'],
        $map['room_column']
    );
    if ($excludeId !== null && $excludeId > 0 && $map['id_column'] !== null) {
        $sql .= sprintf(' AND %s <> :exclude_id', $map['id_column']);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':use_date', $useDate, PDO::PARAM_STR);
    $stmt->bindValue(':room_code', $roomCode, PDO::PARAM_STR);
    if ($excludeId !== null && $excludeId > 0 && $map['id_column'] !== null) {
        $stmt->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
    }
    $stmt->execute();

    $candidate = usage_time_to_range($usageTime);
    foreach ($stmt->fetchAll() as $row) {
        $existing = usage_time_to_range($row['usage_time'] ?? null);
        if (calendar_ranges_overlap($candidate, $existing)) {
            return $row;
        }
    }

    return null;
}

function build_calendar_conflict_message(array $row): string
{
    $usageTime = trim((string)($row['usage_time'] ?? ''));
    $timeLabel = $usageTime !== '' ? $usageTime : '終日扱い';
    return trim((string)($row['use_date'] ?? '')) . ' の ' . room_code_to_label(trim((string)($row['room_code'] ?? ''))) . ' は既存予約（' . trim((string)($row['organization_name'] ?? '団体名未登録')) . ' / ' . $timeLabel . '）と重複しています。';
}

function usage_time_to_range(mixed $value): ?array
{
    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }
    if (!preg_match('/^(\d{2}:\d{2})~(\d{2}:\d{2})$/', $text, $matches)) {
        return null;
    }
    $start = time_text_to_minutes($matches[1]);
    $end = time_text_to_minutes($matches[2]);
    if ($start === null || $end === null || $end <= $start) {
        return null;
    }
    return [$start, $end];
}

function time_text_to_minutes(string $value): ?int
{
    if (!preg_match('/^(\d{2}):(\d{2})$/', $value, $matches)) {
        return null;
    }
    $hour = (int)$matches[1];
    $minute = (int)$matches[2];
    if ($hour < 0 || $hour > 23) {
        return null;
    }
    if (!in_array($minute, [0, 15, 30, 45], true)) {
        return null;
    }
    return $hour * 60 + $minute;
}

function calendar_ranges_overlap(?array $a, ?array $b): bool
{
    if ($a === null || $b === null) {
        return true;
    }
    return $a[0] < $b[1] && $b[0] < $a[1];
}

function build_where_sql(string $q, string $room, string $status, string $dateFrom, string $dateTo, ?string $statusColumn, ?array &$params): string
{
    $clauses = [];
    $params = [];

    if ($q !== '') {
        $like = '%' . $q . '%';
        $clauses[] = '('
            . 'email LIKE :q_email '
            . 'OR room LIKE :q_room '
            . 'OR original_name LIKE :q_original_name '
            . 'OR stored_name LIKE :q_stored_name '
            . 'OR file_path LIKE :q_file_path '
            . 'OR note LIKE :q_note'
            . ')';
        $params[':q_email'] = $like;
        $params[':q_room'] = $like;
        $params[':q_original_name'] = $like;
        $params[':q_stored_name'] = $like;
        $params[':q_file_path'] = $like;
        $params[':q_note'] = $like;
    }

    if ($room !== '') {
        $clauses[] = 'room = :room';
        $params[':room'] = $room;
    }

    if ($status !== '' && $statusColumn !== null) {
        $clauses[] = $statusColumn . ' = :application_status';
        $params[':application_status'] = $status;
    }

    if ($dateFrom !== '') {
        $fromDt = normalize_date_start($dateFrom);
        if ($fromDt !== null) {
            $clauses[] = 'created_at >= :date_from';
            $params[':date_from'] = $fromDt;
        }
    }

    if ($dateTo !== '') {
        $toNext = normalize_date_next_day($dateTo);
        if ($toNext !== null) {
            $clauses[] = 'created_at < :date_to_next';
            $params[':date_to_next'] = $toNext;
        }
    }

    return $clauses ? (' WHERE ' . implode(' AND ', $clauses)) : '';
}

function normalize_date_start(string $value): ?string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!$dt) {
        return null;
    }
    return $dt->format('Y-m-d 00:00:00');
}

function normalize_date_next_day(string $value): ?string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!$dt) {
        return null;
    }
    return $dt->modify('+1 day')->format('Y-m-d 00:00:00');
}

function find_reservation(PDO $pdo, int $id): ?array
{
    $statusSelect = reservation_status_select_expr(get_reservation_status_column($pdo));
    $stmt = $pdo->prepare(
        'SELECT id, email, room, ' . $statusSelect . ' AS application_status, note, original_name, stored_name, file_path, created_at FROM reservations WHERE id = :id'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

function find_reservation_for_update(PDO $pdo, int $id): ?array
{
    $statusSelect = reservation_status_select_expr(get_reservation_status_column($pdo));
    $stmt = $pdo->prepare(
        'SELECT id, email, room, ' . $statusSelect . ' AS application_status, note, original_name, stored_name, file_path, created_at FROM reservations WHERE id = :id FOR UPDATE'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

function get_request_payload(): array
{
    $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function get_calendar_table_name(PDO $pdo): string
{
    static $tableName = null;
    if (is_string($tableName) && $tableName !== '') {
        return $tableName;
    }

    $candidates = [
        'room_calendar_reservations',
        'room_calendar_reservation',
    ];

    $stmt = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
    foreach ($candidates as $candidate) {
        $stmt->execute([':table' => $candidate]);
        $found = $stmt->fetchColumn();
        if (is_string($found) && $found !== '') {
            $tableName = $candidate;
            return $tableName;
        }
    }

    throw new RuntimeException('カレンダー予約テーブルが見つかりません。room_calendar_reservations または room_calendar_reservation を確認してください。');
}

function get_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    $rows = $stmt->fetchAll();
    $columns = [];
    foreach ($rows as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') {
            $columns[] = $field;
        }
    }
    $cache[$table] = $columns;
    return $columns;
}

function resolve_calendar_column_map(array $columns): array
{
    $idColumn = first_existing_column($columns, ['id']);
    $dateColumn = first_existing_column($columns, ['use_date', 'reservation_date', 'date']);
    $roomColumn = first_existing_column($columns, ['room_code', 'room']);
    $orgColumn = first_existing_column($columns, ['organization_name', 'org_name', 'organization']);
    $reservationIdColumn = first_existing_column($columns, ['reservation_id']);
    $peopleCountColumn = first_existing_column($columns, ['people_count']);
    $usageTimeColumn = first_existing_column($columns, ['usage_time']);
    $googleEventIdColumn = first_existing_column($columns, ['google_event_id']);
    $googleCalendarIdColumn = first_existing_column($columns, ['google_calendar_id']);
    $googleSyncStatusColumn = first_existing_column($columns, ['google_sync_status']);
    $googleSyncedAtColumn = first_existing_column($columns, ['google_synced_at']);
    $googleSyncErrorColumn = first_existing_column($columns, ['google_sync_error']);

    if ($dateColumn === null || $roomColumn === null || $orgColumn === null) {
        throw new RuntimeException('カレンダー予約テーブルの列構成を特定できませんでした。');
    }

    return [
        'id_column' => $idColumn,
        'id_select' => $idColumn !== null ? $idColumn : 'NULL',
        'date_column' => $dateColumn,
        'room_column' => $roomColumn,
        'org_column' => $orgColumn,
        'reservation_id_column' => $reservationIdColumn,
        'reservation_id_select' => $reservationIdColumn !== null ? $reservationIdColumn : 'NULL',
        'people_count_column' => $peopleCountColumn,
        'people_count_select' => $peopleCountColumn !== null ? $peopleCountColumn : 'NULL',
        'usage_time_column' => $usageTimeColumn,
        'usage_time_select' => $usageTimeColumn !== null ? $usageTimeColumn : 'NULL',
        'google_event_id_column' => $googleEventIdColumn,
        'google_event_id_select' => $googleEventIdColumn !== null ? $googleEventIdColumn : 'NULL',
        'google_calendar_id_column' => $googleCalendarIdColumn,
        'google_calendar_id_select' => $googleCalendarIdColumn !== null ? $googleCalendarIdColumn : 'NULL',
        'google_sync_status_column' => $googleSyncStatusColumn,
        'google_synced_at_column' => $googleSyncedAtColumn,
        'google_sync_error_column' => $googleSyncErrorColumn,
    ];
}

function normalize_optional_people_count(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }

    if (is_string($value)) {
        $value = trim($value);
    }

    if ($value === '') {
        return null;
    }

    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
        json_response(['ok' => false, 'message' => '人数は整数で入力してください。'], 400);
    }

    $intValue = (int)$value;
    if ($intValue < 1 || $intValue > 9999) {
        json_response(['ok' => false, 'message' => '人数は 1〜9999 の範囲で入力してください。'], 400);
    }

    return $intValue;
}

function normalize_optional_usage_time(mixed $value): ?string
{
    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }

    if (mb_strlen($text, 'UTF-8') > 100) {
        json_response(['ok' => false, 'message' => '利用時間は100文字以内で入力してください。'], 400);
    }

    return $text;
}

function first_existing_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function resolve_storage_file(array $cfg, array $row): ?string
{
    $storedName = trim((string)($row['stored_name'] ?? ''));
    $filePath = trim((string)($row['file_path'] ?? ''));

    $candidates = [];
    $baseDir = get_reservation_storage_dir($cfg);

    if ($baseDir !== null && $storedName !== '') {
        $candidates[] = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($storedName);
    }

    if ($filePath !== '') {
        if (is_absolute_path($filePath)) {
            $candidates[] = $filePath;
        } else {
            $normalizedRelative = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath), DIRECTORY_SEPARATOR);
            foreach (get_project_root_candidates() as $root) {
                $candidates[] = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $normalizedRelative;
            }
        }
    }

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || $candidate === '') {
            continue;
        }

        if ($baseDir !== null) {
            $realBase = realpath($baseDir);
            $candidateDir = realpath(dirname($candidate));
            if ($realBase !== false && $candidateDir !== false) {
                if (strncmp($candidateDir, $realBase, strlen($realBase)) !== 0) {
                    continue;
                }
            }
        }

        if (is_file($candidate)) {
            return $candidate;
        }
    }

    if ($baseDir !== null && $storedName !== '') {
        return rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($storedName);
    }

    return null;
}

function get_reservation_storage_dir(array $cfg): ?string
{
    $fromConfig = trim((string)($cfg['upload']['reservation_dir'] ?? ''));
    if ($fromConfig !== '') {
        return $fromConfig;
    }

    $candidates = [
        __DIR__ . '/../storage/reservations',
        __DIR__ . '/../../storage/reservations',
        dirname(__DIR__) . '/storage/reservations',
    ];

    foreach ($candidates as $dir) {
        if (is_dir($dir)) {
            return $dir;
        }
    }

    return null;
}

function get_project_root_candidates(): array
{
    return [
        __DIR__,
        dirname(__DIR__),
        dirname(__DIR__, 2),
    ];
}

function is_absolute_path(string $path): bool
{
    if ($path === '') {
        return false;
    }

    return $path[0] === '/' || preg_match('/^[A-Za-z]:\\\/', $path) === 1;
}

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
