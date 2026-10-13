<?php
/**
 * 備品貸出システム 共通ヘッダー（利用者画面・無人受付・管理画面）
 */
function lend_render_header(string $active, array $user): void
{
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $items = [
        'user_dashboard' => ['user_dashboard.php', '申請・状況', 'clipboard'],
        'kiosk' => ['kiosk.php', '無人受付', 'qr'],
    ];
    if ($isAdmin) {
        $items['admin'] = ['admin.php', '管理', 'shield'];
    }
    $name = (string)($user['name'] ?? '');
    ?>
<header class="site-header ld-header">
    <div class="site-header__inner">
        <a class="site-brand" href="index.php">
            <span class="brand-mark"><?= fitsc_icon('package') ?></span>
            <span class="site-brand__text"><strong>備品貸出</strong><span>FIT-SC Lend</span></span>
        </a>
        <nav class="ld-nav" aria-label="メニュー">
            <?php foreach ($items as $key => [$href, $label, $icon]): ?>
                <a class="ld-nav__item<?= $active === $key ? ' is-active' : '' ?>" href="<?= h($href) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= fitsc_icon($icon) ?><span><?= h($label) ?></span></a>
            <?php endforeach; ?>
        </nav>
        <div class="site-header__actions">
            <span class="menu-anchor">
                <button type="button" class="btn btn--ghost ld-user" data-menu-toggle aria-haspopup="menu" aria-expanded="false">
                    <span class="avatar"><?= h(fitsc_initials($name)) ?></span>
                    <span class="hide-mobile"><?= h($name) ?></span>
                    <?= fitsc_icon('chevron-down', 'ico--sm') ?>
                </button>
                <div class="menu" role="menu">
                    <div class="menu__label"><?= h($name) ?><?= !empty($user['organization']) ? '・' . h((string)$user['organization']) : '' ?></div>
                    <a class="menu__item menu__item--danger" role="menuitem" href="logout.php?csrf_token=<?= urlencode(csrf_token()) ?>"><?= fitsc_icon('log-out') ?>ログアウト</a>
                </div>
            </span>
            <?= fitsc_theme_toggle() ?>
        </div>
    </div>
</header>
    <?php
}
