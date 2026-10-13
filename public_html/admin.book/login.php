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
<?php require_once __DIR__ . '/../../apps/ui_kit.php'; ?>
<!DOCTYPE html>
<html lang="ja" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>ログイン | 貸し部屋予約 管理</title>
  <?php echo fitsc_ui_head(['css' => ['./css/app.css'], 'guard' => './assets/common/context-menu-guard.js']); ?>
</head>
<body>
  <main class="auth">
    <div class="auth__corner"><?php echo fitsc_theme_toggle(); ?></div>
    <section class="auth__card">
      <div class="auth__head">
        <span class="brand-mark brand-mark--lg"><?php echo fitsc_icon('door'); ?></span>
        <h1>貸し部屋予約 管理</h1>
        <p>管理者アカウントでログインしてください</p>
      </div>

      <div class="stack">
        <?php if ($infoMessage !== ''): ?>
          <?php echo fitsc_flash_html('success', $infoMessage); ?>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
          <div class="alert alert--danger" role="alert"><?php echo fitsc_icon('x-circle'); ?><div class="alert__body"><?php echo admin_auth_h($errorMessage); ?></div></div>
        <?php endif; ?>
        <?php if ($canSetup): ?>
          <div class="alert alert--warning"><?php echo fitsc_icon('alert-triangle'); ?><div class="alert__body">管理者が未登録です。<a href="setup_admin.php">初回セットアップ</a>を行ってください。</div></div>
        <?php endif; ?>

        <form method="post" class="stack" autocomplete="on" data-busy>
          <?php echo admin_auth_csrf_field(); ?>
          <input type="hidden" name="return_to" value="<?php echo admin_auth_h($returnTo); ?>">
          <label class="field">
            <span>ログインID</span>
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
