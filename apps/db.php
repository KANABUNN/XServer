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
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
}

/**
 * 予約情報と、保存済み添付ファイル情報を reservations テーブルへ記録する。
 * ファイル実体は config.php の upload 設定に従って保存する。
 */
function save_reservation_with_uploaded_file(PDO $pdo, array $cfg, array $mailData, $uploadedFile): void
{
    $email = trim((string)($mailData['reply_to'] ?? ''));
    $room  = trim((string)($mailData['roomName'] ?? ''));
    $note  = trim((string)($mailData['note'] ?? ''));

    if ($email === '' || $room === '') {
        throw new RuntimeException('DB保存に必要な予約情報が不足しています。');
    }

    if (!is_array($uploadedFile)) {
        throw new RuntimeException('アップロードファイル情報を取得できませんでした。');
    }

    $uploadError  = (int)($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE);
    $originalName = trim((string)($uploadedFile['name'] ?? ''));
    $tmpPath      = (string)($uploadedFile['tmp_name'] ?? '');
    $fileSize     = (int)($uploadedFile['size'] ?? 0);

    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException('保存対象ファイルの状態が不正です（error=' . $uploadError . '）。');
    }
    if ($originalName === '' || $tmpPath === '') {
        throw new RuntimeException('保存対象ファイルの情報が不足しています。');
    }
    if (!is_uploaded_file($tmpPath)) {
        throw new RuntimeException('アップロードされた一時ファイルを確認できませんでした。');
    }

    [$uploadDir, $pathPrefix] = reservation_upload_settings($cfg);

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('ファイル保存先フォルダを作成できませんでした。');
    }

    $storedName = reservation_stored_filename($originalName);
    $absolutePath = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $storedName;
    $relativePath = trim($pathPrefix, '/');
    $dbPath = ($relativePath === '') ? $storedName : ($relativePath . '/' . $storedName);

    $savedToDisk = false;

    try {
        $pdo->beginTransaction();

        if (!move_uploaded_file($tmpPath, $absolutePath)) {
            throw new RuntimeException('アップロードファイルを保存先へ移動できませんでした。');
        }
        $savedToDisk = true;

        $statusColumn = reservation_status_column($pdo);
        $insertColumns = ['email', 'room', 'note', 'original_name', 'stored_name', 'file_path'];
        $placeholders = [':email', ':room', ':note', ':original_name', ':stored_name', ':file_path'];
        $params = [
            ':email'         => $email,
            ':room'          => $room,
            ':note'          => $note !== '' ? $note : null,
            ':original_name' => reservation_trim_for_db($originalName, 255),
            ':stored_name'   => $storedName,
            ':file_path'     => reservation_trim_for_db($dbPath, 500),
        ];

        if ($statusColumn !== null) {
            $insertColumns[] = $statusColumn;
            $placeholders[] = ':application_status';
            $params[':application_status'] = 'pending';
        }

        $stmt = $pdo->prepare(
            'INSERT INTO reservations (' . implode(', ', $insertColumns) . ') '
            . 'VALUES (' . implode(', ', $placeholders) . ')'
        );

        $stmt->execute($params);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($savedToDisk && is_file($absolutePath)) {
            @unlink($absolutePath);
        }
        throw $e;
    }
}

function reservation_upload_settings(array $cfg): array
{
    $uploadCfg = $cfg['upload'] ?? [];
    if (!is_array($uploadCfg)) {
        $uploadCfg = [];
    }

    $defaultDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'reservations';
    $dir = trim((string)($uploadCfg['reservation_dir'] ?? $defaultDir));
    if ($dir === '') {
        throw new RuntimeException('upload.reservation_dir が未設定です。');
    }

    $pathPrefix = (string)($uploadCfg['reservation_path_prefix'] ?? 'storage/reservations');
    $pathPrefix = str_replace('\\', '/', $pathPrefix);

    return [$dir, $pathPrefix];
}

function reservation_stored_filename(string $originalName): string
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $suffix = $ext !== '' ? '.' . $ext : '';

    return date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . $suffix;
}

function reservation_trim_for_db(string $value, int $maxLength): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }

    return substr($value, 0, $maxLength);
}


function reservation_status_column(PDO $pdo): ?string
{
    static $cacheInitialized = false;
    static $cache = null;
    if ($cacheInitialized) {
        return $cache;
    }

    $cacheInitialized = true;
    $stmt = $pdo->query('SHOW COLUMNS FROM `reservations`');
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $names = [];
    foreach ($columns as $column) {
        $field = (string)($column['Field'] ?? '');
        if ($field !== '') {
            $names[] = $field;
        }
    }

    foreach (['application_status', 'status'] as $candidate) {
        if (in_array($candidate, $names, true)) {
            $cache = $candidate;
            return $cache;
        }
    }

    return null;
}
