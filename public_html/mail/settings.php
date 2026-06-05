<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../../apps/mail_core/smtp_client.php';

[$user, $mailPdo, $dbError] = mail_app_init();
$config = mail_load_config();
$smtp = mail_smtp_config();
[$smtpReady, $smtpMissing] = mail_smtp_is_configured();
$deliveryDriver = mail_delivery_driver();
$storage = $config['storage'] ?? [];
$security = $config['security'] ?? [];

mail_render_page_header('送信設定', $user, 'settings.php');
?>
<header class="page-head">
  <div>
    <h1>送信設定</h1>
    <p class="lead">Google Workspace SMTP Relayによる送信設定を確認する画面です。設定値そのものは非公開領域の <code>config.local.php</code> で管理します。</p>
  </div>
  <div class="head-actions"><a class="link-button secondary" href="delivery.php">SMTP送信画面へ</a></div>
</header>
<?php mail_render_db_error($dbError); ?>
<section class="panel">
  <div class="panel-head"><h2>現在の実装方針</h2><span class="muted">Google Workspace SMTP Relay</span></div>
  <p>当面の本番運用では、bookのメール送信と同様に <strong>Google Workspace SMTP Relay</strong> を使って送信します。</p>
  <p class="muted">現在の送信ドライバは <code><?php echo mail_h($deliveryDriver); ?></code> です。</p>
  <div class="alert alert-info mt-14">過去のMicrosoft 365連携用コアファイルは保守用に残していますが、当面の管理画面ではGoogle Workspace SMTP Relayのみを扱います。</div>
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
    <div><span class="muted">Reply-To</span><code><?php echo mail_h((string)($smtp['reply_to'] ?? '')); ?></code></div>
    <div><span class="muted">1回の送信上限</span><strong><?php echo (int)($smtp['max_sends_per_run'] ?? 10); ?> 件</strong></div>
    <div><span class="muted">送信間隔</span><strong><?php echo (int)($smtp['per_message_delay_seconds'] ?? 0); ?> 秒</strong></div>
  </div>
  <?php if (!$smtpReady): ?>
    <div class="alert alert-warn mt-14">不足しているSMTP設定: <code><?php echo mail_h(implode(', ', $smtpMissing)); ?></code></div>
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
  <h2>Google Workspace SMTP Relay側で必要な設定</h2>
  <ol class="flow-list">
    <li>Google Workspaceで送信元に使う <code>fit-sc.jp</code> 配下のアドレスを決める。</li>
    <li>Google管理コンソールのGmailルーティングでSMTPリレーサービスを構成する。</li>
    <li>XServerの送信元IP、または組織で許可されたSMTP認証方式を設定する。</li>
    <li><code>config.local.php</code> の <code>mail_delivery.driver</code> を <code>smtp</code> にする。</li>
    <li><code>smtp.host</code>、<code>smtp.port</code>、<code>smtp.from_address</code> を設定する。</li>
    <li><code>delivery.php</code> でSMTP接続テスト後、まず少数件だけ送信する。</li>
  </ol>
</section>
<section class="panel mt-18 feature-panel">
  <h2>Gmail下書き保存について</h2>
  <p>SMTP Relayはメールを配送する仕組みであり、Gmailの下書きフォルダへ保存する機能はありません。Gmail下書き保存を行う場合は、別途Gmail APIの下書き作成機能を追加する必要があります。</p>
</section>
<?php
mail_render_page_footer();
