<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../../apps/mail_core/smtp_client.php';

[$user, $mailPdo, $dbError] = mail_app_init();
$selectedBatchId = (int)($_GET['batch_id'] ?? $_POST['batch_id'] ?? 0);
$smtp = mail_smtp_config();
[$smtpReady, $smtpMissing] = mail_smtp_is_configured();
$maxSendsPerRun = mail_smtp_max_sends_per_run();

if ($mailPdo instanceof PDO && $dbError === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    mail_auth_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'smtp_test') {
            mail_require_permission_or_forbid($user, 'settings.manage');
            $result = mail_smtp_test_connection();
            mail_flash_set('info', 'SMTP接続に成功しました。ホスト: ' . $result['host'] . ':' . $result['port']);
            mail_redirect('delivery.php' . ($selectedBatchId > 0 ? '?batch_id=' . $selectedBatchId : ''));
        }

        if ($action === 'send_test') {
            mail_require_permission_or_forbid($user, 'send.execute');
            $targetId = (int)($_POST['target_id'] ?? 0);
            $testEmail = trim((string)($_POST['test_email'] ?? ''));
            $result = mail_smtp_send_test($mailPdo, $targetId, $testEmail, $user);
            mail_flash_set('info', 'テスト送信を実行しました。送信先: ' . $result['test_to'] . ' / 添付: ' . $result['attached_count'] . '件');
            mail_redirect('delivery.php?batch_id=' . $selectedBatchId);
        }

        if ($action === 'send_batch_smtp') {
            mail_require_permission_or_forbid($user, 'send.execute');
            if ((string)($_POST['confirm_text'] ?? '') !== '送信') {
                throw new RuntimeException('一括送信を実行するには、確認欄に「送信」と入力してください。');
            }
            $limit = max(1, min(100, (int)($_POST['limit'] ?? $maxSendsPerRun)));
            $result = mail_smtp_send_batch($mailPdo, $selectedBatchId, $user, $limit);
            mail_flash_set('info', 'SMTP送信を実行しました。成功: ' . $result['success'] . '件 / 失敗: ' . $result['failed'] . '件 / 残り: ' . $result['remaining'] . '件');
            mail_redirect('delivery.php?batch_id=' . $selectedBatchId);
        }

        if ($action === 'send_target_smtp') {
            mail_require_permission_or_forbid($user, 'send.execute');
            if ((string)($_POST['confirm_text'] ?? '') !== '送信') {
                throw new RuntimeException('送信を実行するには、確認欄に「送信」と入力してください。');
            }
            $targetId = (int)($_POST['target_id'] ?? 0);
            mail_smtp_send_target($mailPdo, $targetId, $user);
            mail_flash_set('info', 'SMTP送信を実行しました。対象ID: ' . $targetId);
            mail_redirect('delivery.php?batch_id=' . $selectedBatchId);
        }
    } catch (Throwable $e) {
        mail_flash_set('danger', $e->getMessage());
        mail_redirect('delivery.php' . ($selectedBatchId > 0 ? '?batch_id=' . $selectedBatchId : ''));
    }
}

$batches = [];
$selectedBatch = null;
$targets = [];
$attachments = [];
$pendingAttachmentCount = 0;
$sendableCount = 0;
$sentCount = 0;

if ($mailPdo instanceof PDO && $dbError === '') {
    $batches = mail_list_batches($mailPdo, 150);
    if ($selectedBatchId <= 0) {
        foreach ($batches as $batch) {
            if (in_array((string)$batch['status'], ['approved', 'draft_created'], true)) {
                $selectedBatchId = (int)$batch['id'];
                break;
            }
        }
        if ($selectedBatchId <= 0 && $batches !== []) {
            $selectedBatchId = (int)$batches[0]['id'];
        }
    }
    if ($selectedBatchId > 0) {
        $selectedBatch = mail_get_batch($mailPdo, $selectedBatchId);
        if ($selectedBatch) {
            $targets = mail_list_batch_targets($mailPdo, $selectedBatchId, 1000);
            $attachments = mail_list_batch_attachments($mailPdo, $selectedBatchId);
            $pendingAttachmentCount = mail_smtp_pending_attachment_count($mailPdo, $selectedBatchId);
            $sendableCount = mail_smtp_sendable_target_count($mailPdo, $selectedBatchId);
            $sentCount = mail_smtp_sent_target_count($mailPdo, $selectedBatchId);
        }
    }
}

mail_render_page_header('Google Workspace SMTP送信', $user, 'delivery.php');
?>
<header class="page-head">
  <div>
    <h1>Google Workspace SMTP送信</h1>
    <p class="lead">承認済みバッチをGoogle Workspace / Gmail SMTP経由で送信します。bookのメール送信と同様、PHPMailerでSMTP認証を行います。</p>
  </div>
  <div class="head-actions"><a class="link-button secondary" href="settings.php">送信設定を確認</a></div>
