<?php
require_once __DIR__ . '/../../apps/lend_core/bootstrap.php';

if (!verify_csrf($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('CSRF トークンが不正です。');
}

session_unset();
session_destroy();
header('Location: login.php?logged_out=1');
exit;
