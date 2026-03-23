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

    $storageDir = trim((string)($sb['storage_dir'] ?? ''));
    if ($storageDir === '') {
        $storageDir = dirname(__DIR__) . '/storage/switchbot';
    }

    return [
        'token' => trim((string)($sb['token'] ?? '')),
        'secret' => trim((string)($sb['secret'] ?? '')),
        'api_base' => $apiBase,
        'timezone' => $timezone,
        'timeout' => $timeout,
        'keypads' => $keypads,
        'storage_dir' => $storageDir,
        'detail_dir' => trim((string)($sb['detail_dir'] ?? '')),
        'request_table' => trim((string)($sb['request_table'] ?? 'switchbot_passcode_requests')),
        'webhook_secret' => trim((string)($sb['webhook_secret'] ?? '')),
        'webhook_url' => trim((string)($sb['webhook_url'] ?? '')),
        'webhook_path' => trim((string)($sb['webhook_path'] ?? '')),
    ];
}

function switchbot_is_configured(array $cfg): bool
{
    $sb = switchbot_config($cfg);
    return $sb['token'] !== '' && $sb['secret'] !== '';
}


function switchbot_log_context_value(mixed $value, int $depth = 0): mixed
{
    if ($depth > 6) {
        return '[depth-limit]';
    }

    if (is_array($value)) {
        $result = [];
        foreach ($value as $key => $item) {
            $normalizedKey = is_string($key) ? strtolower($key) : $key;
            if (is_string($normalizedKey) && in_array($normalizedKey, ['authorization', 'token', 'secret', 'sign', 'password'], true)) {
                $result[$key] = '[redacted]';
                continue;
            }
            $result[$key] = switchbot_log_context_value($item, $depth + 1);
        }
        return $result;
    }

    if (is_object($value)) {
        return switchbot_log_context_value((array)$value, $depth + 1);
    }

    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        if (mb_strlen($trimmed, 'UTF-8') > 1200) {
            return mb_substr($trimmed, 0, 1200, 'UTF-8') . ' ...(truncated)';
        }
        return $trimmed;
    }

    return $value;
}

function switchbot_error_log(string $message, array $context = []): void
{
    $normalized = [];
    foreach ($context as $key => $value) {
        $normalized[$key] = switchbot_log_context_value($value);
    }

    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = '{"context_encode_error":true}';
    }

    error_log('[switchbot_api] ' . $message . ' | ' . $json);
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
    $requestMethod = strtoupper($method);

    $ch = curl_init($url);
    if ($ch === false) {
        switchbot_error_log('cURL initialization failed', [
            'method' => $requestMethod,
            'url' => $url,
            'path' => $path,
        ]);
        throw new RuntimeException('cURL を初期化できませんでした。');
    }

    $headers = switchbot_headers($cfg);
    $headers[] = 'Accept: application/json';

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $requestMethod,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $sb['timeout'],
        CURLOPT_CONNECTTIMEOUT => min(10, $sb['timeout']),
    ];

    $requestPayloadJson = null;
    if ($payload !== null) {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            switchbot_error_log('Failed to encode request payload as JSON', [
                'method' => $requestMethod,
                'url' => $url,
                'path' => $path,
                'payload' => $payload,
            ]);
            throw new RuntimeException('SwitchBot 送信用 JSON の生成に失敗しました。');
        }
        $requestPayloadJson = $json;
        $opts[CURLOPT_POSTFIELDS] = $json;
    }

    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $curlErrno = curl_errno($ch);
        $message = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        switchbot_error_log('SwitchBot API request failed at curl_exec', [
            'method' => $requestMethod,
            'url' => $url,
            'path' => $path,
            'http_code' => $httpCode,
            'curl_errno' => $curlErrno,
            'curl_error' => $message,
            'payload' => $payload,
            'payload_json' => $requestPayloadJson,
        ]);
        throw new RuntimeException('SwitchBot API 通信に失敗しました: ' . $message);
    }

    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $snippet = trim(mb_substr((string)$raw, 0, 1000, 'UTF-8'));
        if ($snippet === '') {
            $snippet = '(empty response)';
        }
        switchbot_error_log('SwitchBot API response was not valid JSON', [
            'method' => $requestMethod,
            'url' => $url,
            'path' => $path,
            'http_code' => $httpCode,
            'payload' => $payload,
            'payload_json' => $requestPayloadJson,
            'raw_response' => $snippet,
        ]);
        throw new RuntimeException('SwitchBot API の応答を JSON として解釈できませんでした。HTTP ' . $httpCode . ' / ' . $url . ' / ' . $snippet);
    }

    $decoded['_http_code'] = $httpCode;
    $decoded['_request_url'] = $url;
    $decoded['_request_method'] = $requestMethod;

    $statusCode = (int)($decoded['statusCode'] ?? 0);
    if ($httpCode >= 400 || ($statusCode !== 0 && $statusCode !== 100)) {
        switchbot_error_log('SwitchBot API returned an error response', [
            'method' => $requestMethod,
            'url' => $url,
            'path' => $path,
            'http_code' => $httpCode,
            'status_code' => $statusCode,
            'message' => (string)($decoded['message'] ?? ''),
            'payload' => $payload,
            'payload_json' => $requestPayloadJson,
            'response' => $decoded,
        ]);
    }

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

