<?php
function now(): string
{
    return date('Y-m-d H:i:s');
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    global $config;
    $key = $config['security']['csrf_key'] ?? '_csrf_token';
    if (empty($_SESSION[$key])) {
        $_SESSION[$key] = bin2hex(random_bytes(32));
    }
    return $_SESSION[$key];
}

function csrf_verify(): void
{
    global $config;
    $key = $config['security']['csrf_key'] ?? '_csrf_token';
    $token = $_POST['_csrf'] ?? '';
    $sessionToken = $_SESSION[$key] ?? '';
    if (!$token || !$sessionToken || !hash_equals($sessionToken, $token)) {
        http_response_code(419);
        exit('CSRF token mismatch.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flash(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function badge_class(string $value): string
{
    $map = [
        'scheduled' => 'badge blue',
        'done' => 'badge green',
        'in_progress' => 'badge orange',
        'closed' => 'badge gray',
        'pending' => 'badge gray',
        'resolved' => 'badge green',
        'carried_over' => 'badge orange',
        'todo' => 'badge gray',
        'doing' => 'badge orange',
        'blocked' => 'badge red',
        'completed' => 'badge green',
        'low' => 'badge gray',
        'medium' => 'badge blue',
        'high' => 'badge orange',
        'urgent' => 'badge red',
    ];
    return $map[$value] ?? 'badge gray';
}

function status_label(string $value): string
{
    $map = [
        'scheduled' => '予定',
        'in_progress' => '進行中',
        'done' => '完了',
        'closed' => '終了',
        'pending' => '未着手',
        'resolved' => '解決済み',
        'carried_over' => '継続審議',
        'todo' => '未着手',
        'doing' => '対応中',
        'blocked' => '保留',
        'completed' => '完了',
        'low' => '低',
        'medium' => '中',
        'high' => '高',
        'urgent' => '緊急',
    ];
    return $map[$value] ?? $value;
}

function old(string $key, string $default = ''): string
{
    return $_POST[$key] ?? $default;
}

function query_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function query_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function log_activity(string $actionType, string $targetType, ?int $targetId, string $message): void
{
    $stmt = db()->prepare('INSERT INTO activity_logs (action_type, target_type, target_id, message, created_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$actionType, $targetType, $targetId, $message, now()]);
}

function meeting_options(): array
{
    return query_all('SELECT id, title, meeting_date FROM meetings ORDER BY meeting_date DESC, id DESC');
}
