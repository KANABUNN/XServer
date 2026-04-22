<?php
declare(strict_types=1);

$shared = require dirname(__DIR__) . '/config.php';
$lendDb = (array)($shared['lend_db'] ?? []);
$accountDb = (array)($shared['account_db'] ?? []);
$dbConnections = (array)($shared['db_connections'] ?? []);

return [
    'app_name' => '備品貸出システム',
    'base_url' => 'https://lend.fit-sc.jp',
    'session_name' => 'equipment_kiosk_session',
    'timezone' => (string)($shared['timezone'] ?? 'Asia/Tokyo'),

    // lend 業務DBは専用ユーザー fitsc_lend を使う
    'db' => [
        'host' => (string)($lendDb['host'] ?? 'localhost'),
        'port' => (int)($lendDb['port'] ?? 3306),
        'dbname' => (string)($lendDb['dbname'] ?? 'fitsc_lend'),
        'charset' => (string)($lendDb['charset'] ?? 'utf8mb4'),
        'user' => (string)($lendDb['user'] ?? $lendDb['username'] ?? ''),
        'pass' => (string)($lendDb['pass'] ?? $lendDb['password'] ?? ''),
    ],

    // 認証は fitsc_account を fitsc_admin で参照する
    'account_db' => [
        'host' => (string)($accountDb['host'] ?? 'localhost'),
        'port' => (int)($accountDb['port'] ?? 3306),
        'dbname' => (string)($accountDb['dbname'] ?? 'fitsc_account'),
        'charset' => (string)($accountDb['charset'] ?? 'utf8mb4'),
        'user' => (string)($accountDb['user'] ?? $accountDb['username'] ?? ''),
        'pass' => (string)($accountDb['pass'] ?? $accountDb['password'] ?? ''),
    ],

    'db_connections' => $dbConnections,

    'reservation' => [
        'checkout_early_minutes' => 60,
        'return_grace_minutes' => 240,
    ],

    'kiosk' => [
        'idle_logout_seconds' => 180,
    ],
];
