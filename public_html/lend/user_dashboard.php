<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$user = current_user();
page_header('利用者ダッシュボード', 'dashboard-page');
?>
<header class="topbar">
    <div>
        <h1>利用者ダッシュボード</h1>
        <p class="muted"><?= h($user['name']) ?> さん（<?= h($user['organization'] ?? '') ?>）</p>
    </div>
    <nav class="topbar-actions">
        <a class="btn" href="kiosk.php">無人受付PC画面へ</a>
        <?php if (($user['role'] ?? '') === 'admin'): ?>
            <a class="btn" href="admin.php">管理画面</a>
        <?php endif; ?>
        <a class="btn danger" href="logout.php">ログアウト</a>
    </nav>
</header>

<main class="page-grid">
    <section class="card">
        <h2>新規申請</h2>
        <form id="reservation-form" class="stack-form">
            <label>
                <span>件名</span>
                <input type="text" name="title" required placeholder="新歓説明会用音響">
            </label>
            <label>
                <span>利用目的</span>
                <textarea name="purpose" rows="4" required placeholder="どのような行事で使うか"></textarea>
            </label>
            <label>
                <span>利用場所</span>
                <input type="text" name="place" placeholder="学生会館ホール">
            </label>
            <div class="two-col">
                <label>
                    <span>開始日時</span>
                    <input type="datetime-local" name="start_at" required>
                </label>
                <label>
                    <span>終了日時</span>
                    <input type="datetime-local" name="end_at" required>
                </label>
            </div>
            <label>
                <span>貸出セット</span>
                <select name="asset_set_id" id="asset-set-select" required></select>
            </label>
            <button type="submit" class="btn primary">申請する</button>
        </form>
        <div id="reservation-message" class="alert hidden"></div>
    </section>

    <section class="card">
        <h2>自分の予約・貸出状況</h2>
        <div id="reservation-list" class="stack-list"></div>
    </section>
</main>

<?php page_footer(['assets/js/common.js', 'assets/js/user_dashboard.js']); ?>