function switchbot_setup_webhook(array $cfg, string $url): array
{
    return switchbot_request($cfg, 'POST', 'webhook/setupWebhook', [
        'action' => 'setupWebhook',
        'url' => $url,
        'deviceList' => 'ALL',
    ]);
}

function switchbot_query_webhook_urls(array $cfg): array
{
    $result = switchbot_request($cfg, 'POST', 'webhook/queryWebhook', [
        'action' => 'queryUrl',
    ]);

    $statusCode = (int)($result['statusCode'] ?? 0);
    if ($statusCode !== 100) {
        $message = (string)($result['message'] ?? 'SwitchBot webhook query error');
        throw new RuntimeException('SwitchBot webhook URL 一覧の取得に失敗しました: ' . $message);
    }

    $urls = $result['body']['urls'] ?? [];
    return is_array($urls) ? array_values(array_filter(array_map('strval', $urls), static fn(string $v): bool => $v !== '')) : [];
}

function switchbot_query_webhook_details(array $cfg, array $urls): array
{
    $urls = array_values(array_filter(array_map('strval', $urls), static fn(string $v): bool => $v !== ''));
    if ($urls === []) {
        return [];
    }

    $details = [];
    foreach ($urls as $url) {
        $result = switchbot_request($cfg, 'POST', 'webhook/queryWebhook', [
            'action' => 'queryDetails',
            'urls' => [$url],
        ]);

        $statusCode = (int)($result['statusCode'] ?? 0);
        if ($statusCode !== 100) {
            $message = (string)($result['message'] ?? 'SwitchBot webhook detail error');
            throw new RuntimeException('SwitchBot webhook 詳細の取得に失敗しました: ' . $message . ' / ' . $url);
        }

        $body = $result['body'] ?? [];
        if (is_array($body)) {
            foreach ($body as $item) {
                if (is_array($item)) {
                    $details[] = $item;
                }
            }
        }
    }

    return $details;
}

function switchbot_update_webhook(array $cfg, string $url, bool $enable): array
{
    return switchbot_request($cfg, 'POST', 'webhook/updateWebhook', [
        'action' => 'updateWebhook',
        'config' => [
            'url' => $url,
            'enable' => $enable,
        ],
    ]);
}

function switchbot_delete_webhook(array $cfg, string $url): array
{
    return switchbot_request($cfg, 'POST', 'webhook/deleteWebhook', [
        'action' => 'deleteWebhook',
        'url' => $url,
    ]);
}

function switchbot_expect_success(array $result, string $context): void
{
    $statusCode = (int)($result['statusCode'] ?? 0);
    if ($statusCode !== 100) {
        $message = trim((string)($result['message'] ?? 'SwitchBot API error'));
        $httpCode = (int)($result['_http_code'] ?? 0);
        $requestUrl = (string)($result['_request_url'] ?? '');
        $parts = [$context . ': ' . ($message !== '' ? $message : 'SwitchBot API error')];
        if ($httpCode > 0) {
            $parts[] = 'HTTP ' . $httpCode;
        }
        if ($requestUrl !== '') {
            $parts[] = $requestUrl;
        }
        throw new RuntimeException(implode(' / ', $parts));
    }
}

