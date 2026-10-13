<?php

declare(strict_types=1);

require_once __DIR__ . '/../../apps/mail_core/bootstrap.php';
require_once __DIR__ . '/../../apps/mail_core/auth.php';
require_once __DIR__ . '/../../apps/mail_core/repository.php';
require_once __DIR__ . '/../../apps/ui_kit.php';

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
        ['href' => 'index.php', 'label' => 'ダッシュボード', 'icon' => 'dashboard', 'section' => ''],
        ['href' => 'compose.php', 'label' => 'メール作成', 'icon' => 'pen', 'section' => '作成と送信'],
        ['href' => 'batches.php', 'label' => '送信バッチ', 'icon' => 'layers', 'section' => '作成と送信'],
        ['href' => 'attachments.php', 'label' => '添付ファイル', 'icon' => 'paperclip', 'section' => '作成と送信'],
        ['href' => 'drafts.php', 'label' => 'Gmail下書き', 'icon' => 'inbox', 'section' => '作成と送信'],
        ['href' => 'templates.php', 'label' => 'テンプレート', 'icon' => 'template', 'section' => 'データ'],
        ['href' => 'organizations.php', 'label' => '団体データ', 'icon' => 'building', 'section' => 'データ'],
        ['href' => 'send_logs.php', 'label' => '送信ログ', 'icon' => 'activity', 'section' => '記録・設定'],
    ];

    if ($user !== [] && mail_auth_has_permission($user, 'settings.manage')) {
        $items[] = ['href' => 'logs.php', 'label' => '操作ログ', 'icon' => 'history', 'section' => '記録・設定'];
        $items[] = ['href' => 'settings.php', 'label' => '送信設定', 'icon' => 'settings', 'section' => '記録・設定'];
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
    return mail_purify_html($html);
}

/**
 * 管理画面の共通ヘッダー（サイドバー＋トップバー）。
 *
 * @param array{subtitle?: string, actions?: string} $opts actions はエスケープ済み HTML
 */
function mail_render_page_header(string $title, array $user, string $activeHref, array $opts = []): void
{
    mail_security_headers();
    $csrfToken = mail_auth_get_csrf_token();
    $displayName = (string)($user['display_name'] ?? '');
    $subtitle = (string)($opts['subtitle'] ?? '');
    $actions = (string)($opts['actions'] ?? '');
    ?><!DOCTYPE html>
<html lang="ja" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="mail-csrf-token" content="<?php echo mail_h($csrfToken); ?>">
  <title><?php echo mail_h($title); ?> | メール管理</title>
  <?php echo fitsc_ui_head(['css' => ['./css/app.css'], 'guard' => './assets/common/context-menu-guard.js']); ?>
</head>
<body>
  <div class="app-shell ml-shell">
    <aside class="sidebar" id="app-sidebar" aria-label="メニュー">
      <a class="sidebar__brand" href="index.php">
        <span class="brand-mark"><?php echo fitsc_icon('mail'); ?></span>
        <span class="brand-text"><strong>メール管理</strong><span>FIT-SC Mail</span></span>
      </a>
      <nav class="sidebar__nav" aria-label="管理機能">
        <?php $currentSection = null; ?>
        <?php foreach (mail_nav_items($user) as $item): ?>
          <?php if ($item['section'] !== '' && $item['section'] !== $currentSection): $currentSection = $item['section']; ?>
            <div class="nav-section"><?php echo mail_h($item['section']); ?></div>
          <?php endif; ?>
          <?php $active = $activeHref === $item['href']; ?>
          <a class="nav-item<?php echo $active ? ' is-active' : ''; ?>" href="<?php echo mail_h($item['href']); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?> data-tip="<?php echo mail_h($item['label']); ?>" data-tip-pos="right">
            <?php echo fitsc_icon($item['icon']); ?><span class="nav-item__label"><?php echo mail_h($item['label']); ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
      <div class="sidebar__footer">
        <button type="button" class="nav-item sidebar__collapse" data-sidebar-collapse data-tip="サイドバーを開閉" data-tip-pos="right"><?php echo fitsc_icon('chevrons-left'); ?><span class="nav-item__label">折りたたむ</span></button>
        <div class="user-chip">
          <span class="avatar"><?php echo mail_h(fitsc_initials($displayName)); ?></span>
          <span class="user-chip__text"><strong><?php echo mail_h($displayName); ?></strong><span><?php echo mail_h((string)($user['role_label'] ?? '閲覧者')); ?> / <?php echo mail_h((string)($user['login_id'] ?? '')); ?></span></span>
        </div>
        <form method="post" action="logout.php">
          <?php echo mail_auth_csrf_field(); ?>
          <button type="submit" class="nav-item" data-tip="ログアウト" data-tip-pos="right"><?php echo fitsc_icon('log-out'); ?><span class="nav-item__label">ログアウト</span></button>
        </form>
      </div>
    </aside>
    <div class="sidebar-backdrop" data-sidebar-close></div>
    <div class="app-main">
      <header class="topbar">
        <button type="button" class="btn btn--ghost btn--icon topbar__menu" data-sidebar-toggle aria-label="メニュー"><?php echo fitsc_icon('menu'); ?></button>
        <div class="topbar__title">
          <h1><?php echo mail_h($title); ?></h1>
          <?php if ($subtitle !== ''): ?><p><?php echo mail_h($subtitle); ?></p><?php endif; ?>
        </div>
        <div class="topbar__actions">
          <?php echo $actions; ?>
          <?php echo fitsc_theme_toggle(); ?>
        </div>
      </header>
      <main class="page">
        <?php foreach (mail_flash_get() as $flash): ?>
          <?php echo fitsc_flash_html((string)($flash['type'] ?? 'info'), (string)($flash['message'] ?? '')); ?>
        <?php endforeach; ?>
    <?php
}

