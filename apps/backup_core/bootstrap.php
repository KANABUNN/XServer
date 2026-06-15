<?php

declare(strict_types=1);

/**
 * FIT-SC Backup Manager - bootstrap helpers.
 *
 * This module is intentionally independent from public_html so that backup
 * execution, DB access, and filesystem paths stay outside the web root.
 */

function backup_apps_dir(): string
{
    return dirname(__DIR__);
}

function backup_project_root(): string
{
    return dirname(backup_apps_dir());
}

function backup_public_html_dir(): string
{
    return backup_project_root() . '/public_html';
}

function backup_load_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $candidates = [
        backup_apps_dir() . '/config.php',
        backup_apps_dir() . '/config_up.php',
        backup_project_root() . '/apps/config.php',
        backup_project_root() . '/apps/config_up.php',
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            $loaded = require $path;
            if (!is_array($loaded)) {
                throw new RuntimeException('設定ファイルが配列を返していません: ' . $path);
            }
            $config = $loaded;
            return $config;
        }
    }

    throw new RuntimeException('apps/config.php または apps/config_up.php が見つかりません。');
}

function backup_config_value(string $key, mixed $default = null): mixed
{
    $config = backup_load_config();
    $parts = explode('.', $key);
    $value = $config;
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function backup_timezone(): DateTimeZone
{
    $tz = (string)backup_config_value('backup_manager.timezone', (string)backup_config_value('timezone', 'Asia/Tokyo'));
    return new DateTimeZone($tz !== '' ? $tz : 'Asia/Tokyo');
}

function backup_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', backup_timezone());
}

function backup_root_dir(): string
{
    $root = (string)backup_config_value('backup_manager.backup_root', backup_project_root() . '/private_backups');
    return rtrim($root, '/');
}

function backup_state_dir(): string
{
    $dir = (string)backup_config_value('backup_manager.state_dir', backup_apps_dir() . '/storage/backup_logs');
    return rtrim($dir, '/');
}

function backup_log_file(): string
{
    return backup_state_dir() . '/backup_manager.log';
}

function backup_ensure_dir(string $dir, int $mode = 0700): void
{
    if (is_dir($dir)) {
        return;
    }
    if (!mkdir($dir, $mode, true) && !is_dir($dir)) {
        throw new RuntimeException('ディレクトリを作成できません: ' . $dir);
    }
    @chmod($dir, $mode);
}

function backup_json_encode(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('JSON の生成に失敗しました。');
    }
    return $json;
}

