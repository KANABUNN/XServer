<?php

declare(strict_types=1);

require_once __DIR__ . '/../../apps/admin_auth.php';

admin_auth_bootstrap();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: index.php', true, 302);
    exit;
}

admin_auth_require_csrf();
$user = admin_auth_current_user();
try {
    $pdo = admin_auth_db_connect();
    admin_auth_install_schema($pdo);
    if ($user !== null) {
        admin_auth_write_audit_log($pdo, $user, 'admin.logout', 'admin_user', (int)($user['id'] ?? 0));
    }
} catch (Throwable $e) {
    error_log('[admin_logout] ' . $e->getMessage());
}

admin_auth_logout();
header('Location: login.php', true, 302);
exit;