</header>
<?php mail_render_db_error($dbError); ?>

<?php if ($dbError === ''): ?>
<div class="two-column-grid wide-left">
  <section class="panel">
    <div class="panel-head"><h2>SMTP接続状態</h2><span class="muted">Google Workspace</span></div>
    <div class="settings-grid">
      <div><span class="muted">送信ドライバ</span><strong><?php echo mail_h(mail_delivery_driver()); ?></strong></div>
      <div><span class="muted">SMTP有効</span><strong><?php echo !empty($smtp['enabled']) ? '有効' : '無効'; ?></strong></div>
      <div><span class="muted">設定状態</span><strong><?php echo $smtpReady ? '利用可能' : '不足あり'; ?></strong></div>
      <div><span class="muted">ホスト</span><code><?php echo mail_h((string)($smtp['host'] ?? '')); ?>:<?php echo (int)($smtp['port'] ?? 0); ?></code></div>
      <div><span class="muted">暗号化</span><code><?php echo mail_h((string)($smtp['secure'] ?? 'tls')); ?></code></div>
      <div><span class="muted">SMTP認証</span><strong><?php echo array_key_exists('smtp_auth', $smtp) && !$smtp['smtp_auth'] ? '無効' : '有効'; ?></strong></div>
      <div><span class="muted">ユーザー名</span><code><?php echo mail_h((string)($smtp['username'] ?? '')); ?></code></div>
      <div><span class="muted">送信元</span><code><?php echo mail_h((string)($smtp['from_address'] ?? '')); ?></code></div>
    </div>
    <?php if (!$smtpReady): ?>
      <div class="alert alert-warn mt-14">不足している設定: <code><?php echo mail_h(implode(', ', $smtpMissing)); ?></code></div>
    <?php endif; ?>
    <form method="post" class="mt-14">
      <?php echo mail_auth_csrf_field(); ?>
      <input type="hidden" name="action" value="smtp_test">
      <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatchId; ?>">
      <button type="submit" class="secondary"<?php echo $smtpReady && mail_auth_has_permission($user, 'settings.manage') ? '' : ' disabled'; ?>>SMTP接続テスト</button>
    </form>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>対象バッチ</h2><span class="muted">承認済みから送信</span></div>
    <form method="get" class="stack-form">
      <label><span>送信バッチ</span>
        <select name="batch_id" onchange="this.form.submit()">
          <?php foreach ($batches as $batch): ?>
            <option value="<?php echo (int)$batch['id']; ?>"<?php echo mail_selected($selectedBatchId, (int)$batch['id']); ?>>#<?php echo (int)$batch['id']; ?> <?php echo mail_h((string)$batch['title']); ?>（<?php echo mail_h(mail_status_label((string)$batch['status'])); ?>）</option>
          <?php endforeach; ?>
        </select>
      </label>
      <noscript><button type="submit" class="secondary">表示</button></noscript>
    </form>
    <?php if ($selectedBatch): ?>
      <dl class="detail-list mt-14">
        <div><dt>バッチ名</dt><dd><?php echo mail_h((string)$selectedBatch['title']); ?></dd></div>
        <div><dt>状態</dt><dd><span class="badge"><?php echo mail_h(mail_status_label((string)$selectedBatch['status'])); ?></span></dd></div>
        <div><dt>対象件数</dt><dd><?php echo (int)$selectedBatch['target_count']; ?>件</dd></div>
      </dl>
    <?php else: ?>
      <p class="empty">送信バッチがありません。</p>
    <?php endif; ?>
  </section>
</div>

