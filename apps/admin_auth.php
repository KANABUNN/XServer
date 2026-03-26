<?php

declare(strict_types=1);

function admin_auth_require_db_helpers(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $candidates = [
        __DIR__ . '/db.php',
        dirname(__DIR__) . '/apps/db.php',
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            require_once $path;
            $loaded = true;
            return;
        }
    }

    throw new RuntimeException('db.php が見つかりません。');
}

function admin_auth_load_config(): array
{
    $candidates = [
        __DIR__ . '/config.php',
        dirname(__DIR__) . '/apps/config.php',
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            $cfg = require $path;
            if (!is_array($cfg)) {
                throw new RuntimeException('config.php が配列を返していません。');
            }
            return $cfg;
        }
    }

    throw new RuntimeException('config.php が見つかりません。');
}

function admin_auth_db_connect(): PDO
{
    admin_auth_require_db_helpers();
    $cfg = admin_auth_load_config();

    if (!function_exists('db_connect')) {
        throw new RuntimeException('db_connect() が利用できません。');
    }

    return db_connect($cfg);
}

function admin_auth_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }

    return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function admin_auth_base_path(): string
{
    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($scriptName === '') {
        return '/';
    }

    $dir = str_replace('\\', '/', dirname($scriptName));
    if ($dir === '' || $dir === '.' || $dir === '\\' || $dir === '/') {
        return '/';
    }

    return '/' . trim($dir, '/') . '/';
}

function admin_auth_join_base_path(string $relativePath = ''): string
{
    $base = admin_auth_base_path();
    $relativePath = ltrim($relativePath, '/');
    if ($relativePath === '') {
        return $base;
    }
    if ($base === '/') {
        return '/' . $relativePath;
    }
    return $base . $relativePath;
}

