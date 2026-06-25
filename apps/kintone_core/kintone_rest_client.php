<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/crypto.php';

function kintone_credentials_default(): ?array
{
    $stmt = kintone_pdo('org')->prepare('SELECT * FROM kintone_credentials WHERE connection_key = :key LIMIT 1');
    $stmt->execute([':key' => 'default']);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
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
    if (preg_match('/\Ahttps?:\/\//i', $subdomain)) {
        return rtrim($subdomain, '/');
    }
    return 'https://' . $subdomain . '.cybozu.com';
}

function kintone_rest_request(string $method, string $path, array $payload = [], ?array $credentials = null): array
{
    $credentials = $credentials ?? kintone_credentials_default();
    if (!is_array($credentials)) {
        throw new RuntimeException('kintone接続情報が登録されていません。');
    }
    $token = kintone_api_token_from_credentials($credentials);
    $url = kintone_api_base_url($credentials) . $path;
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('kintone接続の初期化に失敗しました。');
    }
    $headers = ['X-Cybozu-API-Token: ' . $token, 'Content-Type: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($payload !== []) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, kintone_json_encode($payload));
    }
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if (!is_string($body)) {
        throw new RuntimeException('kintone API通信に失敗しました。');
    }
    $decoded = json_decode($body, true);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('kintone APIがエラーを返しました。HTTP ' . $status . ($error !== '' ? ' / ' . $error : ''));
    }
    return is_array($decoded) ? $decoded : [];
}
