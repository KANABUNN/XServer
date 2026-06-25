<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/apps/kintone_core/auth.php';

function kintone_page_header(string $title, array $user): void
{
    $nav = [
        'index.php' => 'ダッシュボード',
        'upload.php' => '名簿アップロード',
        'diff.php' => '差分・承認',
        'review.php' => 'レビュー待ち',
        'organizations.php' => '団体マスタ',
        'logs.php' => 'ログ',
        'settings.php' => '接続設定',
    ];
    ?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= kintone_h($title) ?> - kintone管理</title>
<link rel="stylesheet" href="../assets/common/tokens.css?v=20260625">
<link rel="stylesheet" href="../assets/common/fit-sc-skin.css?v=20260625">
</head>
<body class="admin-shell">
<aside class="sidebar">
  <div class="sidebar-brand"><span class="brand-kicker">FIT-SC</span><strong>kintone管理</strong></div>
  <nav>
    <?php foreach ($nav as $href => $label): ?>
      <a class="nav-link" href="<?= kintone_h($href) ?>"><?= kintone_h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <p class="muted">ログイン: <?= kintone_h((string)($user['display_name'] ?? $user['login_id'] ?? '')) ?></p>
  <p><a class="nav-link" href="logout.php">ログアウト</a></p>
</aside>
<main class="main-content">
<header class="page-header"><h1><?= kintone_h($title) ?></h1></header>
<?php
}

function kintone_page_footer(): void
{
    ?></main></body></html><?php
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
    $class = ($flash['type'] ?? '') === 'error' ? 'alert alert-danger' : 'alert alert-success';
    echo '<div class="' . kintone_h($class) . '">' . kintone_h((string)$flash['message']) . '</div>';
}
