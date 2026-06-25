<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/crypto.php';

final class KintoneRestException extends RuntimeException
{
    public function __construct(
        public int $httpStatus,
        public string $kintoneCode,
        string $message,
        public array $responsePayload = []
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function isRetryable(): bool
    {
        return $this->httpStatus === 429 || ($this->httpStatus >= 500 && $this->httpStatus <= 599);
    }
}

function kintone_normalize_app_key(string $appKey): string
{
    $appKey = trim($appKey);
    if ($appKey === '' || preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/', $appKey) !== 1) {
        throw new InvalidArgumentException('app_key は半角英数字・アンダースコア・ハイフン100文字以内で指定してください。');
    }
    return $appKey;
}

function kintone_credentials_for(string $appKey): array
{
    $appKey = kintone_normalize_app_key($appKey);
    $stmt = kintone_pdo('org')->prepare(
        'SELECT c.*, a.kintone_subdomain AS app_subdomain, a.kintone_app_id AS app_app_id, a.is_active AS app_is_active, a.status AS app_status ' .
        'FROM kintone_credentials c LEFT JOIN kintone_apps a ON a.app_key = c.connection_key ' .
        'WHERE c.connection_key = :app_key LIMIT 1'
    );
    $stmt->execute([':app_key' => $appKey]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('kintone接続情報が登録されていません: ' . $appKey);
    }
    if ((string)($row['auth_type'] ?? 'api_token') !== 'api_token') {
        throw new RuntimeException('現在対応しているkintone認証方式はAPIトークンのみです: ' . $appKey);
    }
    if ((int)($row['app_is_active'] ?? 1) !== 1) {
        throw new RuntimeException('対象kintoneアプリは無効化されています: ' . $appKey);
    }
    if (trim((string)($row['kintone_subdomain'] ?? '')) === '' && trim((string)($row['app_subdomain'] ?? '')) !== '') {
        $row['kintone_subdomain'] = $row['app_subdomain'];
    }
    if ((int)($row['kintone_app_id'] ?? 0) < 1 && (int)($row['app_app_id'] ?? 0) > 0) {
        $row['kintone_app_id'] = (int)$row['app_app_id'];
    }
    return $row;
}

function kintone_credentials_default(): ?array
{
    try {
        return kintone_credentials_for('organizations');
    } catch (Throwable $e) {
        // Phase 2 多アプリ化前の未移行環境だけの保険。通常は差分SQLで organizations に改名される。
        try {
            $stmt = kintone_pdo('org')->prepare('SELECT * FROM kintone_credentials WHERE connection_key = :key LIMIT 1');
            $stmt->execute([':key' => 'default']);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }
}

function kintone_credentials_require_default(): array
{
    $credentials = kintone_credentials_default();
    if (!is_array($credentials)) {
        throw new RuntimeException('団体マスタ用kintone接続情報が登録されていません。');
    }
    if ((string)($credentials['auth_type'] ?? 'api_token') !== 'api_token') {
        throw new RuntimeException('現在対応しているkintone認証方式はAPIトークンのみです。');
    }
    return $credentials;
}

function kintone_api_token_from_credentials(array $credentials): string
{
    $encrypted = (string)($credentials['api_token_encrypted'] ?? '');
    if ($encrypted === '') {
        throw new RuntimeException('kintone APIトークンが登録されていません。');
    }
    return kintone_crypto_decrypt($encrypted);
}

function kintone_api_base_url(array $credentials): string
{
    $subdomain = trim((string)($credentials['kintone_subdomain'] ?? ''));
    if ($subdomain === '') {
        throw new RuntimeException('kintoneサブドメインが未設定です。');
    }
    if (preg_match('/\Ahttps?:\/\//i', $subdomain) === 1) {
        return rtrim($subdomain, '/');
    }
    return 'https://' . rtrim($subdomain, '/') . '.cybozu.com';
}

function kintone_rest_build_url(array $credentials, string $path, array $query = []): string
{
    $path = '/' . ltrim($path, '/');
    $url = kintone_api_base_url($credentials) . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    return $url;
}

function kintone_rest_error_message(int $status, array $decoded, string $curlError = ''): string
{
    $parts = ['kintone APIがエラーを返しました。HTTP ' . $status];
    $code = trim((string)($decoded['code'] ?? ''));
    $message = trim((string)($decoded['message'] ?? ''));
    if ($code !== '') {
        $parts[] = 'code=' . $code;
    }
    if ($message !== '') {
        $parts[] = 'message=' . $message;
    }
    if ($curlError !== '') {
        $parts[] = 'transport=' . $curlError;
    }
    return implode(' / ', $parts);
}

function kintone_rest_single_request(string $method, string $path, array $payload = [], ?array $credentials = null): array
{
    $credentials = $credentials ?? kintone_credentials_require_default();
    $token = kintone_api_token_from_credentials($credentials);
    $method = strtoupper($method);

    $query = [];
    $bodyPayload = $payload;
    if ($method === 'GET') {
        $query = $payload;
        $bodyPayload = [];
    }
    $url = kintone_rest_build_url($credentials, $path, $query);
    $headers = [
        'X-Cybozu-API-Token: ' . $token,
        'Accept: application/json',
    ];
    if ($method !== 'GET' || $bodyPayload !== []) {
        $headers[] = 'Content-Type: application/json';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('kintone接続の初期化に失敗しました。');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int)kintone_config_value('kintone.api_timeout_seconds', 45),
            CURLOPT_CONNECTTIMEOUT => (int)kintone_config_value('kintone.api_connect_timeout_seconds', 15),
        ]);
        if ($method !== 'GET' && $bodyPayload !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, kintone_json_encode($bodyPayload));
        }
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($body)) {
            throw new KintoneRestException(0, '', 'kintone API通信に失敗しました。' . ($error !== '' ? ' / ' . $error : ''));
        }
    } else {
        $contextOptions = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
                'timeout' => (int)kintone_config_value('kintone.api_timeout_seconds', 45),
            ],
        ];
        if ($method !== 'GET' && $bodyPayload !== []) {
            $contextOptions['http']['content'] = kintone_json_encode($bodyPayload);
        }
        $body = file_get_contents($url, false, stream_context_create($contextOptions));
        $status = 0;
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $matches) === 1) {
                $status = (int)$matches[1];
                break;
            }
        }
        $error = '';
        if (!is_string($body)) {
            throw new KintoneRestException($status, '', 'kintone API通信に失敗しました。');
        }
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }
    if ($status < 200 || $status >= 300) {
        throw new KintoneRestException(
            $status,
            (string)($decoded['code'] ?? ''),
            kintone_rest_error_message($status, $decoded, $error),
            $decoded
        );
    }
    return $decoded;
}

