<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../../apps/mail_core/graph_client.php';
require_once __DIR__ . '/../../apps/mail_core/smtp_client.php';

[$user, $mailPdo, $dbError] = mail_app_init();
$config = [];
try {
    $config = mail_load_config();
} catch (Throwable $e) {
    $config = [];
}
$graph = is_array($config['graph'] ?? null) ? $config['graph'] : [];
$smtp = is_array($config['smtp'] ?? null) ? $config['smtp'] : [];
$deliveryDriver = mail_delivery_driver();
$storage = is_array($config['storage'] ?? null) ? $config['storage'] : [];
$security = is_array($config['security'] ?? null) ? $config['security'] : [];
[$graphReady, $graphMissing] = mail_graph_is_configured();
[$smtpReady, $smtpMissing] = mail_smtp_is_configured();

mail_render_page_header('送信設定', $user, 'settings.php');
?>
<header class="page-head">
  <div>
    <h1>送信設定</h1>
    <p class="lead">Google Workspace SMTP送信と、将来用のMicrosoft Graph連携設定を確認する画面です。設定値そのものは非公開領域の <code>config.local.php</code> で管理します。</p>
  </div>
  <div class="head-actions"><a class="link-button secondary" href="delivery.php">GW SMTP送信画面へ</a></div>
</header>
<?php mail_render_db_error($dbError); ?>
<section class="panel">
  <div class="panel-head"><h2>現在の実装方針</h2><span class="muted">Google Workspace優先</span></div>
  <p>当面の本番運用では、bookのメール送信と同様に <strong>Google Workspace / Gmail SMTP</strong> を使って直接送信します。Microsoft Graphは、将来Exchange Onlineが利用可能になった場合の予備経路として残します。</p>
  <p class="muted">現在の送信ドライバは <code><?php echo mail_h($deliveryDriver); ?></code> です。</p>
</section>
<section class="panel mt-18">
  <div class="panel-head"><h2>Google Workspace SMTP設定値</h2><span class="muted">config.local.php から読込</span></div>
  <div class="settings-grid">
    <div><span class="muted">SMTP有効</span><strong><?php echo !empty($smtp['enabled']) ? '有効' : '無効'; ?></strong></div>
    <div><span class="muted">設定状態</span><strong><?php echo $smtpReady ? '利用可能' : '不足あり'; ?></strong></div>
    <div><span class="muted">ホスト</span><code><?php echo mail_h((string)($smtp['host'] ?? '')); ?>:<?php echo (int)($smtp['port'] ?? 0); ?></code></div>
    <div><span class="muted">暗号化</span><code><?php echo mail_h((string)($smtp['secure'] ?? 'tls')); ?></code></div>
    <div><span class="muted">SMTP認証</span><strong><?php echo array_key_exists('smtp_auth', $smtp) && !$smtp['smtp_auth'] ? '無効' : '有効'; ?></strong></div>
    <div><span class="muted">ユーザー名</span><code><?php echo mail_h((string)($smtp['username'] ?? '')); ?></code></div>
    <div><span class="muted">送信元</span><code><?php echo mail_h((string)($smtp['from_address'] ?? '')); ?></code></div>
    <div><span class="muted">1回の送信上限</span><strong><?php echo (int)($smtp['max_sends_per_run'] ?? 10); ?> 件</strong></div>
  </div>
  <?php if (!$smtpReady): ?>
    <div class="alert alert-warn mt-14">不足しているSMTP設定: <code><?php echo mail_h(implode(', ', $smtpMissing)); ?></code></div>
  <?php endif; ?>
