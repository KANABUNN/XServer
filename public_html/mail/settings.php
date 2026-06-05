<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../../apps/mail_core/graph_client.php';

[$user, $mailPdo, $dbError] = mail_app_init();
$config = [];
try {
    $config = mail_load_config();
} catch (Throwable $e) {
    $config = [];
}
$graph = is_array($config['graph'] ?? null) ? $config['graph'] : [];
$storage = is_array($config['storage'] ?? null) ? $config['storage'] : [];
$security = is_array($config['security'] ?? null) ? $config['security'] : [];
[$graphReady, $graphMissing] = mail_graph_is_configured();

mail_render_page_header('Graph設定', $user, 'settings.php');
?>
<header class="page-head">
  <div>
    <h1>Graph設定</h1>
    <p class="lead">Microsoft Graph APIでOutlook下書きを作成するための設定確認画面です。設定値そのものは非公開領域の <code>config.local.php</code> で管理します。</p>
  </div>
  <div class="head-actions"><a class="link-button secondary" href="graph.php">Graph下書き画面へ</a></div>
</header>
<?php mail_render_db_error($dbError); ?>
<section class="panel">
  <div class="panel-head"><h2>現在の実装方針</h2><span class="muted">安全側の初期設計</span></div>
  <p>初期運用では、Webアプリから直接送信せず、Graph APIで <strong>Outlook下書き作成</strong> までを行います。下書きを人間が確認してから送信することで、宛先・本文・添付の誤りを抑えます。</p>
  <p class="muted">認証方式は <code>client_credentials</code> です。送信用メールボックスを固定し、Microsoft Entra ID のアプリケーション権限で下書きを作成します。</p>
</section>
<section class="panel mt-18">
  <div class="panel-head"><h2>Graph設定値</h2><span class="muted">config.local.php から読込</span></div>
  <div class="settings-grid">
    <div><span class="muted">Graph有効</span><strong><?php echo !empty($graph['enabled']) ? '有効' : '無効'; ?></strong></div>
    <div><span class="muted">設定状態</span><strong><?php echo $graphReady ? '利用可能' : '不足あり'; ?></strong></div>
    <div><span class="muted">認証方式</span><code><?php echo mail_h((string)($graph['auth_mode'] ?? 'client_credentials')); ?></code></div>
    <div><span class="muted">APIベース</span><code><?php echo mail_h((string)($graph['api_base'] ?? 'https://graph.microsoft.com/v1.0')); ?></code></div>
    <div><span class="muted">テナントID</span><code><?php echo mail_h((string)($graph['tenant_id'] ?? '')); ?></code></div>
    <div><span class="muted">クライアントID</span><code><?php echo mail_h((string)($graph['client_id'] ?? '')); ?></code></div>
    <div><span class="muted">送信用ユーザー</span><code><?php echo mail_h((string)($graph['sender_user_id'] ?? '')); ?></code></div>
    <div><span class="muted">1回の下書き作成上限</span><strong><?php echo (int)($graph['max_drafts_per_run'] ?? 20); ?> 件</strong></div>
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
  <h2>Microsoft Entra ID 側で必要な設定</h2>
  <ol class="flow-list">
    <li>Microsoft Entra ID でアプリ登録を作成する。</li>
    <li>クライアントシークレットを作成し、<code>config.local.php</code> に設定する。</li>
    <li>Microsoft Graph のアプリケーション権限として <code>Mail.ReadWrite</code> を付与する。</li>
    <li>既存下書きを送信する段階まで進める場合は、追加で <code>Mail.Send</code> を付与する。</li>
    <li>管理者の同意を実行する。</li>
    <li>可能であれば、Exchange Online 側でアプリがアクセスできるメールボックスを送信用アカウントに限定する。</li>
  </ol>
</section>
<section class="panel mt-18 feature-panel">
  <h2>実装済み・未公開の処理</h2>
  <p>下書き作成、通常添付、大容量添付、ログ保存は実装済みです。既存下書きの送信処理も関数としては用意していますが、送信関係のUIは今後の要件に合わせるため、現時点では画面に表示していません。</p>
</section>
<?php
mail_render_page_footer();
