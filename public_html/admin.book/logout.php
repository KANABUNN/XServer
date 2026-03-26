<?php

declare(strict_types=1);

require_once __DIR__ . '/../../apps/admin_auth.php';

admin_auth_logout();
header('Location: login.php', true, 302);
exit;
