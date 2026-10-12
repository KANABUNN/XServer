<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../../apps/mail_core/gmail_client.php';
require_once __DIR__ . '/../../apps/mail_core/smtp_client.php';
require_once __DIR__ . '/../../apps/mail_core/kintone_client.php';

[$user, $mailPdo, $dbError] = mail_app_init();
mail_require_permission_or_forbid($user, 'settings.manage');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        mail_auth_require_csrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'gmail_test') {
            $result = mail_gmail_test_connection();
            mail_flash_set('info', 'Gmail API接続テストに成功しました。対象: ' . ((string)($result['emailAddress'] ?? '')));
            mail_redirect('settings.php');
        }
    } catch (Throwable $e) {
        mail_flash_set('danger', mail_user_safe_error_message($e));
        mail_redirect('settings.php');
    }
}

$config = mail_load_config();
$gmail = mail_gmail_config();
[$gmailReady, $gmailMissing] = mail_gmail_is_configured();
$smtp = mail_smtp_config();
[$smtpReady, $smtpMissing] = mail_smtp_is_configured();
$deliveryDriver = mail_delivery_driver();
$storage = $config['storage'] ?? [];
$security = $config['security'] ?? [];
$kintone = mail_kintone_config();
[$kintoneReady, $kintoneMissing] = mail_kintone_is_configured();

mail_render_page_header('送信設定', $user, 'settings.php');
?>
<header class="page-head">
  <div>
    <h1>送信設定</h1>
    <p class="lead">Gmail APIによる下書き作成設定を確認する画面です。設定値そのものは非公開領域の <code>config.local.php</code> で管理します。</p>
  </div>
  <div class="head-actions"><button type="button" class="link-button secondary" data-nav-href="drafts.php">Gmail下書き画面へ</button></div>
</header>
<?php mail_render_db_error($dbError); ?>
<section class="panel">
  <div class="panel-head"><h2>現在の実装方針</h2><span class="muted">Google Workspace Gmail API</span></div>
  <p>本番運用では、Google Workspaceのサービスアカウントとドメイン全体の委任を使い、送信用アカウントのGmail下書きフォルダへメールを作成します。</p>
  <p class="muted">現在の送信ドライバは <code><?php echo mail_h($deliveryDriver); ?></code> です。</p>
  <div class="alert alert-info mt-14">SMTP Relayの直接送信機能は保守用に残しています。Microsoft Graph関連コアファイルも残していますが、通常UIからは扱いません。</div>
</section>
<section class="panel mt-18">
  <div class="panel-head"><h2>Gmail API設定値</h2><span class="muted">config.local.php から読込</span></div>
  <div class="settings-grid">
    <div><span class="muted">Gmail API</span><strong><?php echo !empty($gmail['enabled']) ? '有効' : '無効'; ?></strong></div>
    <div><span class="muted">設定状態</span><strong><?php echo $gmailReady ? '利用可能' : '不足あり'; ?></strong></div>
    <div><span class="muted">委任ユーザー</span><code><?php echo mail_h((string)($gmail['delegated_user'] ?? '')); ?></code></div>
    <div><span class="muted">From</span><code><?php echo mail_h((string)($gmail['from_address'] ?? '')); ?></code></div>
    <div><span class="muted">Reply-To</span><code><?php echo mail_h((string)($gmail['reply_to'] ?? '')); ?></code></div>
    <div><span class="muted">API</span><code><?php echo mail_h((string)($gmail['api_base'] ?? 'https://gmail.googleapis.com/gmail/v1')); ?></code></div>
    <div><span class="muted">スコープ</span><code><?php echo mail_h(implode(', ', array_map('strval', (array)($gmail['scopes'] ?? [])))); ?></code></div>
    <div><span class="muted">1回の下書き作成上限</span><strong><?php echo (int)($gmail['max_drafts_per_run'] ?? 10); ?> 件</strong><small class="muted">下書き作成画面では変更せず、この設定値を使用します。</small></div>
    <div><span class="muted">サービスアカウントJSON</span><code><?php echo mail_h((string)($gmail['service_account_json_path'] ?? 'inline-json')); ?></code></div>
  </div>
  <?php if (!$gmailReady): ?>
    <div class="alert alert-warn mt-14">不足しているGmail API設定: <code><?php echo mail_h(implode(', ', $gmailMissing)); ?></code></div>
  <?php endif; ?>
  <form method="post" class="mt-14">
    <?php echo mail_auth_csrf_field(); ?>
    <input type="hidden" name="action" value="gmail_test">
    <button type="submit" class="secondary"<?php echo $gmailReady ? '' : ' disabled'; ?>>Gmail API接続テスト</button>
  </form>
