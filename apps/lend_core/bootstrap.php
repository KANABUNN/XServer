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
    session_name($GLOBALS['config']['session_name'] ?? 'equipment_kiosk_session');
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
