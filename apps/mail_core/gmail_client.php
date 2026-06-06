<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/smtp_client.php';

function mail_gmail_config(): array
{
    $config = mail_load_config();
    $gmail = $config['gmail_api'] ?? [];
    return is_array($gmail) ? $gmail : [];
}

function mail_gmail_enabled(): bool
{
    $gmail = mail_gmail_config();
    return mail_delivery_driver() === 'gmail_draft' && !empty($gmail['enabled']);
}

function mail_gmail_max_drafts_per_run(): int
{
    $gmail = mail_gmail_config();
    return max(1, min(100, (int)($gmail['max_drafts_per_run'] ?? 10)));
}

function mail_gmail_service_account_json(): array
{
    $gmail = mail_gmail_config();
    $json = trim((string)($gmail['service_account_json'] ?? ''));
    $path = trim((string)($gmail['service_account_json_path'] ?? ''));

    if ($json === '' && $path !== '') {
        if (!is_file($path)) {
            throw new RuntimeException('Gmail APIサービスアカウントJSONが見つかりません: ' . $path);
        }
        $json = (string)file_get_contents($path);
    }

    if ($json === '') {
        throw new RuntimeException('Gmail APIサービスアカウントJSONが設定されていません。');
    }

    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        throw new RuntimeException('Gmail APIサービスアカウントJSONを読み込めません: ' . $e->getMessage());
    }

    if (!is_array($data)) {
        throw new RuntimeException('Gmail APIサービスアカウントJSONの形式が不正です。');
    }

    foreach (['client_email', 'private_key'] as $key) {
        if (trim((string)($data[$key] ?? '')) === '') {
            throw new RuntimeException('Gmail APIサービスアカウントJSONに ' . $key . ' がありません。');
        }
    }

    return $data;
}

function mail_gmail_is_configured(): array
{
    $gmail = mail_gmail_config();
    $required = ['delegated_user', 'from_address', 'from_name'];
    $missing = [];

    foreach ($required as $key) {
        if (trim((string)($gmail[$key] ?? '')) === '') {
            $missing[] = $key;
        }
    }

    $hasInlineJson = trim((string)($gmail['service_account_json'] ?? '')) !== '';
    $jsonPath = trim((string)($gmail['service_account_json_path'] ?? ''));
    if (!$hasInlineJson && $jsonPath === '') {
        $missing[] = 'service_account_json_path';
    } elseif (!$hasInlineJson && $jsonPath !== '' && !is_file($jsonPath)) {
        $missing[] = 'service_account_json_path(file_not_found)';
    }

    return [mail_gmail_enabled() && $missing === [], $missing];
}

function mail_gmail_base64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function mail_gmail_http_request(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $method = strtoupper($method);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('cURLを初期化できません。');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false) {
            throw new RuntimeException('Gmail API HTTP通信に失敗しました: ' . $error);
        }
        return ['status' => $status, 'body' => (string)$response];
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 60,
        ],
    ]);
    $response = file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) {
            $status = (int)$m[1];
            break;
        }
    }
    if ($response === false) {
        throw new RuntimeException('Gmail API HTTP通信に失敗しました。');
    }
    return ['status' => $status, 'body' => (string)$response];
}

