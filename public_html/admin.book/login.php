<?php

declare(strict_types=1);

require_once __DIR__ . '/../../apps/admin_auth.php';

admin_auth_bootstrap();

if (admin_auth_is_logged_in()) {
    header('Location: index.php', true, 302);
    exit;
}

$returnTo = admin_auth_normalize_return_to((string)($_GET['return_to'] ?? admin_auth_base_path()));
$errorMessage = '';
$infoMessage = '';
$canSetup = false;

try {
    $pdo = admin_auth_db_connect();
    admin_auth_install_schema($pdo);
    $canSetup = admin_auth_count_users($pdo) === 0;
} catch (Throwable $e) {
    $errorMessage = 'DB 接続または認証テーブルの確認に失敗しました: ' . $e->getMessage();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $errorMessage === '') {
    $loginId = trim((string)($_POST['login_id'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $returnTo = admin_auth_normalize_return_to((string)($_POST['return_to'] ?? $returnTo));

    if ($loginId === '' || $password === '') {
        $errorMessage = 'ログインIDとパスワードを入力してください。';
    } else {
        try {
            $user = admin_auth_attempt_login($pdo, $loginId, $password);
            if ($user === null) {
                $errorMessage = 'ログインIDまたはパスワードが正しくありません。';
            } else {
                admin_auth_login_user($user);
                header('Location: ' . $returnTo, true, 302);
                exit;
            }
        } catch (Throwable $e) {
            $errorMessage = 'ログイン処理に失敗しました: ' . $e->getMessage();
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
  <link rel="stylesheet" href="./css/admin-auth.css">
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
