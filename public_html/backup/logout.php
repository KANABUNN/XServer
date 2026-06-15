<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/apps/admin_auth.php';
admin_auth_bootstrap();
admin_auth_logout();
header('Location: login.php', true, 302);
exit;