function switchbot_resolve_webhook_url(array $cfg, array $server): ?string
{
    $sb = switchbot_config($cfg);
    if ($sb['webhook_url'] !== '') {
        return $sb['webhook_url'];
    }

    $host = trim((string)($server['HTTP_X_FORWARDED_HOST'] ?? ''));
    if ($host !== '' && str_contains($host, ',')) {
        $host = trim(explode(',', $host)[0]);
    }
    if ($host === '') {
        $host = trim((string)($server['HTTP_HOST'] ?? ''));
    }
    if ($host === '') {
        return null;
    }

    $forwardedProto = trim((string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwardedProto !== '' && str_contains($forwardedProto, ',')) {
        $forwardedProto = trim(explode(',', $forwardedProto)[0]);
    }

    $scheme = 'http';
    $https = strtolower(trim((string)($server['HTTPS'] ?? '')));
    $requestScheme = strtolower(trim((string)($server['REQUEST_SCHEME'] ?? '')));
    if ($forwardedProto !== '') {
        $scheme = strtolower($forwardedProto) === 'https' ? 'https' : 'http';
    } elseif ($requestScheme !== '') {
        $scheme = $requestScheme === 'https' ? 'https' : 'http';
    } elseif ($https !== '' && $https !== 'off' && $https !== '0') {
        $scheme = 'https';
    } elseif ((string)($server['SERVER_PORT'] ?? '') === '443') {
        $scheme = 'https';
    }

    $scriptName = str_replace('\\', '/', (string)($server['SCRIPT_NAME'] ?? ''));
    $baseDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/.');
    if ($baseDir === '/' || $baseDir === '.') {
        $baseDir = '';
    }

    $path = $sb['webhook_path'];
    if ($path === '') {
        $path = ($baseDir !== '' ? $baseDir : '') . '/switchbot_webhook.php';
    } elseif ($path[0] !== '/') {
        $path = ($baseDir !== '' ? $baseDir . '/' : '/') . ltrim($path, '/');
    }

    $url = $scheme . '://' . $host . $path;
    if ($sb['webhook_secret'] !== '') {
        $glue = str_contains($url, '?') ? '&' : '?';
        $url .= $glue . 'token=' . rawurlencode($sb['webhook_secret']);
    }

    return $url;
}

function switchbot_basic_webhook_overview(array $cfg, array $server): array
{
    $suggestedUrl = switchbot_resolve_webhook_url($cfg, $server);
    $sb = switchbot_config($cfg);

    return [
        'suggested_url' => $suggestedUrl,
        'current_url_registered' => false,
        'current_url_enabled' => null,
        'configured_urls' => [],
        'details' => [],
        'matched_url_detail' => null,
        'query_error' => null,
        'webhook_secret_configured' => $sb['webhook_secret'] !== '',
        'storage_dir' => switchbot_storage_dir($cfg),
    ];
}

function switchbot_get_webhook_overview(array $cfg, array $server): array
{
    $overview = switchbot_basic_webhook_overview($cfg, $server);
    if (!switchbot_is_configured($cfg)) {
        return $overview;
    }

    $urls = switchbot_query_webhook_urls($cfg);
    $details = switchbot_query_webhook_details($cfg, $urls);
    $overview['configured_urls'] = $urls;
    $overview['details'] = array_map(static function (array $detail): array {
        return [
            'url' => (string)($detail['url'] ?? ''),
            'create_time' => $detail['createTime'] ?? null,
            'last_update_time' => $detail['lastUpdateTime'] ?? null,
            'device_list' => (string)($detail['deviceList'] ?? ''),
            'enable' => array_key_exists('enable', $detail) ? (bool)$detail['enable'] : null,
        ];
    }, $details);

    $suggestedUrl = $overview['suggested_url'];
    if (is_string($suggestedUrl) && $suggestedUrl !== '') {
        foreach ($overview['details'] as $detail) {
            if ((string)($detail['url'] ?? '') === $suggestedUrl) {
                $overview['current_url_registered'] = true;
                $overview['current_url_enabled'] = array_key_exists('enable', $detail) ? (bool)$detail['enable'] : null;
                $overview['matched_url_detail'] = $detail;
                break;
            }
        }
        if (!$overview['current_url_registered'] && in_array($suggestedUrl, $urls, true)) {
            $overview['current_url_registered'] = true;
        }
    }

    return $overview;
}

function switchbot_try_get_webhook_overview(array $cfg, array $server): array
{
    $overview = switchbot_basic_webhook_overview($cfg, $server);
    if (!switchbot_is_configured($cfg)) {
        return $overview;
    }

    try {
        return switchbot_get_webhook_overview($cfg, $server);
    } catch (Throwable $e) {
        $overview['query_error'] = $e->getMessage();
        return $overview;
    }
}

function switchbot_sync_webhook_to_current_url(array $cfg, array $server): array
{
    $url = switchbot_resolve_webhook_url($cfg, $server);
    if (!is_string($url) || $url === '') {
        throw new RuntimeException('Webhook 用の公開 URL を生成できませんでした。config.php の switchbot.webhook_url を明示設定してください。');
    }

    // queryWebhook は statusCode 190 / 空 message で失敗する環境があるため、
    // 同期時は照会を前提にせず、まず setup を試し、必要なら update にフォールバックする。
    try {
        switchbot_expect_success(switchbot_setup_webhook($cfg, $url), 'SwitchBot webhook 登録に失敗しました');
    } catch (Throwable $setupError) {
        try {
            switchbot_expect_success(switchbot_update_webhook($cfg, $url, true), 'SwitchBot webhook 更新に失敗しました');
        } catch (Throwable $updateError) {
            throw new RuntimeException(
                'SwitchBot webhook の登録・更新の両方に失敗しました。'
                . ' setup=' . $setupError->getMessage()
                . ' / update=' . $updateError->getMessage()
            );
        }
    }

    $overview = switchbot_try_get_webhook_overview($cfg, $server);
    $overview['current_url_registered'] = true;
    $overview['current_url_enabled'] = true;
    if (!in_array($url, $overview['configured_urls'], true)) {
        $overview['configured_urls'][] = $url;
    }

    return $overview;
}

function switchbot_set_webhook_enabled_for_current_url(array $cfg, array $server, bool $enable): array
{
    $url = switchbot_resolve_webhook_url($cfg, $server);
    if (!is_string($url) || $url === '') {
        throw new RuntimeException('Webhook 用の公開 URL を生成できませんでした。');
    }

    if ($enable) {
        try {
            switchbot_expect_success(switchbot_setup_webhook($cfg, $url), 'SwitchBot webhook 登録に失敗しました');
        } catch (Throwable $setupError) {
            switchbot_expect_success(switchbot_update_webhook($cfg, $url, true), 'SwitchBot webhook 状態更新に失敗しました');
        }
    } else {
        switchbot_expect_success(switchbot_update_webhook($cfg, $url, false), 'SwitchBot webhook 状態更新に失敗しました');
    }

    $overview = switchbot_try_get_webhook_overview($cfg, $server);
    $overview['current_url_registered'] = $enable ? true : $overview['current_url_registered'];
    $overview['current_url_enabled'] = $enable;
    if ($enable && !in_array($url, $overview['configured_urls'], true)) {
        $overview['configured_urls'][] = $url;
    }

    return $overview;
}

function switchbot_db_connect(array $cfg): PDO
{
    $db = $cfg['db'] ?? null;
    if (!is_array($db)) {
        throw new RuntimeException('config.php に db 設定がありません。');
    }

    $dsn = trim((string)($db['dsn'] ?? ''));
    $user = (string)($db['user'] ?? '');
    $password = (string)($db['password'] ?? '');
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

function switchbot_request_table_name(array $cfg): string
{
    $name = trim((string)(switchbot_config($cfg)['request_table'] ?? 'switchbot_passcode_requests'));
    if ($name === '') {
        $name = 'switchbot_passcode_requests';
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('switchbot.request_table の値が不正です。');
    }
    return $name;
}

function switchbot_detail_dir(array $cfg): string
{
    $sb = switchbot_config($cfg);
    $detailDir = trim((string)($sb['detail_dir'] ?? ''));
    if ($detailDir !== '') {
        return $detailDir;
    }
    return rtrim($sb['storage_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'requests';
}

function switchbot_ensure_detail_dir(array $cfg): string
{
    $dir = switchbot_detail_dir($cfg);
    if ($dir === '') {
        throw new RuntimeException('SwitchBot 詳細保存先ディレクトリを特定できません。');
    }
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('SwitchBot 詳細保存先ディレクトリを作成できませんでした: ' . $dir);
    }
    return $dir;
}

function switchbot_generate_local_request_id(): string
{
    return 'sbreq_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6));
}

function switchbot_normalize_datetime_string(string $value, string $timezone): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, new DateTimeZone($timezone));
    if ($dt && $dt->format('Y-m-d\TH:i') === $value) {
        return $dt->format('Y-m-d H:i:s');
    }

    $fallback = new DateTimeImmutable($value, new DateTimeZone($timezone));
    return $fallback->format('Y-m-d H:i:s');
}

function switchbot_safe_json_value(mixed $value, int $depth = 0): mixed
{
    if ($depth > 8) {
        return '[depth-limit]';
    }

    if (is_array($value)) {
        $out = [];
        foreach ($value as $key => $item) {
            $normalizedKey = is_string($key) ? strtolower($key) : $key;
            if (is_string($normalizedKey) && in_array($normalizedKey, ['password', 'secret', 'token', 'authorization', 'sign'], true)) {
                $out[$key] = '[redacted]';
                continue;
            }
            $out[$key] = switchbot_safe_json_value($item, $depth + 1);
        }
        return $out;
    }

    if (is_object($value)) {
        return switchbot_safe_json_value((array)$value, $depth + 1);
    }

    return $value;
}

function switchbot_write_request_detail_json(array $cfg, string $localRequestId, array $detail): string
{
    if ($localRequestId === '') {
        throw new RuntimeException('local_request_id が空のため詳細 JSON を保存できません。');
    }

    $dir = switchbot_ensure_detail_dir($cfg);
    $path = $dir . DIRECTORY_SEPARATOR . $localRequestId . '.json';
    $json = json_encode(switchbot_safe_json_value($detail), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('SwitchBot 詳細 JSON の生成に失敗しました。');
    }
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException('SwitchBot 詳細 JSON を保存できませんでした。');
    }

    $storageDir = rtrim(switchbot_storage_dir($cfg), DIRECTORY_SEPARATOR);
    $relative = str_starts_with($path, $storageDir)
        ? ltrim(str_replace('\\', '/', substr($path, strlen($storageDir))), '/')
        : basename($path);

    return $relative !== '' ? ('requests/' . basename($path)) : ('requests/' . basename($path));
}