<?php if ($selectedBatch): ?>
<section class="panel mt-18">
  <div class="panel-head"><h2>SMTP送信実行</h2><span class="muted">直接送信</span></div>
  <div class="summary-grid small two">
    <article><span>送信可能</span><strong><?php echo $sendableCount; ?></strong></article>
    <article><span>送信済み</span><strong><?php echo $sentCount; ?></strong></article>
    <article><span>要確認添付</span><strong><?php echo $pendingAttachmentCount; ?></strong></article>
    <article><span>添付総数</span><strong><?php echo count($attachments); ?></strong></article>
  </div>

  <?php if (!in_array((string)$selectedBatch['status'], ['approved','draft_created'], true) && $sendableCount > 0): ?>
    <div class="alert alert-warn">SMTP送信には、バッチ状態を「確認済み」にする必要があります。送信前レビューを完了してから実行してください。</div>
  <?php elseif ($pendingAttachmentCount > 0): ?>
    <div class="alert alert-warn">要確認・未対応の添付が残っています。<a class="text-link" href="attachments.php?batch_id=<?php echo (int)$selectedBatch['id']; ?>">添付ファイル画面</a>で確定してください。</div>
  <?php endif; ?>

  <form method="post" class="panel-subform mt-14">
    <?php echo mail_auth_csrf_field(); ?>
    <input type="hidden" name="action" value="send_test">
    <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
    <h3>テスト送信（自分宛で体裁確認）</h3>
    <p class="muted">選択した宛先の差し込み結果と、その団体の<strong>承認済み</strong>添付を、入力したアドレスへ1通だけ送ります。件名に [テスト送信] が付き、対象メール・バッチの状態は変化しません。承認前でも実行できます。</p>
    <label><span class="muted">差し込みに使う宛先</span>
      <select name="target_id" required>
        <option value="">宛先を選択…</option>
        <?php foreach ($targets as $target): ?>
          <option value="<?php echo (int)$target['id']; ?>">#<?php echo (int)$target['id']; ?> <?php echo mail_h((string)$target['identifier'] . ' ' . (string)$target['organization_name']); ?>（<?php echo mail_h((string)$target['to_email']); ?>）</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><span class="muted">テスト送信先メールアドレス</span><input type="email" name="test_email" placeholder="自分のアドレス" required></label>
    <button type="submit" class="secondary"<?php echo ($smtpReady && mail_auth_has_permission($user, 'send.execute') && $targets !== []) ? '' : ' disabled'; ?>>テスト送信</button>
  </form>

  <form method="post" class="panel-subform danger-zone mt-14">
    <?php echo mail_auth_csrf_field(); ?>
    <input type="hidden" name="action" value="send_batch_smtp">
    <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
    <h3>一括SMTP送信</h3>
    <p class="muted">Google Workspaceの送信用アカウントから直接送信します。Gmail下書きは作成されません。実行前に対象・本文・添付を必ず確認してください。</p>
    <label><span class="muted">1回の最大送信件数</span><input type="text" name="limit" value="<?php echo (int)$maxSendsPerRun; ?>" inputmode="numeric" style="max-width:120px"></label>
    <label><span class="muted">確認入力</span><input type="text" name="confirm_text" placeholder="送信"></label>
    <button type="submit" class="danger"<?php echo ($smtpReady && mail_auth_has_permission($user, 'send.execute') && in_array((string)$selectedBatch['status'], ['approved','draft_created'], true) && $pendingAttachmentCount === 0 && $sendableCount > 0) ? '' : ' disabled'; ?>>SMTPで一括送信</button>
  </form>
</section>

<section class="panel mt-18">
  <div class="panel-head"><h2>対象メール一覧</h2><span class="muted">個別送信も可能</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>識別番号</th><th>団体</th><th>宛先</th><th>状態</th><th>操作</th></tr></thead>
      <tbody>
        <?php if ($targets === []): ?><tr><td colspan="6" class="empty">対象メールはありません。</td></tr><?php endif; ?>
        <?php foreach ($targets as $target): ?>
          <?php $canSend = $smtpReady && mail_auth_has_permission($user, 'send.execute') && in_array((string)$selectedBatch['status'], ['approved','draft_created'], true) && $pendingAttachmentCount === 0 && in_array((string)$target['status'], ['ready','failed'], true); ?>
          <tr>
            <td><?php echo (int)$target['id']; ?></td>
            <td><code><?php echo mail_h((string)$target['identifier']); ?></code></td>
            <td><?php echo mail_h((string)$target['organization_name']); ?></td>
            <td><?php echo mail_h((string)$target['to_email']); ?></td>
            <td><span class="badge"><?php echo mail_h(mail_status_label((string)$target['status'])); ?></span><?php if (!empty($target['error_message'])): ?><br><span class="danger-text"><?php echo mail_h((string)$target['error_message']); ?></span><?php endif; ?></td>
            <td class="action-cell">
              <form method="post" class="inline-form">
                <?php echo mail_auth_csrf_field(); ?>
                <input type="hidden" name="action" value="send_target_smtp">
                <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
                <input type="hidden" name="target_id" value="<?php echo (int)$target['id']; ?>">
                <input type="hidden" name="confirm_text" value="送信">
                <button type="submit" class="danger"<?php echo $canSend ? '' : ' disabled'; ?>>SMTP送信</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<section class="panel mt-18 feature-panel">
  <h2>この画面の位置づけ</h2>
  <p>Google Workspace SMTP Relay経由で送信を完了します。当面の本番送信はこの画面から行う想定です。</p>
</section>
<?php endif; ?>
<?php
mail_render_page_footer();
