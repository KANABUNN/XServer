<?php

declare(strict_types=1);

function switchbot_config(array $cfg): array
{
    $sb = $cfg['switchbot'] ?? [];
    if (!is_array($sb)) {
        $sb = [];
    }

    $apiBase = rtrim((string)($sb['api_base'] ?? 'https://api.switch-bot.com/v1.1'), '/');
    $timezone = (string)($sb['timezone'] ?? 'Asia/Tokyo');
    $timeout = (int)($sb['timeout'] ?? 15);
    if ($timeout < 5) {
        $timeout = 5;
    }

    $keypads = $sb['keypads'] ?? [];
    if (!is_array($keypads)) {
        $keypads = [];
    }

    return [
        'token' => trim((string)($sb['token'] ?? '')),
        'secret' => trim((string)($sb['secret'] ?? '')),
        'api_base' => $apiBase,
        'timezone' => $timezone,
        'timeout' => $timeout,
        'keypads' => $keypads,
    ];
}

function switchbot_is_configured(array $cfg): bool
{
    $sb = switchbot_config($cfg);
    return $sb['token'] !== '' && $sb['secret'] !== '';
}

function switchbot_headers(array $cfg): array
{
    $sb = switchbot_config($cfg);
    if ($sb['token'] === '' || $sb['secret'] === '') {
        throw new RuntimeException('SwitchBot の token / secret が未設定です。');
    }

    $nonce = bin2hex(random_bytes(8));
    $timestamp = (string)round(microtime(true) * 1000);
    $data = $sb['token'] . $timestamp . $nonce;
    $sign = base64_encode(hash_hmac('sha256', $data, $sb['secret'], true));

    return [
        'Authorization: ' . $sb['token'],
        'sign: ' . $sign,
        'nonce: ' . $nonce,
        't: ' . $timestamp,
        'Content-Type: application/json; charset=utf8',
        'charset: utf8',
    ];
}

function switchbot_request(array $cfg, string $method, string $path, ?array $payload = null): array
{
    $sb = switchbot_config($cfg);
    $url = $sb['api_base'] . '/' . ltrim($path, '/');

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('cURL を初期化できませんでした。');
    }

    $headers = switchbot_headers($cfg);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $sb['timeout'],
        CURLOPT_CONNECTTIMEOUT => min(10, $sb['timeout']),
    ];

    if ($payload !== null) {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('SwitchBot 送信用 JSON の生成に失敗しました。');
        }
        $opts[CURLOPT_POSTFIELDS] = $json;
    }

    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $message = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('SwitchBot API 通信に失敗しました: ' . $message);
    }

    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('SwitchBot API の応答が JSON ではありません。HTTP ' . $httpCode);
    }

    $decoded['_http_code'] = $httpCode;
    return $decoded;
}

function switchbot_get_devices(array $cfg): array
{
    $result = switchbot_request($cfg, 'GET', 'devices');
    $statusCode = (int)($result['statusCode'] ?? 0);
    if ($statusCode !== 100) {
        $message = (string)($result['message'] ?? 'SwitchBot device list error');
        throw new RuntimeException('SwitchBot デバイス一覧の取得に失敗しました: ' . $message);
    }

    $body = $result['body'] ?? [];
    $deviceList = $body['deviceList'] ?? [];
    return is_array($deviceList) ? $deviceList : [];
}

function switchbot_filter_keypads(array $deviceList): array
{
    return array_values(array_filter($deviceList, static function ($device): bool {
        if (!is_array($device)) {
            return false;
        }
        $type = (string)($device['deviceType'] ?? '');
        return stripos($type, 'Keypad') !== false;
    }));
}

function switchbot_room_entry(array $cfg, string $roomCode): array
{
    $sb = switchbot_config($cfg);
    $entry = $sb['keypads'][$roomCode] ?? [];
    return is_array($entry) ? $entry : [];
}

