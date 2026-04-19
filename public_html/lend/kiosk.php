<?php
require_once __DIR__ . '/../../apps/lend_core/bootstrap.php';
require_login();

$user = current_user();
$idleSeconds = (int) app_config('kiosk.idle_logout_seconds', 180);
page_header('無人受付PC', 'kiosk-page');
?>
<header class="topbar kiosk-topbar">
    <div>
        <h1>無人受付PC</h1>
        <p class="muted">利用者が自分で貸出・返却を行う画面</p>
    </div>
    <nav class="topbar-actions">
        <a class="btn" href="user_dashboard.php">利用者画面</a>
        <?php if (($user['role'] ?? '') === 'admin'): ?>
            <a class="btn" href="admin.php">管理画面</a>
        <?php endif; ?>
        <a class="btn danger" href="logout.php">ログアウト</a>
    </nav>
</header>

<main class="kiosk-layout" data-idle-seconds="<?= (int)$idleSeconds ?>">
    <section class="card">
        <h2>操作種別</h2>
        <div class="kiosk-action-row">
            <button class="btn large primary" data-kiosk-action="checkout">貸出</button>
            <button class="btn large" data-kiosk-action="return">返却</button>
        </div>

        <label class="block-margin">
            <span>対象予約</span>
            <select id="kiosk-reservation-select"></select>
        </label>

        <div class="scanner-shell">
            <div id="reader"></div>
        </div>

        <div class="inline-row">
            <button id="start-scan-btn" class="btn primary">カメラを起動</button>
            <button id="stop-scan-btn" class="btn">停止</button>
        </div>

        <div class="small-note">
            QRコードには備品コードだけを含めてください。<br>
            例: <code>SET-AMP-001</code>
        </div>
    </section>

    <section class="card">
        <h2>スキャン結果</h2>
        <div id="scan-status" class="alert info">まだスキャンしていません。</div>

        <form id="issue-form" class="stack-form hidden">
            <label>
                <span>異常・不足がある場合のメモ</span>
                <textarea id="issue-note" rows="4" placeholder="ケーブル1本不足、マイク本体に擦り傷あり など"></textarea>
            </label>
        </form>

        <div class="small-note">
            高額機材や異常申告がある返却は「確認待ち」になります。
        </div>
    </section>
</main>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<?php page_footer(['assets/js/common.js', 'assets/js/kiosk.js']); ?>
