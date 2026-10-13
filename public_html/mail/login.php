<?php

declare(strict_types=1);

require_once __DIR__ . '/../../apps/mail_core/bootstrap.php';
require_once __DIR__ . '/../../apps/mail_core/auth.php';
require_once __DIR__ . '/../../apps/response_limit.php';

mail_auth_bootstrap();
mail_security_headers();
$csrfToken = mail_auth_get_csrf_token();

if (mail_auth_is_logged_in()) {
    header('Location: index.php', true, 302);
    exit;
}

$returnTo = mail_auth_normalize_return_to((string)($_GET['return_to'] ?? mail_url()));
$errorMessage = '';
$infoMessage = '';
$roleUserCount = null;
$accountPdo = null;

try {
    $accountPdo = mail_pdo('account');
    $roleUserCount = mail_auth_count_role_users($accountPdo);
} catch (Throwable $e) {
    error_log('[mail login init] ' . (string)$e);
    $errorMessage = 'ログインの準備に失敗しました。時間をおいて再試行してください。';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $errorMessage === '' && $accountPdo instanceof PDO) {
    mail_auth_require_csrf();
    $identifier = trim((string)($_POST['login_id'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $returnTo = mail_auth_normalize_return_to((string)($_POST['return_to'] ?? $returnTo));

    if ($identifier === '' || $password === '') {
        $errorMessage = 'ログインIDまたはメールアドレスとパスワードを入力してください。';
    } else {
        try {
            rate_limit_or_throw(get_client_ip(), __DIR__ . '/../../apps/rate_limit_mail_login.json', 5, 300);
        } catch (Throwable $rateLimitError) {
            error_log('[mail login rate_limit] ' . $rateLimitError->getMessage());
            $errorMessage = '短時間にログイン試行が多すぎます。時間をおいて再試行してください。';
        }

        if ($errorMessage === '') {
            try {
                $user = mail_auth_attempt_login($accountPdo, $identifier, $password);
                if ($user === null) {
                    $errorMessage = 'ログイン情報が正しくないか、mail アプリの権限がありません。';
                } else {
                    mail_auth_login_user($user);
                    mail_auth_write_audit_log($accountPdo, $user, 'mail.login', 'account', (string)$user['id'], [
                        'role_keys' => $user['role_keys'] ?? [],
                    ]);
                    header('Location: ' . $returnTo, true, 302);
                    exit;
                }
            } catch (Throwable $e) {
                error_log('[mail login] ' . (string)$e);
                $errorMessage = 'ログイン処理に失敗しました。時間をおいて再試行してください。';
            }
        }
    }
}
?>
<?php require_once __DIR__ . '/../../apps/ui_kit.php'; ?>
<!DOCTYPE html>
<html lang="ja" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>ログイン | メール管理</title>
  <?php echo fitsc_ui_head(['css' => ['./css/app.css'], 'guard' => './assets/common/context-menu-guard.js']); ?>
</head>
<body>
  <main class="auth">
    <div class="auth__corner"><?php echo fitsc_theme_toggle(); ?></div>
    <section class="auth__card">
      <div class="auth__head">
        <span class="brand-mark brand-mark--lg"><?php echo fitsc_icon('mail'); ?></span>
        <h1>メール管理</h1>
        <p>共通アカウントでログインしてください</p>
      </div>
      <div class="stack">
        <?php if ($infoMessage !== ''): ?>
          <?php echo fitsc_flash_html('info', $infoMessage); ?>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
          <div class="alert alert--danger" role="alert"><?php echo fitsc_icon('x-circle'); ?><div class="alert__body"><?php echo mail_h($errorMessage); ?></div></div>
        <?php endif; ?>
        <?php if ($roleUserCount === 0 && $errorMessage === ''): ?>
          <div class="alert alert--warning"><?php echo fitsc_icon('alert-triangle'); ?><div class="alert__body">メール管理の利用者が登録されていません。共通アカウント管理で mail の権限を付与してください。</div></div>
        <?php endif; ?>

        <form method="post" class="stack" autocomplete="on" data-busy>
          <?php echo mail_auth_csrf_field(); ?>
          <input type="hidden" name="return_to" value="<?php echo mail_h($returnTo); ?>">
          <label class="field">
            <span>ログインID または メールアドレス</span>
            <span class="input-icon"><?php echo fitsc_icon('user'); ?><input type="text" name="login_id" maxlength="100" autocomplete="username" required autofocus></span>
          </label>
          <label class="field">
            <span>パスワード</span>
            <span class="input-icon"><?php echo fitsc_icon('lock'); ?><input type="password" name="password" autocomplete="current-password" required></span>
          </label>
          <button type="submit" class="btn btn--primary btn--lg btn--block"><span>ログイン</span><?php echo fitsc_icon('arrow-right'); ?></button>
        </form>
      </div>
    </section>
  </main>
</body>
</html>