function switchbot_find_device_for_room(array $cfg, string $roomCode, array $keypads): ?array
{
    $entry = switchbot_room_entry($cfg, $roomCode);
    $deviceId = trim((string)($entry['device_id'] ?? ''));
    $deviceName = trim((string)($entry['device_name'] ?? ''));
    $roomLabel = switchbot_room_label($roomCode);

    if ($deviceId !== '') {
        foreach ($keypads as $device) {
            if ((string)($device['deviceId'] ?? '') === $deviceId) {
                return $device;
            }
        }
    }

    if ($deviceName !== '') {
        foreach ($keypads as $device) {
            if ((string)($device['deviceName'] ?? '') === $deviceName) {
                return $device;
            }
        }
        foreach ($keypads as $device) {
            $name = (string)($device['deviceName'] ?? '');
            if ($name !== '' && mb_stripos($name, $deviceName, 0, 'UTF-8') !== false) {
                return $device;
            }
        }
    }

    foreach ($keypads as $device) {
        $name = (string)($device['deviceName'] ?? '');
        if ($name !== '' && mb_stripos($name, $roomLabel, 0, 'UTF-8') !== false) {
            return $device;
        }
    }

    return null;
}

function switchbot_room_label(string $roomCode): string
{
    return match ($roomCode) {
        'tamoku' => '多目的室',
        'orange' => 'オレンジの部屋',
        default => $roomCode,
    };
}

function switchbot_build_room_status(array $cfg, string $roomCode, array $keypads): array
{
    $entry = switchbot_room_entry($cfg, $roomCode);
    $configured = trim((string)($entry['device_id'] ?? '')) !== '' || trim((string)($entry['device_name'] ?? '')) !== '';
    $device = switchbot_find_device_for_room($cfg, $roomCode, $keypads);

    $status = [
        'room_code' => $roomCode,
        'room_label' => switchbot_room_label($roomCode),
        'configured' => $configured,
        'ready' => false,
        'device_id' => null,
        'device_name' => null,
        'device_type' => null,
        'note' => null,
    ];

    if (!$configured) {
        $status['note'] = 'config.php の switchbot.keypads に対象キーパッドの device_id または device_name を設定してください。';
        return $status;
    }

    if ($device === null) {
        $status['note'] = '設定済みのキーパッドが SwitchBot のデバイス一覧で見つかりません。device_id / device_name を確認してください。';
        return $status;
    }

    $status['ready'] = true;
    $status['device_id'] = (string)($device['deviceId'] ?? '');
    $status['device_name'] = (string)($device['deviceName'] ?? '');
    $status['device_type'] = (string)($device['deviceType'] ?? '');
    $status['note'] = '期間限定パスワードを追加できます。';
    return $status;
}

function switchbot_datetime_to_unix_seconds(string $datetimeLocal, string $timezone): int
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $datetimeLocal, new DateTimeZone($timezone));
    if (!$dt || $dt->format('Y-m-d\TH:i') !== $datetimeLocal) {
        throw new InvalidArgumentException('日時は YYYY-MM-DDTHH:MM 形式で入力してください。');
    }
    return $dt->getTimestamp();
}

function switchbot_create_time_limited_key(array $cfg, string $deviceId, string $name, string $password, string $startAt, string $endAt): array
{
    if (!preg_match('/^\d{6,12}$/', $password)) {
        throw new InvalidArgumentException('パスワードは 6〜12 桁の数字で入力してください。');
    }

    $sb = switchbot_config($cfg);
    $startTime = switchbot_datetime_to_unix_seconds($startAt, $sb['timezone']);
    $endTime = switchbot_datetime_to_unix_seconds($endAt, $sb['timezone']);

    if ($endTime <= $startTime) {
        throw new InvalidArgumentException('終了日時は開始日時より後にしてください。');
    }

    $payload = [
        'commandType' => 'command',
        'command' => 'createKey',
        'parameter' => [
            'name' => $name,
            'type' => 'timeLimit',
            'password' => $password,
            'startTime' => $startTime,
            'endTime' => $endTime,
        ],
    ];

    return switchbot_request($cfg, 'POST', 'devices/' . rawurlencode($deviceId) . '/commands', $payload);
}
