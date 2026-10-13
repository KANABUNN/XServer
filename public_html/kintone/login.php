<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/apps/kintone_core/auth.php';
require_once $projectRoot . '/apps/kintone_core/audit.php';
require_once $projectRoot . '/apps/response_limit.php';
require_once $projectRoot . '/apps/ui_kit.php';

kintone_auth_bootstrap();
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
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
<html lang="ja" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>ログイン | kintone管理</title>
<?= fitsc_ui_head(['css' => ['assets/css/app.css']]) ?>
</head>
<body>
<main class="auth">
  <div class="auth__corner"><?= fitsc_theme_toggle() ?></div>
  <section class="auth__card">
    <div class="auth__head">
      <span class="brand-mark brand-mark--lg"><?= fitsc_icon('cloud') ?></span>
      <h1>kintone管理</h1>
      <p>団体マスタ・名簿連携の管理サイト</p>
    </div>
    <div class="stack">
      <?php if ($error !== ''): ?>
        <div class="alert alert--danger" role="alert"><?= fitsc_icon('x-circle') ?><div class="alert__body"><?= kintone_h($error) ?></div></div>
      <?php endif; ?>
      <form method="post" action="login.php" class="stack" data-busy>
        <input type="hidden" name="_csrf" value="<?= kintone_h($csrf) ?>">
        <input type="hidden" name="return_to" value="<?= kintone_h($returnTo) ?>">
        <label class="field">
          <span>ログインID</span>
          <span class="input-icon"><?= fitsc_icon('user') ?><input id="login_id" name="login_id" autocomplete="username" required autofocus></span>
        </label>
        <label class="field">
          <span>パスワード</span>
          <span class="input-icon"><?= fitsc_icon('lock') ?><input id="password" name="password" type="password" autocomplete="current-password" required></span>
        </label>
        <button class="btn btn--primary btn--lg btn--block" type="submit"><span>ログイン</span><?= fitsc_icon('arrow-right') ?></button>
      </form>
    </div>
    <p class="auth__foot">共通アカウントでログインします（kintone管理の権限が必要です）</p>
  </section>
</main>
</body>
</html>
