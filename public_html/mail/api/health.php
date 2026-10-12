<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../apps/mail_core/bootstrap.php';
require_once __DIR__ . '/../../../apps/mail_core/auth.php';
require_once __DIR__ . '/../../../apps/mail_core/repository.php';

$user = mail_auth_require_login();

$result = [
    'ok' => true,
    'app' => 'mail.fit-sc.jp',
    'user' => [
        'id' => (int)($user['id'] ?? 0),
        'login_id' => (string)($user['login_id'] ?? ''),
        'role_key' => (string)($user['role_key'] ?? ''),
    ],
    'account_db' => ['ok' => false, 'message' => ''],
    'mail_db' => ['ok' => false, 'message' => '', 'schema' => null],
];

try {
    $accountPdo = mail_pdo('account');
    $accountPdo->query('SELECT 1');
    $result['account_db'] = ['ok' => true, 'message' => 'connected'];
} catch (Throwable $e) {
    $result['ok'] = false;
    $result['account_db'] = ['ok' => false, 'message' => $e->getMessage()];
}

try {
    $mailPdo = mail_pdo('mail');
    $schema = mail_schema_status($mailPdo);
    $result['mail_db'] = ['ok' => (bool)$schema['ready'], 'message' => $schema['ready'] ? 'ready' : 'schema missing', 'schema' => $schema];
    if (!$schema['ready']) {
        $result['ok'] = false;
    }
} catch (Throwable $e) {
    $result['ok'] = false;
    $result['mail_db'] = ['ok' => false, 'message' => $e->getMessage(), 'schema' => null];
}

mail_send_json($result, $result['ok'] ? 200 : 503);
