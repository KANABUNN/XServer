<?php
require_once __DIR__ . '/../../apps/lend_core/bootstrap.php';
require_once __DIR__ . '/_header.php';
require_login();

$user = current_user();
$idleSeconds = (int) app_config('kiosk.idle_logout_seconds', 180);
page_header('無人受付', 'kiosk-page');
?>
<div class="site">
    <?php lend_render_header('kiosk', $user); ?>

    <main class="site-main kiosk-layout ld-kiosk" data-idle-seconds="<?= (int)$idleSeconds ?>">
        <section class="card ld-kiosk__main">
            <div class="card__body stack stack--lg">
                <div class="ld-kiosk__actions" role="radiogroup" aria-label="操作">
                    <button type="button" class="ld-kiosk__action is-active" data-kiosk-action="checkout" role="radio" aria-checked="true">
                        <span class="ld-kiosk__action-icon"><?= fitsc_icon('upload', 'ico--xl') ?></span>
                        <strong>貸出</strong><small>備品を受け取る</small>
                    </button>
                    <button type="button" class="ld-kiosk__action" data-kiosk-action="return" role="radio" aria-checked="false">
                        <span class="ld-kiosk__action-icon"><?= fitsc_icon('download', 'ico--xl') ?></span>
                        <strong>返却</strong><small>備品を返す</small>
                    </button>
                </div>

                <label class="field">
                    <span>対象の予約</span>
                    <select id="kiosk-reservation-select"><option value="">読み込み中…</option></select>
                </label>

                <div class="ld-scanner">
                    <div id="reader" class="ld-scanner__view"></div>
                    <div class="ld-scanner__placeholder" id="scanner-placeholder">
                        <?= fitsc_icon('qr', 'ico--xl') ?>
                        <span>「カメラを起動」を押して、備品の QR コードをかざしてください</span>
                    </div>
                </div>

                <div class="ld-kiosk__controls">
                    <button type="button" id="start-scan-btn" class="btn btn--primary btn--xl"><?= fitsc_icon('camera', 'ico--lg') ?>カメラを起動</button>
                    <button type="button" id="stop-scan-btn" class="btn btn--xl"><?= fitsc_icon('stop', 'ico--lg') ?>停止</button>
                </div>
            </div>
        </section>

        <section class="card ld-kiosk__side">
            <div class="card__header card__header--plain"><div class="card__title"><?= fitsc_icon('activity') ?>結果</div></div>
            <div class="card__body stack">
                <div id="scan-status" class="alert alert--info ld-scan-status" role="status" aria-live="assertive"><?= fitsc_icon('info') ?><div class="alert__body">まだスキャンしていません。</div></div>
                <form id="issue-form" class="stack hidden">
                    <label class="field">
                        <span>不足・破損がある場合のメモ</span>
                        <textarea id="issue-note" rows="4" placeholder="例：ケーブル1本不足、マイク本体に擦り傷あり"></textarea>
                    </label>
                    <p class="small-note">メモがある返却や高額機材の返却は「確認待ち」になります。</p>
                </form>
                <p class="small-note"><?= fitsc_icon('info', 'ico--sm') ?> 一定時間操作がないと自動的にログアウトします。</p>
            </div>
        </section>
    </main>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<?php page_footer(['assets/js/common.js', 'assets/js/kiosk.js']); ?>