function admin_auth_bootstrap(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = admin_auth_is_https();
    if (function_exists('ini_set')) {
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_secure', $secure ? '1' : '0');
        @ini_set('session.cookie_samesite', 'Lax');
    }

    session_name('admin_book_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => admin_auth_base_path(),
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function admin_auth_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function admin_auth_role_labels(): array
{
    return [
        'viewer' => '閲覧者',
        'user' => '編集者',
        'admin' => '管理者',
    ];
}

function admin_auth_role_label(string $roleKey): string
{
    $labels = admin_auth_role_labels();
    return $labels[$roleKey] ?? $roleKey;
}

function admin_auth_role_priority(string $roleKey): int
{
    return match ($roleKey) {
        'admin' => 300,
        'user' => 200,
        'viewer' => 100,
        default => 0,
    };
}

function admin_auth_pick_primary_role(array $roleKeys): string
{
    $bestRole = 'viewer';
    $bestScore = -1;

    foreach ($roleKeys as $roleKey) {
        $score = admin_auth_role_priority((string)$roleKey);
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestRole = (string)$roleKey;
        }
    }

    return $bestRole;
}

function admin_auth_normalize_role_keys(array $roleKeys): array
{
    $normalized = [];
    foreach ($roleKeys as $roleKey) {
        $roleKey = trim((string)$roleKey);
        if ($roleKey === '') {
            continue;
        }
        $normalized[$roleKey] = $roleKey;
    }

    if ($normalized === []) {
        $normalized['viewer'] = 'viewer';
    }

    return array_values($normalized);
}

function admin_auth_role_permissions_map(): array
{
    return [
        'viewer' => [
            'application.view',
            'calendar.view',
            'mail.view',
            'application.export',
            'calendar.export',
            'mail.export',
        ],
        'user' => [
            'application.view',
            'application.download',
            'application.delete',
            'application.status.update',
            'calendar.view',
            'calendar.create',
            'calendar.update',
            'calendar.delete',
            'application.export',
            'calendar.export',
            'mail.view',
            'mail.form.view',
            'mail.send',
            'mail.export',
            'access.view',
            'access.edit',
        ],
        'admin' => [
            'application.view',
            'application.download',
            'application.delete',
            'application.status.update',
            'calendar.view',
            'calendar.create',
            'calendar.update',
            'calendar.delete',
            'application.export',
            'calendar.export',
            'mail.view',
            'mail.form.view',
            'mail.send',
            'mail.export',
            'access.view',
            'access.edit',
            'admin.user.manage',
            'admin.role.manage',
            'admin.audit.view',
            'admin.system.manage',
        ],
    ];
}

function admin_auth_all_permissions(): array
{
    static $all = null;
    if (is_array($all)) {
        return $all;
    }

    $all = [];
    foreach (admin_auth_role_permissions_map() as $permissions) {
        foreach ($permissions as $permission) {
            $all[$permission] = $permission;
        }
    }
    return array_values($all);
}

function admin_auth_permissions_for_roles(array $roleKeys): array
{
    $roleKeys = admin_auth_normalize_role_keys($roleKeys);
    $map = admin_auth_role_permissions_map();
    $permissions = [];

    foreach ($roleKeys as $roleKey) {
        foreach (($map[$roleKey] ?? []) as $permission) {
            $permissions[$permission] = $permission;
        }
    }

    return array_values($permissions);
}

function admin_auth_user_role_keys(array $user): array
{
    $roleKeys = $user['role_keys'] ?? [];
    if (!is_array($roleKeys)) {
        $roleKeys = [$user['role_key'] ?? 'viewer'];
    }
    return admin_auth_normalize_role_keys($roleKeys);
}

function admin_auth_user_has_role(array $user, string $roleKey): bool
{
    return in_array($roleKey, admin_auth_user_role_keys($user), true);
}

function admin_auth_user_permissions(array $user): array
{
    $permissions = $user['permissions'] ?? [];
    if (is_array($permissions) && $permissions !== []) {
        return array_values(array_unique(array_map('strval', $permissions)));
    }
    return admin_auth_permissions_for_roles(admin_auth_user_role_keys($user));
}

function admin_auth_has_permission(array $user, string $permission): bool
{
    if ($permission === '') {
        return true;
    }

    if (admin_auth_user_has_role($user, 'admin')) {
        return true;
    }

    return in_array($permission, admin_auth_user_permissions($user), true);
}

function admin_auth_get_csrf_token(bool $forceRegenerate = false): string
{
    admin_auth_bootstrap();
    if ($forceRegenerate || !is_string($_SESSION['admin_csrf_token'] ?? null) || ($_SESSION['admin_csrf_token'] ?? '') === '') {
        $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['admin_csrf_token'];
}

function admin_auth_csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . admin_auth_h(admin_auth_get_csrf_token()) . '">';
}

function admin_auth_validate_csrf_token(?string $token): bool
{
    admin_auth_bootstrap();
    $expected = (string)($_SESSION['admin_csrf_token'] ?? '');
    $provided = (string)$token;
    if ($expected === '' || $provided === '') {
        return false;
    }
    return hash_equals($expected, $provided);
}

function admin_auth_require_csrf(): void
{
    $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? ''));
    if (admin_auth_validate_csrf_token($token)) {
        return;
    }

    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $expectsJson = str_contains($accept, 'application/json')
        || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';

    if ($expectsJson) {
        admin_auth_send_json([
            'ok' => false,
            'message' => 'CSRF トークンが無効です。ページを再読み込みしてからやり直してください。',
            'login_url' => admin_auth_login_url(),
        ], 403);
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'CSRF トークンが無効です。';
    exit;
}

function admin_auth_schema_sql(): string
{
    return <<<SQL
CREATE TABLE IF NOT EXISTS admin_roles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_key VARCHAR(32) NOT NULL,
    role_name VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_roles_role_key (role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    login_id VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_users_login_id (login_id),
    KEY idx_admin_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_user_roles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_user_roles_user_role (user_id, role_id),
    KEY idx_admin_user_roles_role_id (role_id),
    CONSTRAINT fk_admin_user_roles_user FOREIGN KEY (user_id) REFERENCES admin_users (id) ON DELETE CASCADE,
    CONSTRAINT fk_admin_user_roles_role FOREIGN KEY (role_id) REFERENCES admin_roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id INT UNSIGNED DEFAULT NULL,
    actor_login_id VARCHAR(100) DEFAULT NULL,
    actor_display_name VARCHAR(100) DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(100) DEFAULT NULL,
    target_id VARCHAR(191) DEFAULT NULL,
    summary_json LONGTEXT DEFAULT NULL,
    ip_address VARCHAR(64) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_audit_logs_created_at (created_at),
    KEY idx_admin_audit_logs_actor_user_id (actor_user_id),
    KEY idx_admin_audit_logs_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO admin_roles (role_key, role_name)
VALUES
    ('viewer', '閲覧者'),
    ('user', '編集者'),
    ('admin', '管理者')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);
SQL;
}

