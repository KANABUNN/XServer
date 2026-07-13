<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/apps/kintone_core/auth.php';
require_once $projectRoot . '/apps/kintone_core/audit.php';
require_once $projectRoot . '/apps/response_limit.php';

kintone_auth_bootstrap();
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}
$returnTo = kintone_auth_normalize_return_to((string)($_GET['return_to'] ?? 'index.php'));
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnTo = kintone_auth_normalize_return_to((string)($_POST['return_to'] ?? 'index.php'));
    if (!kintone_auth_verify_csrf((string)($_POST['_csrf'] ?? ''))) {
        $error = 'CSRF トークンが無効です。ページを再読み込みしてください。';
    } else {
        $loginId = trim((string)($_POST['login_id'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        try {
            rate_limit_or_throw(get_client_ip(), $projectRoot . '/apps/rate_limit_kintone_login.json', 5, 300);
        } catch (Throwable $rateLimitError) {
            error_log('[kintone login rate_limit] ' . $rateLimitError->getMessage());
            $error = '短時間にログイン試行が多すぎます。時間をおいて再試行してください。';
        }
        if ($error === '') {
            try {
                $user = kintone_auth_attempt_login($loginId, $password);
                if (is_array($user)) {
                    kintone_auth_login_user($user);
                    kintone_write_audit_log('kintone.login', 'account', (string)($user['id'] ?? ''), [], $user);
                    header('Location: ' . $returnTo, true, 302);
                    exit;
                }
                $error = 'ログインIDまたはパスワードが違います。kintone アプリ権限が付与されているかも確認してください。';
                kintone_write_audit_log('kintone.login_failed', 'account', $loginId, ['reason' => 'invalid_credentials']);
            } catch (Throwable $e) {
                error_log('[kintone login] ' . $e->getMessage());
                $error = 'ログイン処理に失敗しました。';
            }
        }
    }
}
$csrf = kintone_auth_csrf_token();
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ログイン - kintone管理</title>
<link rel="stylesheet" href="../assets/common/tokens.css?v=20260625-style1">
<link rel="stylesheet" href="../assets/common/fit-sc-skin.css?v=20260625-style1">
<link rel="stylesheet" href="../assets/common/fit-sc-responsive.css?v=20260713">
<script src="../assets/common/context-menu-guard.js?v=20260713" defer></script>
</head>
<body>
<div class="login">
  <form class="loginbox" method="post" action="login.php">
    <h1>FIT-SC kintone管理</h1>
    <p class="muted">団体マスタ管理サイトへログインします。</p>
    <?php if ($error !== ''): ?><div class="error"><?= kintone_h($error) ?></div><?php endif; ?>
    <input type="hidden" name="_csrf" value="<?= kintone_h($csrf) ?>">
    <input type="hidden" name="return_to" value="<?= kintone_h($returnTo) ?>">
    <label for="login_id">ログインID</label>
    <input id="login_id" name="login_id" autocomplete="username" required>
    <label for="password">パスワード</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <button class="primary" type="submit">ログイン</button>
    <p class="small">共通アカウントの kintone アプリ権限を使用します。viewer / operator / admin が必要です。</p>
  </form>
</div>
</body>
</html>
