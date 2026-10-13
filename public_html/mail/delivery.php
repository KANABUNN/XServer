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
        mail_flash_set('danger', mail_user_safe_error_message($e));
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
mail_render_page_header('SMTP 直接送信', $user, 'delivery.php', [
    'subtitle' => '保守用：確認済みバッチを Google Workspace の SMTP で直接送信します',
    'actions' => '<a class="btn btn--ghost" href="settings.php">' . fitsc_icon('settings') . '<span class="hide-mobile">送信設定</span></a>',
]);
?>
<?php mail_render_db_error($dbError); ?>
<?php if ($dbError === ''): ?>

<div class="alert alert--warning">
  <?php echo fitsc_icon('alert-triangle'); ?>
  <div class="alert__body">この画面はメールを<strong>実際に送信</strong>します。通常の運用では「Gmail下書き」を使用してください。</div>
</div>

<div class="split">
  <section class="card">
    <div class="card__header">
      <div class="card__title"><?php echo fitsc_icon('server'); ?>SMTP 接続</div>
      <?php echo $smtpReady ? '<span class="badge badge--success badge--dot">利用可能</span>' : '<span class="badge badge--warning badge--dot">不足あり</span>'; ?>
    </div>
    <div class="card__body stack">
      <dl class="dl">
        <div><dt>送信ドライバ</dt><dd><code><?php echo mail_h(mail_delivery_driver()); ?></code></dd></div>
        <div><dt>SMTP</dt><dd><?php echo !empty($smtp['enabled']) ? '有効' : '無効'; ?></dd></div>
        <div><dt>ホスト</dt><dd><code><?php echo mail_h((string)($smtp['host'] ?? '')); ?>:<?php echo (int)($smtp['port'] ?? 0); ?></code></dd></div>
        <div><dt>暗号化</dt><dd><code><?php echo mail_h((string)($smtp['secure'] ?? 'tls')); ?></code></dd></div>
        <div><dt>SMTP 認証</dt><dd><?php echo array_key_exists('smtp_auth', $smtp) && !$smtp['smtp_auth'] ? '無効' : '有効'; ?></dd></div>
        <div><dt>ユーザー名</dt><dd><code><?php echo mail_h((string)($smtp['username'] ?? '')); ?></code></dd></div>
        <div><dt>送信元</dt><dd><code><?php echo mail_h((string)($smtp['from_address'] ?? '')); ?></code></dd></div>
      </dl>
      <?php if (!$smtpReady): ?>
        <div class="alert alert--warning"><?php echo fitsc_icon('alert-triangle'); ?><div class="alert__body">不足している設定：<code><?php echo mail_h(implode(', ', $smtpMissing)); ?></code></div></div>
      <?php endif; ?>
      <form method="post" data-busy>
        <?php echo mail_auth_csrf_field(); ?>
        <input type="hidden" name="action" value="smtp_test">
        <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatchId; ?>">
        <button type="submit" class="btn"<?php echo $smtpReady && mail_auth_has_permission($user, 'settings.manage') ? '' : ' disabled'; ?>><?php echo fitsc_icon('zap'); ?>接続テスト</button>
      </form>
    </div>
  </section>

  <section class="card">
    <div class="card__header"><div class="card__title"><?php echo fitsc_icon('layers'); ?>対象のバッチ</div></div>
    <div class="card__body stack">
      <form method="get">
        <label class="field"><span>送信バッチ</span>
          <select name="batch_id" data-autosubmit>
            <?php foreach ($batches as $batch): ?>
              <option value="<?php echo (int)$batch['id']; ?>"<?php echo mail_selected($selectedBatchId, (int)$batch['id']); ?>>#<?php echo (int)$batch['id']; ?> <?php echo mail_h((string)$batch['title']); ?>（<?php echo mail_h(mail_status_label((string)$batch['status'])); ?>）</option>
            <?php endforeach; ?>
          </select>
        </label>
        <noscript><button type="submit" class="btn">表示</button></noscript>
      </form>
      <?php if ($selectedBatch): ?>
        <div class="cluster">
          <?php echo mail_status_badge((string)$selectedBatch['status']); ?>
          <span class="chip"><?php echo fitsc_icon('users'); ?><?php echo (int)$selectedBatch['target_count']; ?>件</span>
        </div>
      <?php else: ?>
        <p class="small-note">送信バッチがありません。</p>
      <?php endif; ?>
    </div>
  </section>
</div>