function switchbot_db_upsert_request(array $cfg, array $record): array
{
    $pdo = switchbot_db_connect($cfg);
    $table = switchbot_request_table_name($cfg);
    $timezone = switchbot_config($cfg)['timezone'];

    $requestedAt = (string)($record['requested_at'] ?? switchbot_now_string($cfg));
    $updatedAt = (string)($record['updated_at'] ?? $requestedAt);
    $startAt = trim((string)($record['start_at'] ?? ''));
    $endAt = trim((string)($record['end_at'] ?? ''));

    $sql = "INSERT INTO `{$table}` (
        local_request_id, command_id, room_code, room_label, device_id, device_name,
        passcode_name, passcode, start_at, end_at, status, result,
        requested_at, updated_at, webhook_received_at, detail_json_path,
        event_name, event_device_type, event_device_mac, time_of_sample
    ) VALUES (
        :local_request_id, :command_id, :room_code, :room_label, :device_id, :device_name,
        :passcode_name, :passcode, :start_at, :end_at, :status, :result,
        :requested_at, :updated_at, :webhook_received_at, :detail_json_path,
        :event_name, :event_device_type, :event_device_mac, :time_of_sample
    )
    ON DUPLICATE KEY UPDATE
        command_id = VALUES(command_id),
        room_code = VALUES(room_code),
        room_label = VALUES(room_label),
        device_id = VALUES(device_id),
        device_name = VALUES(device_name),
        passcode_name = VALUES(passcode_name),
        passcode = VALUES(passcode),
        start_at = VALUES(start_at),
        end_at = VALUES(end_at),
        status = VALUES(status),
        result = VALUES(result),
        requested_at = VALUES(requested_at),
        updated_at = VALUES(updated_at),
        webhook_received_at = VALUES(webhook_received_at),
        detail_json_path = VALUES(detail_json_path),
        event_name = VALUES(event_name),
        event_device_type = VALUES(event_device_type),
        event_device_mac = VALUES(event_device_mac),
        time_of_sample = VALUES(time_of_sample),
        id = LAST_INSERT_ID(id)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':local_request_id' => (string)($record['local_request_id'] ?? ''),
        ':command_id' => (($record['command_id'] ?? '') !== '' ? (string)$record['command_id'] : null),
        ':room_code' => (string)($record['room_code'] ?? ''),
        ':room_label' => (string)($record['room_label'] ?? ''),
        ':device_id' => (string)($record['device_id'] ?? ''),
        ':device_name' => (($record['device_name'] ?? '') !== '' ? (string)$record['device_name'] : null),
        ':passcode_name' => (string)($record['passcode_name'] ?? ''),
        ':passcode' => (($record['passcode'] ?? '') !== '' ? (string)$record['passcode'] : null),
        ':start_at' => ($startAt !== '' ? switchbot_normalize_datetime_string($startAt, $timezone) : null),
        ':end_at' => ($endAt !== '' ? switchbot_normalize_datetime_string($endAt, $timezone) : null),
        ':status' => (string)($record['status'] ?? 'accepted'),
        ':result' => (($record['result'] ?? '') !== '' ? (string)$record['result'] : null),
        ':requested_at' => $requestedAt,
        ':updated_at' => $updatedAt,
        ':webhook_received_at' => (($record['webhook_received_at'] ?? '') !== '' ? (string)$record['webhook_received_at'] : null),
        ':detail_json_path' => (($record['detail_json_path'] ?? '') !== '' ? (string)$record['detail_json_path'] : null),
        ':event_name' => (($record['event_name'] ?? '') !== '' ? (string)$record['event_name'] : null),
        ':event_device_type' => (($record['event_device_type'] ?? '') !== '' ? (string)$record['event_device_type'] : null),
        ':event_device_mac' => (($record['event_device_mac'] ?? '') !== '' ? (string)$record['event_device_mac'] : null),
        ':time_of_sample' => ($record['time_of_sample'] ?? null),
    ]);

    $record['id'] = (int)$pdo->lastInsertId();
    return $record;
}

