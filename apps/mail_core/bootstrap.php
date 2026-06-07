<?php

declare(strict_types=1);

const MAIL_APP_KEY = 'mail';
const MAIL_APP_NAME = 'メール半自動化管理';

function mail_core_dir(): string
{
    return __DIR__;
}

function mail_apps_dir(): string
{
    return dirname(__DIR__);
}

function mail_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        if ($string === '') {
            return 0;
        }
        $count = preg_match_all('/./us', $string);
        return $count !== false ? $count : strlen($string);
    }
}

function mail_json_encode(array $data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function mail_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function mail_base_path(): string
{
    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($scriptName === '') {
        return '/';
    }
    $dir = str_replace('\\', '/', dirname($scriptName));
    if ($dir === '' || $dir === '.' || $dir === '\\' || $dir === '/') {
        return '/';
    }
    if (str_ends_with($dir, '/api')) {
        $dir = dirname($dir);
    }
    return '/' . trim($dir, '/') . '/';
}

function mail_url(string $relativePath = ''): string
{
    $base = mail_base_path();
    $relativePath = ltrim($relativePath, '/');
    if ($relativePath === '') {
        return $base;
    }
    return ($base === '/') ? '/' . $relativePath : $base . $relativePath;
}

function mail_load_config(): array
{
    $candidates = [
        mail_core_dir() . '/config.local.php',
        mail_core_dir() . '/config.php',
        mail_apps_dir() . '/config_mail.php',
        mail_apps_dir() . '/config.php',
        mail_core_dir() . '/config.sample.php',
    ];

    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }
        $config = require $path;
        if (!is_array($config)) {
            throw new RuntimeException($path . ' が配列を返していません。');
        }
        return $config;
    }

    throw new RuntimeException('mail_core の設定ファイルが見つかりません。config.sample.php を複製して設定してください。');
}

function mail_db_connection_config(array $config, string $connectionName): array
{
    $connections = $config['db_connections'] ?? [];
    $db = is_array($connections) ? ($connections[$connectionName] ?? null) : null;

    if (!is_array($db) && $connectionName === 'mail') {
        $db = $config['db'] ?? null;
    }

    if (!is_array($db)) {
        throw new RuntimeException('DB接続設定 db_connections.' . $connectionName . ' が見つかりません。');
    }

    $dsn = trim((string)($db['dsn'] ?? ''));
    if ($dsn === '') {
        $host = (string)($db['host'] ?? 'localhost');
        $port = (int)($db['port'] ?? 3306);
        $dbname = (string)($db['dbname'] ?? $db['database'] ?? ($connectionName === 'account' ? 'fitsc_account' : 'fitsc_mail'));
        $charset = (string)($db['charset'] ?? 'utf8mb4');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbname, $charset);
    }

    return [
        'dsn' => $dsn,
        'user' => (string)($db['user'] ?? $db['username'] ?? ''),
        'password' => (string)($db['password'] ?? $db['pass'] ?? ''),
    ];
}

function mail_pdo(string $connectionName = 'mail'): PDO
{
    static $instances = [];
    $config = mail_load_config();
    $db = mail_db_connection_config($config, $connectionName);
    $cacheKey = $connectionName . ':' . md5($db['dsn'] . '|' . $db['user']);

    if (isset($instances[$cacheKey]) && $instances[$cacheKey] instanceof PDO) {
        return $instances[$cacheKey];
    }

    $instances[$cacheKey] = new PDO($db['dsn'], $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $instances[$cacheKey];
}

function mail_table_exists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $stmt->execute([':table' => $tableName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

function mail_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
        $stmt->execute([':table' => $tableName, ':column' => $columnName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

function mail_client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}

function mail_user_agent(): string
{
    return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

function mail_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function mail_send_json(array $payload, int $statusCode = 200): void
{
    mail_security_headers();
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo mail_json_encode($payload);
    exit;
}
