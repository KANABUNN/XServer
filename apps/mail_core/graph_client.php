<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/upload_service.php';

const MAIL_GRAPH_SMALL_ATTACHMENT_LIMIT = 3_000_000;
const MAIL_GRAPH_LARGE_ATTACHMENT_LIMIT = 150_000_000;
const MAIL_GRAPH_UPLOAD_CHUNK_SIZE = 3_276_800; // 10 * 320 KiB. Microsoft Graph upload sessions require ordered byte ranges.

function mail_graph_config(): array
{
    $config = mail_load_config();
    $graph = $config['graph'] ?? [];
    return is_array($graph) ? $graph : [];
}

function mail_graph_auth_mode(): string
{
    $mode = strtolower(trim((string)(mail_graph_config()['auth_mode'] ?? 'client_credentials')));
    return in_array($mode, ['client_credentials'], true) ? $mode : 'client_credentials';
}

function mail_graph_api_base(): string
{
    $base = trim((string)(mail_graph_config()['api_base'] ?? 'https://graph.microsoft.com/v1.0'));
    return rtrim($base !== '' ? $base : 'https://graph.microsoft.com/v1.0', '/');
}

function mail_graph_enabled(): bool
{
    $graph = mail_graph_config();
    return !empty($graph['enabled']);
}

function mail_graph_send_enabled(): bool
{
    $graph = mail_graph_config();
    return mail_graph_enabled() && !empty($graph['allow_send_from_ui']);
}

function mail_graph_draft_only_default(): bool
{
    $graph = mail_graph_config();
    return !empty($graph['draft_only_default']);
}

function mail_graph_sender_user_id(): string
{
    $sender = trim((string)(mail_graph_config()['sender_user_id'] ?? ''));
    if ($sender === '') {
        throw new RuntimeException('Graph設定 sender_user_id が未設定です。');
    }
    return $sender;
}

function mail_graph_is_configured(): array
{
    $graph = mail_graph_config();
    $required = ['tenant_id', 'client_id', 'client_secret', 'sender_user_id'];
    $missing = [];
    foreach ($required as $key) {
        if (trim((string)($graph[$key] ?? '')) === '') {
            $missing[] = $key;
        }
    }
    return [mail_graph_enabled() && $missing === [], $missing];
}

function mail_graph_token_cache_file(): string
{
    $config = mail_load_config();
    $storage = $config['storage'] ?? [];
    $tmpDir = is_array($storage) ? trim((string)($storage['tmp_dir'] ?? '')) : '';
    if ($tmpDir === '') {
        $tmpDir = dirname(__DIR__) . '/storage/mail_tmp';
    }
    mail_ensure_dir($tmpDir);
    return rtrim($tmpDir, '/\\') . '/graph_client_credentials_token.json';
}

function mail_graph_encode_user_segment(string $userId): string
{
    return rawurlencode($userId);
}

function mail_graph_user_message_path(?string $messageId = null): string
{
    $path = '/users/' . mail_graph_encode_user_segment(mail_graph_sender_user_id()) . '/messages';
    if ($messageId !== null && $messageId !== '') {
        $path .= '/' . rawurlencode($messageId);
    }
    return $path;
}

function mail_graph_url(string $path): string
{
    if (str_starts_with($path, 'https://')) {
        return $path;
    }
    return mail_graph_api_base() . '/' . ltrim($path, '/');
}

function mail_graph_curl_request(string $method, string $url, array|string|null $body = null, array $headers = [], int $timeout = 60): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL 拡張が有効ではありません。XServerのPHP設定を確認してください。');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('cURL初期化に失敗しました。');
    }

    $responseHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
            $length = strlen($headerLine);
            $headerLine = trim($headerLine);
            if ($headerLine !== '' && str_contains($headerLine, ':')) {
                [$name, $value] = explode(':', $headerLine, 2);
                $responseHeaders[strtolower(trim($name))][] = trim($value);
            }
            return $length;
        },
    ]);

    if ($body !== null) {
        if (is_array($body)) {
            $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    if ($headers !== []) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new RuntimeException('Graph通信エラー: ' . $error);
    }

    $decoded = null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
    }

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'raw' => is_string($raw) ? $raw : '',
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

