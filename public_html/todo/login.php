<?php
require __DIR__ . '/../../apps/todo_core/bootstrap.php';
db_init();

if (is_logged_in()) {
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $identifier = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (attempt_login($identifier, $password)) {
        flash('success', 'ログインしました。');
        redirect('index.php');
    }

    flash('error', 'ログインIDまたはメールアドレス、もしくはパスワードが違います。');
}

$flashes = consume_flash();
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ログイン</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/responsive.css">
</head>
<body class="login-body">
<div class="login-card">
    <h1>ログイン</h1>
    <p>総合管理事務局の会議・ToDo管理サイトです。</p>
    <?php foreach ($flashes as $flash): ?>
        <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
    <form method="post" class="form-grid single-col">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <label>
            <span>ログインIDまたはメールアドレス</span>
            <input type="text" name="username" required value="<?= e(old('username', '')) ?>" autocomplete="username">
        </label>
        <label>
            <span>パスワード</span>
            <input type="password" name="password" required>
        </label>
        <button type="submit" class="btn">ログイン</button>
    </form>
    <div class="hint-box">
        共通アカウントDB <code>fitsc_account</code> の <code>shared_accounts</code> / <code>shared_account_app_roles</code> に <code>app_key = todo</code> の利用者を登録してください。
    </div>
</div>
</body>
</html>
