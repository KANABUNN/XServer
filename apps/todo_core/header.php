<?php
$appName = $config['app_name'] ?? 'ToDo App';
$flashes = consume_flash();
$current = basename($_SERVER['PHP_SELF']);
$user = current_user();
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($appName) ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/responsive.css">
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand"><?= e($appName) ?></div>
        <div class="org"><?= e($config['organization'] ?? '') ?></div>
        <nav class="nav">
            <a class="<?= $current === 'index.php' ? 'active' : '' ?>" href="index.php">ダッシュボード</a>
            <a class="<?= $current === 'meetings.php' ? 'active' : '' ?>" href="meetings.php">会議一覧</a>
            <a class="<?= $current === 'tasks.php' ? 'active' : '' ?>" href="tasks.php">横断タスク一覧</a>
            <a class="<?= $current === 'export_csv.php' ? 'active' : '' ?>" href="export_csv.php">CSV出力</a>
        </nav>
        <div class="sidebar-footer">
            <div>ログイン中: <?= e($user['display_name'] ?? $user['login_id'] ?? '') ?></div>
            <a href="logout.php" class="btn btn-ghost btn-small">ログアウト</a>
        </div>
    </aside>
    <main class="content">
        <?php foreach ($flashes as $flash): ?>
            <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>
