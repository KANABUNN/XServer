<?php
require_once __DIR__ . '/../../apps/forms_core/bootstrap.php';
require_once __DIR__ . '/../../apps/kintone_registry_integration.php';
forms_bootstrap();
$kintoneBridgePayload = fitsc_kintone_public_payload(['categories'], 500);
page_header('提出フォーム', 'forms-public-page', ['app_name' => '総合管理事務局']);
?>
<div class="site fp-site">
    <header class="site-header">
        <div class="site-header__inner">
            <a class="site-brand" href="./" aria-label="提出フォーム トップ">
                <span class="brand-mark"><?= fitsc_icon('file-text') ?></span>
                <span class="site-brand__text">
                    <strong>提出フォーム</strong>
                    <span>総合管理事務局</span>
                </span>
            </a>
            <div class="site-header__actions">
                <div class="search-toggle" data-search-toggle style="--search-w: 280px">
                    <button type="button" class="btn btn--ghost btn--icon search-toggle__button" aria-label="フォームを検索" aria-expanded="false" title="フォームを検索"><?= fitsc_icon('search') ?></button>
                    <div class="search-toggle__field">
                        <?= fitsc_icon('search') ?>
                        <input type="search" id="public-form-search" placeholder="フォーム名で検索" autocomplete="off" aria-label="フォーム名で検索">
                        <button type="button" class="btn btn--ghost btn--icon search-toggle__clear" aria-label="検索を閉じる"><?= fitsc_icon('x') ?></button>
                    </div>
                </div>
                <?= fitsc_theme_toggle() ?>
            </div>
        </div>
    </header>

    <main class="fp-layout" id="fp-layout" data-view="list">
        <aside class="fp-list" aria-label="フォーム一覧">
            <div class="fp-list__head">
                <h2>受付中のフォーム</h2>
                <span class="badge badge--success" id="public-open-count">0</span>
            </div>
            <div id="public-form-nav" class="fp-nav" role="tablist" aria-orientation="vertical" aria-label="提出先フォーム">
                <div class="fp-nav__loading" aria-hidden="true">
                    <span class="skeleton" style="height:58px"></span>
                    <span class="skeleton" style="height:58px"></span>
                    <span class="skeleton" style="height:58px"></span>
                </div>
            </div>
        </aside>

        <section class="fp-detail" aria-label="フォーム入力">
            <button type="button" class="btn btn--ghost btn--sm fp-back" id="public-back"><?= fitsc_icon('arrow-left') ?>フォーム一覧</button>
            <div id="public-form-host" class="fp-host" role="tabpanel" tabindex="-1">
                <div class="card fp-card">
                    <div class="card__body stack">
                        <span class="skeleton" style="height:22px;width:40%"></span>
                        <span class="skeleton" style="height:14px;width:70%"></span>
                        <span class="skeleton" style="height:120px"></span>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <span>&copy; 総合管理事務局</span>
        <span aria-hidden="true">·</span>
        <a href="login.php">管理者ログイン</a>
    </footer>
</div>

<script>
window.FITSC_KINTONE_BRIDGE = <?= json_encode($kintoneBridgePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<?php page_footer(['assets/js/common.js', 'assets/js/public.js']); ?>
