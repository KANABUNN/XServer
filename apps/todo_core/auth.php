<?php
require_once dirname(__DIR__) . '/shared_accounts.php';

function todo_account_db(): PDO
{
    global $config;
    return shared_accounts_db(is_array($config ?? null) ? $config : [], [
        dirname(__DIR__) . '/config.php',
        dirname(__DIR__, 2) . '/includes/config.php',
    ]);
}

function todo_account_app_key(): string
{
    return 'todo';
}

function is_logged_in(): bool
{
    return !empty($_SESSION['todo_user']) && is_array($_SESSION['todo_user']);
}

function current_user(): array
{
    return $_SESSION['todo_user'] ?? [];
}

function attempt_login(string $identifier, string $password): bool
{
    $user = shared_accounts_attempt_login(todo_account_db(), $identifier, $password, todo_account_app_key());
    if (!$user) {
        return false;
    }

    $roleKeys = is_array($user['role_keys'] ?? null) ? $user['role_keys'] : [];
    $primaryRole = (string)($roleKeys[0] ?? 'member');

    $_SESSION['todo_user'] = [
        'id' => (int)($user['id'] ?? 0),
        'login_id' => (string)($user['login_id'] ?? ''),
        'display_name' => (string)($user['display_name'] ?? ''),
        'name' => (string)($user['display_name'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'organization_name' => (string)($user['organization_name'] ?? ''),
        'organization' => (string)($user['organization_name'] ?? ''),
        'role' => $primaryRole,
        'role_keys' => $roleKeys,
    ];

    session_regenerate_id(true);
    return true;
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash('error', 'ログインしてください。');
        redirect('login.php');
    }
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