function mail_gmail_access_token(): string
{
    $gmail = mail_gmail_config();
    [$configured, $missing] = mail_gmail_is_configured();
    if (!$configured) {
        throw new RuntimeException('Gmail API設定が不足しています: ' . implode(', ', $missing));
    }

    $delegatedUser = trim((string)$gmail['delegated_user']);
    $scopes = $gmail['scopes'] ?? ['https://www.googleapis.com/auth/gmail.compose'];
    if (!is_array($scopes) || $scopes === []) {
        $scopes = ['https://www.googleapis.com/auth/gmail.compose'];
    }
    $scope = implode(' ', array_map('strval', $scopes));

    $cacheKey = 'gmail_token_' . sha1($delegatedUser . '|' . $scope);
    if (!empty($GLOBALS[$cacheKey]) && is_array($GLOBALS[$cacheKey])) {
        $cached = $GLOBALS[$cacheKey];
        if (($cached['expires_at'] ?? 0) > time() + 60) {
            return (string)$cached['access_token'];
        }
    }

    $service = mail_gmail_service_account_json();
    $tokenUri = trim((string)($service['token_uri'] ?? $gmail['token_uri'] ?? 'https://oauth2.googleapis.com/token'));
    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claim = [
        'iss' => (string)$service['client_email'],
        'scope' => $scope,
        'aud' => $tokenUri,
        'sub' => $delegatedUser,
        'iat' => $now,
        'exp' => $now + 3600,
    ];

    $signingInput = mail_gmail_base64url(json_encode($header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) . '.' .
        mail_gmail_base64url(json_encode($claim, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $privateKey = (string)$service['private_key'];
    $signature = '';
    $ok = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    if (!$ok) {
        throw new RuntimeException('Gmail API JWT署名に失敗しました。サービスアカウント秘密鍵を確認してください。');
    }
    $assertion = $signingInput . '.' . mail_gmail_base64url($signature);

    $body = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $assertion,
    ], '', '&', PHP_QUERY_RFC3986);

    $response = mail_gmail_http_request('POST', $tokenUri, [
        'Content-Type: application/x-www-form-urlencoded',
    ], $body);

    $payload = json_decode($response['body'], true);
    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($payload) || empty($payload['access_token'])) {
        $message = is_array($payload) ? (string)($payload['error_description'] ?? $payload['error'] ?? '') : '';
        throw new RuntimeException('Gmail APIアクセストークン取得に失敗しました: HTTP ' . $response['status'] . ($message !== '' ? ' / ' . $message : ''));
    }

    $GLOBALS[$cacheKey] = [
        'access_token' => (string)$payload['access_token'],
        'expires_at' => time() + max(60, (int)($payload['expires_in'] ?? 3600)),
    ];
    return (string)$payload['access_token'];
}

function mail_gmail_configured_from(): array
{
    $gmail = mail_gmail_config();
    $fromAddress = trim((string)($gmail['from_address'] ?? ''));
    $fromName = trim((string)($gmail['from_name'] ?? ''));
    $replyTo = trim((string)($gmail['reply_to'] ?? ''));
    if ($fromAddress === '' || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Gmail APIのfrom_addressが不正です。');
    }
    if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Gmail APIのreply_toが不正です。');
    }
    return [$fromAddress, $fromName !== '' ? $fromName : $fromAddress, $replyTo];
}

function mail_gmail_build_mime(PDO $pdo, array $target): array
{
    mail_smtp_load_phpmailer();
    $message = mail_smtp_message_data($target);
    [$fromAddress, $fromName, $replyTo] = mail_gmail_configured_from();

    $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->CharSet = 'UTF-8';
    $mailer->Encoding = 'base64';
    $mailer->setFrom($fromAddress, $fromName, false);
    if ($replyTo !== '') {
        $mailer->addReplyTo($replyTo, $fromName);
    }
    $mailer->addAddress($message['to_email']);
    $mailer->Subject = $message['subject'];

    if ($message['body_type'] === 'html') {
        $mailer->isHTML(true);
        $mailer->Body = $message['body'];
        $mailer->AltBody = \PHPMailer\PHPMailer\PHPMailer::normalizeBreaks(mail_smtp_plain_from_html($message['body']), "\r\n");
    } else {
        $plain = \PHPMailer\PHPMailer\PHPMailer::normalizeBreaks($message['body'], "\r\n");
        $mailer->isHTML(false);
        $mailer->Body = $plain;
        $mailer->AltBody = $plain;
    }

    $batchId = (int)$target['batch_id'];
    $targetId = (int)$target['id'];
    $mailer->addCustomHeader('X-FIT-SC-Mail-Batch-Id', (string)$batchId);
    $mailer->addCustomHeader('X-FIT-SC-Mail-Target-Id', (string)$targetId);

    $attachments = mail_smtp_list_target_attachments($pdo, $batchId, $target['organization_id'] !== null ? (int)$target['organization_id'] : null);
    $attachedCount = 0;
    foreach ($attachments as $attachment) {
        $mailer->addAttachment(mail_smtp_attachment_absolute_path($attachment), (string)$attachment['original_name']);
        $attachedCount++;
    }

    if (!$mailer->preSend()) {
        throw new RuntimeException('MIMEメールの生成に失敗しました: ' . $mailer->ErrorInfo);
    }

    return [
        'message' => $message,
        'mime' => $mailer->getSentMIMEMessage(),
        'attached_count' => $attachedCount,
        'from_address' => $fromAddress,
    ];
}

function mail_gmail_api_base(): string
{
    $gmail = mail_gmail_config();
    return rtrim((string)($gmail['api_base'] ?? 'https://gmail.googleapis.com/gmail/v1'), '/');
}

