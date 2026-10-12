<?php
require_once dirname(__DIR__) . '/shared_accounts.php';

function lend_auth_account_db(): PDO
{
    return shared_accounts_db($GLOBALS['config'] ?? [], [
        dirname(__DIR__) . '/config.php',
        dirname(__DIR__, 2) . '/includes/config.php',
    ]);
}

function lend_auth_app_key(): string
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return str_contains($scriptName, '/forms/') ? 'forms' : 'lend';
}

function lend_account_labels_from_user(array $user): array
{
    return [
        'user_name' => (string)($user['display_name'] ?? $user['name'] ?? ''),
        'organization' => (string)($user['organization_name'] ?? $user['organization'] ?? ''),
    ];
}

function lend_fetch_account_labels(array $accountIds): array
{
    $ids = [];
    foreach ($accountIds as $accountId) {
        $id = (int)$accountId;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    if ($ids === []) {
        return [];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = lend_auth_account_db()->prepare(
            'SELECT id, display_name, organization_name '
            . 'FROM shared_accounts '
            . 'WHERE id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_values($ids));

        $labels = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $labels[$id] = [
                'user_name' => (string)($row['display_name'] ?? ''),
                'organization' => (string)($row['organization_name'] ?? ''),
            ];
        }

        return $labels;
    } catch (Throwable $e) {
        // account DB の表示名解決に失敗しても、lend DB 側の処理は継続する。
        // admin_data.php では reservations のスナップショット列へフォールバックする。
        error_log('[lend_fetch_account_labels] ' . (string)$e);
        return [];
    }
}

function lend_apply_account_labels(array $rows, string $userIdKey = 'user_id'): array
{
    $ids = [];
    foreach ($rows as $row) {
        $id = (int)($row[$userIdKey] ?? 0);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    $labels = lend_fetch_account_labels(array_values($ids));

    foreach ($rows as &$row) {
        $userId = (int)($row[$userIdKey] ?? 0);
        $snapshotName = trim((string)($row['snapshot_user_name'] ?? ''));
        $snapshotOrganization = trim((string)($row['snapshot_organization'] ?? ''));
        $current = $labels[$userId] ?? [];

        $row['user_name'] = trim((string)($current['user_name'] ?? '')) !== ''
            ? (string)$current['user_name']
            : ($snapshotName !== '' ? $snapshotName : ($userId > 0 ? '利用者ID:' . $userId : '不明な利用者'));
        $row['organization'] = trim((string)($current['organization'] ?? '')) !== ''
            ? (string)$current['organization']
            : $snapshotOrganization;

        unset($row['snapshot_user_name'], $row['snapshot_organization']);
    }
    unset($row);

    return $rows;
}

function login_user(string $identifier, string $password): bool
{
    $pdo = lend_auth_account_db();
    $user = shared_accounts_attempt_login($pdo, $identifier, $password, lend_auth_app_key());

    if (!$user) {
        return false;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $roleKeys = is_array($user['role_keys'] ?? null) ? $user['role_keys'] : [];
    $primaryRole = in_array('admin', $roleKeys, true) ? 'admin' : ((string)($roleKeys[0] ?? 'user'));

    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'app_key' => lend_auth_app_key(),
        'session_version' => (int)($user['session_version'] ?? 1),
        'login_id' => (string)($user['login_id'] ?? ''),
        'name' => (string)($user['display_name'] ?? ''),
        'display_name' => (string)($user['display_name'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'role' => $primaryRole,
        'role_keys' => $roleKeys,
        'organization' => (string)($user['organization_name'] ?? ''),
        'organization_name' => (string)($user['organization_name'] ?? ''),
    ];

    audit_log((int)$user['id'], $primaryRole, 'login', 'session', null, [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ]);

    return true;
}

function is_logged_in(): bool
{
    if (empty($_SESSION['user']) || !is_array($_SESSION['user'])) {
        return false;
    }

    // セッションは発行元アプリ(forms / lend)でのみ有効とする。
    // forms と lend は同一の lend_core を共有しており、同一ホスト配信時には
    // Cookie・$_SESSION['user'] を共有し得る。app_key を照合することで、
    // 一方のアプリで得た権限(role)が他方へ流用されるのを防ぐ。
    if ((string)($_SESSION['user']['app_key'] ?? '') !== lend_auth_app_key()) {
        return false;
    }

    return lend_auth_session_state_valid();
}

function lend_auth_session_state_valid(): bool
{
    $sessionUser = $_SESSION['user'] ?? [];
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
        $state = shared_accounts_session_state(lend_auth_account_db(), $accountId, lend_auth_app_key());
    } catch (Throwable $e) {
        return true;
    }

    if ($state === null || (int)($state['is_active'] ?? 0) !== 1) {
        return false;
    }
    if ((int)($state['session_version'] ?? 1) !== (int)($sessionUser['session_version'] ?? -1)) {
        return false;
    }

    $_SESSION['user']['_revalidated_at'] = $now;
    return true;
}

function current_user(): array
{
    return is_logged_in() ? ($_SESSION['user'] ?? []) : [];
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function require_admin(): void
{
    require_login();
    if ((current_user()['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo '管理者権限が必要です。';
        exit;
    }
}

function api_require_login(): array
{
    if (!is_logged_in()) {
        json_response(['ok' => false, 'message' => 'ログインが必要です。'], 401);
    }
    return current_user();
}

function api_require_admin(): array
{
    $user = api_require_login();
    if (($user['role'] ?? '') !== 'admin') {
        json_response(['ok' => false, 'message' => '管理者権限が必要です。'], 403);
    }
    return $user;
}
