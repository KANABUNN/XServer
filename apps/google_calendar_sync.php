<?php

declare(strict_types=1);

function google_calendar_sync_enabled(array $cfg): bool
{
    $googleCfg = $cfg['google_calendar'] ?? null;
    if (!is_array($googleCfg)) {
        return false;
    }

    return (bool)($googleCfg['enabled'] ?? false);
}

function google_calendar_require_room_calendar_id(array $cfg, string $roomCode): string
{
    $googleCfg = $cfg['google_calendar'] ?? [];
    if (!is_array($googleCfg)) {
        throw new RuntimeException('config.php の google_calendar 設定が不正です。');
    }

    $roomCode = trim($roomCode);
    $calendarId = '';

    $flatMap = $googleCfg['room_calendar_map'] ?? null;
    if (is_array($flatMap) && isset($flatMap[$roomCode])) {
        $calendarId = trim((string)$flatMap[$roomCode]);
    }

    $roomMap = $googleCfg['rooms'] ?? null;
    if ($calendarId === '' && is_array($roomMap) && isset($roomMap[$roomCode]) && is_array($roomMap[$roomCode])) {
        $calendarId = trim((string)($roomMap[$roomCode]['calendar_id'] ?? ''));
    }

    if ($calendarId === '') {
        throw new RuntimeException('config.php に部屋「' . $roomCode . '」用の Google カレンダーIDがありません。');
    }

    return $calendarId;
}

function google_calendar_create_via_gas(array $cfg, array $payload): array
{
    $payload['action'] = 'create';
    return google_calendar_post_to_gas($cfg, $payload);
}

function google_calendar_delete_via_gas(array $cfg, array $payload): array
{
    $payload['action'] = 'delete';
    return google_calendar_post_to_gas($cfg, $payload);
}

function google_calendar_update_via_gas(array $cfg, array $payload): array
{
    $payload['action'] = 'update';
    return google_calendar_post_to_gas($cfg, $payload);
}

function google_calendar_post_to_gas(array $cfg, array $payload): array
{
    if (!google_calendar_sync_enabled($cfg)) {
        return ['ok' => true, 'disabled' => true];
    }

    $googleCfg = $cfg['google_calendar'] ?? [];
    if (!is_array($googleCfg)) {
        throw new RuntimeException('config.php の google_calendar 設定が不正です。');
    }

    $url = trim((string)($googleCfg['gas_url'] ?? ''));
    if ($url === '') {
        throw new RuntimeException('config.php の google_calendar.gas_url が未設定です。');
    }

    $secret = trim((string)($googleCfg['shared_secret'] ?? ''));
    if ($secret === '') {
        throw new RuntimeException('config.php の google_calendar.shared_secret が未設定です。');
    }

    $payload['shared_secret'] = $secret;
    $payload['timezone'] = trim((string)($googleCfg['timezone'] ?? 'Asia/Tokyo')) ?: 'Asia/Tokyo';

    $connectTimeout = max(1, (int)($googleCfg['connect_timeout'] ?? 5));
    $timeout = max($connectTimeout, (int)($googleCfg['timeout'] ?? 15));

    $responseText = google_calendar_http_post_json($url, $payload, $timeout, $connectTimeout);
    $decoded = json_decode($responseText, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('GAS の応答が JSON ではありません。');
    }

    if (!($decoded['ok'] ?? false)) {
        $message = trim((string)($decoded['error'] ?? $decoded['message'] ?? 'Google カレンダー連携に失敗しました。'));
        throw new RuntimeException($message !== '' ? $message : 'Google カレンダー連携に失敗しました。');
    }

    return $decoded;
}

function google_calendar_http_post_json(string $url, array $payload, int $timeout, int $connectTimeout): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('GAS 送信用 JSON の生成に失敗しました。');
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('cURL の初期化に失敗しました。');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=UTF-8',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException('GAS への接続に失敗しました: ' . $error);
        }
        if (!is_string($response)) {
            throw new RuntimeException('GAS からの応答を取得できませんでした。');
        }
        if ($status >= 400) {
            throw new RuntimeException('GAS が HTTP ' . $status . ' を返しました。');
        }

        return $response;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json; charset=UTF-8',
                'Accept: application/json',
            ]),
            'content' => $json,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        throw new RuntimeException('GAS への接続に失敗しました。');
    }

    return $response;
}
