<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once kintone_apps_dir() . '/shared_accounts.php';

function kintone_auth_app_key(): string
{
    return 'kintone';
}

function kintone_auth_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = shared_accounts_db(kintone_load_config(), [
        kintone_apps_dir() . '/config.php',
        kintone_apps_dir() . '/config_up.php',
        kintone_project_root() . '/apps/config.php',
        kintone_project_root() . '/apps/config_up.php',
    ]);
    shared_accounts_install_schema($pdo);
    return $pdo;
}

function kintone_auth_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function kintone_auth_base_path(): string
{
    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $dir = str_replace('\\', '/', dirname($scriptName));
    if ($dir === '' || $dir === '.' || $dir === '/') {
        return '/';
    }
    $parts = explode('/', trim($dir, '/'));
    return '/' . ($parts[0] ?? 'kintone') . '/';
}

function kintone_auth_join_path(string $relative = ''): string
{
    $base = kintone_auth_base_path();
    $relative = ltrim($relative, '/');
    return $relative === '' ? $base : ($base === '/' ? '/' . $relative : $base . $relative);
}

function kintone_auth_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function kintone_auth_bootstrap(): void
{
    kintone_auth_security_headers();
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = kintone_auth_is_https();
    if (function_exists('ini_set')) {
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_secure', $secure ? '1' : '0');
        @ini_set('session.cookie_samesite', 'Lax');
    }
    session_name('fit_sc_kintone_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => kintone_auth_base_path(),
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    kintone_auth_security_headers();
}

function kintone_auth_attempt_login(string $identifier, string $password): ?array
{
    kintone_auth_bootstrap();
    return shared_accounts_attempt_login(kintone_auth_db(), trim($identifier), $password, kintone_auth_app_key());
}

function kintone_auth_login_user(array $user): void
{
    kintone_auth_bootstrap();
    $_SESSION['kintone_user'] = [
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

function kintone_auth_session_valid(): bool
{
    $sessionUser = $_SESSION['kintone_user'] ?? [];
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
        $state = shared_accounts_session_state(kintone_auth_db(), $accountId, kintone_auth_app_key());
    } catch (Throwable $e) {
        kintone_write_log('warning', 'kintone session validation skipped', ['error' => $e->getMessage()]);
        return true;
    }
    if ($state === null || (int)($state['is_active'] ?? 0) !== 1) {
        return false;
    }
    if ((int)($state['session_version'] ?? 1) !== (int)($sessionUser['session_version'] ?? -1)) {
        return false;
    }
    $_SESSION['kintone_user']['role_keys'] = is_array($state['role_keys'] ?? null) ? $state['role_keys'] : [];
    $_SESSION['kintone_user']['_revalidated_at'] = $now;
    return true;
}

function kintone_auth_is_logged_in(): bool
{
    kintone_auth_bootstrap();
    if (empty($_SESSION['kintone_user']) || !is_array($_SESSION['kintone_user'])) {
        return false;
    }
    return kintone_auth_session_valid();
}

function kintone_auth_current_user(): array
{
    kintone_auth_bootstrap();
    return is_array($_SESSION['kintone_user'] ?? null) ? $_SESSION['kintone_user'] : [];
}

function kintone_auth_user_has_role(array $user, string $roleKey): bool
{
    $roles = $user['role_keys'] ?? [];
    return is_array($roles) && in_array($roleKey, array_map('strval', $roles), true);
}

function kintone_auth_has_any_role(array $expected): bool
{
    $user = kintone_auth_current_user();
    foreach ($expected as $role) {
        if (kintone_auth_user_has_role($user, (string)$role)) {
            return true;
        }
    }
    return false;
}

function kintone_auth_require_login(): array
{
    kintone_auth_bootstrap();
    if (!kintone_auth_is_logged_in()) {
        header('Location: ' . kintone_auth_join_path('login.php?return_to=' . rawurlencode((string)($_SERVER['REQUEST_URI'] ?? 'index.php'))), true, 302);
        exit;
    }
    return kintone_auth_current_user();
}

function kintone_auth_forbid(string $message): void
{
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>権限がありません</title><p>' . kintone_h($message) . '</p>';
    exit;
}

function kintone_auth_require_view_access(): array
{
    $user = kintone_auth_require_login();
    if (!kintone_auth_has_any_role(['viewer', 'operator', 'admin'])) {
        kintone_auth_forbid('kintone管理サイトを表示する権限がありません。');
    }
    return $user;
}

function kintone_auth_require_operator_access(): array
{
    $user = kintone_auth_require_login();
    if (!kintone_auth_has_any_role(['operator', 'admin'])) {
        kintone_auth_forbid('この操作を実行するには kintone operator 以上の権限が必要です。');
    }
    return $user;
}

function kintone_auth_require_admin_access(): array
{
    $user = kintone_auth_require_login();
    if (!kintone_auth_user_has_role($user, 'admin')) {
        kintone_auth_forbid('この操作を実行するには kintone admin 権限が必要です。');
    }
    return $user;
}

function kintone_auth_csrf_token(bool $force = false): string
{
    kintone_auth_bootstrap();
    if ($force || !is_string($_SESSION['_kintone_csrf'] ?? null) || ($_SESSION['_kintone_csrf'] ?? '') === '') {
        $_SESSION['_kintone_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['_kintone_csrf'];
}

function kintone_auth_csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . kintone_h(kintone_auth_csrf_token()) . '">';
}

function kintone_auth_verify_csrf(?string $token): bool
{
    kintone_auth_bootstrap();
    $expected = (string)($_SESSION['_kintone_csrf'] ?? '');
    $provided = (string)$token;
    return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
}

function kintone_auth_require_csrf(): void
{
    $token = (string)($_POST['_csrf'] ?? ($_GET['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
    if (kintone_auth_verify_csrf($token)) {
        return;
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'CSRF トークンが無効です。ページを再読み込みしてください。';
    exit;
}

function kintone_auth_normalize_return_to(string $returnTo): string
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
        $base = rtrim(kintone_auth_base_path(), '/');
        if ($base !== '' && str_starts_with($returnTo, $base . '/')) {
            return substr($returnTo, strlen($base) + 1) ?: 'index.php';
        }
        return 'index.php';
    }
    return $returnTo;
}

function kintone_auth_logout(): void
{
    kintone_auth_bootstrap();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }
    session_destroy();
}
