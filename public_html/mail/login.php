<?php

declare(strict_types=1);

require_once __DIR__ . '/../../apps/mail_core/bootstrap.php';
require_once __DIR__ . '/../../apps/mail_core/auth.php';

mail_auth_bootstrap();
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
    $errorMessage = '共通アカウントDBへ接続できません: ' . $e->getMessage();
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
            $errorMessage = 'ログイン処理に失敗しました: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ログイン | mail.fit-sc.jp</title>
  <link rel="stylesheet" href="./css/admin-auth.css">
</head>
<body>
  <main class="auth-shell">
    <section class="auth-card">
      <div class="auth-card-head">
        <p class="auth-kicker">mail.fit-sc.jp</p>
        <h1>メール管理ログイン</h1>
        <p class="auth-lead">共通アカウントの <code>shared_account_app_roles.app_key = mail</code> に付与された権限でログインします。</p>
      </div>

      <?php if ($infoMessage !== ''): ?>
        <div class="auth-message auth-message-info"><?php echo mail_h($infoMessage); ?></div>
      <?php endif; ?>

      <?php if ($errorMessage !== ''): ?>
        <div class="auth-message auth-message-error"><?php echo mail_h($errorMessage); ?></div>
      <?php endif; ?>

      <?php if ($roleUserCount === 0 && $errorMessage === ''): ?>
        <div class="auth-message auth-message-warn">
          mail アプリに紐づく利用者が未登録です。<code>fitsc_account.shared_account_app_roles</code> に <code>app_key = 'mail'</code> のロールを追加してください。
        </div>
      <?php endif; ?>

      <form method="post" class="auth-form" autocomplete="on">
        <?php echo mail_auth_csrf_field(); ?>
        <input type="hidden" name="return_to" value="<?php echo mail_h($returnTo); ?>">

        <label class="auth-field">
          <span>ログインIDまたはメールアドレス</span>
          <input type="text" name="login_id" maxlength="100" autocomplete="username" required>
        </label>

        <label class="auth-field">
          <span>パスワード</span>
          <input type="password" name="password" autocomplete="current-password" required>
        </label>

        <button type="submit" class="auth-primary">ログイン</button>
      </form>

      <div class="auth-foot">
        <p>権限ロール: 閲覧者 viewer / 編集者 user / 管理者 admin</p>
        <p>Basic認証を前段に置く場合は <code>.htaccess</code> の該当行を有効化してください。</p>
      </div>
    </section>
  </main>
</body>
</html>
