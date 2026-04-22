<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function account_site_table_columns(PDO $pdo, string $tableName): array
{
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $tableName) . '`');
    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!empty($row['Field'])) {
            $columns[(string)$row['Field']] = true;
        }
    }
    return $columns;
}

function account_site_table_indexes(PDO $pdo, string $tableName): array
{
    $stmt = $pdo->query('SHOW INDEX FROM `' . str_replace('`', '``', $tableName) . '`');
    $indexes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!empty($row['Key_name'])) {
            $indexes[(string)$row['Key_name']] = true;
        }
    }
    return $indexes;
}

function account_site_install_extra_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admin_audit_logs ('
        . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . 'actor_account_id BIGINT UNSIGNED DEFAULT NULL,'
        . 'target_account_id BIGINT UNSIGNED DEFAULT NULL,'
        . 'action_key VARCHAR(64) NOT NULL,'
        . 'detail_json LONGTEXT DEFAULT NULL,'
        . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . 'PRIMARY KEY (id),'
        . 'KEY idx_admin_audit_logs_created_at (created_at),'
        . 'KEY idx_admin_audit_logs_actor (actor_account_id),'
        . 'KEY idx_admin_audit_logs_target (target_account_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $columns = account_site_table_columns($pdo, 'admin_audit_logs');

    if (!isset($columns['actor_account_id'])) {
        $pdo->exec('ALTER TABLE admin_audit_logs ADD COLUMN actor_account_id BIGINT UNSIGNED DEFAULT NULL AFTER id');
        $columns['actor_account_id'] = true;
    }
    if (!isset($columns['target_account_id'])) {
        $after = isset($columns['actor_account_id']) ? 'actor_account_id' : 'id';
        $pdo->exec('ALTER TABLE admin_audit_logs ADD COLUMN target_account_id BIGINT UNSIGNED DEFAULT NULL AFTER ' . $after);
        $columns['target_account_id'] = true;
    }
    if (!isset($columns['action_key'])) {
        $after = isset($columns['target_account_id']) ? 'target_account_id' : 'id';
        $pdo->exec('ALTER TABLE admin_audit_logs ADD COLUMN action_key VARCHAR(64) DEFAULT NULL AFTER ' . $after);
        $columns['action_key'] = true;
    }
    if (!isset($columns['detail_json'])) {
        $after = isset($columns['action_key']) ? 'action_key' : 'id';
        $pdo->exec('ALTER TABLE admin_audit_logs ADD COLUMN detail_json LONGTEXT DEFAULT NULL AFTER ' . $after);
        $columns['detail_json'] = true;
    }

    if (isset($columns['actor_user_id']) && isset($columns['actor_account_id'])) {
        $pdo->exec('UPDATE admin_audit_logs SET actor_account_id = actor_user_id WHERE actor_account_id IS NULL AND actor_user_id IS NOT NULL');
    }
    if (isset($columns['action']) && isset($columns['action_key'])) {
        $pdo->exec('UPDATE admin_audit_logs SET action_key = action WHERE (action_key IS NULL OR action_key = "") AND action IS NOT NULL');
    }
    if (isset($columns['summary_json']) && isset($columns['detail_json'])) {
        $pdo->exec('UPDATE admin_audit_logs SET detail_json = summary_json WHERE detail_json IS NULL AND summary_json IS NOT NULL');
    }

    $indexes = account_site_table_indexes($pdo, 'admin_audit_logs');
    if (!isset($indexes['idx_admin_audit_logs_actor'])) {
        $pdo->exec('ALTER TABLE admin_audit_logs ADD KEY idx_admin_audit_logs_actor (actor_account_id)');
    }
    if (!isset($indexes['idx_admin_audit_logs_target'])) {
        $pdo->exec('ALTER TABLE admin_audit_logs ADD KEY idx_admin_audit_logs_target (target_account_id)');
    }
}

