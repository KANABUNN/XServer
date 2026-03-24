<?php

declare(strict_types=1);

foreach ([__DIR__ . '/../../apps/switchbot_api.php', __DIR__ . '/../apps/switchbot_api.php', __DIR__ . '/apps/switchbot_api.php'] as $__switchbotHelper) {
    if (is_file($__switchbotHelper)) {
        require_once $__switchbotHelper;
        break;
    }
}


try {
    $cfg = load_config();
    load_google_calendar_sync_helpers();
    $action = (string)($_REQUEST['action'] ?? 'list');

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

        case 'mail_form_options':
            $pdo = db_connect($cfg);
            handle_mail_form_options($pdo, $cfg);
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

        default:
            json_response(['ok' => false, 'message' => '不正な action です。'], 400);
    }
} catch (Throwable $e) {
    error_log('[manage_reservations] ' . $e->getMessage());
    error_log('[manage_reservations] ' . $e->getFile() . ':' . $e->getLine());

    $actionForError = (string)($_REQUEST['action'] ?? '');
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

function db_connect(array $cfg): PDO
{
    if (!isset($cfg['db']) || !is_array($cfg['db'])) {
        throw new RuntimeException('config.php に db 設定がありません。');
    }

    $dsn = trim((string)($cfg['db']['dsn'] ?? ''));
    $user = (string)($cfg['db']['user'] ?? '');
    $password = (string)($cfg['db']['password'] ?? '');

    if ($dsn === '') {
        throw new RuntimeException('config.php の db.dsn が未設定です。');
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

function handle_list(PDO $pdo): void
{
    $q = trim((string)($_GET['q'] ?? ''));
    $room = trim((string)($_GET['room'] ?? ''));
    $dateFrom = trim((string)($_GET['date_from'] ?? ''));
    $dateTo = trim((string)($_GET['date_to'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(200, (int)($_GET['per_page'] ?? 50)));

    $sortMap = [
        'id' => 'id',
        'created_at' => 'created_at',
        'email' => 'email',
        'room' => 'room',
        'original_name' => 'original_name',
        'stored_name' => 'stored_name',
        'note' => 'note',
    ];
    $sortKey = (string)($_GET['sort'] ?? 'created_at');
    $sortColumn = $sortMap[$sortKey] ?? 'created_at';

    $dir = strtolower((string)($_GET['dir'] ?? 'desc'));
    $dir = $dir === 'asc' ? 'ASC' : 'DESC';

    $whereSql = build_where_sql($q, $room, $dateFrom, $dateTo, $params);

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

    if ($map['people_count_column'] !== null) {
        $insertColumns[] = $map['people_count_column'];
        $placeholders[] = ':people_count';
    }

    if ($map['usage_time_column'] !== null) {
        $insertColumns[] = $map['usage_time_column'];
        $placeholders[] = ':usage_time';
    }

    $googleSyncEnabled = function_exists('google_calendar_sync_enabled') && google_calendar_sync_enabled($cfg);
    $googleResult = null;

    try {
        $pdo->beginTransaction();

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
                'message' => 'その日付のその部屋は既に登録されています。',
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

        $pdo->commit();

        $message = ($googleSyncEnabled && $googleDeleted)
            ? '確定予約を削除し、Googleカレンダーからも削除しました。'
            : '確定予約を削除しました。';

        json_response([
            'ok' => true,
            'message' => $message,
            'google_deleted' => $googleDeleted,
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
        'SELECT %s AS id, %s AS use_date, %s AS room_code, %s AS organization_name, %s AS people_count, %s AS usage_time, %s AS google_event_id, %s AS google_calendar_id FROM %s',
        $map['id_select'],
        $map['date_column'],
        $map['room_column'],
        $map['org_column'],
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
    $reservationToken = trim((string)($input['reservation_token'] ?? ''));
    $passcodeToken = trim((string)($input['passcode_token'] ?? ''));

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        json_response(['ok' => false, 'message' => '宛先メールアドレスが不正です。'], 400);
    }
    if ($reservationToken === '') {
        json_response(['ok' => false, 'message' => '確定済み予約を選択してください。'], 400);
    }
    if ($passcodeToken === '') {
        json_response(['ok' => false, 'message' => '発行済みパスコードを選択してください。'], 400);
    }

    $reservationSelector = decode_mail_selection_token($reservationToken, 'reservation');
    $passcodeSelector = decode_mail_selection_token($passcodeToken, 'passcode');

    $reservation = find_calendar_reservation_for_mail($pdo, $reservationSelector);
    if (!is_array($reservation)) {
        json_response(['ok' => false, 'message' => '選択された確定済み予約が見つかりませんでした。'], 404);
    }

    $passcode = find_passcode_for_mail($cfg, $passcodeSelector);
    if (!is_array($passcode)) {
        json_response(['ok' => false, 'message' => '選択された発行済みパスコードが見つかりませんでした。'], 404);
    }

    $reservationRoomCode = trim((string)($reservation['room_code'] ?? ''));
    $passcodeRoomCode = trim((string)($passcode['room_code'] ?? ''));
    if ($reservationRoomCode !== '' && $passcodeRoomCode !== '' && $reservationRoomCode !== $passcodeRoomCode) {
        json_response(['ok' => false, 'message' => '選択した予約とパスコードの部屋が一致していません。'], 400);
    }

    $status = trim((string)($passcode['status'] ?? ''));
    if (in_array($status, ['error', 'api_error'], true)) {
        json_response(['ok' => false, 'message' => '失敗状態のパスコードは送信できません。'], 400);
    }

    ensure_mailer_dependencies();

    $roomName = room_code_to_label_for_mail($reservationRoomCode ?: $passcodeRoomCode);
    $organizationName = trim((string)($reservation['organization_name'] ?? ''));
    $useDate = trim((string)($reservation['use_date'] ?? ''));
    $usageTime = trim((string)($reservation['usage_time'] ?? ''));
    $peopleCount = trim((string)($reservation['people_count'] ?? ''));
    $passcodeValue = trim((string)($passcode['passcode'] ?? ''));
    $passcodeStartAt = trim((string)($passcode['start_at'] ?? ''));
    $passcodeEndAt = trim((string)($passcode['end_at'] ?? ''));
    $passcodePeriod = build_passcode_period_label($passcodeStartAt, $passcodeEndAt);
    $sentAt = (new DateTimeImmutable('now', new DateTimeZone((string)($cfg['switchbot']['timezone'] ?? 'Asia/Tokyo'))))->format('Y-m-d H:i:s');

    if ($passcodeValue === '') {
        json_response(['ok' => false, 'message' => '選択したパスコードに通知用の値が保存されていません。'], 400);
    }

    $htmlBody = build_reservation_completion_html([
        'room_name' => $roomName,
        'organization_name' => $organizationName,
        'use_date' => $useDate,
        'usage_time' => $usageTime,
        'people_count' => $peopleCount,
        'passcode' => $passcodeValue,
        'passcode_period' => $passcodePeriod,
        'sent_at' => $sentAt,
    ]);

    $plainLines = [
        $roomName . ' の予約が確定しました。',
        '',
        '【予約内容】',
        '予約部屋: ' . ($roomName !== '' ? $roomName : '—'),
        '使用日: ' . ($useDate !== '' ? $useDate : '—'),
        '利用時間: ' . ($usageTime !== '' ? $usageTime : '未登録'),
        '団体名: ' . ($organizationName !== '' ? $organizationName : '—'),
        '人数: ' . ($peopleCount !== '' ? $peopleCount . '人' : '未登録'),
        '',
        '【入室用パスワード】',
        'パスワード: ' . $passcodeValue,
        '有効期間: ' . ($passcodePeriod !== '' ? $passcodePeriod : '—'),
        '',
        '送信日時: ' . $sentAt,
        '',
        '※ このメールは自動送信です。',
        '※ 入室用パスワードは第三者へ共有しないでください。',
    ];

    send_mail_smtp($cfg, [
        'to' => $to,
        'subject' => ($roomName !== '' ? $roomName . ' の予約確定のお知らせ' : '予約確定のお知らせ'),
        'body' => implode("\r\n", $plainLines),
        'html_body' => $htmlBody,
    ]);

    json_response([
        'ok' => true,
        'message' => '予約通知メールを送信しました。',
    ]);
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
        'SELECT %s AS id, %s AS use_date, %s AS room_code, %s AS organization_name, %s AS people_count, %s AS usage_time FROM %s',
        $map['id_select'],
        $map['date_column'],
        $map['room_column'],
        $map['org_column'],
        $map['people_count_select'],
        $map['usage_time_select'],
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

function build_where_sql(string $q, string $room, string $dateFrom, string $dateTo, ?array &$params): string
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
    $stmt = $pdo->prepare(
        'SELECT id, email, room, note, original_name, stored_name, file_path, created_at FROM reservations WHERE id = :id'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

function find_reservation_for_update(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, email, room, note, original_name, stored_name, file_path, created_at FROM reservations WHERE id = :id FOR UPDATE'
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