function mail_graph_get_access_token(bool $forceRefresh = false): string
{
    [$configured, $missing] = mail_graph_is_configured();
    if (!$configured) {
        throw new RuntimeException('Graph設定が不足しています: ' . implode(', ', $missing));
    }

    $cacheFile = mail_graph_token_cache_file();
    if (!$forceRefresh && is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['access_token'], $cached['expires_at']) && (int)$cached['expires_at'] > time() + 120) {
            return (string)$cached['access_token'];
        }
    }

    $graph = mail_graph_config();
    $tenantId = trim((string)$graph['tenant_id']);
    $url = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';
    $form = http_build_query([
        'client_id' => (string)$graph['client_id'],
        'client_secret' => (string)$graph['client_secret'],
        'scope' => 'https://graph.microsoft.com/.default',
        'grant_type' => 'client_credentials',
    ], '', '&', PHP_QUERY_RFC3986);

    $response = mail_graph_curl_request('POST', $url, $form, ['Content-Type: application/x-www-form-urlencoded'], 60);
    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json']) || empty($response['json']['access_token'])) {
        $message = $response['json']['error_description'] ?? $response['json']['error'] ?? $response['raw'] ?? 'unknown error';
        throw new RuntimeException('Graphアクセストークンの取得に失敗しました: HTTP ' . $response['status'] . ' / ' . $message);
    }

    $expiresIn = (int)($response['json']['expires_in'] ?? 3600);
    $token = (string)$response['json']['access_token'];
    $cache = [
        'access_token' => $token,
        'expires_at' => time() + max(300, $expiresIn),
        'cached_at' => time(),
    ];
    @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_SLASHES));
    @chmod($cacheFile, 0600);

    return $token;
}

function mail_graph_request(string $method, string $path, array|string|null $body = null, array $headers = [], int $timeout = 60): array
{
    $token = mail_graph_get_access_token();
    $headers[] = 'Authorization: Bearer ' . $token;
    $headers[] = 'Accept: application/json';
    $response = mail_graph_curl_request($method, mail_graph_url($path), $body, $headers, $timeout);

    if ($response['status'] === 401) {
        $token = mail_graph_get_access_token(true);
        $headers = array_values(array_filter($headers, static fn (string $h): bool => !str_starts_with(strtolower($h), 'authorization:')));
        $headers[] = 'Authorization: Bearer ' . $token;
        $response = mail_graph_curl_request($method, mail_graph_url($path), $body, $headers, $timeout);
    }

    return $response;
}

function mail_graph_assert_success(array $response, string $operation, array $allowedStatuses = [200, 201, 202, 204]): void
{
    if (in_array((int)$response['status'], $allowedStatuses, true)) {
        return;
    }
    $json = is_array($response['json']) ? $response['json'] : [];
    $error = $json['error']['message'] ?? $json['error_description'] ?? $json['error'] ?? $response['raw'] ?? 'unknown error';
    throw new RuntimeException($operation . ' に失敗しました: HTTP ' . (int)$response['status'] . ' / ' . (string)$error);
}

function mail_graph_test_connection(): array
{
    $sender = mail_graph_sender_user_id();
    $response = mail_graph_request('GET', '/users/' . mail_graph_encode_user_segment($sender) . '?$select=id,displayName,mail,userPrincipalName');
    mail_graph_assert_success($response, 'Graph接続確認');
    return is_array($response['json']) ? $response['json'] : [];
}

