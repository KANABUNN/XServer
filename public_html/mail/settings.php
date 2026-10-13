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
mail_render_page_header('送信設定', $user, 'settings.php', [
    'subtitle' => '設定値は非公開領域の config.local.php で管理しています（この画面は確認用）',
    'actions' => '<a class="btn btn--ghost" href="drafts.php">' . fitsc_icon('inbox') . '<span class="hide-mobile">Gmail下書きへ</span></a>',
]);
$readyBadge = static function (bool $ready): string {
    return $ready ? '<span class="badge badge--success badge--dot">利用可能</span>' : '<span class="badge badge--warning badge--dot">不足あり</span>';
};
$onOff = static function ($value): string {
    return !empty($value) ? '<span class="badge badge--success">有効</span>' : '<span class="badge">無効</span>';
};
?>
<?php mail_render_db_error($dbError); ?>

<div class="alert alert--info">
  <?php echo fitsc_icon('send'); ?>
  <div class="alert__body">現在の送信方式は <code><?php echo mail_h($deliveryDriver); ?></code> です。通常は Google Workspace の Gmail API でサービスアカウントから下書きを作成します。</div>
</div>

<div class="grid grid--2 ml-settings">
  <section class="card">
    <div class="card__header">
      <div class="card__title"><?php echo fitsc_icon('inbox'); ?>Gmail API</div>
      <?php echo $readyBadge($gmailReady); ?>
    </div>
    <div class="card__body stack">
      <dl class="dl">
        <div><dt>Gmail API</dt><dd><?php echo $onOff($gmail['enabled'] ?? false); ?></dd></div>
        <div><dt>委任ユーザー</dt><dd><code><?php echo mail_h((string)($gmail['delegated_user'] ?? '')); ?></code></dd></div>
        <div><dt>From</dt><dd><code><?php echo mail_h((string)($gmail['from_address'] ?? '')); ?></code></dd></div>
        <div><dt>Reply-To</dt><dd><code><?php echo mail_h((string)($gmail['reply_to'] ?? '')); ?></code></dd></div>
        <div><dt>API</dt><dd><code><?php echo mail_h((string)($gmail['api_base'] ?? 'https://gmail.googleapis.com/gmail/v1')); ?></code></dd></div>
        <div><dt>スコープ</dt><dd><code><?php echo mail_h(implode(', ', array_map('strval', (array)($gmail['scopes'] ?? [])))); ?></code></dd></div>
        <div><dt>1回の作成上限</dt><dd><?php echo (int)($gmail['max_drafts_per_run'] ?? 10); ?> 件</dd></div>
        <div><dt>認証情報</dt><dd><code><?php echo mail_h((string)($gmail['service_account_json_path'] ?? 'inline-json')); ?></code></dd></div>
      </dl>
      <?php if (!$gmailReady): ?>
        <div class="alert alert--warning"><?php echo fitsc_icon('alert-triangle'); ?><div class="alert__body">不足している設定：<code><?php echo mail_h(implode(', ', $gmailMissing)); ?></code></div></div>
      <?php endif; ?>
      <form method="post" data-busy>
        <?php echo mail_auth_csrf_field(); ?>
        <input type="hidden" name="action" value="gmail_test">
        <button type="submit" class="btn"<?php echo $gmailReady ? '' : ' disabled'; ?>><?php echo fitsc_icon('zap'); ?>接続テスト</button>
      </form>
    </div>
  </section>

  <div class="stack stack--lg">
    <section class="card">
      <div class="card__header">
        <div class="card__title"><?php echo fitsc_icon('server'); ?>SMTP Relay <small>保守用</small></div>
        <?php echo $readyBadge($smtpReady); ?>
      </div>
      <div class="card__body stack">
        <dl class="dl">
          <div><dt>SMTP</dt><dd><?php echo $onOff($smtp['enabled'] ?? false); ?></dd></div>
          <div><dt>ホスト</dt><dd><code><?php echo mail_h((string)($smtp['host'] ?? '')); ?>:<?php echo (int)($smtp['port'] ?? 0); ?></code></dd></div>
          <div><dt>送信元</dt><dd><code><?php echo mail_h((string)($smtp['from_address'] ?? '')); ?></code></dd></div>
        </dl>
        <?php if (!$smtpReady): ?>
          <div class="alert alert--warning"><?php echo fitsc_icon('alert-triangle'); ?><div class="alert__body">不足している設定：<code><?php echo mail_h(implode(', ', $smtpMissing)); ?></code></div></div>
        <?php endif; ?>
      </div>
    </section>

    <section class="card">
      <div class="card__header">
        <div class="card__title"><?php echo fitsc_icon('cloud'); ?>kintone 連携</div>
        <?php echo $readyBadge($kintoneReady); ?>
      </div>
      <div class="card__body stack">
        <dl class="dl">
          <div><dt>同期</dt><dd><?php echo $onOff(mail_kintone_enabled()); ?></dd></div>
          <div><dt>接続先</dt><dd><code><?php echo mail_h((string)($kintone['base_url'] ?? $kintone['subdomain'] ?? '')); ?></code></dd></div>
          <div><dt>団体アプリID</dt><dd><?php echo (int)($kintone['organization_app_id'] ?? 0); ?></dd></div>
          <div><dt>代表者アプリID</dt><dd><?php echo (int)($kintone['representative_app_id'] ?? 0); ?></dd></div>
          <div><dt>取得条件</dt><dd><code><?php echo mail_h((string)($kintone['organization_query'] ?? 'status in ("活動中") order by id asc')); ?></code></dd></div>
        </dl>
        <?php if (!$kintoneReady): ?>
          <div class="alert alert--warning"><?php echo fitsc_icon('alert-triangle'); ?><div class="alert__body">不足している設定：<code><?php echo mail_h(implode(', ', $kintoneMissing)); ?></code></div></div>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <section class="card">
    <div class="card__header"><div class="card__title"><?php echo fitsc_icon('hard-drive'); ?>ストレージ・アップロード</div></div>
    <div class="card__body">
      <dl class="dl">
        <div><dt>添付の保存先</dt><dd><code><?php echo mail_h((string)($storage['attachments_dir'] ?? '')); ?></code></dd></div>
        <div><dt>一時保存先</dt><dd><code><?php echo mail_h((string)($storage['tmp_dir'] ?? '')); ?></code></dd></div>
        <div><dt>最大サイズ</dt><dd><?php echo number_format(((int)($security['max_upload_bytes'] ?? 0)) / 1024 / 1024, 1); ?> MB</dd></div>
        <div><dt>許可する拡張子</dt><dd><code><?php echo mail_h(implode(', ', array_map('strval', (array)($security['allowed_attachment_extensions'] ?? [])))); ?></code></dd></div>
      </dl>
    </div>
  </section>

  <section class="card">
    <div class="card__header"><div class="card__title"><?php echo fitsc_icon('book'); ?>Google Workspace 側の準備</div></div>
    <div class="card__body">
      <ol class="ml-steps">
        <li>Google Cloud で Gmail API を有効化</li>
        <li>サービスアカウントを作成し、ドメイン全体の委任を有効化</li>
        <li>管理コンソールでクライアントIDに <code>https://www.googleapis.com/auth/gmail.compose</code> を許可</li>
        <li>サービスアカウントの JSON を <code>public_html</code> 外の非公開領域に配置</li>
        <li><code>config.local.php</code> の <code>mail_delivery.driver</code> を <code>gmail_draft</code> に設定</li>
        <li>この画面で接続テスト後、まず 1 件だけ下書きを作成して確認</li>
      </ol>
    </div>
  </section>
</div>
<?php
mail_render_page_footer();