function mail_render_page_footer(): void
{
    ?>
      </main>
    </div>
  </div>
  <script src="<?php echo mail_h(fitsc_ui_versioned('./js/mail-admin.js')); ?>"></script>
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
    <div class="alert alert--danger" role="alert">
      <?php echo fitsc_icon('database'); ?>
      <div class="alert__body">
        <strong class="alert__title">メールデータベースに接続できません</strong>
        <span><?php echo mail_h($dbError); ?></span>
        <span class="text-subtle text-xs">管理者へ連絡してください（schema.sql の反映・config.local.php の DB 設定を確認）。</span>
      </div>
    </div>
    <?php
}

/**
 * 状態コードを色付きバッジで表示する（ラベルは mail_status_label）。
 */
function mail_status_badge(string $status): string
{
    $tone = [
        'draft' => '',
        'prepared' => 'info',
        'reviewing' => 'accent',
        'approved' => 'success',
        'draft_created' => 'teal',
        'sent' => 'success',
        'cancelled' => '',
        'waiting' => 'info',
        'ready' => 'info',
        'failed' => 'danger',
        'excluded' => '',
        'uploaded' => 'info',
        'matched' => 'success',
        'needs_review' => 'warning',
        'unmatched' => 'warning',
        'used' => '',
        'archived' => '',
        'success' => 'success',
    ][$status] ?? '';
    return '<span class="badge badge--dot' . ($tone !== '' ? ' badge--' . $tone : '') . '" title="' . mail_h($status) . '">' . mail_h(mail_status_label($status)) . '</span>';
}

function mail_active_badge(int|string|null $value): string
{
    return (int)$value === 1
        ? '<span class="badge badge--success badge--dot">有効</span>'
        : '<span class="badge badge--dot">無効</span>';
}

function mail_selected(int|string|null $a, int|string|null $b): string
{
    return (string)$a === (string)$b ? ' selected' : '';
}

function mail_checked(bool $condition): string
{
    return $condition ? ' checked' : '';
}
