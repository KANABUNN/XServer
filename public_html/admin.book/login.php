<?php

declare(strict_types=1);

require_once __DIR__ . '/../../apps/admin_auth.php';
require_once __DIR__ . '/../../apps/response_limit.php';

admin_auth_bootstrap();
$csrfToken = admin_auth_get_csrf_token();

if (admin_auth_is_logged_in()) {
    header('Location: index.php', true, 302);
    exit;
}

$returnTo = admin_auth_normalize_return_to((string)($_GET['return_to'] ?? admin_auth_base_path()));
$errorMessage = '';
$infoMessage = '';
$canSetup = false;
$pdo = null;

try {
    $pdo = admin_auth_db_connect();
    admin_auth_install_schema($pdo);
    $canSetup = admin_auth_count_users($pdo) === 0;
} catch (Throwable $e) {
    error_log('[admin.book login init] ' . $e->getMessage());
    $errorMessage = 'DB 接続または認証テーブルの確認に失敗しました。';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $errorMessage === '' && $pdo instanceof PDO) {
    admin_auth_require_csrf();
    $loginId = trim((string)($_POST['login_id'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $returnTo = admin_auth_normalize_return_to((string)($_POST['return_to'] ?? $returnTo));

    if ($loginId === '' || $password === '') {
        $errorMessage = 'ログインIDとパスワードを入力してください。';
    } else {
        try {
            rate_limit_or_throw(get_client_ip(), __DIR__ . '/../../apps/rate_limit_admin_book_login.json', 5, 300);
        } catch (Throwable $rateLimitError) {
            error_log('[admin.book login rate_limit] ' . $rateLimitError->getMessage());
            $errorMessage = '短時間にログイン試行が多すぎます。時間をおいて再試行してください。';
        }

        if ($errorMessage === '') {
            try {
                $user = admin_auth_attempt_login($pdo, $loginId, $password);
                if ($user === null) {
                    $errorMessage = 'ログインIDまたはパスワードが正しくありません。';
                } else {
                    $userId = (int)($user['id'] ?? 0);
                    admin_auth_login_user($user);
                    admin_auth_write_audit_log($pdo, $user, 'admin.login', 'admin_user', $userId, [
                        'role_key' => (string)($user['role_key'] ?? ''),
                    ]);
                    header('Location: ' . $returnTo, true, 302);
                    exit;
                }
            } catch (Throwable $e) {
                error_log('[admin.book login] ' . $e->getMessage());
                $errorMessage = 'ログイン処理に失敗しました。';
            }
        }
    }
}

if (isset($_GET['created']) && $_GET['created'] === '1' && $errorMessage === '') {
    $infoMessage = '初回管理者を作成しました。ログインしてください。';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>管理画面ログイン</title>
  <link rel="stylesheet" href="./assets/common/tokens.css?v=20260624">
  <link rel="stylesheet" href="./css/admin-auth.css">
  <link rel="stylesheet" href="./assets/common/fit-sc-skin.css?v=20260624">
</head>
<body>
  <main class="auth-shell">
    <section class="auth-card">
      <div class="auth-card-head">
        <p class="auth-kicker">admin.book</p>
        <h1>管理画面ログイン</h1>
        <p class="auth-lead">アプリ内ログインで個人単位の識別を行います。Basic 認証は別途 .htaccess で前段に置いてください。</p>
      </div>

      <?php if ($infoMessage !== ''): ?>
        <div class="auth-message auth-message-info"><?php echo admin_auth_h($infoMessage); ?></div>
      <?php endif; ?>

      <?php if ($errorMessage !== ''): ?>
        <div class="auth-message auth-message-error"><?php echo admin_auth_h($errorMessage); ?></div>
      <?php endif; ?>

      <?php if ($canSetup): ?>
        <div class="auth-message auth-message-warn">
          初期管理者が未作成です。先に <a href="setup_admin.php">初回セットアップ</a> を行ってください。
        </div>
      <?php endif; ?>

      <form method="post" class="auth-form" autocomplete="on">
        <?php echo admin_auth_csrf_field(); ?>
        <input type="hidden" name="return_to" value="<?php echo admin_auth_h($returnTo); ?>">

        <label class="auth-field">
          <span>ログインID</span>
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
        <p><a href="./">管理画面トップへ</a></p>
      </div>
    </section>
  </main>
</body>
</html>
