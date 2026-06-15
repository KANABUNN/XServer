<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/apps/backup_core/auth.php';
backup_auth_bootstrap();
backup_auth_logout();
header('Location: login.php', true, 302);
exit;
