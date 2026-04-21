<?php
require_once __DIR__ . '/../../apps/forms_core/bootstrap.php';
forms_bootstrap();
page_header('汎用フォーム', 'forms-public-page');
?>
<header class="public-site-header">
    <div class="public-site-header__brand">
        <div class="brand-mark" aria-hidden="true">Fit</div>
        <div>
            <p class="eyebrow">Flexible Forms</p>
            <h1>提出フォーム</h1>
            <p class="muted">左のサイドバーから提出先を選び、右側で内容を確認しながら入力できます。</p>
        </div>
    </div>
    <div class="topbar-actions">
        <a class="btn ghost" href="login.php">管理者ログイン</a>
    </div>
</header>

<main class="page-shell public-app-shell">
    <aside class="public-sidebar card glass-card">
        <section class="public-sidebar-section sidebar-hero-block">
            <div>
                <p class="eyebrow">Navigator</p>
                <h2>提出先フォーム</h2>
                <p class="small-note">公開中のフォームだけを一覧表示しています。フォームを切り替えると、右側の入力内容も切り替わります。</p>
            </div>
            <div class="public-sidebar-stats">
                <article class="mini-stat-card accent-card">
                    <span class="mini-stat-label">公開中</span>
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
            <div id="public-form-nav" class="public-form-nav"></div>
        </section>

        <section class="public-sidebar-section helper-panel">
            <h3>入力のポイント</h3>
            <ul class="helper-list allow-select">
                <li>メールアドレスと団体名は必須です。</li>
                <li>同一メールアドレスまたは団体名から再送信した場合は、履歴を残しつつ最新内容へ更新します。</li>
                <li>フォームごとに日付欄や添付欄の有無が異なります。</li>
            </ul>
        </section>
    </aside>

    <section class="public-main">
        <section class="card public-hero-card accent-surface">
            <div class="public-hero-card__content">
                <div>
                    <p class="eyebrow">Selected Form</p>
                    <h2 id="public-hero-title">フォームを選択してください</h2>
                    <p id="public-hero-description" class="muted">左のサイドバーから提出先を選択すると、ここに概要と入力欄が表示されます。</p>
                </div>
                <div id="public-hero-meta" class="public-hero-meta"></div>
            </div>
            <div id="public-hero-highlights" class="public-highlight-grid"></div>
        </section>

        <section class="public-main-grid">
            <article class="card public-form-panel form-panel-emphasis">
                <div class="section-title-row">
                    <div>
                        <p class="eyebrow">Form Workspace</p>
                        <h2>入力フォーム</h2>
                        <p class="small-note">入力に必要な項目だけをわかりやすく表示します。</p>
                    </div>
                    <div id="public-active-summary" class="meta-line"></div>
                </div>
                <div id="public-form-host" class="form-host public-form-host"></div>
            </article>

            <aside class="public-side-panels">
                <section class="card public-info-panel">
                    <h3>受付状況</h3>
                    <div id="public-availability-panel" class="stack-list"></div>
                </section>

                <section class="card public-info-panel">
                    <h3>フォーム概要</h3>
                    <div id="public-summary-panel" class="stack-list"></div>
                </section>
            </aside>
        </section>
    </section>
</main>

<?php page_footer(['assets/js/common.js', 'assets/js/public.js']); ?>
