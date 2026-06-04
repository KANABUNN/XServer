<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function account_site_attempt_login(string $identifier, string $password): bool
{
    account_site_bootstrap_session();

    $user = shared_accounts_attempt_login(account_site_db(), trim($identifier), $password, account_site_app_key());
    if (!$user) {
        return false;
    }

    $roleKeys = is_array($user['role_keys'] ?? null) ? $user['role_keys'] : [];
    $_SESSION['account_user'] = [
        'id' => (int)($user['id'] ?? 0),
        'session_version' => (int)($user['session_version'] ?? 1),
        'login_id' => (string)($user['login_id'] ?? ''),
        'display_name' => (string)($user['display_name'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'organization_name' => (string)($user['organization_name'] ?? ''),
        'role_keys' => $roleKeys,
    ];
    session_regenerate_id(true);
    return true;
}

function account_site_is_logged_in(): bool
{
    if (empty($_SESSION['account_user']) || !is_array($_SESSION['account_user'])) {
        return false;
    }
    return account_site_session_state_valid();
}

function account_site_session_state_valid(): bool
{
    $sessionUser = $_SESSION['account_user'] ?? [];
    $accountId = (int)($sessionUser['id'] ?? 0);
    if ($accountId < 1) {
        return false;
    }

    $now = time();
    $lastChecked = (int)($sessionUser['_revalidated_at'] ?? 0);
    if ($lastChecked > 0 && ($now - $lastChecked) < 60) {
        return true;
    }

    try {
        $state = shared_accounts_session_state(account_site_db(), $accountId, account_site_app_key());
    } catch (Throwable $e) {
        return true;
    }

    if ($state === null || (int)($state['is_active'] ?? 0) !== 1) {
        return false;
    }
    if ((int)($state['session_version'] ?? 1) !== (int)($sessionUser['session_version'] ?? -1)) {
        return false;
    }

    $_SESSION['account_user']['_revalidated_at'] = $now;
    return true;
}

function account_site_current_user(): array
{
    return $_SESSION['account_user'] ?? [];
}

function account_site_has_any_role(array $expected): bool
{
    $current = account_site_current_user();
    $roleKeys = is_array($current['role_keys'] ?? null) ? $current['role_keys'] : [];
    foreach ($expected as $role) {
        if (in_array((string)$role, $roleKeys, true)) {
            return true;
        }
    }
    return false;
}

function account_site_require_login(): void
{
    account_site_bootstrap_session();
    if (!account_site_is_logged_in()) {
        account_site_set_flash('error', 'ログインしてください。');
        account_site_redirect('login.php');
    }
}

function account_site_require_view_access(): void
{
    account_site_require_login();
    if (!account_site_has_any_role(['viewer', 'user', 'admin'])) {
        http_response_code(403);
        echo 'このページを閲覧する権限がありません。';
        exit;
    }
}

function account_site_require_manage_access(): void
{
    account_site_require_login();
    if (!account_site_has_any_role(['admin'])) {
        http_response_code(403);
        echo 'この操作を実行する権限がありません。';
        exit;
    }
}

function account_site_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }
    session_destroy();
}
