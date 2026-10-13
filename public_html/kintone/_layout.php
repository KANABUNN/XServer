<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/apps/kintone_core/auth.php';
require_once dirname(__DIR__, 2) . '/apps/kintone_core/audit.php';
require_once dirname(__DIR__, 2) . '/apps/ui_kit.php';

function kintone_user_can_operate(array $user): bool
{
    return kintone_auth_user_has_role($user, 'operator') || kintone_auth_user_has_role($user, 'admin');
}

function kintone_user_role_label(array $user): string
{
    if (kintone_auth_user_has_role($user, 'admin')) {
        return '管理者';
    }
    if (kintone_auth_user_has_role($user, 'operator')) {
        return '担当者';
    }
    return '閲覧者';
}

function kintone_nav_items(array $user): array
{
    $canOperate = kintone_user_can_operate($user);
    $isAdmin = kintone_auth_user_has_role($user, 'admin');
    $items = [
        ['href' => 'index.php', 'label' => 'ダッシュボード', 'icon' => 'dashboard', 'section' => '', 'show' => true],
        ['href' => 'upload.php', 'label' => '名簿アップロード', 'icon' => 'upload', 'section' => '名簿の取込', 'show' => $canOperate],
        ['href' => 'diff.php', 'label' => '差分・承認', 'icon' => 'diff', 'section' => '名簿の取込', 'show' => true],
        ['href' => 'review.php', 'label' => 'レビュー待ち', 'icon' => 'inbox', 'section' => '名簿の取込', 'show' => true],
        ['href' => 'organizations.php', 'label' => '団体マスタ', 'icon' => 'building', 'section' => 'データ', 'show' => true],
        ['href' => 'sync.php', 'label' => 'kintone同期', 'icon' => 'refresh', 'section' => 'データ', 'show' => true],
        ['href' => 'logs.php', 'label' => 'ログ', 'icon' => 'history', 'section' => 'データ', 'show' => true],
        ['href' => 'settings.php', 'label' => 'アプリ設定', 'icon' => 'settings', 'section' => '設定', 'show' => $isAdmin],
    ];
    return array_values(array_filter($items, static fn(array $item): bool => $item['show']));
}

/**
 * @param array{subtitle?:string, actions?:string} $opts actions は信頼済み HTML
 */
function kintone_page_header(string $title, array $user, array $opts = []): void
{
    $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) ?: 'index.php';
    if ($current === 'confirm_apply.php') {
        $current = 'diff.php';
    }
    $displayName = (string)($user['display_name'] ?? $user['login_id'] ?? '');
    $subtitle = (string)($opts['subtitle'] ?? '');
    $actions = (string)($opts['actions'] ?? '');
    ?><!doctype html>
<html lang="ja" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= kintone_h($title) ?> | kintone管理</title>
<?= fitsc_ui_head(['css' => ['assets/css/app.css']]) ?>
</head>
<body>
<div class="app-shell kt-shell">
  <aside class="sidebar" id="app-sidebar" aria-label="メニュー">
    <a class="sidebar__brand" href="index.php">
      <span class="brand-mark"><?= fitsc_icon('cloud') ?></span>
      <span class="brand-text"><strong>kintone管理</strong><span>団体マスタ・名簿連携</span></span>
    </a>
    <nav class="sidebar__nav" aria-label="kintone管理メニュー">
      <?php $section = null; ?>
      <?php foreach (kintone_nav_items($user) as $item): ?>
        <?php if ($item['section'] !== '' && $item['section'] !== $section): $section = $item['section']; ?>
          <div class="nav-section"><?= kintone_h($section) ?></div>
        <?php endif; ?>
        <?php $active = $current === $item['href']; ?>
        <a class="nav-item<?= $active ? ' is-active' : '' ?>" href="<?= kintone_h($item['href']) ?>"<?= $active ? ' aria-current="page"' : '' ?> data-tip="<?= kintone_h($item['label']) ?>" data-tip-pos="right">
          <?= fitsc_icon($item['icon']) ?><span class="nav-item__label"><?= kintone_h($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar__footer">
      <button type="button" class="nav-item sidebar__collapse" data-sidebar-collapse data-tip="サイドバーを開閉" data-tip-pos="right"><?= fitsc_icon('chevrons-left') ?><span class="nav-item__label">折りたたむ</span></button>
      <div class="user-chip">
        <span class="avatar"><?= kintone_h(fitsc_initials($displayName)) ?></span>
        <span class="user-chip__text"><strong><?= kintone_h($displayName) ?></strong><span><?= kintone_h(kintone_user_role_label($user)) ?></span></span>
      </div>
      <a class="nav-item" href="logout.php" data-tip="ログアウト" data-tip-pos="right"><?= fitsc_icon('log-out') ?><span class="nav-item__label">ログアウト</span></a>
    </div>
  </aside>
  <div class="sidebar-backdrop" data-sidebar-close></div>
  <div class="app-main">
    <header class="topbar">
      <button type="button" class="btn btn--ghost btn--icon topbar__menu" data-sidebar-toggle aria-label="メニュー"><?= fitsc_icon('menu') ?></button>
      <div class="topbar__title">
        <h1><?= kintone_h($title) ?></h1>
        <?php if ($subtitle !== ''): ?><p><?= kintone_h($subtitle) ?></p><?php endif; ?>
      </div>
      <div class="topbar__actions">
        <?= $actions ?>
        <?= fitsc_theme_toggle() ?>
      </div>
    </header>
    <main class="page">
<?php
}

