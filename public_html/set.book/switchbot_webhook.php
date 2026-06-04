<?php
declare(strict_types=1);

foreach ([__DIR__ . '/../../apps/switchbot_api.php', __DIR__ . '/../apps/switchbot_api.php', __DIR__ . '/apps/switchbot_api.php'] as $__switchbotHelper) {
    if (is_file($__switchbotHelper)) {
        require_once $__switchbotHelper;
        break;
    }
}
foreach ([__DIR__ . '/../../apps/db.php', __DIR__ . '/../apps/db.php', __DIR__ . '/apps/db.php'] as $__dbHelper) {
    if (is_file($__dbHelper)) {
        require_once $__dbHelper;
        break;
    }
}
foreach ([__DIR__ . '/../../apps/reservation_service.php', __DIR__ . '/../apps/reservation_service.php', __DIR__ . '/apps/reservation_service.php'] as $__reservationHelper) {
    if (is_file($__reservationHelper)) {
        require_once $__reservationHelper;
        break;
    }
}
foreach ([__DIR__ . '/../../apps/response_limit.php', __DIR__ . '/../apps/response_limit.php', __DIR__ . '/apps/response_limit.php'] as $__rateLimitHelper) {
    if (is_file($__rateLimitHelper)) {
        require_once $__rateLimitHelper;
        break;
    }
}

try {
    $cfg = load_switchbot_webhook_config();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond_json(['ok' => false, 'message' => 'POST only'], 405);
    }

    if (function_exists('rate_limit_or_throw') && function_exists('get_client_ip')) {
        try {
            rate_limit_or_throw(get_client_ip(), __DIR__ . '/../../apps/rate_limit_switchbot_webhook.json', 60, 60);
        } catch (Throwable $rateLimitError) {
            error_log('[switchbot_webhook][rate_limit] ' . $rateLimitError->getMessage());
            respond_json(['ok' => false, 'message' => 'too many requests'], 429);
        }
    }

    $providedToken = switchbot_webhook_token_from_request();
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
        'payload' => $payload,
    ]);

    $updated = switchbot_apply_webhook_event_to_store($cfg, $payload);

    if (is_array($updated) && function_exists('db_connect') && function_exists('reservation_sync_from_switchbot_request')) {
        try {
            /** @var mixed $appCfg */
            $appCfg = require __DIR__ . '/../../apps/config.php';
            if (is_array($appCfg)) {
                $pdo = db_connect($appCfg);
                reservation_sync_from_switchbot_request($pdo, $updated);
            }
        } catch (Throwable $syncError) {
            error_log('[switchbot_webhook][reservation_sync] ' . $syncError->getMessage());
        }
    }

    respond_json([
        'ok' => true,
        'updated' => $updated,
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
        if (is_file($path)) {
            $cfg = require $path;
            if (is_array($cfg)) {
                return $cfg;
            }
        }
    }

    throw new RuntimeException('config.php が見つかりません。');
}

function switchbot_webhook_token_from_request(): string
{
    $candidates = [
        $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '',
        $_SERVER['HTTP_X_SWITCHBOT_WEBHOOK_TOKEN'] ?? '',
        $_GET['token'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $token = trim((string)$candidate);
        if ($token !== '') {
            return $token;
        }
    }
    return '';
}

function respond_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
