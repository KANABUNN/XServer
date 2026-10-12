<?php

declare(strict_types=1);

require_once __DIR__ . '/../../apps/mail_core/bootstrap.php';
require_once __DIR__ . '/../../apps/mail_core/auth.php';

mail_auth_bootstrap();
$user = mail_auth_current_user();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    mail_auth_require_csrf();
    try {
        $accountPdo = mail_pdo('account');
        mail_auth_write_audit_log($accountPdo, $user, 'mail.logout', 'account', $user !== null ? (string)($user['id'] ?? '') : null);
    } catch (Throwable $e) {
        error_log('[mail logout] ' . $e->getMessage());
    }
    mail_auth_logout();
    header('Location: login.php', true, 302);
    exit;
}

http_response_code(405);
header('Content-Type: text/plain; charset=UTF-8');
echo 'Method Not Allowed';
