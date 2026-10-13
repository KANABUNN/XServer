<?php
/**
 * 共通ヘッダー（FIT-SC UI v7）
 * ページ側で $pageTitle / $pageSubtitle / $pageActions（信頼済み HTML）/ $pageBack を設定してから読み込む。
 */
require_once dirname(__DIR__) . '/ui_kit.php';

$appName = $config['app_name'] ?? '会議・ToDo';
$flashes = consume_flash();
$current = basename($_SERVER['PHP_SELF']);
$user = current_user();
$displayName = (string)($user['display_name'] ?? $user['login_id'] ?? '');
$pageTitle = $pageTitle ?? $appName;
$pageSubtitle = $pageSubtitle ?? '';
$pageActions = $pageActions ?? '';
$pageBack = $pageBack ?? '';
$navCurrent = $current === 'meeting.php' ? 'meetings.php' : ($current === 'export_csv.php' ? 'tasks.php' : $current);
$navItems = [
    ['href' => 'index.php', 'label' => 'ダッシュボード', 'icon' => 'dashboard'],
    ['href' => 'meetings.php', 'label' => '会議', 'icon' => 'calendar'],
    ['href' => 'tasks.php', 'label' => 'タスク', 'icon' => 'list-checks'],
];
?>
<!doctype html>
<html lang="ja" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($pageTitle === $appName ? $appName : $pageTitle . ' | ' . $appName) ?></title>
    <?= fitsc_ui_head(['css' => ['assets/app.css'], 'guard' => 'assets/context-menu-guard.js']) ?>
</head>
<body>
<div class="app-shell td-shell">
    <aside class="sidebar" id="app-sidebar" aria-label="メニュー">
        <a class="sidebar__brand" href="index.php">
            <span class="brand-mark"><?= fitsc_icon('clipboard-check') ?></span>
            <span class="brand-text"><strong><?= e($appName) ?></strong><span><?= e($config['organization'] ?? '会議・議題・タスク管理') ?></span></span>
        </a>
        <nav class="sidebar__nav" aria-label="メインメニュー">
            <?php foreach ($navItems as $item): $active = $navCurrent === $item['href']; ?>
                <a class="nav-item<?= $active ? ' is-active' : '' ?>" href="<?= e($item['href']) ?>"<?= $active ? ' aria-current="page"' : '' ?> data-tip="<?= e($item['label']) ?>" data-tip-pos="right">
                    <?= fitsc_icon($item['icon']) ?><span class="nav-item__label"><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar__footer">
            <button type="button" class="nav-item sidebar__collapse" data-sidebar-collapse data-tip="サイドバーを開閉" data-tip-pos="right"><?= fitsc_icon('chevrons-left') ?><span class="nav-item__label">折りたたむ</span></button>
            <div class="user-chip">
                <span class="avatar"><?= e(fitsc_initials($displayName)) ?></span>
                <span class="user-chip__text"><strong><?= e($displayName) ?></strong><span><?= e((string)($user['organization_name'] ?? '') ?: (string)($user['login_id'] ?? '')) ?></span></span>
            </div>
            <a class="nav-item" href="logout.php" data-tip="ログアウト" data-tip-pos="right"><?= fitsc_icon('log-out') ?><span class="nav-item__label">ログアウト</span></a>
        </div>
    </aside>
    <div class="sidebar-backdrop" data-sidebar-close></div>
    <div class="app-main">
        <header class="topbar">
            <button type="button" class="btn btn--ghost btn--icon topbar__menu" data-sidebar-toggle aria-label="メニュー"><?= fitsc_icon('menu') ?></button>
            <?php if ($pageBack !== ''): ?>
                <a class="btn btn--ghost btn--icon" href="<?= e($pageBack) ?>" aria-label="戻る" data-tip="戻る" data-tip-pos="bottom"><?= fitsc_icon('arrow-left') ?></a>
            <?php endif; ?>
            <div class="topbar__title">
                <h1><?= e($pageTitle) ?></h1>
                <?php if ($pageSubtitle !== ''): ?><p><?= e($pageSubtitle) ?></p><?php endif; ?>
            </div>
            <div class="topbar__actions">
                <?= $pageActions ?>
                <?= fitsc_theme_toggle() ?>
            </div>
        </header>
        <main class="page">
            <?php foreach ($flashes as $flash): ?>
                <?= fitsc_flash_html((string)($flash['type'] ?? 'info'), (string)($flash['message'] ?? '')) ?>
            <?php endforeach; ?>
