<?php
declare(strict_types=1);

$shared = require __DIR__ . '/config.php';
$formsDb = (array)($shared['forms_db'] ?? []);

return [
    'forms' => [
        'db_name' => (string)($formsDb['dbname'] ?? 'fitsc_forms'),
        'upload_root' => __DIR__ . '/forms_uploads',
    ],

    'forms_db' => [
        'host' => (string)($formsDb['host'] ?? 'localhost'),
        'port' => (int)($formsDb['port'] ?? 3306),
        'dbname' => (string)($formsDb['dbname'] ?? 'fitsc_forms'),
        'charset' => (string)($formsDb['charset'] ?? 'utf8mb4'),
        'user' => (string)($formsDb['user'] ?? $formsDb['username'] ?? ''),
        'pass' => (string)($formsDb['pass'] ?? $formsDb['password'] ?? ''),
    ],
];
