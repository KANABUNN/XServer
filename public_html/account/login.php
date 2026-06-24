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
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>アカウント管理ログイン</title>
    <link rel="stylesheet" href="assets/common/tokens.css?v=20260624a">
    <link rel="stylesheet" href="assets/css/account.css">
    <link rel="stylesheet" href="assets/css/account-responsive.css">
    <link rel="stylesheet" href="assets/common/fit-sc-skin.css?v=20260624a">
</head>
<body class="account-login-body">
<div class="account-login-card">
    <div class="brand-block">
        <div class="brand-kicker">FIT-SC</div>
        <h1>共通アカウント管理</h1>
        <p>account.fit-sc.jp では、共有アカウントの作成・権限付与・停止を一元管理します。</p>
    </div>

    <?php if ($flash): ?>
        <div class="flash <?= account_site_h($flash['type']) ?>"><?= nl2br(account_site_h($flash['message'])) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="flash error"><?= nl2br(account_site_h($error)) ?></div>
    <?php endif; ?>

    <form method="post" class="panel form-stack">
        <input type="hidden" name="_csrf" value="<?= account_site_h(account_site_csrf_token()) ?>">
        <label>
            <span>ログインID または メールアドレス</span>
            <input type="text" name="identifier" autocomplete="username" required>
        </label>
        <label>
            <span>パスワード</span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit" class="button primary full">ログイン</button>
    </form>
</div>
</body>
</html>
