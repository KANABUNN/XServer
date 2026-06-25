<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/apps/kintone_core/auth.php';
kintone_auth_logout();
header('Location: login.php', true, 302);
exit;
