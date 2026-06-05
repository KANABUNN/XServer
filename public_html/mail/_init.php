<?php

declare(strict_types=1);

require_once __DIR__ . '/../../apps/mail_core/bootstrap.php';
require_once __DIR__ . '/../../apps/mail_core/auth.php';
require_once __DIR__ . '/../../apps/mail_core/repository.php';

function mail_app_init(): array
{
    $user = mail_auth_require_login();
    try {
        $pdo = mail_pdo('mail');
        $schema = mail_schema_status($pdo);
        if (!$schema['ready']) {
            return [$user, $pdo, 'メールDBのテーブルが不足しています: ' . implode(', ', $schema['missing'])];
        }
        return [$user, $pdo, ''];
    } catch (Throwable $e) {
        return [$user, null, 'メールDBへ接続できません: ' . $e->getMessage()];
    }
}

function mail_require_permission_or_forbid(array $user, string $permission): void
{
    if (mail_auth_has_permission($user, $permission)) {
        return;
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'この操作を行う権限がありません。';
    exit;
}

function mail_flash_set(string $type, string $message): void
{
    mail_auth_bootstrap();
    $_SESSION['mail_flash'][] = ['type' => $type, 'message' => $message];
}

function mail_flash_get(): array
{
    mail_auth_bootstrap();
    $messages = $_SESSION['mail_flash'] ?? [];
    unset($_SESSION['mail_flash']);
    return is_array($messages) ? $messages : [];
}

function mail_redirect(string $path): void
{
    header('Location: ' . mail_url($path), true, 302);
    exit;
}

function mail_nav_items(): array
{
    return [
        ['href' => 'index.php', 'label' => 'ダッシュボード'],
        ['href' => 'organizations.php', 'label' => '団体データ'],
        ['href' => 'templates.php', 'label' => 'テンプレート'],
        ['href' => 'batches.php', 'label' => '送信バッチ'],
        ['href' => 'attachments.php', 'label' => '添付ファイル'],
        ['href' => 'graph.php', 'label' => 'Graph下書き・送信'],
        ['href' => 'logs.php', 'label' => 'ログ'],
        ['href' => 'settings.php', 'label' => 'Graph設定'],
    ];
}

function mail_render_page_header(string $title, array $user, string $activeHref): void
{
    $csrfToken = mail_auth_get_csrf_token();
    ?><!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="mail-csrf-token" content="<?php echo mail_h($csrfToken); ?>">
  <title><?php echo mail_h($title); ?> | mail.fit-sc.jp</title>
  <link rel="stylesheet" href="./css/mail-admin.css">
</head>
<body>
  <div class="admin-shell">
    <aside class="sidebar">
      <div class="sidebar-brand">
        <p class="brand-kicker">mail.fit-sc.jp</p>
        <h1>メール管理</h1>
        <p>団体DB / テンプレート / 添付 / 下書き・送信</p>
      </div>
      <nav class="sidebar-nav" aria-label="管理機能">
        <?php foreach (mail_nav_items() as $item): ?>
          <a class="sidebar-link <?php echo $activeHref === $item['href'] ? 'is-active' : ''; ?>" href="<?php echo mail_h($item['href']); ?>"><?php echo mail_h($item['label']); ?></a>
        <?php endforeach; ?>
      </nav>
      <div class="sidebar-user">
        <div class="sidebar-user-card">
          <strong><?php echo mail_h((string)($user['display_name'] ?? '')); ?></strong>
          <span><?php echo mail_h((string)($user['role_label'] ?? '閲覧者')); ?> / <?php echo mail_h((string)($user['login_id'] ?? '')); ?></span>
        </div>
        <form method="post" action="logout.php" class="sidebar-logout-form">
          <?php echo mail_auth_csrf_field(); ?>
          <button type="submit" class="sidebar-logout-link">ログアウト</button>
        </form>
      </div>
    </aside>
    <main class="content-shell">
      <div class="wrap">
        <?php foreach (mail_flash_get() as $flash): ?>
          <div class="alert alert-<?php echo mail_h((string)($flash['type'] ?? 'info')); ?>"><?php echo mail_h((string)($flash['message'] ?? '')); ?></div>
        <?php endforeach; ?>
    <?php
}

function mail_render_page_footer(): void
{
    ?>
      </div>
    </main>
  </div>
  <script src="./js/mail-admin.js"></script>
</body>
</html>
    <?php
}

function mail_render_db_error(string $dbError): void
{
    if ($dbError === '') {
        return;
    }
    ?>
    <div class="alert alert-danger">
      <strong>利用準備が完了していません。</strong>
      <p><?php echo mail_h($dbError); ?></p>
      <p><code>apps/mail_core/schema.sql</code> の反映と <code>config.local.php</code> のDB設定を確認してください。</p>
    </div>
    <?php
}

function mail_selected(int|string|null $a, int|string|null $b): string
{
    return (string)$a === (string)$b ? ' selected' : '';
}

function mail_checked(bool $condition): string
{
    return $condition ? ' checked' : '';
}
