<?php
$configPath = dirname(__DIR__, 2) . '/config.php';
if (!file_exists($configPath)) {
    $configPath = dirname(__DIR__, 2) . '/config.example.php';
}
$GLOBALS['config'] = require $configPath;

date_default_timezone_set($GLOBALS['config']['timezone'] ?? 'Asia/Tokyo');

session_name($GLOBALS['config']['session_name'] ?? 'equipment_kiosk_session');
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