function mail_graph_get_target(PDO $pdo, int $targetId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT bt.*, b.status AS batch_status, b.title AS batch_title, b.id AS batch_id, b.template_id, ' .
        't.body_type, o.identifier, o.name AS organization_name, o.representative_name, o.category ' .
        'FROM mail_batch_targets bt ' .
        'INNER JOIN mail_batches b ON b.id = bt.batch_id ' .
        'LEFT JOIN mail_templates t ON t.id = b.template_id ' .
        'LEFT JOIN mail_organizations o ON o.id = bt.organization_id ' .
        'WHERE bt.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $targetId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function mail_graph_pending_attachment_count(PDO $pdo, int $batchId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM mail_attachments WHERE mail_batch_id = :batch_id AND status NOT IN ('approved','excluded','used')"
    );
    $stmt->execute([':batch_id' => $batchId]);
    return (int)$stmt->fetchColumn();
}

function mail_graph_list_target_attachments(PDO $pdo, int $batchId, ?int $organizationId): array
{
    $stmt = $pdo->prepare(
        'SELECT a.*, f.original_name, f.relative_path, f.file_size, f.mime_type ' .
        'FROM mail_attachments a INNER JOIN mail_uploaded_files f ON f.id = a.uploaded_file_id ' .
        'WHERE a.mail_batch_id = :batch_id AND a.status = "approved" ' .
        'AND (a.is_common = 1 OR (:organization_id IS NOT NULL AND a.organization_id = :organization_id)) ' .
        'ORDER BY a.is_common DESC, f.original_name ASC'
    );
    $stmt->execute([':batch_id' => $batchId, ':organization_id' => $organizationId]);
    return $stmt->fetchAll() ?: [];
}

function mail_graph_attachment_absolute_path(array $attachment): string
{
    $relative = str_replace(['..', '\\'], ['', '/'], (string)($attachment['relative_path'] ?? ''));
    $path = rtrim(mail_storage_root(), '/\\') . '/' . ltrim($relative, '/');
    if (!is_file($path)) {
        throw new RuntimeException('添付ファイルが見つかりません: ' . (string)($attachment['original_name'] ?? $relative));
    }
    return $path;
}

function mail_graph_insert_send_log(PDO $pdo, ?int $batchId, ?int $targetId, string $action, string $result, ?int $responseCode, ?string $responseBody, ?string $errorMessage, ?array $actor): void
{
    if (!mail_table_exists($pdo, 'mail_send_logs')) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO mail_send_logs (batch_id, batch_target_id, action, result, response_code, response_body, error_message, created_by_account_id) ' .
        'VALUES (:batch_id, :target_id, :action, :result, :response_code, :response_body, :error_message, :created_by)'
    );
    $stmt->execute([
        ':batch_id' => $batchId,
        ':target_id' => $targetId,
        ':action' => $action,
        ':result' => $result,
        ':response_code' => $responseCode,
        ':response_body' => $responseBody !== null ? mb_substr($responseBody, 0, 8000) : null,
        ':error_message' => $errorMessage !== null ? mb_substr($errorMessage, 0, 1000) : null,
        ':created_by' => $actor !== null ? (int)($actor['id'] ?? 0) : null,
    ]);
}

function mail_graph_mark_target_failed(PDO $pdo, int $targetId, string $message, ?string $messageId = null): void
{
    if ($messageId !== null && $messageId !== '') {
        $stmt = $pdo->prepare('UPDATE mail_batch_targets SET status = "failed", graph_message_id = :graph_message_id, error_message = :error_message WHERE id = :id');
        $stmt->execute([':graph_message_id' => $messageId, ':error_message' => mb_substr($message, 0, 1000), ':id' => $targetId]);
        return;
    }
    $stmt = $pdo->prepare('UPDATE mail_batch_targets SET status = "failed", error_message = :error_message WHERE id = :id');
    $stmt->execute([':error_message' => mb_substr($message, 0, 1000), ':id' => $targetId]);
}

