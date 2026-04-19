<?php
require_once __DIR__ . '/../../apps/lend_core/bootstrap.php';
require_admin();

$user = current_user();
page_header('管理画面', 'admin-page');
?>
<header class="topbar">
    <div>
        <h1>管理画面</h1>
        <p class="muted"><?= h($user['name']) ?> さん</p>
    </div>
    <nav class="topbar-actions">
        <a class="btn" href="user_dashboard.php">利用者画面</a>
        <a class="btn" href="kiosk.php">無人受付PC画面</a>
        <a class="btn danger" href="logout.php">ログアウト</a>
    </nav>
</header>

<main class="page-grid admin-grid">
    <section class="card">
        <h2>承認待ち予約</h2>
        <div id="pending-reservations" class="stack-list"></div>
    </section>

    <section class="card">
        <h2>確認待ち返却 / 異常案件</h2>
        <div id="return-review-list" class="stack-list"></div>
    </section>

    <section class="card">
        <h2>延滞・未返却</h2>
        <div id="overdue-list" class="stack-list"></div>
    </section>
</main>

<?php page_footer(['assets/js/common.js', 'assets/js/admin.js']); ?>
