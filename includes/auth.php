<?php
function login_user(string $email, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = :email AND is_active = 1 LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'organization' => $user['organization'],
    ];

    audit_log((int)$user['id'], $user['role'], 'login', 'session', null, [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ]);

    return true;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user']);
}

function current_user(): array
{
    return $_SESSION['user'] ?? [];
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function require_admin(): void
{
    require_login();
    if ((current_user()['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo '管理者権限が必要です。';
        exit;
    }
}

function api_require_login(): array
{
    if (!is_logged_in()) {
        json_response(['ok' => false, 'message' => 'ログインが必要です。'], 401);
    }
    return current_user();
}

function api_require_admin(): array
{
    $user = api_require_login();
    if (($user['role'] ?? '') !== 'admin') {
        json_response(['ok' => false, 'message' => '管理者権限が必要です。'], 403);
    }
    return $user;
}