function mail_graph_mark_target_draft_created(PDO $pdo, int $targetId, string $messageId): void
{
    $stmt = $pdo->prepare('UPDATE mail_batch_targets SET status = "draft_created", graph_message_id = :graph_message_id, error_message = NULL WHERE id = :id');
    $stmt->execute([':graph_message_id' => $messageId, ':id' => $targetId]);
}

function mail_graph_create_message_payload(array $target): array
{
    $subject = trim((string)($target['rendered_subject'] ?? ''));
    $body = (string)($target['rendered_body'] ?? '');
    $toEmail = mail_normalize_email((string)($target['to_email'] ?? ''));
    if ($subject === '') {
        throw new InvalidArgumentException('件名が空です。');
    }
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('宛先メールアドレスが不正です。');
    }
    if (preg_match('/{{\s*[^}]+\s*}}/u', $subject . "\n" . $body) === 1) {
        throw new InvalidArgumentException('未置換の変数が残っています。');
    }

    $contentType = strtolower((string)($target['body_type'] ?? 'plain')) === 'html' ? 'HTML' : 'Text';
    return [
        'subject' => $subject,
        'body' => [
            'contentType' => $contentType,
            'content' => $body,
        ],
        'toRecipients' => [[
            'emailAddress' => ['address' => $toEmail],
        ]],
    ];
}

function mail_graph_attach_small_file(string $messageId, array $attachment): array
{
    $path = mail_graph_attachment_absolute_path($attachment);
    $bytes = file_get_contents($path);
    if ($bytes === false) {
        throw new RuntimeException('添付ファイルを読み取れません: ' . (string)$attachment['original_name']);
    }
    $payload = [
        '@odata.type' => '#microsoft.graph.fileAttachment',
        'name' => (string)$attachment['original_name'],
        'contentType' => (string)($attachment['mime_type'] ?? 'application/octet-stream'),
        'contentBytes' => base64_encode($bytes),
    ];
    $response = mail_graph_request('POST', mail_graph_user_message_path($messageId) . '/attachments', $payload, [], 120);
    mail_graph_assert_success($response, '小容量添付ファイル追加', [201]);
    return is_array($response['json']) ? $response['json'] : [];
}

function mail_graph_attach_large_file(string $messageId, array $attachment): array
{
    $path = mail_graph_attachment_absolute_path($attachment);
    $size = filesize($path);
    if ($size === false || $size <= 0) {
        throw new RuntimeException('添付ファイルサイズを取得できません: ' . (string)$attachment['original_name']);
    }
    if ($size > MAIL_GRAPH_LARGE_ATTACHMENT_LIMIT) {
        throw new RuntimeException('Graphの大容量添付上限を超えています: ' . (string)$attachment['original_name']);
    }

    $sessionPayload = [
        'AttachmentItem' => [
            'attachmentType' => 'file',
            'name' => (string)$attachment['original_name'],
            'size' => (int)$size,
        ],
    ];
    $sessionResponse = mail_graph_request('POST', mail_graph_user_message_path($messageId) . '/attachments/createUploadSession', $sessionPayload, [], 120);
    mail_graph_assert_success($sessionResponse, '大容量添付アップロードセッション作成', [201]);
    $uploadUrl = (string)($sessionResponse['json']['uploadUrl'] ?? '');
    if ($uploadUrl === '') {
        throw new RuntimeException('GraphからuploadUrlが返りませんでした。');
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('添付ファイルを開けません: ' . (string)$attachment['original_name']);
    }

    $offset = 0;
    $lastResponse = [];
    try {
        while ($offset < $size) {
            $chunkSize = min(MAIL_GRAPH_UPLOAD_CHUNK_SIZE, $size - $offset);
            $data = fread($handle, $chunkSize);
            if ($data === false || $data === '') {
                throw new RuntimeException('添付ファイルの分割読込に失敗しました。');
            }
            $end = $offset + strlen($data) - 1;
            $headers = [
                'Content-Length: ' . strlen($data),
                'Content-Range: bytes ' . $offset . '-' . $end . '/' . $size,
            ];
            $lastResponse = mail_graph_curl_request('PUT', $uploadUrl, $data, $headers, 180);
            if (!in_array((int)$lastResponse['status'], [200, 201, 202], true)) {
                $json = is_array($lastResponse['json']) ? $lastResponse['json'] : [];
                $error = $json['error']['message'] ?? $lastResponse['raw'] ?? 'unknown error';
                throw new RuntimeException('大容量添付アップロードに失敗しました: HTTP ' . (int)$lastResponse['status'] . ' / ' . (string)$error);
            }
            $offset = $end + 1;
        }
    } finally {
        fclose($handle);
    }

    return $lastResponse;
}