function kintone_page_footer(): void
{
    ?>
    </main>
  </div>
</div>
<script src="<?= kintone_h(fitsc_ui_versioned('assets/js/app.js')) ?>" defer></script>
</body>
</html>
<?php
}

function kintone_flash(): ?array
{
    kintone_auth_bootstrap();
    $flash = $_SESSION['_kintone_flash'] ?? null;
    unset($_SESSION['_kintone_flash']);
    return is_array($flash) ? $flash : null;
}

function kintone_set_flash(string $type, string $message): void
{
    kintone_auth_bootstrap();
    $_SESSION['_kintone_flash'] = ['type' => $type, 'message' => $message];
}

function kintone_render_flash(): void
{
    $flash = kintone_flash();
    if (!$flash) {
        return;
    }
    echo fitsc_flash_html((string)($flash['type'] ?? 'success'), (string)($flash['message'] ?? ''));
}

/** 日本語ラベル付きの状態バッジ（元の値は title に残す） */
function kintone_status_badge(?string $status): string
{
    $status = (string)$status;
    if ($status === '') {
        return '<span class="text-subtle">—</span>';
    }
    [$label, $tone] = match ($status) {
        'success' => ['成功', 'success'],
        'connected' => ['接続済み', 'success'],
        'partial' => ['一部失敗', 'warning'],
        'not_connected' => ['未接続', 'warning'],
        'failed' => ['失敗', 'danger'],
        'error' => ['エラー', 'danger'],
        'running' => ['実行中', 'info'],
        'queued', 'pending' => ['待機中', 'info'],
        'parsed' => ['解析済み', 'info'],
        'validated' => ['検証済み', 'teal'],
        'needs_review' => ['要確認', 'warning'],
        'approved' => ['承認済み', 'accent'],
        'applied' => ['反映済み', 'success'],
        'active' => ['活動中', 'success'],
        'inactive' => ['活動不可', 'danger'],
        'unknown' => ['不明', ''],
        default => [$status, ''],
    };
    $class = 'badge badge--dot' . ($tone !== '' ? ' badge--' . $tone : '');
    return '<span class="' . $class . '" title="' . kintone_h($status) . '">' . kintone_h($label) . '</span>';
}

function kintone_risk_badge(?string $risk): string
{
    [$label, $tone] = match ((string)$risk) {
        'high' => ['高', 'danger'],
        'medium' => ['中', 'warning'],
        'low' => ['低', 'success'],
        default => [(string)$risk, ''],
    };
    return '<span class="badge kt-risk' . ($tone !== '' ? ' badge--' . $tone : '') . '" title="リスク: ' . kintone_h((string)$risk) . '">' . kintone_h($label) . '</span>';
}

function kintone_role_badge(?string $role): string
{
    [$label, $tone] = match ((string)$role) {
        'push' => ['送信 push', 'accent'],
        'pull' => ['取得 pull', 'teal'],
        'manual' => ['手動', ''],
        default => [(string)$role, ''],
    };
    return '<span class="badge' . ($tone !== '' ? ' badge--' . $tone : '') . '">' . kintone_h($label) . '</span>';
}

function kintone_field_label(string $field): string
{
    return match ($field) {
        'organization_name' => '団体名',
        'normalized_organization_name' => '団体名（正規化）',
        'category' => '区分',
        'representative_name' => '代表者',
        'representative_email' => '代表メール',
        'rep_source' => '代表者の判定元',
        'activity_status' => '活動状態',
        'activity_status_source' => '活動状態の判定元',
        'member_count' => '部員数',
        default => $field,
    };
}

/** 差分の値表示（活動状態は日本語バッジ、空は — ） */
function kintone_change_value(string $field, ?string $value): string
{
    if ($value === null || $value === '') {
        return '<span class="text-subtle">—</span>';
    }
    if ($field === 'activity_status') {
        return kintone_status_badge($value);
    }
    return '<span class="allow-select">' . kintone_h($value) . '</span>';
}
