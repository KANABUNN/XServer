<?php
require_once __DIR__ . '/../../apps/lend_core/bootstrap.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

page_header('ログイン', 'auth-page');
?>
<main class="auth-layout">
    <section class="card auth-card">
        <h1>備品貸出システム</h1>
        <p class="muted">利用者向け画面・無人受付PC・管理画面の共通ログインです。共通DBのアカウントを参照します。</p>

        <?php if (isset($_GET['logged_out'])): ?>
            <div class="alert success">ログアウトしました。</div>
        <?php endif; ?>

        <form id="login-form" class="stack-form">
            <label>
                <span>ログインIDまたはメールアドレス</span>
                <input type="text" name="identifier" required placeholder="user または user@example.jp" autocomplete="username">
            </label>
            <label>
                <span>パスワード</span>
                <input type="password" name="password" required placeholder="password123">
            </label>
            <button type="submit" class="btn primary">ログイン</button>
        </form>

        <div id="login-message" class="alert hidden"></div>

        <div class="demo-box">
            <strong>サンプルアカウント</strong>
            <ul>
                <li>サンプル表記は旧構成です。共通アカウントDB移行後は shared_accounts の内容に合わせてください。</li>
            </ul>
        </div>
    </section>
</main>
<?php page_footer(['assets/js/common.js']); ?>