function switchbot_upsert_request_record(array $cfg, array $record, array $detail = []): array
{
    $now = switchbot_now_string($cfg);
    $record['local_request_id'] = trim((string)($record['local_request_id'] ?? ''));
    if ($record['local_request_id'] === '') {
        $record['local_request_id'] = switchbot_generate_local_request_id();
    }
    $record['requested_at'] = (string)($record['requested_at'] ?? $now);
    $record['updated_at'] = (string)($record['updated_at'] ?? $now);
    $record['status'] = (string)($record['status'] ?? 'accepted');

    $detailPayload = $detail;
    $detailPayload['local_request_id'] = $record['local_request_id'];
    $detailPayload['updated_at'] = $record['updated_at'];
    $record['detail_json_path'] = switchbot_write_request_detail_json($cfg, $record['local_request_id'], $detailPayload);
    $record = switchbot_record_command_request($cfg, $record);
    $record = switchbot_db_upsert_request($cfg, $record);
    return $record;
}

function switchbot_find_record_index_by_local_request_id(array $records, string $localRequestId): ?int
{
    if ($localRequestId === '') {
        return null;
    }
    foreach ($records as $idx => $record) {
        if ((string)($record['local_request_id'] ?? '') === $localRequestId) {
            return $idx;
        }
    }
    return null;
}

