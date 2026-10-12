<?php
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Tokyo');

if (session_status() === PHP_SESSION_NONE) {
    session_name($config['security']['session_name'] ?? 'app_sid');
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
