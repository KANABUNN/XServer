<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
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
mail_render_page_header('Graph連携', $user, 'settings.php');
?>
<header class="page-head">
  <div>
    <h1>Graph連携</h1>
    <p class="lead">Microsoft Graph APIでOutlook下書きを作成するための設定確認画面です。現段階では送信処理は未実装です。</p>
  </div>
</header>
<?php mail_render_db_error($dbError); ?>
<section class="panel">
  <div class="panel-head"><h2>現在の方針</h2><span class="muted">安全側の初期設計</span></div>
  <p>初期運用では、Webアプリから直接送信せず、Graph APIで <strong>Outlook下書き作成</strong> までを行う構成を推奨します。下書きを人間が確認してから送信することで、宛先・本文・添付の誤りを抑えられます。</p>
</section>
<section class="panel mt-18">
  <div class="panel-head"><h2>設定値</h2><span class="muted">config.local.php から読込</span></div>
  <div class="settings-grid">
    <div><span class="muted">Graph有効</span><strong><?php echo !empty($graph['enabled']) ? '有効' : '無効'; ?></strong></div>
    <div><span class="muted">テナントID</span><code><?php echo mail_h((string)($graph['tenant_id'] ?? '')); ?></code></div>
    <div><span class="muted">クライアントID</span><code><?php echo mail_h((string)($graph['client_id'] ?? '')); ?></code></div>
    <div><span class="muted">送信用ユーザー</span><code><?php echo mail_h((string)($graph['sender_user_id'] ?? '')); ?></code></div>
    <div><span class="muted">下書き既定</span><strong><?php echo !empty($graph['draft_only_default']) ? '下書き作成' : '即時送信許可'; ?></strong></div>
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
<section class="panel mt-18">
  <h2>次に追加する処理</h2>
  <ol class="flow-list">
    <li>Graph OAuth / アプリケーション権限のトークン取得処理</li>
    <li>承認済みバッチの未送信ターゲット抽出</li>
    <li>Outlook下書き作成</li>
    <li>3MB以上の添付ファイル用アップロードセッション</li>
    <li>GraphメッセージIDとエラー内容の保存</li>
  </ol>
</section>
<?php
mail_render_page_footer();
