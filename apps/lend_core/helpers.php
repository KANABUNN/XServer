<?php
function app_config(string $key = null, $default = null)
{
    if ($key === null) {
        return $GLOBALS['config'] ?? [];
    }

    $segments = explode('.', $key);
    $value = $GLOBALS['config'] ?? [];
    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function now_str(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function app_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
}

function json_response(array $payload, int $statusCode = 200): void
{
    app_security_headers();
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'message' => 'POST のみ許可されています。'], 405);
    }
}

function lend_db_table_has_column(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
        );
        $stmt->execute([
            ':table_name' => $tableName,
            ':column_name' => $columnName,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('[lend_db_table_has_column] ' . (string)$e);
        return false;
    }
}

function lend_reservation_user_snapshot_available(): bool
{
    static $available = null;
    if (is_bool($available)) {
        return $available;
    }

    $pdo = db();
    $available = lend_db_table_has_column($pdo, 'reservations', 'user_display_name')
        && lend_db_table_has_column($pdo, 'reservations', 'user_organization_name');

    return $available;
}

function lend_reservation_user_snapshot_sql(): string
{
    return lend_reservation_user_snapshot_available()
        ? 'r.user_display_name AS snapshot_user_name, r.user_organization_name AS snapshot_organization,'
        : 'NULL AS snapshot_user_name, NULL AS snapshot_organization,';
}

function audit_log(?int $userId, string $actorType, string $action, string $targetType, ?int $targetId, array $details = []): void
{
    $stmt = db()->prepare('
        INSERT INTO audit_logs (user_id, actor_type, action, target_type, target_id, details_json)
        VALUES (:user_id, :actor_type, :action, :target_type, :target_id, :details_json)
    ');
    $stmt->execute([
        ':user_id' => $userId,
        ':actor_type' => $actorType,
        ':action' => $action,
        ':target_type' => $targetType,
        ':target_id' => $targetId,
        ':details_json' => empty($details) ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function reservation_window_allows_checkout(array $reservation): bool
{
    $earlyMinutes = (int) app_config('reservation.checkout_early_minutes', 60);
    $start = new DateTimeImmutable($reservation['start_at']);
    $windowStart = $start->modify("-{$earlyMinutes} minutes");
    $now = new DateTimeImmutable('now');
    return $now >= $windowStart && $now <= new DateTimeImmutable($reservation['end_at']);
}

function reservation_window_allows_return(array $reservation): bool
{
    $graceMinutes = (int) app_config('reservation.return_grace_minutes', 240);
    $end = new DateTimeImmutable($reservation['end_at']);
    $windowEnd = $end->modify("+{$graceMinutes} minutes");
    $now = new DateTimeImmutable('now');
    return $now <= $windowEnd;
}

function asset_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    if (preg_match('/^(https?:|\/\/|data:)/i', $path) === 1) {
        return $path;
    }

    $pathOnly = preg_split('/[?#]/', $path, 2)[0] ?? $path;
    $scriptDir = dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $candidate = $scriptDir . '/' . ltrim($pathOnly, '/');
    if ($pathOnly !== '' && is_file($candidate)) {
        $separator = str_contains($path, '?') ? '&' : '?';
        return $path . $separator . 'v=' . filemtime($candidate);
    }

    return $path;
}

function page_header(string $title, string $bodyClass = ''): void
{
    app_security_headers();
    $csrf = csrf_token();
    $appName = h(app_config('app_name', '備品貸出システム'));
    $stylesUrl = h(asset_url('assets/css/styles.css'));
    $responsiveUrl = h(asset_url('assets/css/responsive.css'));
    $commonTokensUrl = h(asset_url('assets/common/tokens.css'));
    $commonSkinUrl = h(asset_url('assets/common/fit-sc-skin.css?v=20260625a'));
    echo <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} | {$appName}</title>
    <meta name="csrf-token" content="{$csrf}">
    <link rel="stylesheet" href="{$commonTokensUrl}">
    <link rel="stylesheet" href="{$stylesUrl}">
    <link rel="stylesheet" href="{$responsiveUrl}">
    <link rel="stylesheet" href="{$commonSkinUrl}">
</head>
<body class="{$bodyClass}">
HTML;
}

function page_footer(array $scripts = []): void
{
    foreach ($scripts as $script) {
        echo '<script src="' . h(asset_url($script)) . '"></script>';
    }
    echo '</body></html>';
}
