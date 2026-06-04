<?php
$configCandidates = [
    __DIR__ . '/config.php',
    dirname(__DIR__, 2) . '/includes/config.php',
];

$configPath = null;
foreach ($configCandidates as $candidate) {
    if (is_file($candidate)) {
        $configPath = $candidate;
        break;
    }
}

if ($configPath === null) {
    throw new RuntimeException('lend 用の config.php が見つかりません。');
}

$GLOBALS['config'] = require $configPath;

date_default_timezone_set($GLOBALS['config']['timezone'] ?? 'Asia/Tokyo');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $secure = ($https !== '' && $https !== 'off' && $https !== '0')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

    if (function_exists('ini_set')) {
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_secure', $secure ? '1' : '0');
        @ini_set('session.cookie_samesite', 'Lax');
    }

    session_name($GLOBALS['config']['session_name'] ?? 'equipment_kiosk_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