function kintone_rest_request(string $method, string $path, array $payload = [], ?array $credentials = null, ?int $maxRetries = null): array
{
    $credentials = $credentials ?? kintone_credentials_require_default();
    $maxRetries = $maxRetries ?? (int)kintone_config_value('kintone.api_max_retries', 3);
    $attempt = 0;
    while (true) {
        try {
            return kintone_rest_single_request($method, $path, $payload, $credentials);
        } catch (KintoneRestException $e) {
            if (!$e->isRetryable() || $attempt >= $maxRetries) {
                throw $e;
            }
            $sleepMs = min(8000, (500 * (2 ** $attempt)) + random_int(0, 250));
            usleep($sleepMs * 1000);
            $attempt++;
        }
    }
}

function kintone_rest_get_records(int $appId, array $fields, string $query, ?array $credentials = null): array
{
    $payload = ['app' => $appId, 'query' => $query];
    if ($fields !== []) {
        $payload['fields'] = array_values($fields);
    }
    return kintone_rest_request('GET', '/k/v1/records.json', $payload, $credentials);
}

function kintone_rest_add_records(int $appId, array $records, ?array $credentials = null): array
{
    if (count($records) > 100) {
        throw new InvalidArgumentException('kintoneへ一度に登録できるレコードは100件までです。');
    }
    return kintone_rest_request('POST', '/k/v1/records.json', [
        'app' => $appId,
        'records' => array_values($records),
    ], $credentials);
}