function mail_gmail_create_draft_raw(string $rawMime): array
{
    $gmail = mail_gmail_config();
    $userId = rawurlencode(trim((string)($gmail['delegated_user'] ?? '')));
    if ($userId === '') {
        throw new RuntimeException('Gmail API delegated_userが設定されていません。');
    }

    $token = mail_gmail_access_token();
    $url = mail_gmail_api_base() . '/users/' . $userId . '/drafts';
    $body = json_encode([
        'message' => [
            'raw' => mail_gmail_base64url($rawMime),
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $response = mail_gmail_http_request('POST', $url, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json; charset=UTF-8',
    ], $body);

    $payload = json_decode($response['body'], true);
    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($payload) || empty($payload['id'])) {
        $message = '';
        if (is_array($payload)) {
            $error = $payload['error'] ?? null;
            if (is_array($error)) {
                $message = (string)($error['message'] ?? '');
            } else {
                $message = (string)($payload['error_description'] ?? $payload['error'] ?? '');
            }
        }
        throw new RuntimeException('Gmail下書き作成に失敗しました: HTTP ' . $response['status'] . ($message !== '' ? ' / ' . $message : ''));
    }

    return $payload;
}

function mail_gmail_insert_log(PDO $pdo, ?int $batchId, ?int $targetId, string $action, string $result, ?string $responseBody, ?string $errorMessage, ?array $actor): void
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
        ':response_code' => null,
        ':response_body' => $responseBody !== null ? mb_substr($responseBody, 0, 8000) : null,
        ':error_message' => $errorMessage !== null ? mb_substr($errorMessage, 0, 1000) : null,
        ':created_by' => $actor !== null ? (int)($actor['id'] ?? 0) : null,
    ]);
}

function mail_gmail_mark_target_failed(PDO $pdo, int $targetId, string $message): void
{
    $stmt = $pdo->prepare('UPDATE mail_batch_targets SET status = "failed", error_message = :error_message WHERE id = :id');
    $stmt->execute([':error_message' => mb_substr($message, 0, 1000), ':id' => $targetId]);
}

function mail_gmail_get_target(PDO $pdo, int $targetId): ?array
{
    return mail_smtp_get_target($pdo, $targetId);
}