function mail_graph_attach_file(string $messageId, array $attachment): array
{
    $path = mail_graph_attachment_absolute_path($attachment);
    $size = (int)filesize($path);
    if ($size < MAIL_GRAPH_SMALL_ATTACHMENT_LIMIT) {
        return mail_graph_attach_small_file($messageId, $attachment);
    }
    return mail_graph_attach_large_file($messageId, $attachment);
}

function mail_graph_create_draft_for_target(PDO $pdo, int $targetId, ?array $actor = null): array
{
    $target = mail_graph_get_target($pdo, $targetId);
    if (!$target) {
        throw new InvalidArgumentException('対象メールが見つかりません。');
    }
    $batchId = (int)$target['batch_id'];
    if ((string)$target['batch_status'] !== 'approved') {
        throw new RuntimeException('バッチが確認済みではないため、Graph下書き作成は実行できません。');
    }
    if ((string)$target['status'] !== 'ready' && (string)$target['status'] !== 'failed') {
        throw new RuntimeException('対象メールの状態が下書き作成可能ではありません。現在: ' . mail_status_label((string)$target['status']));
    }
    if (!empty($target['graph_message_id'])) {
        throw new RuntimeException('この対象は既にGraphメッセージIDを保持しています。重複作成を避けるため停止しました。');
    }
    $pending = mail_graph_pending_attachment_count($pdo, $batchId);
    if ($pending > 0) {
        throw new RuntimeException('要確認または未対応の添付が残っています。先に添付対応を確定してください。');
    }

    $messageId = null;
    try {
        $payload = mail_graph_create_message_payload($target);
        $createResponse = mail_graph_request('POST', mail_graph_user_message_path(), $payload, [], 120);
        mail_graph_assert_success($createResponse, 'Outlook下書き作成', [201]);
        $messageId = (string)($createResponse['json']['id'] ?? '');
        if ($messageId === '') {
            throw new RuntimeException('Graphの下書き作成結果にmessage idが含まれていません。');
        }

        $attachments = mail_graph_list_target_attachments($pdo, $batchId, $target['organization_id'] !== null ? (int)$target['organization_id'] : null);
        $attachedCount = 0;
        foreach ($attachments as $attachment) {
            mail_graph_attach_file($messageId, $attachment);
            $attachedCount++;
        }

        mail_graph_mark_target_draft_created($pdo, $targetId, $messageId);
        mail_graph_insert_send_log($pdo, $batchId, $targetId, 'graph.draft.create', 'success', 201, json_encode([
            'message_id' => $messageId,
            'attached_count' => $attachedCount,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), null, $actor);
        mail_audit_log($pdo, $actor, 'mail.graph.draft_create', 'mail_batch_target', (string)$targetId, [
            'batch_id' => $batchId,
            'message_id' => $messageId,
            'attached_count' => $attachedCount,
        ]);

        return [
            'ok' => true,
            'target_id' => $targetId,
            'message_id' => $messageId,
            'attached_count' => $attachedCount,
            'to_email' => (string)$target['to_email'],
            'organization_name' => (string)($target['organization_name'] ?? ''),
        ];
    } catch (Throwable $e) {
        mail_graph_mark_target_failed($pdo, $targetId, $e->getMessage(), $messageId);
        mail_graph_insert_send_log($pdo, $batchId, $targetId, 'graph.draft.create', 'failed', null, $messageId !== null ? json_encode(['message_id' => $messageId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $e->getMessage(), $actor);
        throw $e;
    }
}

function mail_graph_list_draftable_targets(PDO $pdo, int $batchId, int $limit = 30): array
{
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare(
        'SELECT bt.id FROM mail_batch_targets bt INNER JOIN mail_batches b ON b.id = bt.batch_id ' .
        'WHERE bt.batch_id = :batch_id AND b.status = "approved" AND bt.status IN ("ready", "failed") AND (bt.graph_message_id IS NULL OR bt.graph_message_id = "") ' .
        'ORDER BY bt.id ASC LIMIT ' . $limit
    );
    $stmt->execute([':batch_id' => $batchId]);
    return array_map('intval', array_column($stmt->fetchAll() ?: [], 'id'));
}


function mail_graph_remaining_unfinished_target_count(PDO $pdo, int $batchId): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM mail_batch_targets WHERE batch_id = :batch_id AND status IN ("waiting", "ready", "needs_review", "failed")'
    );
    $stmt->execute([':batch_id' => $batchId]);
    return (int)$stmt->fetchColumn();
}

function mail_graph_create_drafts_for_batch(PDO $pdo, int $batchId, ?array $actor = null, int $limit = 20): array
{
    $batch = mail_get_batch($pdo, $batchId);
    if (!$batch) {
        throw new InvalidArgumentException('対象バッチが見つかりません。');
    }
    if ((string)$batch['status'] !== 'approved') {
        throw new RuntimeException('バッチが確認済みではありません。');
    }
    $pending = mail_graph_pending_attachment_count($pdo, $batchId);
    if ($pending > 0) {
        throw new RuntimeException('要確認または未対応の添付が残っています。先に添付対応を確定してください。');
    }

    $targetIds = mail_graph_list_draftable_targets($pdo, $batchId, $limit);
    $results = [];
    $success = 0;
    $failed = 0;
    foreach ($targetIds as $targetId) {
        try {
            $result = mail_graph_create_draft_for_target($pdo, $targetId, $actor);
            $results[] = $result;
            $success++;
        } catch (Throwable $e) {
            $results[] = [
                'ok' => false,
                'target_id' => $targetId,
                'message' => $e->getMessage(),
            ];
            $failed++;
        }
    }

    $remaining = count(mail_graph_list_draftable_targets($pdo, $batchId, 1));
    if ($remaining === 0 && $failed === 0 && mail_graph_remaining_unfinished_target_count($pdo, $batchId) === 0) {
        mail_update_batch_status($pdo, $batchId, 'draft_created', $actor);
    }

    return [
        'success' => $success,
        'failed' => $failed,
        'remaining' => $remaining,
        'results' => $results,
    ];
}

function mail_graph_sendable_target_count(PDO $pdo, int $batchId): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM mail_batch_targets WHERE batch_id = :batch_id AND status = "draft_created" AND graph_message_id IS NOT NULL AND graph_message_id <> ""'
    );
    $stmt->execute([':batch_id' => $batchId]);
    return (int)$stmt->fetchColumn();
}

