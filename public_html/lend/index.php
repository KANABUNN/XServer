<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if (is_logged_in()) {
    if (current_user()['role'] === 'admin') {
        header('Location: admin.php');
    } else {
        header('Location: user_dashboard.php');
    }
    exit;
}
header('Location: login.php');
exit;