function admin_auth_install_schema(PDO $pdo): void
{
    static $installedForRequest = false;
    if ($installedForRequest) {
        return;
    }
    $installedForRequest = true;

    $sql = admin_auth_schema_sql();
    $statements = array_filter(array_map('trim', preg_split('/;\s*(?:\R|$)/u', $sql) ?: []));
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
}

function admin_auth_count_users(PDO $pdo): int
{
    admin_auth_install_schema($pdo);
    $stmt = $pdo->query('SELECT COUNT(*) FROM admin_users');
    return (int)$stmt->fetchColumn();
}

function admin_auth_fetch_role_id(PDO $pdo, string $roleKey): int
{
    admin_auth_install_schema($pdo);
    $stmt = $pdo->prepare('SELECT id FROM admin_roles WHERE role_key = :role_key LIMIT 1');
    $stmt->execute([':role_key' => $roleKey]);
    return (int)$stmt->fetchColumn();
}

function admin_auth_fetch_user_by_login(PDO $pdo, string $loginId): ?array
{
    admin_auth_install_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT u.id, u.login_id, u.password_hash, u.display_name, u.email, u.is_active, u.last_login_at, u.created_at, u.updated_at, '
        . 'GROUP_CONCAT(r.role_key ORDER BY r.id SEPARATOR ",") AS role_keys '
        . 'FROM admin_users u '
        . 'LEFT JOIN admin_user_roles ur ON ur.user_id = u.id '
        . 'LEFT JOIN admin_roles r ON r.id = ur.role_id '
        . 'WHERE u.login_id = :login_id '
        . 'GROUP BY u.id '
        . 'LIMIT 1'
    );
    $stmt->execute([':login_id' => $loginId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return admin_auth_hydrate_user_row($row);
}

function admin_auth_fetch_user_by_id(PDO $pdo, int $userId): ?array
{
    admin_auth_install_schema($pdo);
    if ($userId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT u.id, u.login_id, u.password_hash, u.display_name, u.email, u.is_active, u.last_login_at, u.created_at, u.updated_at, '
        . 'GROUP_CONCAT(r.role_key ORDER BY r.id SEPARATOR ",") AS role_keys '
        . 'FROM admin_users u '
        . 'LEFT JOIN admin_user_roles ur ON ur.user_id = u.id '
        . 'LEFT JOIN admin_roles r ON r.id = ur.role_id '
        . 'WHERE u.id = :id '
        . 'GROUP BY u.id '
        . 'LIMIT 1'
    );
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return admin_auth_hydrate_user_row($row);
}

function admin_auth_hydrate_user_row(array $row): array
{
    $roles = [];
    foreach (explode(',', (string)($row['role_keys'] ?? '')) as $roleKey) {
        $roleKey = trim($roleKey);
        if ($roleKey !== '') {
            $roles[] = $roleKey;
        }
    }
    if ($roles === []) {
        $roles = ['viewer'];
    }

    $row['role_keys'] = $roles;
    $row['role_key'] = admin_auth_pick_primary_role($roles);
    $row['role_label'] = admin_auth_role_label((string)$row['role_key']);
    $row['permissions'] = admin_auth_permissions_for_roles($roles);
    $row['is_active'] = (int)($row['is_active'] ?? 0);
    return $row;
}

function admin_auth_list_users(PDO $pdo): array
{
    admin_auth_install_schema($pdo);
    $stmt = $pdo->query(
        'SELECT u.id, u.login_id, u.display_name, u.email, u.is_active, u.last_login_at, u.created_at, u.updated_at, '
        . 'GROUP_CONCAT(r.role_key ORDER BY r.id SEPARATOR ",") AS role_keys '
        . 'FROM admin_users u '
        . 'LEFT JOIN admin_user_roles ur ON ur.user_id = u.id '
        . 'LEFT JOIN admin_roles r ON r.id = ur.role_id '
        . 'GROUP BY u.id '
        . 'ORDER BY u.is_active DESC, u.id ASC'
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map('admin_auth_hydrate_user_row', $rows);
}

function admin_auth_create_user(PDO $pdo, array $data): int
{
    admin_auth_install_schema($pdo);
    $loginId = trim((string)($data['login_id'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $displayName = trim((string)($data['display_name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $roleKey = trim((string)($data['role_key'] ?? 'admin'));

    admin_auth_validate_user_payload($loginId, $displayName, $email, $roleKey, $password, true);

    $roleId = admin_auth_fetch_role_id($pdo, $roleKey);
    if ($roleId < 1) {
        throw new RuntimeException('admin_roles に必要なロールがありません。');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        throw new RuntimeException('パスワードハッシュを生成できませんでした。');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_users (login_id, password_hash, display_name, email, is_active) '
            . 'VALUES (:login_id, :password_hash, :display_name, :email, 1)'
        );
        $stmt->execute([
            ':login_id' => $loginId,
            ':password_hash' => $hash,
            ':display_name' => $displayName,
            ':email' => $email !== '' ? $email : null,
        ]);

        $userId = (int)$pdo->lastInsertId();

        $linkStmt = $pdo->prepare('INSERT INTO admin_user_roles (user_id, role_id) VALUES (:user_id, :role_id)');
        $linkStmt->execute([
            ':user_id' => $userId,
            ':role_id' => $roleId,
        ]);

        $pdo->commit();
        return $userId;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23000') {
            throw new RuntimeException('そのログインIDは既に使用されています。');
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function admin_auth_update_user(PDO $pdo, int $userId, array $data, ?array $actorUser = null): array
{
    admin_auth_install_schema($pdo);
    if ($userId <= 0) {
        throw new RuntimeException('更新対象のユーザーIDが不正です。');
    }

    $current = admin_auth_fetch_user_by_id($pdo, $userId);
    if ($current === null) {
        throw new RuntimeException('更新対象のユーザーが見つかりません。');
    }

    $loginId = trim((string)($data['login_id'] ?? $current['login_id'] ?? ''));
    $displayName = trim((string)($data['display_name'] ?? $current['display_name'] ?? ''));
    $email = trim((string)($data['email'] ?? $current['email'] ?? ''));
    $roleKey = trim((string)($data['role_key'] ?? $current['role_key'] ?? 'viewer'));
    $password = (string)($data['password'] ?? '');
    $isActive = array_key_exists('is_active', $data) ? (int)(bool)$data['is_active'] : (int)($current['is_active'] ?? 0);

    admin_auth_validate_user_payload($loginId, $displayName, $email, $roleKey, $password, false);

    $roleId = admin_auth_fetch_role_id($pdo, $roleKey);
    if ($roleId < 1) {
        throw new RuntimeException('admin_roles に必要なロールがありません。');
    }

    $currentRoleKey = (string)($current['role_key'] ?? 'viewer');
    $wasActiveAdmin = $currentRoleKey === 'admin' && (int)($current['is_active'] ?? 0) === 1;
    $willRemainActiveAdmin = $roleKey === 'admin' && $isActive === 1;
    if ($wasActiveAdmin && !$willRemainActiveAdmin && admin_auth_count_active_admin_users($pdo) <= 1) {
        throw new RuntimeException('最後の管理者を無効化または降格することはできません。');
    }

    $pdo->beginTransaction();
    try {
        $params = [
            ':id' => $userId,
            ':login_id' => $loginId,
            ':display_name' => $displayName,
            ':email' => $email !== '' ? $email : null,
            ':is_active' => $isActive,
        ];

        $setSql = 'login_id = :login_id, display_name = :display_name, email = :email, is_active = :is_active';
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($hash === false) {
                throw new RuntimeException('パスワードハッシュを生成できませんでした。');
            }
            $setSql .= ', password_hash = :password_hash';
            $params[':password_hash'] = $hash;
        }

        $stmt = $pdo->prepare('UPDATE admin_users SET ' . $setSql . ' WHERE id = :id');
        $stmt->execute($params);

        $deleteRoleStmt = $pdo->prepare('DELETE FROM admin_user_roles WHERE user_id = :user_id');
        $deleteRoleStmt->execute([':user_id' => $userId]);

        $insertRoleStmt = $pdo->prepare('INSERT INTO admin_user_roles (user_id, role_id) VALUES (:user_id, :role_id)');
        $insertRoleStmt->execute([':user_id' => $userId, ':role_id' => $roleId]);

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23000') {
            throw new RuntimeException('そのログインIDは既に使用されています。');
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $updated = admin_auth_fetch_user_by_id($pdo, $userId);
    if ($updated === null) {
        throw new RuntimeException('更新後のユーザー取得に失敗しました。');
    }

    if ($actorUser !== null && (int)($actorUser['id'] ?? 0) === $userId) {
        if ((int)$updated['is_active'] === 1) {
            admin_auth_login_user($updated);
        } else {
            admin_auth_logout();
        }
    }

    return $updated;
}

function admin_auth_validate_user_payload(string $loginId, string $displayName, string $email, string $roleKey, string $password, bool $passwordRequired): void
{
    if ($loginId === '' || !preg_match('/\A[a-zA-Z0-9_.-]{3,100}\z/', $loginId)) {
        throw new RuntimeException('ログインIDは 3〜100 文字の英数字・._- で入力してください。');
    }
    if ($displayName === '') {
        throw new RuntimeException('表示名を入力してください。');
    }
    if (!in_array($roleKey, ['viewer', 'user', 'admin'], true)) {
        throw new RuntimeException('ロールの指定が不正です。');
    }
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('メールアドレスの形式が不正です。');
    }
    if ($passwordRequired && mb_strlen($password, 'UTF-8') < 10) {
        throw new RuntimeException('パスワードは 10 文字以上で入力してください。');
    }
    if (!$passwordRequired && $password !== '' && mb_strlen($password, 'UTF-8') < 10) {
        throw new RuntimeException('パスワードを変更する場合は 10 文字以上で入力してください。');
    }
}

function admin_auth_count_active_admin_users(PDO $pdo): int
{
    admin_auth_install_schema($pdo);
    $stmt = $pdo->query(
        'SELECT COUNT(*) FROM admin_users u '
        . 'INNER JOIN admin_user_roles ur ON ur.user_id = u.id '
        . 'INNER JOIN admin_roles r ON r.id = ur.role_id '
        . 'WHERE u.is_active = 1 AND r.role_key = "admin"'
    );
    return (int)$stmt->fetchColumn();
}

function admin_auth_attempt_login(PDO $pdo, string $loginId, string $password): ?array
{
    admin_auth_install_schema($pdo);
    $user = admin_auth_fetch_user_by_login($pdo, trim($loginId));
    if ($user === null) {
        return null;
    }
    if ((int)($user['is_active'] ?? 0) !== 1) {
        return null;
    }
    if (!password_verify($password, (string)($user['password_hash'] ?? ''))) {
        return null;
    }

    if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        if ($newHash !== false) {
            $updateStmt = $pdo->prepare('UPDATE admin_users SET password_hash = :password_hash WHERE id = :id');
            $updateStmt->execute([
                ':password_hash' => $newHash,
                ':id' => (int)$user['id'],
            ]);
        }
    }

    $updateLastLoginStmt = $pdo->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = :id');
    $updateLastLoginStmt->execute([':id' => (int)$user['id']]);

    return admin_auth_fetch_user_by_id($pdo, (int)$user['id']);
}

function admin_auth_login_user(array $user): void
{
    admin_auth_bootstrap();
    session_regenerate_id(true);

    $roleKeys = admin_auth_user_role_keys($user);
    $primaryRole = (string)($user['role_key'] ?? admin_auth_pick_primary_role($roleKeys));

    $_SESSION['admin_user'] = [
        'id' => (int)($user['id'] ?? 0),
        'login_id' => (string)($user['login_id'] ?? ''),
        'display_name' => (string)($user['display_name'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'role_key' => $primaryRole,
        'role_label' => admin_auth_role_label($primaryRole),
        'role_keys' => $roleKeys,
        'permissions' => admin_auth_permissions_for_roles($roleKeys),
        'last_login_at' => (string)($user['last_login_at'] ?? ''),
        'logged_in_at' => date('c'),
    ];

    admin_auth_get_csrf_token(true);
}

function admin_auth_current_user(): ?array
{
    admin_auth_bootstrap();
    $user = $_SESSION['admin_user'] ?? null;
    if (!is_array($user) || (int)($user['id'] ?? 0) < 1) {
        return null;
    }

    $roleKeys = admin_auth_user_role_keys($user);
    $primaryRole = (string)($user['role_key'] ?? admin_auth_pick_primary_role($roleKeys));
    $permissions = admin_auth_permissions_for_roles($roleKeys);

    $user['role_keys'] = $roleKeys;
    $user['role_key'] = $primaryRole;
    $user['role_label'] = admin_auth_role_label($primaryRole);
    $user['permissions'] = $permissions;
    $_SESSION['admin_user'] = $user;

    return $user;
}

function admin_auth_is_logged_in(): bool
{
    return admin_auth_current_user() !== null;
}

function admin_auth_logout(): void
{
    admin_auth_bootstrap();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? admin_auth_base_path(),
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }
    session_destroy();
}

function admin_auth_normalize_return_to(?string $value): string
{
    $basePath = admin_auth_base_path();
    $value = trim((string)$value);

    if ($value === '') {
        return $basePath;
    }

    if (preg_match('/\Ahttps?:\/\//i', $value)) {
        return $basePath;
    }

    if ($value[0] !== '/') {
        return admin_auth_join_base_path($value);
    }

    if ($basePath !== '/' && !str_starts_with($value, $basePath)) {
        return $basePath;
    }

    return $value;
}

function admin_auth_current_request_uri(): string
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? admin_auth_base_path());
    return admin_auth_normalize_return_to($uri);
}

function admin_auth_login_url(?string $returnTo = null): string
{
    $target = admin_auth_normalize_return_to($returnTo ?: admin_auth_current_request_uri());
    return 'login.php?return_to=' . rawurlencode($target);
}

function admin_auth_send_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function admin_auth_require_permission(string $permission, ?array $user = null): array
{
    $user = $user ?: admin_auth_require_login();
    if (admin_auth_has_permission($user, $permission)) {
        return $user;
    }

    admin_auth_send_json([
        'ok' => false,
        'message' => 'この操作を実行する権限がありません。',
        'required_permission' => $permission,
    ], 403);
}

function admin_auth_require_login(array $options = []): array
{
    $user = admin_auth_current_user();
    if ($user !== null) {
        return $user;
    }

    $loginUrl = admin_auth_login_url();
    $isFetch = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $expectsJson = $isFetch
        || str_contains($accept, 'application/json')
        || strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET';

    if ($expectsJson) {
        admin_auth_send_json([
            'ok' => false,
            'message' => 'ログインが必要です。',
            'login_url' => $loginUrl,
        ], 401);
    }

    header('Location: ' . $loginUrl, true, 302);
    exit;
}

function admin_auth_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        $value = trim((string)($_SERVER[$key] ?? ''));
        if ($value === '') {
            continue;
        }
        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $parts = array_map('trim', explode(',', $value));
            return (string)($parts[0] ?? '');
        }
        return $value;
    }
    return '';
}

function admin_auth_write_audit_log(PDO $pdo, ?array $actorUser, string $action, ?string $targetType = null, string|int|null $targetId = null, array $summary = []): int
{
    admin_auth_install_schema($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO admin_audit_logs (actor_user_id, actor_login_id, actor_display_name, action, target_type, target_id, summary_json, ip_address, user_agent) '
        . 'VALUES (:actor_user_id, :actor_login_id, :actor_display_name, :action, :target_type, :target_id, :summary_json, :ip_address, :user_agent)'
    );

    $summaryJson = $summary !== []
        ? json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null;

    $stmt->execute([
        ':actor_user_id' => is_array($actorUser) && (int)($actorUser['id'] ?? 0) > 0 ? (int)$actorUser['id'] : null,
        ':actor_login_id' => is_array($actorUser) ? ((string)($actorUser['login_id'] ?? '') !== '' ? (string)$actorUser['login_id'] : null) : null,
        ':actor_display_name' => is_array($actorUser) ? ((string)($actorUser['display_name'] ?? '') !== '' ? (string)$actorUser['display_name'] : null) : null,
        ':action' => $action,
        ':target_type' => $targetType !== null && $targetType !== '' ? $targetType : null,
        ':target_id' => $targetId !== null && (string)$targetId !== '' ? (string)$targetId : null,
        ':summary_json' => $summaryJson,
        ':ip_address' => ($ip = admin_auth_client_ip()) !== '' ? $ip : null,
        ':user_agent' => ($ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))) !== '' ? mb_substr($ua, 0, 255, 'UTF-8') : null,
    ]);

    return (int)$pdo->lastInsertId();
}

function admin_auth_list_audit_logs(PDO $pdo, int $limit = 100): array
{
    admin_auth_install_schema($pdo);
    $limit = max(1, min(500, $limit));
    $stmt = $pdo->prepare(
        'SELECT id, actor_user_id, actor_login_id, actor_display_name, action, target_type, target_id, summary_json, ip_address, user_agent, created_at '
        . 'FROM admin_audit_logs ORDER BY id DESC LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        $row['summary'] = null;
        $summaryJson = trim((string)($row['summary_json'] ?? ''));
        if ($summaryJson !== '') {
            $decoded = json_decode($summaryJson, true);
            if (is_array($decoded)) {
                $row['summary'] = $decoded;
            }
        }
        return $row;
    }, $rows);
}