function mail_graph_sent_target_count(PDO $pdo, int $batchId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM mail_batch_targets WHERE batch_id = :batch_id AND status = "sent"');
    $stmt->execute([':batch_id' => $batchId]);
    return (int)$stmt->fetchColumn();
}

function mail_graph_remaining_not_sent_target_count(PDO $pdo, int $batchId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM mail_batch_targets WHERE batch_id = :batch_id AND status <> "excluded" AND status <> "sent"');
    $stmt->execute([':batch_id' => $batchId]);
    return (int)$stmt->fetchColumn();
}

function mail_graph_list_sendable_targets(PDO $pdo, int $batchId, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare(
        'SELECT id FROM mail_batch_targets WHERE batch_id = :batch_id AND status = "draft_created" AND graph_message_id IS NOT NULL AND graph_message_id <> "" ORDER BY id ASC LIMIT ' . $limit
    );
    $stmt->execute([':batch_id' => $batchId]);
    return array_map('intval', array_column($stmt->fetchAll() ?: [], 'id'));
}

function mail_graph_send_existing_draft(PDO $pdo, int $targetId, ?array $actor = null): array
{
    if (!mail_graph_send_enabled()) {
        throw new RuntimeException('Graph送信UIが無効です。config.local.php の graph.allow_send_from_ui を確認してください。');
    }

    $target = mail_graph_get_target($pdo, $targetId);
    if (!$target) {
        throw new InvalidArgumentException('対象メールが見つかりません。');
    }
    if ((string)$target['status'] !== 'draft_created') {
        throw new RuntimeException('対象メールは下書き作成済みではありません。現在: ' . mail_status_label((string)$target['status']));
    }
    $messageId = trim((string)($target['graph_message_id'] ?? ''));
    if ($messageId === '') {
        throw new RuntimeException('GraphメッセージIDがないため送信できません。');
    }

    try {
        $response = mail_graph_request('POST', mail_graph_user_message_path($messageId) . '/send', null, ['Content-Length: 0'], 120);
        mail_graph_assert_success($response, 'Outlook下書き送信', [202]);
        $stmt = $pdo->prepare('UPDATE mail_batch_targets SET status = "sent", error_message = NULL WHERE id = :id');
        $stmt->execute([':id' => $targetId]);

        $batchId = (int)$target['batch_id'];
        if (mail_graph_remaining_not_sent_target_count($pdo, $batchId) === 0) {
            mail_update_batch_status($pdo, $batchId, 'sent', $actor);
        }

        mail_graph_insert_send_log($pdo, $batchId, $targetId, 'graph.message.send', 'success', 202, null, null, $actor);
        mail_audit_log($pdo, $actor, 'mail.graph.send', 'mail_batch_target', (string)$targetId, [
            'batch_id' => $batchId,
            'message_id' => $messageId,
        ]);
        return ['ok' => true, 'target_id' => $targetId, 'message_id' => $messageId];
    } catch (Throwable $e) {
        mail_graph_mark_target_failed($pdo, $targetId, $e->getMessage(), $messageId);
        mail_graph_insert_send_log($pdo, (int)$target['batch_id'], $targetId, 'graph.message.send', 'failed', null, null, $e->getMessage(), $actor);
        throw $e;
    }
}

