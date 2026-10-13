<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$appsDir = $projectRoot . '/apps';

require_once $appsDir . '/backup_core/auth.php';
require_once $appsDir . '/backup_core/manager.php';
require_once $appsDir . '/backup_core/alert_rules.php';
require_once $appsDir . '/ui_kit.php';

backup_auth_bootstrap();
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function backup_web_require_user(): array
{
    return backup_auth_require_view_access();
}

function backup_web_require_admin(): array
{
    return backup_auth_require_admin_access();
}

function backup_web_is_admin(array $user): bool
{
    return backup_auth_user_has_role($user, 'admin');
}

function backup_web_manager(): FitScBackupManager
{
    static $manager = null;
    if ($manager instanceof FitScBackupManager) {
        return $manager;
    }
    $manager = new FitScBackupManager();
    return $manager;
}

/**
 * @param array{subtitle?:string, actions?:string, back?:string} $opts actions は信頼済み HTML
 */
function backup_web_header(string $title, array $user, array $opts = []): void
{
    $displayName = (string)($user['display_name'] ?? $user['login_id'] ?? '');
    $isAdmin = backup_web_is_admin($user);
    $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) ?: 'index.php';
    if ($current === 'detail.php') {
        $current = 'history.php';
    }
    $nav = [
        ['href' => 'index.php', 'label' => 'ダッシュボード', 'icon' => 'dashboard', 'section' => '', 'show' => true],
        ['href' => 'history.php', 'label' => '実行履歴', 'icon' => 'history', 'section' => '記録', 'show' => true],
        ['href' => 'storage.php', 'label' => 'ストレージ', 'icon' => 'hard-drive', 'section' => '記録', 'show' => true],
        ['href' => 'integrity.php', 'label' => '整合性', 'icon' => 'shield', 'section' => '記録', 'show' => true],
        ['href' => 'alerts.php', 'label' => 'アラート', 'icon' => 'bell', 'section' => '記録', 'show' => true],
        ['href' => 'restore.php', 'label' => '復元補助', 'icon' => 'rotate-ccw', 'section' => '運用', 'show' => $isAdmin],
        ['href' => 'reports.php', 'label' => 'レポート', 'icon' => 'file-text', 'section' => '運用', 'show' => true],
    ];
    $subtitle = (string)($opts['subtitle'] ?? '');
    $actions = (string)($opts['actions'] ?? '');
    $back = (string)($opts['back'] ?? '');
    ?><!doctype html>
<html lang="ja" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= backup_h($title) ?> | バックアップ管理</title>
<?= fitsc_ui_head(['css' => ['assets/css/app.css'], 'guard' => 'context-menu-guard.js']) ?>
</head>
<body>
<div class="app-shell bk-shell">
  <aside class="sidebar" id="app-sidebar" aria-label="メニュー">
    <a class="sidebar__brand" href="index.php">
      <span class="brand-mark"><?= fitsc_icon('archive') ?></span>
      <span class="brand-text"><strong>バックアップ管理</strong><span>FIT-SC Backup</span></span>
    </a>
    <nav class="sidebar__nav" aria-label="バックアップ管理メニュー">
      <?php $section = null; ?>
      <?php foreach ($nav as $item): if (!$item['show']) continue; ?>
        <?php if ($item['section'] !== '' && $item['section'] !== $section): $section = $item['section']; ?>
          <div class="nav-section"><?= backup_h($section) ?></div>
        <?php endif; ?>
        <?php $active = $current === $item['href']; ?>
        <a class="nav-item<?= $active ? ' is-active' : '' ?>" href="<?= backup_h($item['href']) ?>"<?= $active ? ' aria-current="page"' : '' ?> data-tip="<?= backup_h($item['label']) ?>" data-tip-pos="right">
          <?= fitsc_icon($item['icon']) ?><span class="nav-item__label"><?= backup_h($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar__footer">
      <button type="button" class="nav-item sidebar__collapse" data-sidebar-collapse data-tip="サイドバーを開閉" data-tip-pos="right"><?= fitsc_icon('chevrons-left') ?><span class="nav-item__label">折りたたむ</span></button>
      <div class="user-chip">
        <span class="avatar"><?= backup_h(fitsc_initials($displayName)) ?></span>
        <span class="user-chip__text"><strong><?= backup_h($displayName) ?></strong><span><?= $isAdmin ? '管理者' : '閲覧者' ?></span></span>
      </div>
      <a class="nav-item" href="logout.php" data-tip="ログアウト" data-tip-pos="right"><?= fitsc_icon('log-out') ?><span class="nav-item__label">ログアウト</span></a>
    </div>
  </aside>
  <div class="sidebar-backdrop" data-sidebar-close></div>
  <div class="app-main">
    <header class="topbar">
      <button type="button" class="btn btn--ghost btn--icon topbar__menu" data-sidebar-toggle aria-label="メニュー"><?= fitsc_icon('menu') ?></button>
      <?php if ($back !== ''): ?>
        <a class="btn btn--ghost btn--icon" href="<?= backup_h($back) ?>" aria-label="戻る" data-tip="戻る" data-tip-pos="bottom"><?= fitsc_icon('arrow-left') ?></a>
      <?php endif; ?>
      <div class="topbar__title">
        <h1><?= backup_h($title) ?></h1>
        <?php if ($subtitle !== ''): ?><p><?= backup_h($subtitle) ?></p><?php endif; ?>
      </div>
      <div class="topbar__actions">
        <?= $actions ?>
        <?= fitsc_theme_toggle() ?>
      </div>
    </header>
    <main class="page">
<?php
}

function backup_web_footer(): void
{
    ?>
    </main>
  </div>
</div>
<script src="<?= backup_h(fitsc_ui_versioned('assets/js/app.js')) ?>"></script>
</body>
</html>
<?php
}

function backup_web_status_tone(string $status): string
{
    return match ($status) {
        'success', 'ok' => 'success',
        'running', 'info' => 'info',
        'failed', 'error', 'critical', 'missing' => 'danger',
        'partial', 'warning' => 'warning',
        default => '',
    };
}

function backup_web_status_badge(string $status): string
{
    $tone = backup_web_status_tone($status);
    return '<span class="badge badge--dot' . ($tone !== '' ? ' badge--' . $tone : '') . '">' . backup_h(backup_status_label($status)) . '</span>';
}

function backup_web_level_badge(string $level): string
{
    [$label, $tone] = match ($level) {
        'critical' => ['重大', 'danger badge--solid'],
        'error' => ['エラー', 'danger'],
        'warning' => ['警告', 'warning'],
        'info' => ['情報', 'info'],
        default => [$level, ''],
    };
    return '<span class="badge' . ($tone !== '' ? ' badge--' . $tone : '') . '">' . backup_h($label) . '</span>';
}

function backup_web_job_type_label(string $type): string
{
    return match ($type) {
        'daily' => '日次',
        'weekly' => '週次',
        'monthly' => '月次',
        default => $type,
    };
}

function backup_web_trigger_label(string $trigger): string
{
    return match ($trigger) {
        'cron' => '自動',
        'manual', 'cli' => '手動',
        default => $trigger,
    };
}

function backup_web_flash(?string $type, ?string $message): void
{
    if (!$type || !$message) {
        return;
    }
    echo fitsc_flash_html($type, $message);
}

function backup_web_empty(string $icon, string $title, string $hint = ''): string
{
    return '<div class="empty"><span class="empty__icon">' . fitsc_icon($icon) . '</span>'
        . '<span class="empty__title">' . backup_h($title) . '</span>'
        . ($hint !== '' ? '<span>' . $hint . '</span>' : '')
        . '</div>';
}
