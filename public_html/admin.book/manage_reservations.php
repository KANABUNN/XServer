<?php

declare(strict_types=1);

try {
    $cfg = load_config();
    load_google_calendar_sync_helpers();
    $pdo = db_connect($cfg);

    $action = (string)($_REQUEST['action'] ?? 'list');

    switch ($action) {
        case 'list':
            handle_list($pdo);
            break;

        case 'detail':
            handle_detail($pdo, $cfg);
            break;

        case 'rooms':
            handle_rooms($pdo);
            break;

        case 'download':
            handle_download($pdo, $cfg);
            break;

        case 'delete':
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => '削除は POST で呼び出してください。'], 405);
            }
            handle_delete($pdo, $cfg);
            break;

        case 'calendar_list':
            handle_calendar_list($pdo);
            break;

        case 'calendar_add':
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => '登録は POST で呼び出してください。'], 405);
            }
            handle_calendar_add($pdo, $cfg);
            break;

        case 'calendar_delete':
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => '削除は POST で呼び出してください。'], 405);
            }
            handle_calendar_delete($pdo, $cfg);
            break;

        default:
            json_response(['ok' => false, 'message' => '不正な action です。'], 400);
    }
} catch (Throwable $e) {
    error_log('[manage_reservations] ' . $e->getMessage());
    error_log('[manage_reservations] ' . $e->getFile() . ':' . $e->getLine());

    if (!headers_sent()) {
        json_response([
            'ok' => false,
            'message' => '管理処理でエラーが発生しました。ログを確認してください。',
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
