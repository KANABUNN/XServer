<?php

declare(strict_types=1);

require_once __DIR__ . '/../../apps/admin_auth.php';

admin_auth_bootstrap();
$errorMessage = '';
$infoMessage = '';
$setupAllowed = true;

$values = [
    'login_id' => '',
    'display_name' => '',
    'email' => '',
    'role_key' => 'admin',
];

try {
    $pdo = admin_auth_db_connect();
    admin_auth_install_schema($pdo);
    $existingUsers = admin_auth_count_users($pdo);
    if ($existingUsers > 0) {
        $setupAllowed = false;
        $infoMessage = '初回セットアップは完了しています。以後は login.php からログインしてください。';
    }
} catch (Throwable $e) {
    $setupAllowed = false;
    $errorMessage = 'セットアップの準備に失敗しました: ' . $e->getMessage();
}

if ($setupAllowed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_auth_require_csrf();
    $values['login_id'] = trim((string)($_POST['login_id'] ?? ''));
    $values['display_name'] = trim((string)($_POST['display_name'] ?? ''));
    $values['email'] = trim((string)($_POST['email'] ?? ''));
    $values['role_key'] = trim((string)($_POST['role_key'] ?? 'admin'));
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

    if ($password !== $passwordConfirm) {
        $errorMessage = '確認用パスワードが一致しません。';
    } else {
        try {
            $createdId = admin_auth_create_user($pdo, [
                'login_id' => $values['login_id'],
                'display_name' => $values['display_name'],
                'email' => $values['email'],
                'password' => $password,
                'role_key' => $values['role_key'],
            ]);
            admin_auth_write_audit_log($pdo, null, 'admin.bootstrap.create', 'admin_user', $createdId, [
                'login_id' => $values['login_id'],
                'display_name' => $values['display_name'],
                'role_key' => $values['role_key'],
            ]);
            header('Location: login.php?created=1', true, 302);
            exit;
        } catch (Throwable $e) {
            $errorMessage = '初回管理者の作成に失敗しました: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>初回管理者セットアップ</title>
  <link rel="stylesheet" href="./css/admin-auth.css">
  <script src="./assets/common/context-menu-guard.js?v=20260713" defer></script>
</head>
<body>
  <main class="auth-shell">
    <section class="auth-card auth-card-wide">
      <div class="auth-card-head">
        <p class="auth-kicker">admin.book</p>
        <h1>初回管理者セットアップ</h1>
        <p class="auth-lead">認証テーブルの初期化と、最初の管理者アカウント作成を行います。作成後は login.php からログインしてください。</p>
      </div>

      <?php if ($infoMessage !== ''): ?>
        <div class="auth-message auth-message-info"><?php echo admin_auth_h($infoMessage); ?></div>
      <?php endif; ?>

      <?php if ($errorMessage !== ''): ?>
        <div class="auth-message auth-message-error"><?php echo admin_auth_h($errorMessage); ?></div>
      <?php endif; ?>

      <?php if ($setupAllowed): ?>
      <form method="post" class="auth-form" autocomplete="on">
        <?php echo admin_auth_csrf_field(); ?>
        <div class="auth-grid">
          <label class="auth-field">
            <span>ログインID</span>
            <input type="text" name="login_id" maxlength="100" autocomplete="username" value="<?php echo admin_auth_h($values['login_id']); ?>" required>
          </label>

          <label class="auth-field">
            <span>表示名</span>
            <input type="text" name="display_name" maxlength="100" value="<?php echo admin_auth_h($values['display_name']); ?>" required>
          </label>

          <label class="auth-field auth-field-wide">
            <span>メールアドレス</span>
            <input type="email" name="email" maxlength="255" autocomplete="email" value="<?php echo admin_auth_h($values['email']); ?>">
          </label>

          <label class="auth-field">
            <span>ロール</span>
            <select name="role_key" required>
              <option value="admin" <?php echo $values['role_key'] === 'admin' ? 'selected' : ''; ?>>管理者 (admin)</option>
              <option value="user" <?php echo $values['role_key'] === 'user' ? 'selected' : ''; ?>>編集者 (user)</option>
              <option value="viewer" <?php echo $values['role_key'] === 'viewer' ? 'selected' : ''; ?>>閲覧者 (viewer)</option>
            </select>
          </label>

          <label class="auth-field">
            <span>パスワード</span>
            <input type="password" name="password" minlength="10" autocomplete="new-password" required>
          </label>

          <label class="auth-field auth-field-wide">
            <span>パスワード（確認）</span>
            <input type="password" name="password_confirm" minlength="10" autocomplete="new-password" required>
          </label>
        </div>

        <button type="submit" class="auth-primary">初回管理者を作成</button>
      </form>
      <?php endif; ?>

      <div class="auth-foot">
        <p>この画面は最初のアカウント作成後は不要です。運用開始後は削除または無効化してください。</p>
        <p><a href="login.php">ログイン画面へ</a></p>
      </div>
    </section>
  </main>
</body>
</html>
