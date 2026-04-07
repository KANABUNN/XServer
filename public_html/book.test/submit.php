<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
/** @var mixed $cfg */
$cfg = require __DIR__ . '/../../apps/config_test.php';
require_once __DIR__ . '/../../apps/db.php';
require_once __DIR__ . '/../../apps/response_limit.php';
require_once __DIR__ . '/../../apps/switchbot_api.php';
require_once __DIR__ . '/../../apps/google_calendar_sync.php';
require_once __DIR__ . '/../../apps/smtp_mailer.php';
require_once __DIR__ . '/../../apps/mail_html_templates.php';
require_once __DIR__ . '/../../apps/reservation_service.php';

function reservation_current_base_path(): string
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = trim(str_replace('\\', '/', dirname($scriptName)));
    if ($dir === '' || $dir === '.') {
        return '';
    }
    return rtrim($dir, '/');
}


try {
    if (!is_array($cfg)) {
        throw new RuntimeException('config_test.php の形式が不正です。');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method Not Allowed');
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    rate_limit_or_throw($ip, __DIR__ . '/../../apps/rate_limit.json', 5, 300);

    $validated = reservation_validate_form_input($cfg, $_POST);
    $pdo = db_connect($cfg);
    $reservation = reservation_process_submission($cfg, $pdo, $validated);
    if ($reservation === [] || !isset($reservation['request_token'])) {
        throw new RuntimeException('予約処理結果を取得できませんでした。');
    }

    reservation_send_emails($cfg, $pdo, $reservation);

    $basePath = reservation_current_base_path();
    header('Location: ' . ($basePath !== '' ? $basePath : '') . '/result.php?token=' . rawurlencode((string)$reservation['request_token']), true, 303);
    exit;
} catch (Throwable $e) {
    $id = bin2hex(random_bytes(6));
    error_log('[reservation_test:' . $id . '] ' . $e->getMessage());
    error_log('[reservation_test:' . $id . '] ' . $e->getFile() . ':' . $e->getLine());
    error_log('[reservation_test:' . $id . '] POST=' . json_encode($_POST, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $basePath = reservation_current_base_path();
    header('Location: ' . ($basePath !== '' ? $basePath : '') . '/mistake.html', true, 303);
    exit;
}
