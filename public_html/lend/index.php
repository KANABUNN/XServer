<?php
require_once __DIR__ . '/../../apps/lend_core/bootstrap.php';

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
