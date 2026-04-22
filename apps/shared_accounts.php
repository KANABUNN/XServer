<?php

declare(strict_types=1);

function shared_accounts_config_from_candidates(array $candidates): array
{
    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || $candidate === '' || !is_file($candidate)) {
            continue;
        }
        $cfg = require $candidate;
        if (is_array($cfg)) {
            return $cfg;
        }
    }

    return [];
}

function shared_accounts_db_config(array $baseConfig = [], array $fallbackConfigCandidates = []): array
{
    $configs = [];
    if ($baseConfig !== []) {
        $configs[] = $baseConfig;
    }

    $fallback = shared_accounts_config_from_candidates($fallbackConfigCandidates);
    if ($fallback !== []) {
        $configs[] = $fallback;
    }

    foreach ($configs as $config) {
        $accountDb = $config['account_db'] ?? null;
        if (is_array($accountDb) && ($accountDb['host'] ?? '') !== '' && ($accountDb['user'] ?? $accountDb['username'] ?? '') !== '') {
            return [
                'host' => (string)($accountDb['host'] ?? 'localhost'),
                'port' => (int)($accountDb['port'] ?? 3306),
                'dbname' => (string)($accountDb['dbname'] ?? 'fitsc_account'),
                'charset' => (string)($accountDb['charset'] ?? 'utf8mb4'),
                'user' => (string)($accountDb['user'] ?? $accountDb['username'] ?? ''),
                'pass' => (string)($accountDb['pass'] ?? $accountDb['password'] ?? ''),
            ];
        }

        $db = $config['db'] ?? null;
        if (!is_array($db)) {
            continue;
        }

        $host = (string)($db['host'] ?? '');
        $user = (string)($db['user'] ?? $db['username'] ?? '');
        if ($host === '' || $user === '') {
            continue;
        }

        return [
            'host' => $host,
            'port' => (int)($db['port'] ?? 3306),
            'dbname' => 'fitsc_account',
            'charset' => (string)($db['charset'] ?? 'utf8mb4'),
            'user' => $user,
            'pass' => (string)($db['pass'] ?? $db['password'] ?? ''),
        ];
    }

    throw new RuntimeException('共通アカウントDB接続設定が見つかりません。config.php に account_db を追加するか、既存 MySQL 設定から fitsc_account を参照できるようにしてください。');
}

function shared_accounts_db(array $baseConfig = [], array $fallbackConfigCandidates = []): PDO
{
    static $instances = [];

    $cfg = shared_accounts_db_config($baseConfig, $fallbackConfigCandidates);
    $cacheKey = md5(json_encode([$cfg['host'], $cfg['port'], $cfg['dbname'], $cfg['charset'], $cfg['user']]));
    if (isset($instances[$cacheKey]) && $instances[$cacheKey] instanceof PDO) {
        return $instances[$cacheKey];
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['dbname'],
        $cfg['charset']
    );

    $instances[$cacheKey] = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $instances[$cacheKey];
}

function shared_accounts_schema_sql(): string
{
    return <<<SQL
CREATE TABLE IF NOT EXISTS shared_accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    login_id VARCHAR(100) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    organization_name VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shared_accounts_login_id (login_id),
    UNIQUE KEY uq_shared_accounts_email (email),
    KEY idx_shared_accounts_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shared_account_app_roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    app_key VARCHAR(64) NOT NULL,
    role_key VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shared_account_app_role (account_id, app_key, role_key),
    KEY idx_shared_account_app_lookup (app_key, role_key, account_id),
    CONSTRAINT fk_shared_account_app_roles_account FOREIGN KEY (account_id) REFERENCES shared_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
}

function shared_accounts_install_schema(PDO $pdo): void
{
    static $installed = [];
    $key = spl_object_hash($pdo);
    if (isset($installed[$key])) {
        return;
    }
    $installed[$key] = true;

    $statements = array_filter(array_map('trim', preg_split('/;\s*(?:\R|$)/u', shared_accounts_schema_sql()) ?: []));
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
}

function shared_accounts_normalize_role_keys(array $roleKeys): array
{
    $normalized = [];
    foreach ($roleKeys as $roleKey) {
        $roleKey = trim((string)$roleKey);
        if ($roleKey === '') {
            continue;
        }
        $normalized[$roleKey] = $roleKey;
    }
    return array_values($normalized);
}

function shared_accounts_hydrate_user_row(array $row, string $appKey): array
{
    $roleKeys = shared_accounts_normalize_role_keys(explode(',', (string)($row['role_keys'] ?? '')));
    $row['role_keys'] = $roleKeys;
    $row['app_key'] = $appKey;
    $row['is_active'] = (int)($row['is_active'] ?? 0);
    $row['name'] = (string)($row['display_name'] ?? '');
    $row['organization'] = (string)($row['organization_name'] ?? '');
    return $row;
}

