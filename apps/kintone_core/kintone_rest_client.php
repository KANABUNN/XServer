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

function kintone_credentials_default(): ?array
{
    $stmt = kintone_pdo('org')->prepare('SELECT * FROM kintone_credentials WHERE connection_key = :key LIMIT 1');
    $stmt->execute([':key' => 'default']);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function kintone_credentials_require_default(): array
{
    $credentials = kintone_credentials_default();
    if (!is_array($credentials)) {
        throw new RuntimeException('kintone接続情報が登録されていません。');
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
    return kintone_rest_request('GET', '/k/v1/records.json', [
        'app' => $appId,
        'fields' => array_values($fields),
        'query' => $query,
    ], $credentials);
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

function kintone_rest_update_records(int $appId, array $records, ?array $credentials = null): array
{
    if (count($records) > 100) {
        throw new InvalidArgumentException('kintoneへ一度に更新できるレコードは100件までです。');
    }
    return kintone_rest_request('PUT', '/k/v1/records.json', [
        'app' => $appId,
        'records' => array_values($records),
    ], $credentials);
}
