<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/apps/account_core/auth.php';
require_once dirname(__DIR__, 2) . '/apps/response_limit.php';

account_site_bootstrap_session();
$flash = account_site_pull_flash();

if (account_site_is_logged_in()) {
    account_site_redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!account_site_verify_csrf($_POST['_csrf'] ?? null)) {
        $error = 'セッションの検証に失敗しました。再度お試しください。';
    } else {
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        try {
            rate_limit_or_throw(get_client_ip(), dirname(__DIR__, 2) . '/apps/rate_limit_account_login.json', 5, 300);
        } catch (Throwable $rateLimitError) {
            error_log('[account login rate_limit] ' . $rateLimitError->getMessage());
            $error = '短時間にログイン試行が多すぎます。時間をおいて再試行してください。';
        }
        if ($error === '' && account_site_attempt_login($identifier, $password)) {
            account_site_set_flash('success', 'ログインしました。');
            account_site_redirect('index.php');
        }
        if ($error === '') {
            $error = 'ログインに失敗しました。ID・メールアドレス・パスワードを確認してください。';
        }
    }
}
?>
<?php require_once dirname(__DIR__, 2) . '/apps/ui_kit.php'; ?>
<!doctype html>
<html lang="ja" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>ログイン | 共通アカウント管理</title>
    <?= fitsc_ui_head(['css' => ['assets/css/app.css']]) ?>
</head>
<body>
<main class="auth">
    <div class="auth__corner"><?= fitsc_theme_toggle() ?></div>
    <section class="auth__card">
        <div class="auth__head">
            <span class="brand-mark brand-mark--lg"><?= fitsc_icon('users') ?></span>
            <h1>共通アカウント管理</h1>
            <p>アカウント管理の権限を持つ共通アカウントでログイン</p>
        </div>
        <div class="stack">
            <?php if ($flash): ?>
                <?= fitsc_flash_html((string)$flash['type'], (string)$flash['message']) ?>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="alert alert--danger" role="alert"><?= fitsc_icon('x-circle') ?><div class="alert__body"><?= nl2br(account_site_h($error)) ?></div></div>
            <?php endif; ?>
            <form method="post" class="stack" data-busy>
                <input type="hidden" name="_csrf" value="<?= account_site_h(account_site_csrf_token()) ?>">
                <label class="field">
                    <span>ログインID または メールアドレス</span>
                    <span class="input-icon"><?= fitsc_icon('user') ?><input type="text" name="identifier" autocomplete="username" required autofocus></span>
                </label>
                <label class="field">
                    <span>パスワード</span>
                    <span class="input-icon"><?= fitsc_icon('lock') ?><input type="password" name="password" autocomplete="current-password" required></span>
                </label>
                <button type="submit" class="btn btn--primary btn--lg btn--block"><span>ログイン</span><?= fitsc_icon('arrow-right') ?></button>
            </form>
        </div>
    </section>
</main>
</body>
</html>