function account_site_audit(PDO $pdo, ?int $actorId, ?int $targetId, string $actionKey, array $detail = []): void
{
    account_site_install_extra_schema($pdo);
    $stmt = $pdo->prepare('INSERT INTO admin_audit_logs (actor_account_id, target_account_id, action_key, detail_json) VALUES (:actor, :target, :action_key, :detail_json)');
    $stmt->execute([
        ':actor' => $actorId,
        ':target' => $targetId,
        ':action_key' => $actionKey,
        ':detail_json' => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function account_site_parse_role_pairs(?string $pairs): array
{
    $rolesByApp = [];
    foreach (explode('|', (string)$pairs) as $pair) {
        if ($pair === '' || !str_contains($pair, ':')) {
            continue;
        }
        [$appKey, $roleKey] = explode(':', $pair, 2);
        $appKey = trim($appKey);
        $roleKey = trim($roleKey);
        if ($appKey === '' || $roleKey === '') {
            continue;
        }
        $rolesByApp[$appKey] ??= [];
        $rolesByApp[$appKey][$roleKey] = $roleKey;
    }

    foreach ($rolesByApp as $appKey => $roleMap) {
        $rolesByApp[$appKey] = array_values($roleMap);
    }

    return $rolesByApp;
}

function account_site_fetch_accounts(PDO $pdo, string $search = ''): array
{
    $params = [];
    $whereSql = '';
    $search = trim($search);
    if ($search !== '') {
        $whereSql = 'WHERE a.login_id LIKE :q OR a.display_name LIKE :q OR a.email LIKE :q OR a.organization_name LIKE :q';
        $params[':q'] = '%' . $search . '%';
    }

    $sql = 'SELECT a.id, a.login_id, a.email, a.display_name, a.organization_name, a.is_active, a.last_login_at, a.created_at, a.updated_at, '
        . 'GROUP_CONCAT(DISTINCT CONCAT(ar.app_key, ":", ar.role_key) ORDER BY ar.app_key, ar.role_key SEPARATOR "|") AS role_pairs '
        . 'FROM shared_accounts a '
        . 'LEFT JOIN shared_account_app_roles ar ON ar.account_id = a.id '
        . $whereSql . ' '
        . 'GROUP BY a.id '
        . 'ORDER BY a.is_active DESC, a.display_name ASC, a.id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['is_active'] = (int)$row['is_active'];
        $row['roles_by_app'] = account_site_parse_role_pairs($row['role_pairs'] ?? null);
    }
    unset($row);

    return $rows;
}

function account_site_fetch_account(PDO $pdo, int $accountId): ?array
{
    if ($accountId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT a.id, a.login_id, a.email, a.display_name, a.organization_name, a.is_active, a.last_login_at, a.created_at, a.updated_at, '
        . 'GROUP_CONCAT(DISTINCT CONCAT(ar.app_key, ":", ar.role_key) ORDER BY ar.app_key, ar.role_key SEPARATOR "|") AS role_pairs '
        . 'FROM shared_accounts a '
        . 'LEFT JOIN shared_account_app_roles ar ON ar.account_id = a.id '
        . 'WHERE a.id = :id '
        . 'GROUP BY a.id '
        . 'LIMIT 1'
    );
    $stmt->execute([':id' => $accountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $row['id'] = (int)$row['id'];
    $row['is_active'] = (int)$row['is_active'];
    $row['roles_by_app'] = account_site_parse_role_pairs($row['role_pairs'] ?? null);
    return $row;
}

function account_site_count_active_account_admins(PDO $pdo, ?int $excludeAccountId = null): int
{
    $sql = 'SELECT COUNT(DISTINCT a.id) '
        . 'FROM shared_accounts a '
        . 'INNER JOIN shared_account_app_roles ar ON ar.account_id = a.id '
        . 'WHERE a.is_active = 1 AND ar.app_key = :app_key AND ar.role_key = :role_key';
    $params = [
        ':app_key' => account_site_app_key(),
        ':role_key' => 'admin',
    ];

    if ($excludeAccountId !== null && $excludeAccountId > 0) {
        $sql .= ' AND a.id <> :exclude_id';
        $params[':exclude_id'] = $excludeAccountId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function account_site_recent_audit_logs(PDO $pdo, int $limit = 20): array
{
    account_site_install_extra_schema($pdo);
    $limit = max(1, min($limit, 100));
    $stmt = $pdo->query(
        'SELECT l.id, l.actor_account_id, l.target_account_id, l.action_key, l.detail_json, l.created_at, '
        . 'actor.display_name AS actor_name, target.display_name AS target_name '
        . 'FROM admin_audit_logs l '
        . 'LEFT JOIN shared_accounts actor ON actor.id = l.actor_account_id '
        . 'LEFT JOIN shared_accounts target ON target.id = l.target_account_id '
        . 'ORDER BY l.id DESC LIMIT ' . $limit
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function account_site_normalize_role_input(array $input): array
{
    $definitions = account_site_app_definitions();
    $normalized = [];

    foreach ($definitions as $appKey => $meta) {
        $requested = $input[$appKey] ?? [];
        if (!is_array($requested)) {
            $requested = [$requested];
        }
        foreach ($requested as $roleKey) {
            $roleKey = trim((string)$roleKey);
            if ($roleKey === '' || !isset($meta['roles'][$roleKey])) {
                continue;
            }
            $normalized[$appKey][$roleKey] = $roleKey;
        }
        if (isset($normalized[$appKey])) {
            $normalized[$appKey] = array_values($normalized[$appKey]);
        }
    }

    return $normalized;
}

function account_site_validate_payload(array $payload, bool $isCreate): array
{
    $errors = [];
    if (trim((string)($payload['login_id'] ?? '')) === '') {
        $errors[] = 'ログインIDを入力してください。';
    }
    if (trim((string)($payload['display_name'] ?? '')) === '') {
        $errors[] = '表示名を入力してください。';
    }
    if ($isCreate && trim((string)($payload['password'] ?? '')) === '') {
        $errors[] = '新規作成時は初期パスワードが必要です。';
    }

    $email = trim((string)($payload['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'メールアドレスの形式が不正です。';
    }

    $rolesByApp = account_site_normalize_role_input($payload['roles_by_app'] ?? []);
    if ($rolesByApp === []) {
        $errors[] = '少なくとも1つのアプリ権限を付与してください。';
    }

    return [$errors, $rolesByApp];
}

function account_site_replace_all_roles(PDO $pdo, int $accountId, array $rolesByApp): void
{
    $deleteStmt = $pdo->prepare('DELETE FROM shared_account_app_roles WHERE account_id = :account_id');
    $deleteStmt->execute([':account_id' => $accountId]);

    $insertStmt = $pdo->prepare('INSERT INTO shared_account_app_roles (account_id, app_key, role_key) VALUES (:account_id, :app_key, :role_key)');
    foreach ($rolesByApp as $appKey => $roleKeys) {
        foreach ($roleKeys as $roleKey) {
            $insertStmt->execute([
                ':account_id' => $accountId,
                ':app_key' => $appKey,
                ':role_key' => $roleKey,
            ]);
        }
    }
}

function account_site_save_account(PDO $pdo, array $payload, int $actorId): int
{
    [$errors, $rolesByApp] = account_site_validate_payload($payload, empty($payload['id']));
    if ($errors !== []) {
        throw new RuntimeException(implode("\n", $errors));
    }

    $accountId = (int)($payload['id'] ?? 0);
    $loginId = trim((string)($payload['login_id'] ?? ''));
    $displayName = trim((string)($payload['display_name'] ?? ''));
    $email = trim((string)($payload['email'] ?? ''));
    $organizationName = trim((string)($payload['organization_name'] ?? ''));
    $password = (string)($payload['password'] ?? '');
    $isActive = !empty($payload['is_active']) ? 1 : 0;

    if ($accountId > 0) {
        $current = account_site_fetch_account($pdo, $accountId);
        if ($current === null) {
            throw new RuntimeException('更新対象のアカウントが見つかりません。');
        }

        $currentHasPortalAdmin = in_array('admin', $current['roles_by_app'][account_site_app_key()] ?? [], true);
        $nextHasPortalAdmin = in_array('admin', $rolesByApp[account_site_app_key()] ?? [], true);
        if ($currentHasPortalAdmin && (!$nextHasPortalAdmin || $isActive !== 1) && account_site_count_active_account_admins($pdo, $accountId) === 0) {
            throw new RuntimeException('最後のアカウント管理者を無効化または権限削除することはできません。');
        }
    }

    $pdo->beginTransaction();
    try {
        if ($accountId > 0) {
            $params = [
                ':id' => $accountId,
                ':login_id' => $loginId,
                ':email' => $email !== '' ? $email : null,
                ':display_name' => $displayName,
                ':organization_name' => $organizationName !== '' ? $organizationName : null,
                ':is_active' => $isActive,
            ];
            $setSql = 'login_id = :login_id, email = :email, display_name = :display_name, organization_name = :organization_name, is_active = :is_active';
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                if ($hash === false) {
                    throw new RuntimeException('パスワードハッシュの生成に失敗しました。');
                }
                $setSql .= ', password_hash = :password_hash';
                $params[':password_hash'] = $hash;
            }
            $stmt = $pdo->prepare('UPDATE shared_accounts SET ' . $setSql . ' WHERE id = :id');
            $stmt->execute($params);
            account_site_replace_all_roles($pdo, $accountId, $rolesByApp);
            account_site_audit($pdo, $actorId, $accountId, 'account.update', [
                'roles_by_app' => $rolesByApp,
                'is_active' => $isActive,
            ]);
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($hash === false) {
                throw new RuntimeException('パスワードハッシュの生成に失敗しました。');
            }
            $stmt = $pdo->prepare('INSERT INTO shared_accounts (login_id, email, password_hash, display_name, organization_name, is_active) VALUES (:login_id, :email, :password_hash, :display_name, :organization_name, :is_active)');
            $stmt->execute([
                ':login_id' => $loginId,
                ':email' => $email !== '' ? $email : null,
                ':password_hash' => $hash,
                ':display_name' => $displayName,
                ':organization_name' => $organizationName !== '' ? $organizationName : null,
                ':is_active' => $isActive,
            ]);
            $accountId = (int)$pdo->lastInsertId();
            account_site_replace_all_roles($pdo, $accountId, $rolesByApp);
            account_site_audit($pdo, $actorId, $accountId, 'account.create', [
                'roles_by_app' => $rolesByApp,
                'is_active' => $isActive,
            ]);
        }

        $pdo->commit();
        return $accountId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function account_site_change_own_password(PDO $pdo, int $accountId, string $currentPassword, string $newPassword): void
{
    if ($accountId <= 0) {
        throw new RuntimeException('ログイン状態が不正です。');
    }
    if ($newPassword === '') {
        throw new RuntimeException('新しいパスワードを入力してください。');
    }
    if (strlen($newPassword) < 8) {
        throw new RuntimeException('新しいパスワードは8文字以上にしてください。');
    }

    $stmt = $pdo->prepare('SELECT password_hash FROM shared_accounts WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $accountId]);
    $hash = (string)$stmt->fetchColumn();
    if ($hash === '' || !password_verify($currentPassword, $hash)) {
        throw new RuntimeException('現在のパスワードが一致しません。');
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    if ($newHash === false) {
        throw new RuntimeException('新しいパスワードの保存に失敗しました。');
    }

    $updateStmt = $pdo->prepare('UPDATE shared_accounts SET password_hash = :password_hash WHERE id = :id');
    $updateStmt->execute([
        ':password_hash' => $newHash,
        ':id' => $accountId,
    ]);

    account_site_audit($pdo, $accountId, $accountId, 'account.password.change');
}