</section>
<section class="panel mt-18">
  <div class="panel-head"><h2>SMTP Relay設定値</h2><span class="muted">保守・暫定直接送信用</span></div>
  <div class="settings-grid">
    <div><span class="muted">SMTP有効</span><strong><?php echo !empty($smtp['enabled']) ? '有効' : '無効'; ?></strong></div>
    <div><span class="muted">設定状態</span><strong><?php echo $smtpReady ? '利用可能' : '不足あり'; ?></strong></div>
    <div><span class="muted">ホスト</span><code><?php echo mail_h((string)($smtp['host'] ?? '')); ?>:<?php echo (int)($smtp['port'] ?? 0); ?></code></div>
    <div><span class="muted">送信元</span><code><?php echo mail_h((string)($smtp['from_address'] ?? '')); ?></code></div>
  </div>
  <?php if (!$smtpReady): ?>
    <div class="alert alert-warn mt-14">不足しているSMTP設定: <code><?php echo mail_h(implode(', ', $smtpMissing)); ?></code></div>
  <?php endif; ?>
</section>
<section class="panel mt-18">
  <div class="panel-head"><h2>kintone同期設定</h2><span class="muted">団体データ連携</span></div>
  <div class="settings-grid">
    <div><span class="muted">kintone同期</span><strong><?php echo mail_kintone_enabled() ? '有効' : '無効'; ?></strong></div>
    <div><span class="muted">設定状態</span><strong><?php echo $kintoneReady ? '利用可能' : '不足あり'; ?></strong></div>
    <div><span class="muted">接続先</span><code><?php echo mail_h((string)($kintone['base_url'] ?? $kintone['subdomain'] ?? '')); ?></code></div>
    <div><span class="muted">団体管理アプリID</span><strong><?php echo (int)($kintone['organization_app_id'] ?? 0); ?></strong></div>
    <div><span class="muted">代表者管理アプリID</span><strong><?php echo (int)($kintone['representative_app_id'] ?? 0); ?></strong></div>
    <div><span class="muted">団体取得条件</span><code><?php echo mail_h((string)($kintone['organization_query'] ?? 'status in ("活動中") order by id asc')); ?></code></div>
  </div>
  <?php if (!$kintoneReady): ?>
    <div class="alert alert-warn mt-14">不足しているkintone設定: <code><?php echo mail_h(implode(', ', $kintoneMissing)); ?></code></div>
  <?php endif; ?>
  <div class="mt-14">
    <button type="button" class="link-button secondary" data-nav-href="organizations.php">団体データ画面へ</button>
  </div>
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
  <h2>Google Workspace側で必要な設定</h2>
  <ol class="flow-list">
    <li>Google CloudでGmail APIを有効化する。</li>
    <li>サービスアカウントを作成し、ドメイン全体の委任を有効化する。</li>
    <li>Google管理コンソールで、サービスアカウントのクライアントIDに <code>https://www.googleapis.com/auth/gmail.compose</code> を許可する。</li>
    <li>サービスアカウントJSONを <code>public_html</code> 外の非公開領域に配置する。</li>
    <li><code>config.local.php</code> の <code>mail_delivery.driver</code> を <code>gmail_draft</code> にする。</li>
    <li><code>送信設定</code> でGmail API接続確認後、まず1件だけ下書き作成を試す。</li>
  </ol>
</section>
<?php
mail_render_page_footer();
