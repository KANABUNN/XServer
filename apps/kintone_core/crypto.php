<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function kintone_crypto_key(): string
{
    $keyFile = (string)kintone_config_value('kintone.crypto_key_file', __DIR__ . '/config/kintone_crypto.key');
    if (!is_file($keyFile)) {
        throw new RuntimeException('kintone暗号鍵ファイルが見つかりません。public_html外に鍵を作成してください。');
    }
    $raw = trim((string)file_get_contents($keyFile));
    $decoded = base64_decode($raw, true);
    $key = $decoded !== false ? $decoded : $raw;
    if (strlen($key) < 32) {
        throw new RuntimeException('kintone暗号鍵は32バイト以上にしてください。');
    }
    return substr($key, 0, 32);
}

function kintone_crypto_encrypt(string $plain): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', kintone_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($cipher)) {
        throw new RuntimeException('暗号化に失敗しました。');
    }
    return base64_encode($iv . $tag . $cipher);
}

function kintone_crypto_decrypt(string $encoded): string
{
    $raw = base64_decode($encoded, true);
    if (!is_string($raw) || strlen($raw) < 29) {
        throw new RuntimeException('暗号化データの形式が不正です。');
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', kintone_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($plain)) {
        throw new RuntimeException('復号に失敗しました。');
    }
    return $plain;
}

function kintone_mask_secret(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    return '••••••' . substr($value, -4);
}