function shared_accounts_fetch_by_identifier(PDO $pdo, string $identifier, string $appKey): ?array
{
    shared_accounts_install_schema($pdo);
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT a.id, a.login_id, a.email, a.password_hash, a.display_name, a.organization_name, a.is_active, a.last_login_at, a.created_at, a.updated_at, '
        . 'GROUP_CONCAT(DISTINCT ar.role_key ORDER BY ar.role_key SEPARATOR ",") AS role_keys '
        . 'FROM shared_accounts a '
        . 'INNER JOIN shared_account_app_roles ar ON ar.account_id = a.id AND ar.app_key = :app_key '
        . 'WHERE a.login_id = :login_id_identifier OR a.email = :email_identifier '
        . 'GROUP BY a.id '
        . 'LIMIT 1'
    );
    $stmt->execute([
        ':app_key' => $appKey,
        ':login_id_identifier' => $identifier,
        ':email_identifier' => $identifier,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return shared_accounts_hydrate_user_row($row, $appKey);
}

function shared_accounts_fetch_by_id(PDO $pdo, int $accountId, string $appKey): ?array
{
    shared_accounts_install_schema($pdo);
    if ($accountId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT a.id, a.login_id, a.email, a.password_hash, a.display_name, a.organization_name, a.is_active, a.last_login_at, a.created_at, a.updated_at, '
        . 'GROUP_CONCAT(DISTINCT ar.role_key ORDER BY ar.role_key SEPARATOR ",") AS role_keys '
        . 'FROM shared_accounts a '
        . 'INNER JOIN shared_account_app_roles ar ON ar.account_id = a.id AND ar.app_key = :app_key '
        . 'WHERE a.id = :id '
        . 'GROUP BY a.id '
        . 'LIMIT 1'
    );
    $stmt->execute([
        ':app_key' => $appKey,
        ':id' => $accountId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return shared_accounts_hydrate_user_row($row, $appKey);
}

function shared_accounts_list_users(PDO $pdo, string $appKey): array
{
    shared_accounts_install_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT a.id, a.login_id, a.email, a.display_name, a.organization_name, a.is_active, a.last_login_at, a.created_at, a.updated_at, '
        . 'GROUP_CONCAT(DISTINCT ar.role_key ORDER BY ar.role_key SEPARATOR ",") AS role_keys '
        . 'FROM shared_accounts a '
        . 'INNER JOIN shared_account_app_roles ar ON ar.account_id = a.id AND ar.app_key = :app_key '
        . 'GROUP BY a.id '
        . 'ORDER BY a.is_active DESC, a.id ASC'
    );
    $stmt->execute([':app_key' => $appKey]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(static fn(array $row): array => shared_accounts_hydrate_user_row($row, $appKey), $rows);
}

function shared_accounts_replace_roles(PDO $pdo, int $accountId, string $appKey, array $roleKeys): void
{
    $roleKeys = shared_accounts_normalize_role_keys($roleKeys);
    if ($roleKeys === []) {
        return;
    }

    $deleteStmt = $pdo->prepare('DELETE FROM shared_account_app_roles WHERE account_id = :account_id AND app_key = :app_key');
    $deleteStmt->execute([
        ':account_id' => $accountId,
        ':app_key' => $appKey,
    ]);

    $insertStmt = $pdo->prepare('INSERT INTO shared_account_app_roles (account_id, app_key, role_key) VALUES (:account_id, :app_key, :role_key)');
    foreach ($roleKeys as $roleKey) {
        $insertStmt->execute([
            ':account_id' => $accountId,
            ':app_key' => $appKey,
            ':role_key' => $roleKey,
        ]);
    }
}

function shared_accounts_create_user(PDO $pdo, array $data, string $appKey): int
{
    shared_accounts_install_schema($pdo);

    $loginId = trim((string)($data['login_id'] ?? ''));
    $passwordHash = (string)($data['password_hash'] ?? '');
    $displayName = trim((string)($data['display_name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $organizationName = trim((string)($data['organization_name'] ?? ''));
    $isActive = array_key_exists('is_active', $data) ? (int)(bool)$data['is_active'] : 1;
    $roleKeys = $data['role_keys'] ?? [$data['role_key'] ?? 'user'];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO shared_accounts (login_id, email, password_hash, display_name, organization_name, is_active) '
            . 'VALUES (:login_id, :email, :password_hash, :display_name, :organization_name, :is_active)'
        );
        $stmt->execute([
            ':login_id' => $loginId,
            ':email' => $email !== '' ? $email : null,
            ':password_hash' => $passwordHash,
            ':display_name' => $displayName,
            ':organization_name' => $organizationName !== '' ? $organizationName : null,
            ':is_active' => $isActive,
        ]);
        $accountId = (int)$pdo->lastInsertId();
        shared_accounts_replace_roles($pdo, $accountId, $appKey, is_array($roleKeys) ? $roleKeys : [(string)$roleKeys]);
        $pdo->commit();
        return $accountId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function shared_accounts_update_user(PDO $pdo, int $accountId, array $data, string $appKey): array
{
    shared_accounts_install_schema($pdo);
    if ($accountId <= 0) {
        throw new RuntimeException('更新対象のアカウントIDが不正です。');
    }

    $current = shared_accounts_fetch_by_id($pdo, $accountId, $appKey);
    if ($current === null) {
        throw new RuntimeException('更新対象のアカウントが見つかりません。');
    }

    $loginId = trim((string)($data['login_id'] ?? $current['login_id'] ?? ''));
    $displayName = trim((string)($data['display_name'] ?? $current['display_name'] ?? ''));
    $email = trim((string)($data['email'] ?? $current['email'] ?? ''));
    $organizationName = trim((string)($data['organization_name'] ?? $current['organization_name'] ?? ''));
    $isActive = array_key_exists('is_active', $data) ? (int)(bool)$data['is_active'] : (int)($current['is_active'] ?? 0);
    $passwordHash = (string)($data['password_hash'] ?? '');
    $roleKeys = $data['role_keys'] ?? [$data['role_key'] ?? ($current['role_keys'] ?? ['user'])];

    $pdo->beginTransaction();
    try {
        $params = [
            ':id' => $accountId,
            ':login_id' => $loginId,
            ':email' => $email !== '' ? $email : null,
            ':display_name' => $displayName,
            ':organization_name' => $organizationName !== '' ? $organizationName : null,
            ':is_active' => $isActive,
        ];
        $setSql = 'login_id = :login_id, email = :email, display_name = :display_name, organization_name = :organization_name, is_active = :is_active';
        if ($passwordHash !== '') {
            $setSql .= ', password_hash = :password_hash';
            $params[':password_hash'] = $passwordHash;
        }

        $stmt = $pdo->prepare('UPDATE shared_accounts SET ' . $setSql . ' WHERE id = :id');
        $stmt->execute($params);
        shared_accounts_replace_roles($pdo, $accountId, $appKey, is_array($roleKeys) ? $roleKeys : [(string)$roleKeys]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $updated = shared_accounts_fetch_by_id($pdo, $accountId, $appKey);
    if ($updated === null) {
        throw new RuntimeException('更新後のアカウント取得に失敗しました。');
    }
    return $updated;
}

function shared_accounts_count_users(PDO $pdo, string $appKey): int
{
    shared_accounts_install_schema($pdo);
    $stmt = $pdo->prepare('SELECT COUNT(DISTINCT account_id) FROM shared_account_app_roles WHERE app_key = :app_key');
    $stmt->execute([':app_key' => $appKey]);
    return (int)$stmt->fetchColumn();
}

function shared_accounts_count_active_role_users(PDO $pdo, string $appKey, string $roleKey): int
{
    shared_accounts_install_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT a.id) '
        . 'FROM shared_accounts a '
        . 'INNER JOIN shared_account_app_roles ar ON ar.account_id = a.id '
        . 'WHERE a.is_active = 1 AND ar.app_key = :app_key AND ar.role_key = :role_key'
    );
    $stmt->execute([
        ':app_key' => $appKey,
        ':role_key' => $roleKey,
    ]);
    return (int)$stmt->fetchColumn();
}

function shared_accounts_attempt_login(PDO $pdo, string $identifier, string $password, string $appKey): ?array
{
    shared_accounts_install_schema($pdo);
    static $dummyHash = '$2y$12$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LHtPflJvfCm';

    $user = shared_accounts_fetch_by_identifier($pdo, trim($identifier), $appKey);
    if ($user === null) {
        password_verify($password, $dummyHash);
        return null;
    }
    if ((int)($user['is_active'] ?? 0) !== 1) {
        password_verify($password, $dummyHash);
        return null;
    }
    if (!password_verify($password, (string)($user['password_hash'] ?? ''))) {
        return null;
    }

    if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        if ($newHash !== false) {
            $updateStmt = $pdo->prepare('UPDATE shared_accounts SET password_hash = :password_hash WHERE id = :id');
            $updateStmt->execute([
                ':password_hash' => $newHash,
                ':id' => (int)$user['id'],
            ]);
        }
    }

    $updateLastLoginStmt = $pdo->prepare('UPDATE shared_accounts SET last_login_at = NOW() WHERE id = :id');
    $updateLastLoginStmt->execute([':id' => (int)$user['id']]);

    return shared_accounts_fetch_by_id($pdo, (int)$user['id'], $appKey);
}