function switchbot_guess_record_index_for_create_key_event(array $records): ?int
{
    foreach ($records as $idx => $record) {
        $status = (string)($record['status'] ?? '');
        $commandId = trim((string)($record['command_id'] ?? ''));
        $webhookAt = trim((string)($record['webhook_received_at'] ?? ''));
        if ($commandId === '' && $webhookAt === '' && in_array($status, ['queued', 'accepted', 'webhook_received'], true)) {
            return $idx;
        }
    }
    return null;
}

function switchbot_storage_dir(array $cfg): string
{
    return switchbot_config($cfg)['storage_dir'];
}

function switchbot_ensure_storage_dir(array $cfg): string
{
    $dir = switchbot_storage_dir($cfg);
    if ($dir === '') {
        throw new RuntimeException('SwitchBot の保存先ディレクトリを特定できません。');
    }
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('SwitchBot 保存先ディレクトリを作成できませんでした: ' . $dir);
    }
    return $dir;
}

function switchbot_storage_path(array $cfg, string $filename): string
{
    return rtrim(switchbot_ensure_storage_dir($cfg), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($filename, DIRECTORY_SEPARATOR);
}

function switchbot_now_string(array $cfg): string
{
    $timezone = switchbot_config($cfg)['timezone'];
    return (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d H:i:s');
}

function switchbot_read_command_records(array $cfg): array
{
    $path = switchbot_storage_path($cfg, 'command_store.json');
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
}

function switchbot_write_command_records(array $cfg, array $records): void
{
    $path = switchbot_storage_path($cfg, 'command_store.json');
    $json = json_encode(array_values($records), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('SwitchBot command store JSON の生成に失敗しました。');
    }

    $fp = fopen($path, 'c+');
    if ($fp === false) {
        throw new RuntimeException('SwitchBot command store を開けませんでした。');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('SwitchBot command store のロックに失敗しました。');
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }
}

function switchbot_record_command_request(array $cfg, array $record): array
{
    $records = switchbot_read_command_records($cfg);
    $now = switchbot_now_string($cfg);
    $record['requested_at'] = (string)($record['requested_at'] ?? $now);
    $record['updated_at'] = (string)($record['updated_at'] ?? $now);
    $record['status'] = (string)($record['status'] ?? 'accepted');

    $localRequestId = trim((string)($record['local_request_id'] ?? ''));
    $commandId = trim((string)($record['command_id'] ?? ''));
    $replaced = false;

    $localIndex = switchbot_find_record_index_by_local_request_id($records, $localRequestId);
    if ($localIndex !== null) {
        $records[$localIndex] = array_merge($records[$localIndex], $record, ['updated_at' => $now]);
        $record = $records[$localIndex];
        $replaced = true;
    }

    if (!$replaced && $commandId !== '') {
        foreach ($records as $idx => $existing) {
            if ((string)($existing['command_id'] ?? '') === $commandId) {
                $records[$idx] = array_merge($existing, $record, ['updated_at' => $now]);
                $record = $records[$idx];
                $replaced = true;
                break;
            }
        }
    }

    if (!$replaced) {
        array_unshift($records, $record);
    }

    $records = array_slice(array_values($records), 0, 300);
    switchbot_write_command_records($cfg, $records);
    return $record;
}

function switchbot_append_webhook_event(array $cfg, array $entry): void
{
    $path = switchbot_storage_path($cfg, 'webhook_events.jsonl');
    $fp = fopen($path, 'ab');
    if ($fp === false) {
        throw new RuntimeException('SwitchBot webhook event log を開けませんでした。');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('SwitchBot webhook event log のロックに失敗しました。');
        }
        $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('SwitchBot webhook event log JSON の生成に失敗しました。');
        }
        fwrite($fp, $json . PHP_EOL);
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }
}

function switchbot_is_passcode_history_record(array $record): bool
{
    if (trim((string)($record['room_code'] ?? '')) !== '') {
        return true;
    }
    if (trim((string)($record['room_label'] ?? '')) !== '') {
        return true;
    }
    if (trim((string)($record['passcode_name'] ?? '')) !== '') {
        return true;
    }
    if (trim((string)($record['start_at'] ?? '')) !== '' || trim((string)($record['end_at'] ?? '')) !== '') {
        return true;
    }
    return trim((string)($record['event_name'] ?? '')) === 'createKey';
}

