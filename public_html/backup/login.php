<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/apps/admin_auth.php';
require_once $projectRoot . '/apps/backup_core/bootstrap.php';

admin_auth_bootstrap();
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

$returnTo = admin_auth_normalize_return_to((string)($_GET['return_to'] ?? 'index.php'));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnTo = admin_auth_normalize_return_to((string)($_POST['return_to'] ?? 'index.php'));
    if (!admin_auth_validate_csrf_token((string)($_POST['_csrf'] ?? ''))) {
        $error = 'CSRF トークンが無効です。ページを再読み込みしてください。';
    } else {
        $loginId = trim((string)($_POST['login_id'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        try {
            $user = admin_auth_attempt_login(admin_auth_db_connect(), $loginId, $password);
            if (is_array($user)) {
                admin_auth_login_user($user);
                header('Location: ' . $returnTo, true, 302);
                exit;
            }
            $error = 'ログインIDまたはパスワードが違います。';
        } catch (Throwable $e) {
            $error = 'ログイン処理に失敗しました。';
            backup_write_log('warning', 'backup login failed', ['error' => $e->getMessage()]);
        }
    }
}

$csrf = admin_auth_get_csrf_token();
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ログイン - FIT-SC Backup</title>
<style>
*,*::before,*::after{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f5f7fb;color:#172033}.login{min-height:100vh;display:grid;place-items:center;padding:24px}.loginbox{width:min(420px,100%);background:#fff;border:1px solid #d8e0ef;border-radius:16px;padding:24px;box-shadow:0 16px 40px rgba(15,23,42,.08)}h1{margin:0 0 6px;font-size:22px}.muted{color:#64748b;margin:0 0 18px}.error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:12px;padding:10px;margin:12px 0}label{font-weight:700;display:block;margin:14px 0 6px}input{width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:10px;font:inherit}.primary{width:100%;margin-top:18px;padding:11px 14px;border:0;border-radius:10px;background:#1d4ed8;color:#fff;font-weight:800}.small{font-size:12px;color:#64748b;margin-top:14px}
</style>
</head>
<body>
<div class="login">
  <form class="loginbox" method="post" action="login.php">
    <h1>FIT-SC Backup Manager</h1>
    <p class="muted">バックアップ結果表示サイトへログインします。</p>
    <?php if ($error !== ''): ?><div class="error"><?= backup_h($error) ?></div><?php endif; ?>
    <input type="hidden" name="_csrf" value="<?= backup_h($csrf) ?>">
    <input type="hidden" name="return_to" value="<?= backup_h($returnTo) ?>">
    <label for="login_id">ログインID</label>
    <input id="login_id" name="login_id" autocomplete="username" required>
    <label for="password">パスワード</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <button class="primary" type="submit">ログイン</button>
    <p class="small">既存の共通アカウントを使用します。バックアップ管理画面は管理者権限が必要です。</p>
  </form>
</div>
</body>
</html>
