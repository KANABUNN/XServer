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
<?php require_once __DIR__ . '/../../apps/ui_kit.php'; ?>
<!DOCTYPE html>
<html lang="ja" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>初回セットアップ | 貸し部屋予約 管理</title>
  <?php echo fitsc_ui_head(['css' => ['./css/app.css'], 'guard' => './assets/common/context-menu-guard.js']); ?>
</head>
<body>
  <main class="auth">
    <div class="auth__corner"><?php echo fitsc_theme_toggle(); ?></div>
    <section class="auth__card auth__card--wide">
      <div class="auth__head">
        <span class="brand-mark brand-mark--lg"><?php echo fitsc_icon('shield'); ?></span>
        <h1>初回セットアップ</h1>
        <p>最初の管理者アカウントを作成します</p>
      </div>

      <div class="stack">
        <?php if ($infoMessage !== ''): ?>
          <div class="alert alert--info"><?php echo fitsc_icon('info'); ?><div class="alert__body"><?php echo admin_auth_h($infoMessage); ?></div></div>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
          <div class="alert alert--danger" role="alert"><?php echo fitsc_icon('x-circle'); ?><div class="alert__body"><?php echo admin_auth_h($errorMessage); ?></div></div>
        <?php endif; ?>

        <?php if ($setupAllowed): ?>
          <form method="post" class="stack" autocomplete="on" data-busy>
            <?php echo admin_auth_csrf_field(); ?>
            <div class="form-grid">
              <label class="field"><span>ログインID</span><input type="text" name="login_id" maxlength="100" autocomplete="username" value="<?php echo admin_auth_h($values['login_id']); ?>" required autofocus></label>
              <label class="field"><span>表示名</span><input type="text" name="display_name" maxlength="100" value="<?php echo admin_auth_h($values['display_name']); ?>" required></label>
              <label class="field span-full"><span>メールアドレス</span><input type="email" name="email" maxlength="255" autocomplete="email" value="<?php echo admin_auth_h($values['email']); ?>"></label>
              <label class="field span-full"><span>ロール</span>
                <select name="role_key" required>
                  <option value="admin" <?php echo $values['role_key'] === 'admin' ? 'selected' : ''; ?>>管理者 (admin)</option>
                  <option value="user" <?php echo $values['role_key'] === 'user' ? 'selected' : ''; ?>>編集者 (user)</option>
                  <option value="viewer" <?php echo $values['role_key'] === 'viewer' ? 'selected' : ''; ?>>閲覧者 (viewer)</option>
                </select>
              </label>
              <label class="field"><span>パスワード <span class="opt">10文字以上</span></span><input type="password" name="password" minlength="10" autocomplete="new-password" required></label>
              <label class="field"><span>パスワード（確認）</span><input type="password" name="password_confirm" minlength="10" autocomplete="new-password" required></label>
            </div>
            <button type="submit" class="btn btn--primary btn--lg btn--block"><?php echo fitsc_icon('check'); ?>管理者を作成</button>
          </form>
        <?php endif; ?>
        <a class="btn btn--ghost btn--block" href="login.php"><?php echo fitsc_icon('log-in'); ?>ログイン画面へ</a>
      </div>
    </section>
  </main>
</body>
</html>
