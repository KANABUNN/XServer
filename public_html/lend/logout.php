<?php
require_once __DIR__ . '/../../apps/lend_core/bootstrap.php';
session_unset();
session_destroy();
header('Location: login.php?logged_out=1');
exit;