function mail_graph_send_drafts_for_batch(PDO $pdo, int $batchId, ?array $actor = null, int $limit = 20): array
{
    if (!mail_graph_send_enabled()) {
        throw new RuntimeException('Graph送信UIが無効です。config.local.php の graph.allow_send_from_ui を確認してください。');
    }

    $batch = mail_get_batch($pdo, $batchId);
    if (!$batch) {
        throw new InvalidArgumentException('対象バッチが見つかりません。');
    }
    if (!in_array((string)$batch['status'], ['draft_created', 'approved'], true)) {
        throw new RuntimeException('バッチが送信可能状態ではありません。現在: ' . mail_status_label((string)$batch['status']));
    }

    $targetIds = mail_graph_list_sendable_targets($pdo, $batchId, $limit);
    $results = [];
    $success = 0;
    $failed = 0;
    foreach ($targetIds as $targetId) {
        try {
            $result = mail_graph_send_existing_draft($pdo, $targetId, $actor);
            $results[] = $result;
            $success++;
        } catch (Throwable $e) {
            $results[] = [
                'ok' => false,
                'target_id' => $targetId,
                'message' => $e->getMessage(),
            ];
            $failed++;
        }
    }

    return [
        'success' => $success,
        'failed' => $failed,
        'remaining' => mail_graph_sendable_target_count($pdo, $batchId),
        'results' => $results,
    ];
}
