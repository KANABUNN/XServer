<?php

declare(strict_types=1);

foreach ([__DIR__ . '/../../apps/switchbot_api.php', __DIR__ . '/../apps/switchbot_api.php', __DIR__ . '/apps/switchbot_api.php'] as $__switchbotHelper) {
    if (is_file($__switchbotHelper)) {
        require_once $__switchbotHelper;
        break;
    }
}

try {
    $cfg = load_switchbot_webhook_config();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        respond_json(['ok' => false, 'message' => 'POST only'], 405);
    }

    $providedToken = isset($_GET['token']) ? (string)$_GET['token'] : null;
    if (!switchbot_validate_webhook_secret($cfg, $providedToken)) {
        respond_json(['ok' => false, 'message' => 'invalid token'], 403);
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($payload)) {
        respond_json(['ok' => false, 'message' => 'invalid json'], 400);
    }

    $receivedAt = switchbot_now_string($cfg);
    switchbot_append_webhook_event($cfg, [
        'received_at' => $receivedAt,
        'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'payload' => $payload,
    ]);

    $updated = switchbot_apply_webhook_event_to_store($cfg, $payload);

    respond_json([
        'ok' => true,
        'updated' => $updated !== null,
        'received_at' => $receivedAt,
    ]);
} catch (Throwable $e) {
    error_log('[switchbot_webhook] ' . $e->getMessage());
    error_log('[switchbot_webhook] ' . $e->getFile() . ':' . $e->getLine());
    respond_json(['ok' => false, 'message' => 'internal error'], 500);
}

function load_switchbot_webhook_config(): array
{
    $candidates = [
        __DIR__ . '/../../apps/config.php',
        __DIR__ . '/../apps/config.php',
        __DIR__ . '/apps/config.php',
    ];

    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }

        $cfg = require $path;
        if (!is_array($cfg)) {
            throw new RuntimeException('config.php が配列を返していません。');
        }
        return $cfg;
    }

    throw new RuntimeException('config.php が見つかりません。');
}

function respond_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
