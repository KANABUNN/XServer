<?php
require_once __DIR__ . '/../../apps/forms_core/bootstrap.php';

if (!verify_csrf($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('CSRF トークンが不正です。');
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
header('Location: login.php?logged_out=1');
exit;
