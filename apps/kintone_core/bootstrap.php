<?php

declare(strict_types=1);

function kintone_apps_dir(): string
{
    return dirname(__DIR__);
}

function kintone_project_root(): string
{
    return dirname(kintone_apps_dir());
}

function kintone_public_html_dir(): string
{
    return kintone_project_root() . '/public_html';
}

function kintone_load_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }
    $candidates = [
        __DIR__ . '/config.php',
        __DIR__ . '/config_up.php',
        kintone_apps_dir() . '/config.php',
        kintone_apps_dir() . '/config_up.php',
        kintone_project_root() . '/apps/config.php',
        kintone_project_root() . '/apps/config_up.php',
    ];
    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }
        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new RuntimeException('設定ファイルが配列を返していません。');
        }
        $config = $loaded;
        return $config;
    }
    throw new RuntimeException('apps/config.php または apps/config_up.php が見つかりません。');
}

function kintone_config_value(string $key, mixed $default = null): mixed
{
    $config = kintone_load_config();
    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function kintone_timezone(): DateTimeZone
{
    $tz = (string)kintone_config_value('timezone', 'Asia/Tokyo');
    return new DateTimeZone($tz !== '' ? $tz : 'Asia/Tokyo');
}

function kintone_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', kintone_timezone());
}

function kintone_json_encode(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('JSON の生成に失敗しました。');
    }
    return $json;
}

function kintone_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function kintone_ensure_dir(string $dir, int $mode = 0700): void
{
    if (!is_dir($dir) && !mkdir($dir, $mode, true) && !is_dir($dir)) {
        throw new RuntimeException('ディレクトリを作成できません。');
    }
    @chmod($dir, $mode);
}

function kintone_storage_root(): string
{
    $root = (string)kintone_config_value('kintone.storage_root', __DIR__ . '/storage');
    $root = rtrim($root, '/');
    kintone_ensure_dir($root, 0700);
    $denyFile = $root . '/.htaccess';
    if (!is_file($denyFile)) {
        @file_put_contents($denyFile, "Require all denied\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return $root;
}

function kintone_roster_upload_root(): string
{
    $dir = kintone_storage_root() . '/roster_uploads';
    kintone_ensure_dir($dir, 0700);
    $denyFile = $dir . '/.htaccess';
    if (!is_file($denyFile)) {
        @file_put_contents($denyFile, "Require all denied\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return $dir;
}

function kintone_log_file(): string
{
    return kintone_storage_root() . '/kintone_manager.log';
}

function kintone_write_log(string $level, string $message, array $context = []): void
{
    try {
        $line = kintone_now()->format('Y-m-d H:i:s') . ' [' . strtoupper($level) . '] ' . $message;
        if ($context !== []) {
            $line .= ' ' . kintone_json_encode($context);
        }
        file_put_contents(kintone_log_file(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    } catch (Throwable) {
        // ログ失敗で業務処理を止めない。
    }
}

function kintone_normalize_db_config(array $raw, string $defaultDb): array
{
    $host = (string)($raw['host'] ?? 'localhost');
    $port = (int)($raw['port'] ?? 3306);
    $dbname = (string)($raw['dbname'] ?? $raw['database'] ?? $defaultDb);
    $charset = (string)($raw['charset'] ?? 'utf8mb4');
    $user = (string)($raw['user'] ?? $raw['username'] ?? '');
    $password = (string)($raw['password'] ?? $raw['pass'] ?? '');
    if ($dbname === '' || $user === '') {
        throw new RuntimeException('DB接続設定が不完全です。');
    }
    return [
        'dsn' => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbname, $charset),
        'user' => $user,
        'password' => $password,
    ];
}

function kintone_connection_config(string $connectionKey): array
{
    $config = kintone_load_config();
    $connections = is_array($config['db_connections'] ?? null) ? $config['db_connections'] : [];
    if (isset($connections[$connectionKey]) && is_array($connections[$connectionKey])) {
        return kintone_normalize_db_config($connections[$connectionKey], 'fitsc_' . $connectionKey);
    }
    $legacy = $connectionKey . '_db';
    if (isset($config[$legacy]) && is_array($config[$legacy])) {
        return kintone_normalize_db_config($config[$legacy], 'fitsc_' . $connectionKey);
    }
    $base = is_array($config['db'] ?? null) ? $config['db'] : [];
    if ($base !== []) {
        $default = match ($connectionKey) {
            'org' => 'fitsc_org',
            'mail' => 'fitsc_mail',
            default => 'fitsc_' . $connectionKey,
        };
        $base['dbname'] = $base['database'] = $default;
        return kintone_normalize_db_config($base, $default);
    }
    throw new RuntimeException('DB接続設定が見つかりません: ' . $connectionKey);
}

function kintone_pdo(string $connectionKey = 'org'): PDO
{
    static $instances = [];
    if (isset($instances[$connectionKey]) && $instances[$connectionKey] instanceof PDO) {
        return $instances[$connectionKey];
    }
    $cfg = kintone_connection_config($connectionKey);
    $pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('SET NAMES utf8mb4');
    $instances[$connectionKey] = $pdo;
    return $pdo;
}

function kintone_reference_id(string $prefix = 'ERR'): string
{
    return $prefix . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
}
