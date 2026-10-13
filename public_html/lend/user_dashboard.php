<?php
require_once __DIR__ . '/../../apps/lend_core/bootstrap.php';
require_once __DIR__ . '/_header.php';
require_login();

$user = current_user();
page_header('申請・状況', 'lend-dashboard-page');
?>
<div class="site">
    <?php lend_render_header('user_dashboard', $user); ?>

    <main class="site-main">
        <div class="ld-dashboard">
            <section class="card">
                <div class="card__header card__header--plain">
                    <div class="card__title"><?= fitsc_icon('plus') ?>新しく申請する</div>
                </div>
                <form id="reservation-form" class="card__body stack" data-busy>
                    <label class="field"><span>件名 <span class="req">必須</span></span><input type="text" name="title" required placeholder="例：新歓説明会用の音響"></label>
                    <label class="field"><span>利用目的 <span class="req">必須</span></span><textarea name="purpose" rows="3" required placeholder="どのような行事で使うか"></textarea></label>
                    <label class="field"><span>利用場所</span><input type="text" name="place" placeholder="例：学生会館ホール"></label>
                    <div class="form-grid">
                        <label class="field"><span>開始日時 <span class="req">必須</span></span><input type="datetime-local" name="start_at" required></label>
                        <label class="field"><span>終了日時 <span class="req">必須</span></span><input type="datetime-local" name="end_at" required></label>
                    </div>
                    <label class="field"><span>貸出セット <span class="req">必須</span></span><select name="asset_set_id" id="asset-set-select" required><option value="">読み込み中…</option></select></label>
                    <div id="kintone-asset-reference"></div>
                    <div id="reservation-message" class="alert hidden" role="status"></div>
                    <div class="form-actions form-actions--end">
                        <button type="submit" class="btn btn--primary btn--lg"><?= fitsc_icon('send') ?>申請する</button>
                    </div>
                </form>
            </section>

            <section class="card">
                <div class="card__header">
                    <div class="card__title"><?= fitsc_icon('clipboard') ?>あなたの予約・貸出 <small id="reservation-count"></small></div>
                </div>
                <div id="reservation-list" class="ld-list">
                    <div class="card__body stack stack--sm"><span class="skeleton" style="height:64px"></span><span class="skeleton" style="height:64px"></span></div>
                </div>
            </section>
        </div>
    </main>
</div>
<?php page_footer(['assets/js/common.js', 'assets/js/user_dashboard.js']); ?>