function switchbot_apply_webhook_event_to_store(array $cfg, array $event): ?array
{
    $context = $event['context'] ?? [];
    if (!is_array($context)) {
        $context = [];
    }

    $records = switchbot_read_command_records($cfg);
    $now = switchbot_now_string($cfg);
    $result = strtolower(trim((string)($context['result'] ?? '')));
    $status = $result === 'success' ? 'success' : ($result !== '' ? 'error' : 'webhook_received');
    $commandId = trim((string)($context['commandId'] ?? ''));
    $eventName = trim((string)($context['eventName'] ?? ''));
    $updatedRecord = null;
    $targetIndex = null;

    if ($commandId !== '') {
        foreach ($records as $idx => $record) {
            if ((string)($record['command_id'] ?? '') === $commandId) {
                $targetIndex = $idx;
                break;
            }
        }
    }

    if ($targetIndex === null && $eventName === 'createKey') {
        $targetIndex = switchbot_guess_record_index_for_create_key_event($records);
    }

    if ($targetIndex === null && $eventName !== 'createKey') {
        return null;
    }

    if ($targetIndex !== null) {
        $record = $records[$targetIndex];
        $records[$targetIndex] = array_merge($record, [
            'command_id' => $commandId !== '' ? $commandId : (string)($record['command_id'] ?? ''),
            'event_name' => $eventName,
            'event_device_type' => trim((string)($context['deviceType'] ?? '')),
            'event_device_mac' => trim((string)($context['deviceMac'] ?? '')),
            'result' => $result,
            'time_of_sample' => $context['timeOfSample'] ?? null,
            'webhook_received_at' => $now,
            'updated_at' => $now,
            'status' => $status,
        ]);
        $updatedRecord = $records[$targetIndex];
    } else {
        $updatedRecord = [
            'local_request_id' => switchbot_generate_local_request_id(),
            'command_id' => $commandId,
            'room_code' => '',
            'room_label' => '',
            'device_id' => '',
            'device_name' => '',
            'passcode_name' => '',
            'passcode' => '',
            'start_at' => '',
            'end_at' => '',
            'requested_at' => $now,
            'event_name' => $eventName,
            'event_device_type' => trim((string)($context['deviceType'] ?? '')),
            'event_device_mac' => trim((string)($context['deviceMac'] ?? '')),
            'result' => $result,
            'time_of_sample' => $context['timeOfSample'] ?? null,
            'webhook_received_at' => $now,
            'updated_at' => $now,
            'status' => $status,
        ];
        array_unshift($records, $updatedRecord);
    }

    $records = array_values(array_filter($records, 'switchbot_is_passcode_history_record'));
    $records = array_slice($records, 0, 300);
    switchbot_write_command_records($cfg, $records);
    $updatedRecord = switchbot_db_upsert_request($cfg, $updatedRecord);
    $detail = [
        'local_request_id' => (string)($updatedRecord['local_request_id'] ?? ''),
        'command_id' => (string)($updatedRecord['command_id'] ?? ''),
        'status' => (string)($updatedRecord['status'] ?? ''),
        'webhook_received_at' => (string)($updatedRecord['webhook_received_at'] ?? ''),
        'event' => $event,
    ];
    $updatedRecord['detail_json_path'] = switchbot_write_request_detail_json($cfg, (string)$updatedRecord['local_request_id'], $detail);
    $updatedRecord = switchbot_record_command_request($cfg, $updatedRecord);
    $updatedRecord = switchbot_db_upsert_request($cfg, $updatedRecord);
    return $updatedRecord;
}

function switchbot_list_recent_commands(array $cfg, int $limit = 20): array
{
    $records = array_values(array_filter(switchbot_read_command_records($cfg), 'switchbot_is_passcode_history_record'));
    usort($records, static function (array $a, array $b): int {
        return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
    });

    return array_slice(array_map(static function (array $record): array {
        return [
            'id' => (int)($record['id'] ?? 0),
            'local_request_id' => (string)($record['local_request_id'] ?? ''),
            'command_id' => (string)($record['command_id'] ?? ''),
            'room_code' => (string)($record['room_code'] ?? ''),
            'room_label' => (string)($record['room_label'] ?? ''),
            'device_id' => (string)($record['device_id'] ?? ''),
            'device_name' => (string)($record['device_name'] ?? ''),
            'passcode_name' => (string)($record['passcode_name'] ?? ''),
            'start_at' => (string)($record['start_at'] ?? ''),
            'end_at' => (string)($record['end_at'] ?? ''),
            'requested_at' => (string)($record['requested_at'] ?? ''),
            'updated_at' => (string)($record['updated_at'] ?? ''),
            'webhook_received_at' => (string)($record['webhook_received_at'] ?? ''),
            'detail_json_path' => (string)($record['detail_json_path'] ?? ''),
            'status' => (string)($record['status'] ?? 'accepted'),
            'result' => (string)($record['result'] ?? ''),
            'event_name' => (string)($record['event_name'] ?? ''),
            'event_device_type' => (string)($record['event_device_type'] ?? ''),
            'event_device_mac' => (string)($record['event_device_mac'] ?? ''),
            'time_of_sample' => $record['time_of_sample'] ?? null,
        ];
    }, $records), 0, max(1, $limit));
}

