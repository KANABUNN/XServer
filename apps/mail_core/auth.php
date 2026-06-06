<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function mail_auth_bootstrap(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = mail_is_https();
    if (function_exists('ini_set')) {
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_secure', $secure ? '1' : '0');
        @ini_set('session.cookie_samesite', 'Lax');
    }

    session_name('mail_fit_sc_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => mail_base_path(),
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function mail_role_labels(): array
{
    return [
        'viewer' => '閲覧者',
        'user' => '編集者',
        'admin' => '管理者',
    ];
}

function mail_role_label(string $roleKey): string
{
    return mail_role_labels()[$roleKey] ?? $roleKey;
}

function mail_role_priority(string $roleKey): int
{
    return match ($roleKey) {
        'admin' => 300,
        'user' => 200,
        'viewer' => 100,
        default => 0,
    };
}

function mail_normalize_role_keys(array $roleKeys): array
{
    $normalized = [];
    foreach ($roleKeys as $roleKey) {
        $roleKey = trim((string)$roleKey);
        if ($roleKey !== '') {
            $normalized[$roleKey] = $roleKey;
        }
    }
    return array_values($normalized ?: ['viewer' => 'viewer']);
}

function mail_pick_primary_role(array $roleKeys): string
{
    $best = 'viewer';
    $score = -1;
    foreach (mail_normalize_role_keys($roleKeys) as $roleKey) {
        $current = mail_role_priority($roleKey);
        if ($current > $score) {
            $score = $current;
            $best = $roleKey;
        }
    }
    return $best;
}

function mail_role_permissions_map(): array
{
    return [
        'viewer' => [
            'dashboard.view',
            'organization.view',
            'template.view',
            'batch.view',
            'attachment.view',
            'log.view',
        ],
        'user' => [
            'dashboard.view',
            'organization.view',
            'organization.edit',
            'template.view',
            'template.edit',
            'batch.view',
            'batch.edit',
            'attachment.view',
            'attachment.upload',
            'preview.create',
            'draft.create',
            'log.view',
        ],
        'admin' => [
            'dashboard.view',
            'organization.view',
            'organization.edit',
            'template.view',
            'template.edit',
            'batch.view',
            'batch.edit',
            'attachment.view',
            'attachment.upload',
            'preview.create',
            'draft.create',
            'send.execute',
            'settings.manage',
            'log.view',
            'admin.manage',
        ],
    ];
}

function mail_permissions_for_roles(array $roleKeys): array
{
    $permissions = [];
    $map = mail_role_permissions_map();
    foreach (mail_normalize_role_keys($roleKeys) as $roleKey) {
        foreach (($map[$roleKey] ?? []) as $permission) {
            $permissions[$permission] = $permission;
        }
    }
    return array_values($permissions);
}

function mail_auth_hydrate_user(array $row): array
{
    $roleKeys = mail_normalize_role_keys(explode(',', (string)($row['role_keys'] ?? '')));
    $primary = mail_pick_primary_role($roleKeys);
    return [
        'id' => (int)($row['id'] ?? 0),
        'login_id' => (string)($row['login_id'] ?? ''),
        'email' => (string)($row['email'] ?? ''),
        'display_name' => (string)($row['display_name'] ?? ''),
        'organization_name' => (string)($row['organization_name'] ?? ''),
        'is_active' => (int)($row['is_active'] ?? 0),
        'session_version' => (int)($row['session_version'] ?? 1),
        'role_keys' => $roleKeys,
        'role_key' => $primary,
        'role_label' => mail_role_label($primary),
        'permissions' => mail_permissions_for_roles($roleKeys),
    ];
}

function mail_auth_fetch_user_by_identifier(PDO $pdo, string $identifier): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    $hasSessionVersion = mail_column_exists($pdo, 'shared_accounts', 'session_version');
    $sessionVersionSelect = $hasSessionVersion ? 'a.session_version' : '1 AS session_version';

    $stmt = $pdo->prepare(
        'SELECT a.id, a.login_id, a.email, a.password_hash, a.display_name, a.organization_name, a.is_active, ' .
        $sessionVersionSelect . ', GROUP_CONCAT(DISTINCT r.role_key ORDER BY r.role_key SEPARATOR ",") AS role_keys ' .
        'FROM shared_accounts a ' .
        'INNER JOIN shared_account_app_roles r ON r.account_id = a.id AND r.app_key = :app_key ' .
        'WHERE a.login_id = :identifier_login OR a.email = :identifier_email ' .
        'GROUP BY a.id LIMIT 1'
    );
    $stmt->execute([
        ':app_key' => MAIL_APP_KEY,
        ':identifier_login' => $identifier,
        ':identifier_email' => $identifier,
    ]);
    $row = $stmt->fetch();
    return $row ? $row : null;
}

function mail_auth_fetch_user_by_id(PDO $pdo, int $accountId): ?array
{
    if ($accountId <= 0) {
        return null;
    }

    $hasSessionVersion = mail_column_exists($pdo, 'shared_accounts', 'session_version');
    $sessionVersionSelect = $hasSessionVersion ? 'a.session_version' : '1 AS session_version';

    $stmt = $pdo->prepare(
        'SELECT a.id, a.login_id, a.email, a.display_name, a.organization_name, a.is_active, ' .
        $sessionVersionSelect . ', GROUP_CONCAT(DISTINCT r.role_key ORDER BY r.role_key SEPARATOR ",") AS role_keys ' .
        'FROM shared_accounts a ' .
        'INNER JOIN shared_account_app_roles r ON r.account_id = a.id AND r.app_key = :app_key ' .
        'WHERE a.id = :id GROUP BY a.id LIMIT 1'
    );
    $stmt->execute([':app_key' => MAIL_APP_KEY, ':id' => $accountId]);
    $row = $stmt->fetch();
    return $row ? mail_auth_hydrate_user($row) : null;
}

function mail_auth_attempt_login(PDO $pdo, string $identifier, string $password): ?array
{
    static $dummyHash = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    $row = mail_auth_fetch_user_by_identifier($pdo, $identifier);
    if (!$row) {
        password_verify($password, $dummyHash);
        return null;
    }
    if ((int)($row['is_active'] ?? 0) !== 1) {
        password_verify($password, $dummyHash);
        return null;
    }
    if (!password_verify($password, (string)($row['password_hash'] ?? ''))) {
        return null;
    }

    $stmt = $pdo->prepare('UPDATE shared_accounts SET last_login_at = NOW() WHERE id = :id');
    $stmt->execute([':id' => (int)$row['id']]);

    return mail_auth_fetch_user_by_id($pdo, (int)$row['id']);
}

function mail_auth_login_user(array $user): void
{
    mail_auth_bootstrap();
    session_regenerate_id(true);
    $_SESSION['mail_user'] = $user;
    $_SESSION['mail_login_at'] = time();
    mail_auth_get_csrf_token(true);
}

function mail_auth_current_user(): ?array
{
    mail_auth_bootstrap();
    $user = $_SESSION['mail_user'] ?? null;
    return is_array($user) ? $user : null;
}

function mail_auth_is_logged_in(): bool
{
    return mail_auth_current_user() !== null;
}

function mail_auth_logout(): void
{
    mail_auth_bootstrap();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}

function mail_auth_require_login(): array
{
    mail_auth_bootstrap();
    $user = mail_auth_current_user();
    if ($user !== null) {
        return $user;
    }

    $returnTo = urlencode((string)($_SERVER['REQUEST_URI'] ?? mail_url()));
    header('Location: ' . mail_url('login.php?return_to=' . $returnTo), true, 302);
    exit;
}

function mail_auth_has_permission(array $user, string $permission): bool
{
    if (in_array('admin', (array)($user['role_keys'] ?? []), true)) {
        return true;
    }
    return in_array($permission, (array)($user['permissions'] ?? []), true);
}

function mail_auth_get_csrf_token(bool $forceRegenerate = false): string
{
    mail_auth_bootstrap();
    if ($forceRegenerate || !is_string($_SESSION['mail_csrf_token'] ?? null) || (string)$_SESSION['mail_csrf_token'] === '') {
        $_SESSION['mail_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['mail_csrf_token'];
}

function mail_auth_csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . mail_h(mail_auth_get_csrf_token()) . '">';
}

function mail_auth_validate_csrf_token(?string $token): bool
{
    mail_auth_bootstrap();
    $expected = (string)($_SESSION['mail_csrf_token'] ?? '');
    $provided = (string)$token;
    return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
}


function mail_auth_verify_csrf_token(?string $token): void
{
    if (mail_auth_validate_csrf_token($token)) {
        return;
    }

    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (str_contains($accept, 'application/json')) {
        mail_send_json(['ok' => false, 'message' => 'CSRFトークンが無効です。'], 403);
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'CSRFトークンが無効です。';
    exit;
}

function mail_auth_require_csrf(): void
{
    $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? ''));
    if (mail_auth_validate_csrf_token($token)) {
        return;
    }

    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (str_contains($accept, 'application/json')) {
        mail_send_json(['ok' => false, 'message' => 'CSRFトークンが無効です。'], 403);
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'CSRFトークンが無効です。';
    exit;
}

function mail_auth_normalize_return_to(string $returnTo): string
{
    $returnTo = trim($returnTo);
    if ($returnTo === '' || str_starts_with($returnTo, 'http://') || str_starts_with($returnTo, 'https://') || str_starts_with($returnTo, '//')) {
        return mail_url();
    }
    if (!str_starts_with($returnTo, '/')) {
        return mail_url();
    }
    return $returnTo;
}

function mail_auth_count_role_users(PDO $pdo): int
{
    $stmt = $pdo->prepare('SELECT COUNT(DISTINCT account_id) FROM shared_account_app_roles WHERE app_key = :app_key');
    $stmt->execute([':app_key' => MAIL_APP_KEY]);
    return (int)$stmt->fetchColumn();
}

function mail_auth_write_audit_log(PDO $pdo, ?array $actor, string $action, ?string $targetType = null, ?string $targetId = null, array $summary = []): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_audit_logs ' .
            '(actor_account_id, actor_login_id, actor_display_name, action_key, action, target_type, target_id, summary_json, detail_json, ip_address, user_agent) ' .
            'VALUES (:actor_account_id, :actor_login_id, :actor_display_name, :action_key, :action, :target_type, :target_id, :summary_json, :detail_json, :ip_address, :user_agent)'
        );
        $summaryJson = $summary === [] ? null : mail_json_encode($summary);
        $stmt->execute([
            ':actor_account_id' => $actor !== null ? (int)($actor['id'] ?? 0) : null,
            ':actor_login_id' => $actor !== null ? (string)($actor['login_id'] ?? '') : null,
            ':actor_display_name' => $actor !== null ? (string)($actor['display_name'] ?? '') : null,
            ':action_key' => $action,
            ':action' => $action,
            ':target_type' => $targetType,
            ':target_id' => $targetId,
            ':summary_json' => $summaryJson,
            ':detail_json' => $summaryJson,
            ':ip_address' => mail_client_ip(),
            ':user_agent' => mail_user_agent(),
        ]);
    } catch (Throwable $e) {
        error_log('[mail audit] ' . $e->getMessage());
    }
}
