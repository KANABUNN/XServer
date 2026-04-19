<?php
require_once __DIR__ . '/../../apps/lend_core/bootstrap.php';
require_once __DIR__ . '/../../apps/forms_module.php';
forms_bootstrap();
page_header('汎用フォーム', 'forms-public-page');
?>
<header class="topbar">
    <div>
        <h1>提出フォーム</h1>
        <p class="muted">管理者が登録したフォームを切り替えて提出できます。</p>
    </div>
    <div class="topbar-actions">
        <a class="btn" href="login.php">管理者ログイン</a>
    </div>
</header>

<main class="page-shell public-shell">
    <section class="card">
        <div id="public-message" class="alert hidden"></div>
        <div id="public-tab-list" class="tab-list"></div>
        <div id="public-form-host" class="form-host"></div>
    </section>
</main>

<?php page_footer(['assets/js/common.js', 'assets/js/public.js']); ?>