<?php if ($selectedBatch): ?>
  <div class="stat-grid">
    <div class="stat stat--info"><span class="stat__label">送信可能</span><span class="stat__value"><?php echo $sendableCount; ?></span></div>
    <div class="stat stat--success"><span class="stat__label">送信済み</span><span class="stat__value"><?php echo $sentCount; ?></span></div>
    <div class="stat stat--warning"><span class="stat__label">要確認の添付</span><span class="stat__value"><?php echo $pendingAttachmentCount; ?></span></div>
    <div class="stat"><span class="stat__label">添付総数</span><span class="stat__value"><?php echo count($attachments); ?></span></div>
  </div>
  <?php if (!in_array((string)$selectedBatch['status'], ['approved', 'draft_created'], true) && $sendableCount > 0): ?>
    <div class="alert alert--warning"><?php echo fitsc_icon('alert-triangle'); ?><div class="alert__body">送信するには、バッチの状態を「確認済み」にしてください。</div></div>
  <?php elseif ($pendingAttachmentCount > 0): ?>
    <div class="alert alert--warning"><?php echo fitsc_icon('paperclip'); ?><div class="alert__body">要確認の添付があります。<a href="attachments.php?batch_id=<?php echo (int)$selectedBatch['id']; ?>">添付ファイル画面</a>で確定してください。</div></div>
  <?php endif; ?>

  <div class="split">
    <section class="card">
      <div class="card__header"><div class="card__title"><?php echo fitsc_icon('eye'); ?>テスト送信（自分宛て）</div></div>
      <form method="post" class="card__body stack" data-busy>
        <?php echo mail_auth_csrf_field(); ?>
        <input type="hidden" name="action" value="send_test">
        <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
        <p class="small-note">選んだ宛先の差し込み結果と確認済み添付を、指定アドレスへ 1 通だけ送ります。件名に [テスト送信] が付き、バッチの状態は変わりません。</p>
        <label class="field"><span>差し込みに使う宛先</span>
          <select name="target_id" required>
            <option value="">宛先を選択…</option>
            <?php foreach ($targets as $target): ?>
              <option value="<?php echo (int)$target['id']; ?>">#<?php echo (int)$target['id']; ?> <?php echo mail_h((string)$target['identifier'] . ' ' . (string)$target['organization_name']); ?>（<?php echo mail_h((string)$target['to_email']); ?>）</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field"><span>テスト送信先</span><input type="email" name="test_email" placeholder="自分のメールアドレス" required></label>
        <div><button type="submit" class="btn"<?php echo ($smtpReady && mail_auth_has_permission($user, 'send.execute') && $targets !== []) ? '' : ' disabled'; ?>><?php echo fitsc_icon('send'); ?>テスト送信</button></div>
      </form>
    </section>

    <section class="card card--danger">
      <div class="card__header"><div class="card__title"><?php echo fitsc_icon('send'); ?>一括送信</div></div>
      <form method="post" class="card__body stack" data-busy data-confirm="バッチ #<?php echo (int)$selectedBatch['id']; ?> のメールを SMTP で送信します。取り消すことはできません。" data-confirm-title="メールを送信しますか？" data-confirm-ok="送信する">
        <?php echo mail_auth_csrf_field(); ?>
        <input type="hidden" name="action" value="send_batch_smtp">
        <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
        <p class="small-note">送信用アカウントから直接送信します（Gmail 下書きは作成されません）。宛先・本文・添付を必ず確認してから実行してください。</p>
        <div class="form-grid">
          <label class="field"><span>1回の最大送信件数</span><input type="text" name="limit" value="<?php echo (int)$maxSendsPerRun; ?>" inputmode="numeric"></label>
          <label class="field"><span>確認のため「送信」と入力</span><input type="text" name="confirm_text" placeholder="送信" autocomplete="off"></label>
        </div>
        <div><button type="submit" class="btn btn--danger"<?php echo ($smtpReady && mail_auth_has_permission($user, 'send.execute') && in_array((string)$selectedBatch['status'], ['approved', 'draft_created'], true) && $pendingAttachmentCount === 0 && $sendableCount > 0) ? '' : ' disabled'; ?>><?php echo fitsc_icon('send'); ?>一括送信</button></div>
      </form>
    </section>
  </div>

  <section class="card">
    <div class="card__header"><div class="card__title"><?php echo fitsc_icon('mail'); ?>対象メール <small><?php echo count($targets); ?>件</small></div></div>
    <div class="table-wrap is-stacked">
      <table class="table">
        <thead><tr><th>団体</th><th>宛先</th><th>状態</th><th class="col-actions"></th></tr></thead>
        <tbody>
          <?php if ($targets === []): ?><tr><td colspan="4" class="empty">対象メールはありません。</td></tr><?php endif; ?>
          <?php foreach ($targets as $target): ?>
            <?php $canSend = $smtpReady && mail_auth_has_permission($user, 'send.execute') && in_array((string)$selectedBatch['status'], ['approved', 'draft_created'], true) && $pendingAttachmentCount === 0 && in_array((string)$target['status'], ['ready', 'failed'], true); ?>
            <tr<?php echo !empty($target['error_message']) ? ' class="is-error"' : ''; ?>>
              <td><span class="cell-main"><?php echo mail_h((string)$target['organization_name']); ?></span><span class="cell-sub"><code><?php echo mail_h((string)$target['identifier']); ?></code> ・ #<?php echo (int)$target['id']; ?></span></td>
              <td class="allow-select"><?php echo mail_h((string)$target['to_email']); ?></td>
              <td><?php echo mail_status_badge((string)$target['status']); ?><?php if (!empty($target['error_message'])): ?><span class="cell-sub text-danger"><?php echo mail_h((string)$target['error_message']); ?></span><?php endif; ?></td>
              <td class="col-actions">
                <form method="post" data-busy data-confirm="<?php echo mail_h((string)$target['organization_name']); ?>（<?php echo mail_h((string)$target['to_email']); ?>）へ送信します。" data-confirm-title="このメールを送信しますか？" data-confirm-ok="送信する">
                  <?php echo mail_auth_csrf_field(); ?>
                  <input type="hidden" name="action" value="send_target_smtp">
                  <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
                  <input type="hidden" name="target_id" value="<?php echo (int)$target['id']; ?>">
                  <input type="hidden" name="confirm_text" value="送信">
                  <button type="submit" class="btn btn--danger-ghost btn--sm"<?php echo $canSend ? '' : ' disabled'; ?>><?php echo fitsc_icon('send'); ?>送信</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>
<?php endif; ?>
<?php
mail_render_page_footer();
