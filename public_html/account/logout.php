<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/apps/account_core/auth.php';

account_site_bootstrap_session();
account_site_logout();
account_site_bootstrap_session();
account_site_set_flash('success', 'ログアウトしました。');
account_site_redirect('login.php');
