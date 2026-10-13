<?php
require_once __DIR__ . '/../../apps/lend_core/bootstrap.php';
require_once __DIR__ . '/_header.php';
require_admin();

$user = current_user();
page_header('管理', 'admin-page');
?>
<div class="site">
    <?php lend_render_header('admin', $user); ?>

    <main class="site-main stack stack--lg">
        <div class="cluster cluster--between">
            <div class="page-head__title">
                <h2>対応が必要な案件</h2>
                <p>承認・返却確認・延滞をまとめて確認します</p>
            </div>
            <button type="button" class="btn btn--ghost" id="admin-reload"><?= fitsc_icon('refresh') ?>再読込</button>
        </div>

        <div class="ld-admin-grid">
            <section class="card">
                <div class="card__header">
                    <div class="card__title"><?= fitsc_icon('inbox') ?>承認待ちの予約</div>
                    <span class="badge badge--info" data-count="pending">0</span>
                </div>
                <div id="pending-reservations" class="ld-list"></div>
            </section>

            <section class="card">
                <div class="card__header">
                    <div class="card__title"><?= fitsc_icon('alert-triangle') ?>返却の確認・異常</div>
                    <span class="badge badge--warning" data-count="review">0</span>
                </div>
                <div id="return-review-list" class="ld-list"></div>
            </section>

            <section class="card">
                <div class="card__header">
                    <div class="card__title"><?= fitsc_icon('clock') ?>延滞・未返却</div>
                    <span class="badge badge--danger" data-count="overdue">0</span>
                </div>
                <div id="overdue-list" class="ld-list"></div>
            </section>
        </div>
    </main>
</div>
<?php page_footer(['assets/js/common.js', 'assets/js/admin.js']); ?>
