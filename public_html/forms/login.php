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
        <p class="muted">共通アカウントDBに登録された管理アカウントを利用します。</p>

        <?php if (isset($_GET['logged_out'])): ?>
            <div class="alert success">ログアウトしました。</div>
        <?php endif; ?>

        <form id="login-form" class="stack-form">
            <label>
                <span>ログインIDまたはメールアドレス</span>
                <input type="text" name="email" required autocomplete="username">
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
