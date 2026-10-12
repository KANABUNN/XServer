<?php
require_once __DIR__ . '/../../apps/forms_core/bootstrap.php';

if (is_logged_in()) {
    header('Location: admin.php');
    exit;
}

page_header('ログイン', 'forms-login-page');
?>
<main class="auth-layout">
    <section class="card auth-card">
        <h1>フォーム管理ログイン</h1>
        <p class="muted">現行の共通アカウントログインを使用します。ログインIDまたはメールアドレスでサインインできます。</p>

        <?php if (isset($_GET['logged_out'])): ?>
            <div class="alert success">ログアウトしました。</div>
        <?php endif; ?>

        <form id="login-form" class="stack-form">
            <label>
                <span>ログインIDまたはメールアドレス</span>
                <input type="text" name="identifier" required placeholder="user1 または user1@example.jp" autocomplete="username" inputmode="text">
            </label>
            <label>
                <span>パスワード</span>
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <button type="submit" class="btn primary">ログイン</button>
        </form>
        <div id="login-message" class="alert hidden"></div>
    </section>
</main>
<?php page_footer(['assets/js/common.js']); ?>
