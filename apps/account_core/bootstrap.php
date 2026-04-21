<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shared_accounts.php';

function account_site_config_candidates(): array
{
    return [
        dirname(__DIR__) . '/config.php',
        dirname(__DIR__, 2) . '/includes/config.php',
        dirname(__DIR__, 2) . '/apps/config.php',
    ];
}

function account_site_load_config(): array
{
    foreach (account_site_config_candidates() as $path) {
        if (!is_file($path)) {
            continue;
        }

        $cfg = require $path;
        if (is_array($cfg)) {
            return $cfg;
        }
    }

    return [];
}

function account_site_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = account_site_load_config();
    $pdo = shared_accounts_db($config, account_site_config_candidates());
    shared_accounts_install_schema($pdo);
    return $pdo;
}

function account_site_app_key(): string
{
    return 'account';
}

function account_site_app_definitions(): array
{
    return [
        'account' => [
            'label' => 'アカウント管理',
            'roles' => [
                'viewer' => '閲覧者',
                'user' => '編集者',
                'admin' => '管理者',
            ],
        ],
        'admin_book' => [
            'label' => '予約管理',
            'roles' => [
                'viewer' => '閲覧者',
                'user' => '編集者',
                'admin' => '管理者',
            ],
        ],
        'lend' => [
            'label' => '貸出管理',
            'roles' => [
                'user' => '利用者',
                'admin' => '管理者',
            ],
        ],
        'forms' => [
            'label' => 'フォーム管理',
            'roles' => [
                'user' => '利用者',
                'admin' => '管理者',
            ],
        ],
        'todo' => [
            'label' => 'ToDo管理',
            'roles' => [
                'member' => 'メンバー',
                'admin' => '管理者',
            ],
        ],
    ];
}

function account_site_base_path(): string
{
    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $dir = str_replace('\\', '/', dirname($scriptName));
    if ($dir === '' || $dir === '.' || $dir === '/') {
        return '/';
    }
    return '/' . trim($dir, '/') . '/';
}

function account_site_join_path(string $relative = ''): string
{
    $base = account_site_base_path();
    $relative = ltrim($relative, '/');
    if ($relative === '') {
        return $base;
    }
    return $base === '/' ? '/' . $relative : $base . $relative;
}

function account_site_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function account_site_bootstrap_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = account_site_is_https();
    if (function_exists('ini_set')) {
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_secure', $secure ? '1' : '0');
        @ini_set('session.cookie_samesite', 'Lax');
    }

    session_name('fit_sc_account_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => account_site_base_path(),
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function account_site_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function account_site_redirect(string $relative = ''): never
{
    header('Location: ' . account_site_join_path($relative));
    exit;
}

function account_site_set_flash(string $type, string $message): void
{
    $_SESSION['account_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function account_site_pull_flash(): ?array
{
    if (!isset($_SESSION['account_flash']) || !is_array($_SESSION['account_flash'])) {
        return null;
    }
    $flash = $_SESSION['account_flash'];
    unset($_SESSION['account_flash']);
    return $flash;
}

function account_site_csrf_token(): string
{
    if (empty($_SESSION['_account_csrf'])) {
        $_SESSION['_account_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['_account_csrf'];
}

function account_site_verify_csrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['_account_csrf'])
        && hash_equals((string)$_SESSION['_account_csrf'], $token);
}
