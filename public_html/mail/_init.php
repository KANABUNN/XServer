<?php

declare(strict_types=1);

require_once __DIR__ . '/../../apps/mail_core/bootstrap.php';
require_once __DIR__ . '/../../apps/mail_core/auth.php';
require_once __DIR__ . '/../../apps/mail_core/repository.php';

function mail_user_safe_error_message(Throwable $e, string $context = 'mail'): string
{
    // 利用者向けに意図して投げる検証エラーはそのまま表示
    if ($e instanceof InvalidArgumentException) {
        return $e->getMessage();
    }
    // PDOException は RuntimeException 派生のため先に判定し、DB内部情報を隠す
    if ($e instanceof PDOException || $e instanceof Error) {
        error_log('[mail ' . $context . '] ' . (string)$e);
        return 'サーバー側でエラーが発生しました。時間をおいて再試行してください。';
    }
    // アプリが意図して投げる業務例外（確認文言・SMTP結果など）は表示
    if ($e instanceof RuntimeException) {
        return $e->getMessage();
    }
    error_log('[mail ' . $context . '] ' . (string)$e);
    return 'サーバー側でエラーが発生しました。時間をおいて再試行してください。';
}

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
        error_log('[mail app_init] ' . (string)$e);
        return [$user, null, 'メールDBへ接続できません。時間をおいて再試行するか、管理者に連絡してください。'];
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
    $url = mail_url($path);
    if (!headers_sent()) {
        header('Location: ' . $url, true, 302);
        exit;
    }

    // 予期しないデバッグ出力などで既に本文が出ている場合でも、
    // PHP Warningを出さずに管理画面へ戻すためのフォールバック。
    $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<script>window.location.replace(' . json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ');</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . $safeUrl . '"></noscript>';
    echo '<p><a href="' . $safeUrl . '">処理後の画面へ移動</a></p>';
    exit;
}

function mail_nav_items(array $user = []): array
{
    $items = [
        ['href' => 'index.php', 'label' => 'ダッシュボード'],
        ['href' => 'compose.php', 'label' => 'メール作成'],
        ['href' => 'batches.php', 'label' => '送信バッチ'],
        ['href' => 'drafts.php', 'label' => 'Gmail下書き'],
        ['href' => 'attachments.php', 'label' => '添付ファイル'],
        ['href' => 'templates.php', 'label' => 'テンプレート'],
        ['href' => 'organizations.php', 'label' => '団体データ'],
        ['href' => 'send_logs.php', 'label' => '送信ログ'],
    ];

    if ($user !== [] && mail_auth_has_permission($user, 'settings.manage')) {
        $items[] = ['href' => 'settings.php', 'label' => '送信設定'];
        $items[] = ['href' => 'logs.php', 'label' => '操作ログ'];
    }

    return $items;
}


function mail_admin_html_looks_like_markup(string $value): bool
{
    return preg_match('/<\s*(p|div|br|span|strong|b|em|i|u|ul|ol|li|table|thead|tbody|tr|td|th|h[1-6]|blockquote|a|img|hr)\b|<\s*\/\s*(p|div|span|strong|b|em|i|u|ul|ol|li|table|thead|tbody|tr|td|th|h[1-6]|blockquote|a)\s*>/i', $value) === 1;
}

function mail_admin_plain_text_to_html(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $paragraphs = preg_split('/\n{2,}/', $text) ?: [];
    $html = [];
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph, "\n");
        if ($paragraph === '') {
            continue;
        }
        $escapedLines = array_map(static function (string $line): string {
            return mail_h($line);
        }, explode("\n", $paragraph));
        $html[] = '<p>' . implode('<br>', $escapedLines) . '</p>';
    }
    return implode("\n", $html);
}

function mail_admin_body_to_editor_html(string $body, ?string $bodyType = null): string
{
    $body = trim($body);
    if ($body === '') {
        return '';
    }
    if (mail_admin_html_looks_like_markup($body)) {
        return $body;
    }
    return mail_admin_plain_text_to_html($body);
}


function mail_admin_sanitize_editor_html(string $html): string
{
    $html = preg_replace('/<\s*(script|style|iframe|object|embed|form|input|button|meta|link)\b[^>]*>[\s\S]*?<\s*\/\s*\1\s*>/i', '', $html) ?? $html;
    $html = preg_replace('/<\s*(script|style|iframe|object|embed|form|input|button|meta|link)\b[^>]*\/?>/i', '', $html) ?? $html;
    $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
    $html = preg_replace('/javascript\s*:/i', '', $html) ?? $html;
    return $html;
}

function mail_render_page_header(string $title, array $user, string $activeHref): void
{
    mail_security_headers();
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
        <p>団体DB / テンプレート / 添付 / Gmail下書き</p>
      </div>
      <nav class="sidebar-nav" aria-label="管理機能">
        <?php foreach (mail_nav_items($user) as $item): ?>
          <button type="button" class="sidebar-link <?php echo $activeHref === $item['href'] ? 'is-active' : ''; ?>" data-sidebar-href="<?php echo mail_h($item['href']); ?>" draggable="false"><?php echo mail_h($item['label']); ?></button>
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