</section>
<section class="panel mt-18">
  <div class="panel-head"><h2>Graph設定値</h2><span class="muted">将来用 / config.local.php から読込</span></div>
  <div class="settings-grid">
    <div><span class="muted">Graph有効</span><strong><?php echo !empty($graph['enabled']) ? '有効' : '無効'; ?></strong></div>
    <div><span class="muted">設定状態</span><strong><?php echo $graphReady ? '利用可能' : '不足あり'; ?></strong></div>
    <div><span class="muted">認証方式</span><code><?php echo mail_h((string)($graph['auth_mode'] ?? 'client_credentials')); ?></code></div>
    <div><span class="muted">APIベース</span><code><?php echo mail_h((string)($graph['api_base'] ?? 'https://graph.microsoft.com/v1.0')); ?></code></div>
    <div><span class="muted">テナントID</span><code><?php echo mail_h((string)($graph['tenant_id'] ?? '')); ?></code></div>
    <div><span class="muted">クライアントID</span><code><?php echo mail_h((string)($graph['client_id'] ?? '')); ?></code></div>
    <div><span class="muted">送信用ユーザー</span><code><?php echo mail_h((string)($graph['sender_user_id'] ?? '')); ?></code></div>
    <div><span class="muted">1回の下書き作成上限</span><strong><?php echo (int)($graph['max_drafts_per_run'] ?? 20); ?> 件</strong></div>
    <div><span class="muted">送信UI</span><strong><?php echo !empty($graph['allow_send_from_ui']) ? '有効' : '無効'; ?></strong></div>
    <div><span class="muted">1回の送信上限</span><strong><?php echo (int)($graph['max_sends_per_run'] ?? 10); ?> 件</strong></div>
    <div><span class="muted">委任認証予約設定</span><strong><?php echo !empty($graph['delegated_enabled']) ? '有効' : '無効'; ?></strong></div>
  </div>
  <?php if (!$graphReady): ?>
    <div class="alert alert-warn mt-14">不足している設定: <code><?php echo mail_h(implode(', ', $graphMissing)); ?></code></div>
  <?php endif; ?>
</section>
<section class="panel mt-18">
  <div class="panel-head"><h2>ストレージ・アップロード</h2><span class="muted">非公開領域に保存</span></div>
  <div class="settings-grid">
    <div><span class="muted">添付保存先</span><code><?php echo mail_h((string)($storage['attachments_dir'] ?? '')); ?></code></div>
    <div><span class="muted">一時保存先</span><code><?php echo mail_h((string)($storage['tmp_dir'] ?? '')); ?></code></div>
    <div><span class="muted">最大サイズ</span><strong><?php echo number_format(((int)($security['max_upload_bytes'] ?? 0)) / 1024 / 1024, 1); ?> MB</strong></div>
    <div><span class="muted">許可拡張子</span><code><?php echo mail_h(implode(', ', array_map('strval', (array)($security['allowed_attachment_extensions'] ?? [])))); ?></code></div>
  </div>
</section>
<section class="panel mt-18 feature-panel">
  <h2>Google Workspace SMTP側で必要な設定</h2>
  <ol class="flow-list">
    <li>Google Workspaceで送信用アカウントを用意する。</li>
    <li>SMTP利用方式を決める。通常は <code>smtp.gmail.com:587 / TLS / SMTP認証</code> を使う。</li>
    <li>2段階認証とアプリパスワード、または組織で許可されたSMTP認証情報を用意する。</li>
    <li><code>config.local.php</code> の <code>mail_delivery.driver</code> を <code>smtp</code> にする。</li>
    <li><code>smtp.username</code>、<code>smtp.password</code>、<code>smtp.from_address</code> を設定する。</li>
    <li><code>delivery.php</code> でSMTP接続テスト後、まず少数件だけ送信する。</li>
  </ol>
</section>
<section class="panel mt-18 feature-panel">
  <h2>実装済み・未公開の処理</h2>
  <p>Google Workspace SMTPによる直接送信、添付ファイル送信、ログ保存を実装済みです。Graphの下書き作成・送信処理は将来用として残し、委任認証方式は後から追加できるよう設定項目のみ予約しています。</p>
</section>
<?php
mail_render_page_footer();