function mail_gmail_create_draft_target(PDO $pdo, int $targetId, ?array $actor = null): array
{
    if (!mail_gmail_enabled()) {
        throw new RuntimeException('Gmail下書き作成が無効です。config.local.php の mail_delivery.driver と gmail_api.enabled を確認してください。');
    }

    $target = mail_gmail_get_target($pdo, $targetId);
    if (!$target) {
        throw new InvalidArgumentException('対象メールが見つかりません。');
    }

    $batchId = (int)$target['batch_id'];
    if (!in_array((string)$target['batch_status'], ['approved', 'draft_created'], true)) {
        throw new RuntimeException('バッチが下書き作成可能状態ではありません。現在: ' . mail_status_label((string)$target['batch_status']));
    }
    if (!in_array((string)$target['status'], ['ready', 'failed'], true)) {
        throw new RuntimeException('対象メールの状態がGmail下書き作成可能ではありません。現在: ' . mail_status_label((string)$target['status']));
    }
    if (trim((string)($target['graph_message_id'] ?? '')) !== '') {
        throw new RuntimeException('この対象メールは既に外部下書きIDを持っています。重複作成を避けるため中止しました。');
    }

    $pending = mail_smtp_pending_attachment_count($pdo, $batchId);
    if ($pending > 0) {
        throw new RuntimeException('要確認または未対応の添付が残っています。先に添付対応を確定してください。');
    }

    try {
        $built = mail_gmail_build_mime($pdo, $target);
        $draft = mail_gmail_create_draft_raw((string)$built['mime']);
        $draftId = (string)$draft['id'];
        $messageId = isset($draft['message']['id']) ? (string)$draft['message']['id'] : '';
        $externalId = $messageId !== '' ? $draftId . '|' . $messageId : $draftId;

        $stmt = $pdo->prepare('UPDATE mail_batch_targets SET status = "draft_created", graph_message_id = :external_id, error_message = NULL WHERE id = :id');
        $stmt->execute([':external_id' => $externalId, ':id' => $targetId]);

        if (mail_gmail_draftable_target_count($pdo, $batchId) === 0) {
            mail_update_batch_status($pdo, $batchId, 'draft_created', $actor);
        }

        mail_gmail_insert_log($pdo, $batchId, $targetId, 'gmail.draft.create', 'success', json_encode([
            'draft_id' => $draftId,
            'message_id' => $messageId,
            'to_email' => $built['message']['to_email'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), null, $actor);
        mail_audit_log($pdo, $actor, 'mail.gmail.draft.create', 'mail_batch_target', (string)$targetId, [
            'batch_id' => $batchId,
            'draft_id' => $draftId,
            'message_id' => $messageId,
            'to_email' => $built['message']['to_email'],
            'attached_count' => $built['attached_count'],
        ]);

        return [
            'ok' => true,
            'target_id' => $targetId,
            'to_email' => $built['message']['to_email'],
            'draft_id' => $draftId,
            'message_id' => $messageId,
            'attached_count' => $built['attached_count'],
        ];
    } catch (Throwable $e) {
        mail_gmail_mark_target_failed($pdo, $targetId, $e->getMessage());
        mail_gmail_insert_log($pdo, $batchId, $targetId, 'gmail.draft.create', 'failed', null, $e->getMessage(), $actor);
        throw $e;
    }
}

function mail_gmail_draftable_target_count(PDO $pdo, int $batchId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM mail_batch_targets WHERE batch_id = :batch_id AND status IN ("ready", "failed") AND (graph_message_id IS NULL OR graph_message_id = "")');
    $stmt->execute([':batch_id' => $batchId]);
    return (int)$stmt->fetchColumn();
}

function mail_gmail_drafted_target_count(PDO $pdo, int $batchId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM mail_batch_targets WHERE batch_id = :batch_id AND status = "draft_created"');
    $stmt->execute([':batch_id' => $batchId]);
    return (int)$stmt->fetchColumn();
}

function mail_gmail_list_draftable_targets(PDO $pdo, int $batchId, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare('SELECT id FROM mail_batch_targets WHERE batch_id = :batch_id AND status IN ("ready", "failed") AND (graph_message_id IS NULL OR graph_message_id = "") ORDER BY id ASC LIMIT ' . $limit);
    $stmt->execute([':batch_id' => $batchId]);
    return array_map('intval', array_column($stmt->fetchAll() ?: [], 'id'));
}

function mail_gmail_create_drafts_batch(PDO $pdo, int $batchId, ?array $actor = null, int $limit = 10): array
{
    $batch = mail_get_batch($pdo, $batchId);
    if (!$batch) {
        throw new InvalidArgumentException('対象バッチが見つかりません。');
    }
    if (!in_array((string)$batch['status'], ['approved', 'draft_created'], true)) {
        throw new RuntimeException('バッチが下書き作成可能状態ではありません。現在: ' . mail_status_label((string)$batch['status']));
    }
    $pending = mail_smtp_pending_attachment_count($pdo, $batchId);
    if ($pending > 0) {
        throw new RuntimeException('要確認または未対応の添付が残っています。先に添付対応を確定してください。');
    }

    $targetIds = mail_gmail_list_draftable_targets($pdo, $batchId, $limit);
    $results = [];
    $success = 0;
    $failed = 0;
    foreach ($targetIds as $targetId) {
        try {
            $results[] = mail_gmail_create_draft_target($pdo, $targetId, $actor);
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
        'remaining' => mail_gmail_draftable_target_count($pdo, $batchId),
        'results' => $results,
    ];
}

function mail_gmail_test_connection(): array
{
    $token = mail_gmail_access_token();
    $gmail = mail_gmail_config();
    $userId = rawurlencode(trim((string)($gmail['delegated_user'] ?? '')));
    $url = mail_gmail_api_base() . '/users/' . $userId . '/profile';
    $response = mail_gmail_http_request('GET', $url, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);
    $payload = json_decode($response['body'], true);
    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($payload)) {
        $message = '';
        if (is_array($payload) && isset($payload['error']) && is_array($payload['error'])) {
            $message = (string)($payload['error']['message'] ?? '');
        }
        throw new RuntimeException('Gmail API接続確認に失敗しました: HTTP ' . $response['status'] . ($message !== '' ? ' / ' . $message : ''));
    }
    return [
        'ok' => true,
        'emailAddress' => (string)($payload['emailAddress'] ?? ''),
        'messagesTotal' => $payload['messagesTotal'] ?? null,
        'threadsTotal' => $payload['threadsTotal'] ?? null,
    ];
}
