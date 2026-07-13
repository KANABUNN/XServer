<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once backup_apps_dir() . '/shared_accounts.php';

function backup_auth_app_key(): string
{
    return 'backup';
}

function backup_auth_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = shared_accounts_db(backup_load_config(), [
        backup_apps_dir() . '/config.php',
        backup_apps_dir() . '/config_up.php',
        backup_project_root() . '/apps/config.php',
        backup_project_root() . '/apps/config_up.php',
    ]);
    shared_accounts_install_schema($pdo);
    return $pdo;
}

function backup_auth_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function backup_auth_base_path(): string
{
    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $dir = str_replace('\\', '/', dirname($scriptName));
    if ($dir === '' || $dir === '.' || $dir === '/') {
        return '/';
    }
    return '/' . trim($dir, '/') . '/';
}

function backup_auth_join_path(string $relative = ''): string
{
    $base = backup_auth_base_path();
    $relative = ltrim($relative, '/');
    if ($relative === '') {
        return $base;
    }
    return $base === '/' ? '/' . $relative : $base . $relative;
}

function backup_auth_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function backup_auth_bootstrap(): void
{
    backup_auth_security_headers();
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = backup_auth_is_https();
    if (function_exists('ini_set')) {
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_secure', $secure ? '1' : '0');
        @ini_set('session.cookie_samesite', 'Lax');
    }
    session_name('fit_sc_backup_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => backup_auth_base_path(),
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    backup_auth_security_headers();
}

function backup_auth_attempt_login(string $identifier, string $password): ?array
{
    backup_auth_bootstrap();
    return shared_accounts_attempt_login(backup_auth_db(), trim($identifier), $password, backup_auth_app_key());
}

function backup_auth_login_user(array $user): void
{
    backup_auth_bootstrap();
    $_SESSION['backup_user'] = [
        'id' => (int)($user['id'] ?? 0),
        'session_version' => (int)($user['session_version'] ?? 1),
        'login_id' => (string)($user['login_id'] ?? ''),
        'display_name' => (string)($user['display_name'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'organization_name' => (string)($user['organization_name'] ?? ''),
        'role_keys' => is_array($user['role_keys'] ?? null) ? array_values(array_map('strval', $user['role_keys'])) : [],
    ];
    session_regenerate_id(true);
}

function backup_auth_session_valid(): bool
{
    $sessionUser = $_SESSION['backup_user'] ?? [];
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
        $state = shared_accounts_session_state(backup_auth_db(), $accountId, backup_auth_app_key());
    } catch (Throwable $e) {
        backup_write_log('warning', 'backup session validation skipped', ['error' => $e->getMessage()]);
        return true;
    }
    if ($state === null || (int)($state['is_active'] ?? 0) !== 1) {
        return false;
    }
    if ((int)($state['session_version'] ?? 1) !== (int)($sessionUser['session_version'] ?? -1)) {
        return false;
    }
    $_SESSION['backup_user']['role_keys'] = is_array($state['role_keys'] ?? null) ? $state['role_keys'] : [];
    $_SESSION['backup_user']['_revalidated_at'] = $now;
    return true;
}

function backup_auth_is_logged_in(): bool
{
    backup_auth_bootstrap();
    if (empty($_SESSION['backup_user']) || !is_array($_SESSION['backup_user'])) {
        return false;
    }
    return backup_auth_session_valid();
}

function backup_auth_current_user(): array
{
    backup_auth_bootstrap();
    return is_array($_SESSION['backup_user'] ?? null) ? $_SESSION['backup_user'] : [];
}

function backup_auth_user_has_role(array $user, string $roleKey): bool
{
    $roles = $user['role_keys'] ?? [];
    if (!is_array($roles)) {
        return false;
    }
    return in_array($roleKey, array_map('strval', $roles), true);
}

function backup_auth_has_any_role(array $expected): bool
{
    $user = backup_auth_current_user();
    foreach ($expected as $role) {
        if (backup_auth_user_has_role($user, (string)$role)) {
            return true;
        }
    }
    return false;
}

function backup_auth_require_login(): array
{
    backup_auth_bootstrap();
    if (!backup_auth_is_logged_in()) {
        header('Location: ' . backup_auth_join_path('login.php?return_to=' . rawurlencode((string)($_SERVER['REQUEST_URI'] ?? 'index.php'))), true, 302);
        exit;
    }
    return backup_auth_current_user();
}

function backup_auth_require_view_access(): array
{
    $user = backup_auth_require_login();
    if (!backup_auth_user_has_role($user, 'viewer') && !backup_auth_user_has_role($user, 'admin')) {
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><title>権限がありません</title><script src="context-menu-guard.js?v=20260713" defer></script><p>バックアップ管理画面を表示する権限がありません。</p>';
        exit;
    }
    return $user;
}

function backup_auth_require_admin_access(): array
{
    $user = backup_auth_require_login();
    if (!backup_auth_user_has_role($user, 'admin')) {
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><title>権限がありません</title><script src="context-menu-guard.js?v=20260713" defer></script><p>この操作を実行するにはバックアップ管理者権限が必要です。</p>';
        exit;
    }
    return $user;
}

function backup_auth_csrf_token(bool $force = false): string
{
    backup_auth_bootstrap();
    if ($force || !is_string($_SESSION['_backup_csrf'] ?? null) || ($_SESSION['_backup_csrf'] ?? '') === '') {
        $_SESSION['_backup_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['_backup_csrf'];
}

function backup_auth_verify_csrf(?string $token): bool
{
    backup_auth_bootstrap();
    $expected = (string)($_SESSION['_backup_csrf'] ?? '');
    $provided = (string)$token;
    return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
}

function backup_auth_require_csrf(): void
{
    $token = (string)($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (backup_auth_verify_csrf($token)) {
        return;
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'CSRF トークンが無効です。ページを再読み込みしてください。';
    exit;
}

function backup_auth_normalize_return_to(string $returnTo): string
{
    $returnTo = trim($returnTo);
    if ($returnTo === '') {
        return 'index.php';
    }
    if (preg_match('/\Ahttps?:\/\//i', $returnTo) || str_starts_with($returnTo, '//')) {
        return 'index.php';
    }
    $returnTo = str_replace(["\r", "\n"], '', $returnTo);
    if (str_contains($returnTo, '..')) {
        return 'index.php';
    }
    if (str_starts_with($returnTo, '/')) {
        $base = rtrim(backup_auth_base_path(), '/');
        if ($base !== '' && str_starts_with($returnTo, $base . '/')) {
            return substr($returnTo, strlen($base) + 1) ?: 'index.php';
        }
        return 'index.php';
    }
    return $returnTo;
}

function backup_auth_logout(): void
{
    backup_auth_bootstrap();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }
    session_destroy();
}
