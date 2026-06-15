<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$appsDir = $projectRoot . '/apps';

require_once $appsDir . '/admin_auth.php';
require_once $appsDir . '/backup_core/manager.php';

admin_auth_bootstrap();

if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function backup_web_require_admin(): array
{
    $user = admin_auth_require_login();
    $isAdmin = false;
    if (function_exists('admin_auth_user_has_role') && admin_auth_user_has_role($user, 'admin')) {
        $isAdmin = true;
    }
    if (!$isAdmin && function_exists('admin_auth_has_permission') && admin_auth_has_permission($user, 'admin.system.manage')) {
        $isAdmin = true;
    }
    if (!$isAdmin) {
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><title>権限がありません</title><p>バックアップ管理画面を表示する権限がありません。</p>';
        exit;
    }
    return $user;
}

function backup_web_manager(): FitScBackupManager
{
    static $manager = null;
    if ($manager instanceof FitScBackupManager) {
        return $manager;
    }
    $manager = new FitScBackupManager();
    return $manager;
}

function backup_web_header(string $title, array $user): void
{
    $displayName = backup_h((string)($user['display_name'] ?? $user['login_id'] ?? ''));
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . backup_h($title) . ' - FIT-SC Backup</title>';
    echo '<style>';
    echo '*,*::before,*::after{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f5f7fb;color:#172033}a{color:#1b5fd1;text-decoration:none}a:hover{text-decoration:underline}.top{background:#10203f;color:#fff;padding:16px 24px}.top h1{margin:0;font-size:20px}.top p{margin:6px 0 0;color:#cbd5e1}.nav{display:flex;gap:12px;flex-wrap:wrap;background:#fff;border-bottom:1px solid #d8e0ef;padding:10px 24px}.nav a{font-weight:700}.wrap{max-width:1180px;margin:0 auto;padding:24px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}.card{background:#fff;border:1px solid #d8e0ef;border-radius:14px;padding:18px;box-shadow:0 8px 22px rgba(15,23,42,.05)}.card h2{font-size:16px;margin:0 0 12px}.metric{font-size:28px;font-weight:800}.muted{color:#64748b}.status{display:inline-flex;align-items:center;border-radius:999px;padding:4px 10px;font-weight:700;font-size:12px}.status-success{background:#dcfce7;color:#166534}.status-running{background:#dbeafe;color:#1d4ed8}.status-failed{background:#fee2e2;color:#991b1b}.status-partial,.status-warning{background:#fef3c7;color:#92400e}table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #d8e0ef;border-radius:14px;overflow:hidden}th,td{padding:10px 12px;border-bottom:1px solid #e5eaf3;text-align:left;vertical-align:top}th{background:#f8fafc;color:#334155;font-size:13px}tr:last-child td{border-bottom:0}.mono{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:12px}.danger{color:#b91c1c}.ok{color:#166534}.btn{display:inline-block;border:1px solid #cbd5e1;border-radius:10px;padding:8px 12px;background:#fff;font-weight:700}.footer{padding:24px;color:#64748b;text-align:center}.login{min-height:100vh;display:grid;place-items:center;padding:24px}.loginbox{width:min(420px,100%);background:#fff;border:1px solid #d8e0ef;border-radius:16px;padding:24px;box-shadow:0 16px 40px rgba(15,23,42,.08)}label{font-weight:700;display:block;margin:14px 0 6px}input{width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:10px;font:inherit}.primary{width:100%;margin-top:18px;padding:11px 14px;border:0;border-radius:10px;background:#1d4ed8;color:#fff;font-weight:800}.alert{background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:12px;margin:12px 0}.small{font-size:12px}';
    echo '</style></head><body>';
    echo '<header class="top"><h1>FIT-SC Backup Manager</h1><p>ログイン中: ' . $displayName . ' / バックアップ本体はWebから直接取得できません。</p></header>';
    echo '<nav class="nav"><a href="index.php">ダッシュボード</a><a href="history.php">実行履歴</a><a href="logout.php">ログアウト</a></nav>';
    echo '<main class="wrap">';
}

function backup_web_footer(): void
{
    echo '</main><footer class="footer">FIT-SC Backup Manager</footer></body></html>';
}

function backup_web_status_badge(string $status): string
{
    $safe = preg_replace('/[^a-z0-9_-]/i', '', $status);
    return '<span class="status status-' . backup_h($safe) . '">' . backup_h(backup_status_label($status)) . '</span>';
}