function switchbot_find_request_row(array $cfg, string $localRequestId = '', int $id = 0): ?array
{
    try {
        $pdo = switchbot_db_connect($cfg);
    } catch (Throwable $e) {
        return null;
    }

    $table = switchbot_request_table_name($cfg);
    if ($localRequestId !== '') {
        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE local_request_id = :local_request_id LIMIT 1");
        $stmt->execute([':local_request_id' => $localRequestId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    return null;
}

function switchbot_find_command_record(array $cfg, string $localRequestId = '', int $id = 0): ?array
{
    $records = switchbot_read_command_records($cfg);

    if ($localRequestId !== '') {
        foreach ($records as $record) {
            if ((string)($record['local_request_id'] ?? '') === $localRequestId) {
                return is_array($record) ? $record : null;
            }
        }
    }

    if ($id > 0) {
        foreach ($records as $record) {
            if ((int)($record['id'] ?? 0) === $id) {
                return is_array($record) ? $record : null;
            }
        }
    }

    return null;
}

function switchbot_resolve_detail_json_absolute_path(array $cfg, string $detailJsonPath = ''): ?string
{
    $relative = trim($detailJsonPath);
    if ($relative === '') {
        return null;
    }

    $storageDir = realpath(switchbot_ensure_storage_dir($cfg));
    if ($storageDir === false) {
        return null;
    }

    $candidate = rtrim($storageDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim(str_replace(['\\', '..'], ['/', ''], $relative), '/');
    if (!is_file($candidate)) {
        return null;
    }

    $realCandidate = realpath($candidate);
    if ($realCandidate === false) {
        return null;
    }

    $prefix = rtrim(str_replace('\\', '/', $storageDir), '/');
    $normalizedCandidate = str_replace('\\', '/', $realCandidate);
    if (!str_starts_with($normalizedCandidate, $prefix . '/')) {
        return null;
    }

    return $realCandidate;
}

function switchbot_read_request_detail_json(array $cfg, string $detailJsonPath = '', string $localRequestId = ''): ?array
{
    $path = switchbot_resolve_detail_json_absolute_path($cfg, $detailJsonPath);
    if ($path === null && $localRequestId !== '') {
        $candidate = switchbot_ensure_detail_dir($cfg) . DIRECTORY_SEPARATOR . $localRequestId . '.json';
        if (is_file($candidate)) {
            $path = $candidate;
        }
    }

    if ($path === null || !is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('SwitchBot 詳細 JSON を読み込めませんでした。');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            '_raw' => $raw,
            '_decode_error' => true,
        ];
    }

    return $decoded;
}

function switchbot_get_request_detail(array $cfg, string $localRequestId = '', int $id = 0): ?array
{
    $dbRow = switchbot_find_request_row($cfg, $localRequestId, $id);
    $record = $dbRow;

    if (!is_array($record)) {
        $record = switchbot_find_command_record($cfg, $localRequestId, $id);
        if (!is_array($record)) {
            return null;
        }
    }

    $detailJsonPath = trim((string)($record['detail_json_path'] ?? ''));
    $effectiveLocalRequestId = trim((string)($record['local_request_id'] ?? ''));
    $detailJson = switchbot_read_request_detail_json($cfg, $detailJsonPath, $effectiveLocalRequestId);

    return [
        'record' => [
            'id' => (int)($record['id'] ?? 0),
            'local_request_id' => (string)($record['local_request_id'] ?? ''),
            'command_id' => (string)($record['command_id'] ?? ''),
            'room_code' => (string)($record['room_code'] ?? ''),
            'room_label' => (string)($record['room_label'] ?? ''),
            'device_id' => (string)($record['device_id'] ?? ''),
            'device_name' => (string)($record['device_name'] ?? ''),
            'passcode_name' => (string)($record['passcode_name'] ?? ''),
            'passcode' => (string)($record['passcode'] ?? ''),
            'start_at' => (string)($record['start_at'] ?? ''),
            'end_at' => (string)($record['end_at'] ?? ''),
            'status' => (string)($record['status'] ?? ''),
            'result' => (string)($record['result'] ?? ''),
            'requested_at' => (string)($record['requested_at'] ?? ''),
            'updated_at' => (string)($record['updated_at'] ?? ''),
            'webhook_received_at' => (string)($record['webhook_received_at'] ?? ''),
            'detail_json_path' => $detailJsonPath,
            'event_name' => (string)($record['event_name'] ?? ''),
            'event_device_type' => (string)($record['event_device_type'] ?? ''),
            'event_device_mac' => (string)($record['event_device_mac'] ?? ''),
            'time_of_sample' => $record['time_of_sample'] ?? null,
        ],
        'detail_json' => $detailJson,
        'detail_json_path' => $detailJsonPath,
        'detail_json_exists' => is_array($detailJson),
    ];
}

function switchbot_validate_webhook_secret(array $cfg, ?string $providedToken): bool
{
    $expected = switchbot_config($cfg)['webhook_secret'];
    if ($expected === '') {
        return true;
    }
    if ($providedToken === null) {
        return false;
    }
    return hash_equals($expected, $providedToken);
}
