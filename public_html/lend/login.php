<?php
require_once __DIR__ . '/../../apps/lend_core/bootstrap.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

page_header('ログイン', 'lend-login-page');
?>
<main class="auth">
    <div class="auth__corner"><?= fitsc_theme_toggle() ?></div>
    <section class="auth__card">
        <div class="auth__head">
            <span class="brand-mark brand-mark--lg"><?= fitsc_icon('package') ?></span>
            <h1>備品貸出システム</h1>
            <p>共通アカウントでログインしてください</p>
        </div>

        <?php if (isset($_GET['logged_out'])): ?>
            <?= fitsc_flash_html('success', 'ログアウトしました。') ?>
        <?php endif; ?>

        <form id="login-form" class="stack">
            <label class="field">
                <span>ログインID または メールアドレス</span>
                <span class="input-icon"><?= fitsc_icon('user') ?><input type="text" name="identifier" required autocomplete="username" autofocus></span>
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
