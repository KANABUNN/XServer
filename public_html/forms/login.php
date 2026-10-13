<?php
require_once __DIR__ . '/../../apps/forms_core/bootstrap.php';

if (is_logged_in()) {
    header('Location: admin.php');
    exit;
}

page_header('管理者ログイン', 'forms-login-page', ['app_name' => 'フォーム管理']);
?>
<main class="auth">
    <div class="auth__corner">
        <a class="btn btn--ghost btn--sm" href="index.php"><?= fitsc_icon('arrow-left') ?>提出フォームへ</a>
        <?= fitsc_theme_toggle() ?>
    </div>
    <section class="auth__card">
        <div class="auth__head">
            <span class="brand-mark brand-mark--lg"><?= fitsc_icon('file-text') ?></span>
            <h1>フォーム管理</h1>
            <p>共通アカウントでログインしてください</p>
        </div>

        <?php if (isset($_GET['logged_out'])): ?>
            <?= fitsc_flash_html('success', 'ログアウトしました。') ?>
        <?php endif; ?>

        <form id="login-form" class="stack">
            <label class="field">
                <span>ログインID またはメールアドレス</span>
                <span class="input-icon"><?= fitsc_icon('user') ?><input type="text" name="identifier" required autocomplete="username" inputmode="text" autofocus></span>
            </label>
            <label class="field">
                <span>パスワード</span>
                <span class="input-icon"><?= fitsc_icon('lock') ?><input type="password" name="password" required autocomplete="current-password"></span>
            </label>
            <div id="login-message" class="alert hidden" role="alert"></div>
            <button type="submit" class="btn btn--primary btn--lg btn--block"><span>ログイン</span><?= fitsc_icon('arrow-right') ?></button>
        </form>
    </section>
</main>
<?php page_footer(['assets/js/common.js']); ?>
