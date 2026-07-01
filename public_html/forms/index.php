<?php
require_once __DIR__ . '/../../apps/forms_core/bootstrap.php';
require_once __DIR__ . '/../../apps/kintone_registry_integration.php';
forms_bootstrap();
$kintoneBridgePayload = fitsc_kintone_public_payload(['categories'], 500);
page_header('汎用フォーム', 'forms-public-page');
?>
<header class="public-site-header">
    <div class="public-site-header__brand">
        <div class="brand-mark" aria-hidden="true">F</div>
        <div>
            <h1>提出フォーム</h1>
            <p class="muted">フォーム一覧から提出先を選び、右側で内容を確認・入力してください。</p>
        </div>
    </div>
    <div class="topbar-actions">
        <button type="button" class="btn ghost public-drawer-toggle" id="public-drawer-open" aria-controls="public-sidebar" aria-expanded="false">フォーム一覧</button>
        <a class="btn ghost" href="login.php">管理者ログイン</a>
    </div>
</header>

<div class="public-drawer-backdrop" id="public-drawer-backdrop" hidden></div>

<main class="page-shell public-app-shell">
    <aside class="public-sidebar card glass-card" id="public-sidebar" aria-label="提出先フォーム一覧">
        <div class="public-sidebar-mobile-head">
            <strong>提出先フォーム</strong>
            <button type="button" class="btn ghost btn-compact public-drawer-close" id="public-drawer-close" aria-label="フォーム一覧を閉じる">閉じる</button>
        </div>
        <section class="public-sidebar-section sidebar-hero-block">
            <div>
                <h2>提出先フォーム</h2>
                <p class="small-note">受付中・提出期間前・受付終了のフォームを一覧表示しています。フォームを切り替えると、右側の内容も切り替わります。</p>
            </div>
            <div class="public-sidebar-stats">
                <article class="mini-stat-card accent-card">
                    <span class="mini-stat-label">表示中</span>
                    <strong id="public-form-count">0</strong>
                </article>
            </div>
        </section>

        <section class="public-sidebar-section">
            <label class="search-box public-search-box">
                <span class="small-note">フォームを検索</span>
                <input type="search" id="public-form-search" placeholder="フォーム名や説明で検索">
            </label>
        </section>

        <section class="public-sidebar-section">
            <div id="public-message" class="alert hidden"></div>
            <div id="public-form-nav" class="public-form-nav" role="tablist" aria-orientation="vertical" aria-label="提出先フォーム"></div>
        </section>

        <section class="public-sidebar-section helper-panel">
            <h3>入力のポイント</h3>
            <ul class="helper-list allow-select">
                <li>メールアドレスと団体名は必須です。</li>
                <li>フォームごとに日付欄や添付欄の有無が異なります。</li>
                <li>団体名候補が表示される場合がありますが、候補に無い団体でも送信は止めません。</li>
            </ul>
        </section>
    </aside>

    <section class="public-main">
        <section class="card public-hero-card accent-surface">
            <div class="public-hero-card__content">
                <div>
                    <h2 id="public-hero-title">フォームを選択してください</h2>
                    <p id="public-hero-description" class="muted">サイドバーから提出先を選択すると、ここに概要と入力欄が表示されます。</p>
                </div>
                <div id="public-hero-meta" class="public-hero-meta"></div>
            </div>
            <div id="public-hero-highlights" class="public-highlight-grid"></div>
        </section>

        <section class="public-main-grid">
            <article class="card public-form-panel form-panel-emphasis">
                <div class="section-title-row">
                    <div>
                        <h2>入力フォーム</h2>
                        <p class="small-note">以下がフォームの内容です。指定された項目に記入してください。</p>
                    </div>
                </div>
                <div id="public-form-host" class="form-host public-form-host" role="tabpanel" tabindex="0"></div>
            </article>
        </section>
    </section>
</main>

<script>
window.FITSC_KINTONE_BRIDGE = <?= json_encode($kintoneBridgePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<?php page_footer(['assets/js/common.js', 'assets/js/public_period_guard.js', 'assets/js/public.js', 'assets/js/distribution_multi_public.js', 'assets/js/kintone_public_hints.js']); ?>
