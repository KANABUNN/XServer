<?php
require __DIR__ . '/../../apps/todo_core/bootstrap.php';
require_once __DIR__ . '/../../apps/response_limit.php';
db_init();

if (is_logged_in()) {
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        rate_limit_or_throw(get_client_ip(), __DIR__ . '/../../apps/rate_limit_todo_login.json', 5, 300);
    } catch (Throwable $rateLimitError) {
        error_log('[todo login rate_limit] ' . $rateLimitError->getMessage());
        flash('error', '短時間にログイン試行が多すぎます。時間をおいて再試行してください。');
        redirect('login.php');
    }
    $identifier = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (attempt_login($identifier, $password)) {
        flash('success', 'ログインしました。');
        redirect('index.php');
    }

    flash('error', 'ログインIDまたはメールアドレス、もしくはパスワードが違います。');
}

$flashes = consume_flash();
$appName = $config['app_name'] ?? '会議・ToDo';
?>
<!doctype html>
<html lang="ja" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>ログイン | <?= e($appName) ?></title>
    <?= fitsc_ui_head(['css' => ['assets/app.css'], 'guard' => 'assets/context-menu-guard.js']) ?>
</head>
<body>
<main class="auth">
    <div class="auth__corner"><?= fitsc_theme_toggle() ?></div>
    <section class="auth__card">
        <div class="auth__head">
            <span class="brand-mark brand-mark--lg"><?= fitsc_icon('clipboard-check') ?></span>
            <h1><?= e($appName) ?></h1>
            <p>会議の議題・決定事項・タスクを管理します</p>
        </div>
        <div class="stack">
            <?php foreach ($flashes as $flash): ?>
                <?php $isError = ($flash['type'] ?? '') === 'error'; ?>
                <div class="alert alert--<?= $isError ? 'danger' : 'success' ?>" role="<?= $isError ? 'alert' : 'status' ?>"><?= fitsc_icon($isError ? 'x-circle' : 'check-circle') ?><div class="alert__body"><?= e($flash['message']) ?></div></div>
            <?php endforeach; ?>
            <form method="post" class="stack" data-busy>
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <label class="field">
                    <span>ログインID または メールアドレス</span>
                    <span class="input-icon"><?= fitsc_icon('user') ?><input type="text" name="username" required value="<?= e(old('username', '')) ?>" autocomplete="username" autofocus></span>
                </label>
                <label class="field">
                    <span>パスワード</span>
                    <span class="input-icon"><?= fitsc_icon('lock') ?><input type="password" name="password" required autocomplete="current-password"></span>
                </label>
                <button type="submit" class="btn btn--primary btn--lg btn--block"><span>ログイン</span><?= fitsc_icon('arrow-right') ?></button>
            </form>
        </div>
        <p class="auth__foot">共通アカウントでログインします</p>
    </section>
</main>
</body>
</html>
