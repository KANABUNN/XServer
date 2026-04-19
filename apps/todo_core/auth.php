<?php
function is_logged_in(): bool
{
    return !empty($_SESSION['logged_in']);
}

function attempt_login(string $username, string $password): bool
{
    global $config;
    $auth = $config['auth'] ?? [];
    $validUser = hash_equals((string)($auth['username'] ?? ''), $username);
    $validPass = password_verify($password, $auth['password_hash'] ?? '');

    if ($validUser && $validPass) {
        $_SESSION['logged_in'] = true;
        $_SESSION['username'] = $username;
        session_regenerate_id(true);
        return true;
    }
    return false;
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
