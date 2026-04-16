<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

page_header('ログイン', 'auth-page');
?>
<main class="auth-layout">
    <section class="card auth-card">
        <h1>備品貸出システム</h1>
        <p class="muted">利用者向け画面・無人受付PC・管理画面の共通ログインです。</p>

        <?php if (isset($_GET['logged_out'])): ?>
            <div class="alert success">ログアウトしました。</div>
        <?php endif; ?>

        <form id="login-form" class="stack-form">
            <label>
                <span>メールアドレス</span>
                <input type="email" name="email" required placeholder="user1@example.jp">
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
                <li>利用者: user1@example.jp / password123</li>
                <li>管理者: admin@example.jp / password123</li>
            </ul>
        </div>
    </section>
</main>
<?php page_footer(['assets/js/common.js']); ?>