function kintone_rest_update_records(int $appId, array $records, ?array $credentials = null, bool $upsert = false): array
{
    if (count($records) > 100) {
        throw new InvalidArgumentException('kintoneへ一度に更新できるレコードは100件までです。');
    }
    $payload = [
        'app' => $appId,
        'records' => array_values($records),
    ];
    if ($upsert) {
        $payload['upsert'] = true;
    }
    return kintone_rest_request('PUT', '/k/v1/records.json', $payload, $credentials);
}

function kintone_rest_create_cursor(int $appId, array $fields, string $query, ?array $credentials = null, int $size = 500): array
{
    $size = max(1, min(500, $size));
    $payload = [
        'app' => $appId,
        'query' => $query,
        'size' => $size,
    ];
    if ($fields !== []) {
        $payload['fields'] = array_values($fields);
    }
    return kintone_rest_request('POST', '/k/v1/records/cursor.json', $payload, $credentials);
}

function kintone_rest_get_cursor(string $cursorId, ?array $credentials = null): array
{
    return kintone_rest_request('GET', '/k/v1/records/cursor.json', ['id' => $cursorId], $credentials);
}

function kintone_rest_delete_cursor(string $cursorId, ?array $credentials = null): void
{
    if ($cursorId === '') {
        return;
    }
    try {
        kintone_rest_request('DELETE', '/k/v1/records/cursor.json', ['id' => $cursorId], $credentials, 0);
    } catch (Throwable $e) {
        kintone_write_log('warning', 'kintone cursor delete failed', ['error' => $e->getMessage()]);
    }
}

function kintone_rest_iterate_records(int $appId, array $fields, string $query, callable $onChunk, ?array $credentials = null, bool $preferCursor = true): array
{
    $credentials = $credentials ?? kintone_credentials_require_default();
    $count = 0;
    $chunks = 0;
    $cursorId = '';
    if ($preferCursor) {
        try {
            $cursor = kintone_rest_create_cursor($appId, $fields, $query, $credentials, 500);
            $cursorId = (string)($cursor['id'] ?? '');
            if ($cursorId === '') {
                throw new RuntimeException('kintoneカーソルIDが返されませんでした。');
            }
            do {
                $page = kintone_rest_get_cursor($cursorId, $credentials);
                $records = is_array($page['records'] ?? null) ? $page['records'] : [];
                if ($records !== []) {
                    $onChunk($records);
                    $count += count($records);
                    $chunks++;
                }
                $next = (bool)($page['next'] ?? false);
                if ($next) {
                    usleep((int)kintone_config_value('kintone.api_chunk_pause_ms', 200) * 1000);
                }
            } while ($next);
            kintone_rest_delete_cursor($cursorId, $credentials);
            return ['count' => $count, 'chunks' => $chunks, 'mode' => 'cursor'];
        } catch (Throwable $e) {
            if ($cursorId !== '') {
                kintone_rest_delete_cursor($cursorId, $credentials);
            }
            throw $e;
        }
    }

    $offset = 0;
    $limit = 500;
    while (true) {
        if ($offset + $limit > 10000) {
            throw new RuntimeException('kintoneのoffset取得上限10,000件に達するため、カーソルAPIでの取得が必要です。');
        }
        $pageQuery = trim($query . ' limit ' . $limit . ' offset ' . $offset);
        $response = kintone_rest_get_records($appId, $fields, $pageQuery, $credentials);
        $records = is_array($response['records'] ?? null) ? $response['records'] : [];
        if ($records !== []) {
            $onChunk($records);
            $count += count($records);
            $chunks++;
        }
        if (count($records) < $limit) {
            break;
        }
        $offset += $limit;
        usleep((int)kintone_config_value('kintone.api_chunk_pause_ms', 200) * 1000);
    }
    return ['count' => $count, 'chunks' => $chunks, 'mode' => 'offset'];
}