function backup_write_log(string $level, string $message, array $context = []): void
{
    try {
        backup_ensure_dir(backup_state_dir(), 0700);
        $line = backup_now()->format('Y-m-d H:i:s') . ' [' . strtoupper($level) . '] ' . $message;
        if ($context !== []) {
            $line .= ' ' . backup_json_encode($context);
        }
        file_put_contents(backup_log_file(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    } catch (Throwable) {
        // Logging must never break backup execution.
    }
}

function backup_connection_config(string $connectionKey): array
{
    $config = backup_load_config();
    $connections = $config['db_connections'] ?? [];
    if (is_array($connections) && isset($connections[$connectionKey]) && is_array($connections[$connectionKey])) {
        return backup_normalize_db_config($connections[$connectionKey]);
    }

    $legacyKey = $connectionKey . '_db';
    if (isset($config[$legacyKey]) && is_array($config[$legacyKey])) {
        return backup_normalize_db_config($config[$legacyKey]);
    }

    if ($connectionKey === 'backup') {
        $base = [];
        if (is_array($connections) && isset($connections['account']) && is_array($connections['account'])) {
            $base = $connections['account'];
        } elseif (isset($config['account_db']) && is_array($config['account_db'])) {
            $base = $config['account_db'];
        }
        if ($base !== []) {
            $base['dbname'] = 'fitsc_backup';
            return backup_normalize_db_config($base);
        }
    }

    throw new RuntimeException('DB接続設定が見つかりません: ' . $connectionKey);
}

function backup_normalize_db_config(array $raw): array
{
    $host = (string)($raw['host'] ?? 'localhost');
    $port = (int)($raw['port'] ?? 3306);
    $dbname = (string)($raw['dbname'] ?? '');
    $charset = (string)($raw['charset'] ?? 'utf8mb4');
    $user = (string)($raw['user'] ?? '');
    $password = (string)($raw['password'] ?? ($raw['pass'] ?? ''));

    if ($dbname === '' || $user === '') {
        throw new RuntimeException('DB接続設定が不完全です。dbname/user を確認してください。');
    }

    return [
        'driver' => (string)($raw['driver'] ?? 'mysql'),
        'host' => $host,
        'port' => $port,
        'dbname' => $dbname,
        'charset' => $charset,
        'user' => $user,
        'password' => $password,
        'dsn' => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbname, $charset),
    ];
}

function backup_pdo(string $connectionKey = 'backup'): PDO
{
    $cfg = backup_connection_config($connectionKey);
    $pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET NAMES utf8mb4");
    return $pdo;
}

function backup_format_bytes(int|float|null $bytes): string
{
    $bytes = (float)($bytes ?? 0);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return $i === 0 ? sprintf('%d %s', (int)$bytes, $units[$i]) : sprintf('%.1f %s', $bytes, $units[$i]);
}

function backup_mask_path(?string $path): string
{
    $path = (string)$path;
    if ($path === '') {
        return '';
    }
    $root = backup_project_root();
    if (str_starts_with($path, $root)) {
        return '$ROOT' . substr($path, strlen($root));
    }
    return basename($path);
}

function backup_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function backup_status_label(string $status): string
{
    return match ($status) {
        'success' => '成功',
        'running' => '実行中',
        'failed' => '失敗',
        'partial' => '一部失敗',
        'warning' => '警告',
        'missing' => '欠損',
        'ok' => '正常',
        'skipped' => 'スキップ',
        'critical' => '重大',
        'error' => 'エラー',
        'info' => '情報',
        default => $status,
    };
}

function backup_default_db_targets(): array
{
    $configured = backup_config_value('backup_manager.db_targets', null);
    if (is_array($configured) && $configured !== []) {
        return $configured;
    }

    $targets = [];
    $connections = backup_config_value('db_connections', []);
    foreach (['account', 'book', 'forms', 'lend', 'mail'] as $key) {
        if (is_array($connections) && isset($connections[$key]) && is_array($connections[$key])) {
            $targets[] = [
                'key' => $key,
                'connection' => $key,
                'dbname' => (string)($connections[$key]['dbname'] ?? ''),
                'required' => in_array($key, ['account', 'book', 'forms'], true),
            ];
        }
    }

    // fitsc_mail exists in the current schema but older config_up.php may not yet
    // define a mail connection. Keep it as an optional target and log a warning
    // instead of making every daily backup fail.
    if (!array_filter($targets, static fn(array $t): bool => ($t['key'] ?? '') === 'mail')) {
        $targets[] = [
            'key' => 'mail',
            'connection' => 'mail',
            'dbname' => 'fitsc_mail',
            'required' => false,
        ];
    }

    return $targets;
}

function backup_default_file_targets(): array
{
    $configured = backup_config_value('backup_manager.file_targets', null);
    if (is_array($configured) && $configured !== []) {
        return $configured;
    }

    return [
        [
            'key' => 'apps',
            'label' => 'apps',
            'path' => backup_apps_dir(),
            'required' => true,
            'exclude' => [
                'apps/storage/maintenance/tmp',
                'apps/storage/backup_logs/tmp',
                'apps/storage/backups',
                'apps/backup_tmp',
                'apps/.git',
                'apps/vendor',
                'apps/node_modules',
            ],
        ],
        [
            'key' => 'public_html',
            'label' => 'public_html',
            'path' => backup_public_html_dir(),
            'required' => true,
            'exclude' => [
                'public_html/.git',
                'public_html/node_modules',
                'public_html/cache',
            ],
        ],
    ];
}

function backup_notify_on_failure(string $subject, string $body): void
{
    $to = (string)backup_config_value('backup_manager.alert_mail_to', (string)backup_config_value('storage_maintenance.report_mail_to', ''));
    if ($to === '') {
        return;
    }
    $from = (string)backup_config_value('from_addr', 'info@fit-sc.jp');
    $headers = 'From: ' . $from . "\r\n" . 'Content-Type: text/plain; charset=UTF-8';
    @mail($to, $subject, $body, $headers);
}
