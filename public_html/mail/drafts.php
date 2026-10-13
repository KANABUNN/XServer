<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../../apps/mail_core/gmail_client.php';
require_once __DIR__ . '/../../apps/mail_core/gmail_html_client.php';

[$user, $mailPdo, $dbError] = mail_app_init();
[$gmailReady, $gmailMissing] = mail_gmail_is_configured();
$maxDraftsPerRun = mail_gmail_max_drafts_per_run();
$selectedBatchId = (int)($_GET['batch_id'] ?? $_POST['batch_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbError === '' && $mailPdo instanceof PDO) {
    try {
        mail_auth_require_csrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create_batch_drafts') {
            mail_require_permission_or_forbid($user, 'send.execute');
            if ((string)($_POST['confirm_submit'] ?? '') !== '1') {
                throw new RuntimeException('Gmail下書き作成の確認が完了していません。');
            }
            $limit = max(1, min(100, (int)$maxDraftsPerRun));
            $result = mail_gmail_html_create_drafts_batch($mailPdo, $selectedBatchId, $user, $limit);
            mail_flash_set('info', 'Gmail下書き作成を実行しました。成功: ' . $result['success'] . '件 / 失敗: ' . $result['failed'] . '件 / 残り: ' . $result['remaining'] . '件');
            mail_redirect('drafts.php?batch_id=' . $selectedBatchId);
        }

        if ($action === 'create_target_draft') {
            mail_require_permission_or_forbid($user, 'send.execute');
            $targetId = (int)($_POST['target_id'] ?? 0);
            $result = mail_gmail_html_create_draft_target($mailPdo, $targetId, $user);
            mail_flash_set('info', 'Gmail下書きを作成しました。対象ID: ' . $targetId . ' / Draft ID: ' . $result['draft_id']);
            mail_redirect('drafts.php?batch_id=' . $selectedBatchId);
        }
    } catch (Throwable $e) {
        mail_flash_set('danger', mail_user_safe_error_message($e));
        mail_redirect('drafts.php' . ($selectedBatchId > 0 ? '?batch_id=' . $selectedBatchId : ''));
    }
}

$batches = [];
$selectedBatch = null;
$targets = [];
$attachments = [];
$pendingAttachmentCount = 0;
$draftableCount = 0;
$draftedCount = 0;

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
            $draftableCount = mail_gmail_draftable_target_count($mailPdo, $selectedBatchId);
            $draftedCount = mail_gmail_drafted_target_count($mailPdo, $selectedBatchId);
        }
    }
}
mail_render_page_header('Gmail下書き', $user, 'drafts.php', [
    'subtitle' => '確認済みのバッチを Gmail の下書きフォルダに作成します（送信は Gmail で行います）',
    'actions' => mail_auth_has_permission($user, 'settings.manage') ? '<a class="btn btn--ghost" href="settings.php">' . fitsc_icon('settings') . '<span class="hide-mobile">送信設定</span></a>' : '',
]);
?>
<?php mail_render_db_error($dbError); ?>
<?php if ($dbError === ''): ?>

<section class="card">
  <div class="card__body">
    <form method="get" class="ml-batch-picker">
      <label class="field"><span>対象のバッチ</span>
        <select name="batch_id" data-autosubmit>
          <?php if ($batches === []): ?><option value="">バッチがありません</option><?php endif; ?>
          <?php foreach ($batches as $batch): ?>
            <option value="<?php echo (int)$batch['id']; ?>"<?php echo mail_selected($selectedBatchId, (int)$batch['id']); ?>>#<?php echo (int)$batch['id']; ?> <?php echo mail_h((string)$batch['title']); ?>（<?php echo mail_h(mail_status_label((string)$batch['status'])); ?>）</option>
          <?php endforeach; ?>
        </select>
      </label>
      <noscript><button type="submit" class="btn">表示</button></noscript>
      <?php if ($selectedBatch): ?>
        <div class="cluster">
          <?php echo mail_status_badge((string)$selectedBatch['status']); ?>
          <span class="chip"><?php echo fitsc_icon('users'); ?><?php echo (int)$selectedBatch['target_count']; ?>件</span>
          <a class="btn btn--ghost btn--sm" href="batches.php?batch_id=<?php echo (int)$selectedBatch['id']; ?>">バッチを確認<?php echo fitsc_icon('arrow-right'); ?></a>
        </div>
      <?php endif; ?>
    </form>
  </div>
</section>

<?php if ($selectedBatch): ?>
  <?php
    $statusReady = in_array((string)$selectedBatch['status'], ['approved', 'draft_created'], true);
    $canBulk = $gmailReady && mail_auth_has_permission($user, 'send.execute') && $statusReady && $pendingAttachmentCount === 0 && $draftableCount > 0;
  ?>
  <div class="stat-grid">
    <div class="stat stat--info"><span class="stat__label"><span class="stat__icon"><?php echo fitsc_icon('inbox'); ?></span>下書きにできる</span><span class="stat__value"><?php echo $draftableCount; ?></span></div>
    <div class="stat stat--success"><span class="stat__label"><span class="stat__icon"><?php echo fitsc_icon('check-circle'); ?></span>作成済み</span><span class="stat__value"><?php echo $draftedCount; ?></span></div>
    <div class="stat <?php echo $pendingAttachmentCount > 0 ? 'stat--warning' : 'stat--teal'; ?>"><span class="stat__label"><span class="stat__icon"><?php echo fitsc_icon('paperclip'); ?></span>要確認の添付</span><span class="stat__value"><?php echo $pendingAttachmentCount; ?></span></div>
    <div class="stat"><span class="stat__label"><span class="stat__icon"><?php echo fitsc_icon('gauge'); ?></span>1回の作成上限</span><span class="stat__value"><?php echo (int)$maxDraftsPerRun; ?></span></div>
  </div>

  <?php if (!$gmailReady): ?>
    <div class="alert alert--warning"><?php echo fitsc_icon('alert-triangle'); ?><div class="alert__body"><strong class="alert__title">Gmail API の設定が不足しています</strong><span>送信設定を確認してください： <code><?php echo mail_h(implode(', ', $gmailMissing)); ?></code></span></div></div>
  <?php elseif (!$statusReady && $draftableCount > 0): ?>
    <div class="alert alert--warning"><?php echo fitsc_icon('alert-triangle'); ?><div class="alert__body">下書きを作るには、<a href="batches.php?batch_id=<?php echo (int)$selectedBatch['id']; ?>">送信バッチ画面</a>で状態を「確認済み」にしてください。</div></div>
  <?php elseif ($pendingAttachmentCount > 0): ?>
    <div class="alert alert--warning"><?php echo fitsc_icon('paperclip'); ?><div class="alert__body">要確認・未対応の添付があります。<a href="attachments.php?batch_id=<?php echo (int)$selectedBatch['id']; ?>">添付ファイル画面</a>で確定してください。</div></div>
  <?php endif; ?>

  <section class="card">
    <div class="card__header">
      <div>
        <div class="card__title"><?php echo fitsc_icon('inbox'); ?>まとめて下書きを作成</div>
        <p class="card__subtitle">宛先・件名・本文・添付を含むメールを Gmail の下書きに作成します。ここでは送信しません。</p>
      </div>
      <form method="post" id="bulkDraftForm" data-busy
        data-confirm="対象 <?php echo (int)$draftableCount; ?>件（今回の上限 <?php echo (int)$maxDraftsPerRun; ?>件）を Gmail の下書きに作成します。送信は Gmail 側で最終確認してから行ってください。"
        data-confirm-title="Gmail下書きを作成しますか？" data-confirm-ok="作成する" data-confirm-tone="default">
        <?php echo mail_auth_csrf_field(); ?>
        <input type="hidden" name="action" value="create_batch_drafts">
        <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
        <input type="hidden" name="confirm_submit" value="1">
        <button type="submit" class="btn btn--primary"<?php echo $canBulk ? '' : ' disabled'; ?>><?php echo fitsc_icon('zap'); ?>一括作成</button>
      </form>
    </div>
    <div class="table-wrap is-stacked">
      <table class="table">
        <thead><tr><th>団体</th><th>宛先</th><th>状態</th><th>下書きID</th><th class="col-actions"></th></tr></thead>
        <tbody>
          <?php if ($targets === []): ?><tr><td colspan="5" class="empty">対象メールはありません。</td></tr><?php endif; ?>
          <?php foreach ($targets as $target): ?>
            <?php $canDraft = $gmailReady && mail_auth_has_permission($user, 'send.execute') && $statusReady && $pendingAttachmentCount === 0 && in_array((string)$target['status'], ['ready', 'failed'], true) && trim((string)($target['graph_message_id'] ?? '')) === ''; ?>
            <tr<?php echo !empty($target['error_message']) ? ' class="is-error"' : ''; ?>>
              <td><span class="cell-main"><?php echo mail_h((string)$target['organization_name']); ?></span><span class="cell-sub"><code><?php echo mail_h((string)$target['identifier']); ?></code> ・ #<?php echo (int)$target['id']; ?></span></td>
              <td class="allow-select"><?php echo mail_h((string)$target['to_email']); ?></td>
              <td><?php echo mail_status_badge((string)$target['status']); ?><?php if (!empty($target['error_message'])): ?><span class="cell-sub text-danger"><?php echo mail_h((string)$target['error_message']); ?></span><?php endif; ?></td>
              <td><?php echo trim((string)($target['graph_message_id'] ?? '')) !== '' ? '<code class="text-xs">' . mail_h((string)$target['graph_message_id']) . '</code>' : '<span class="text-subtle">—</span>'; ?></td>
              <td class="col-actions">
                <form method="post" data-busy>
                  <?php echo mail_auth_csrf_field(); ?>
                  <input type="hidden" name="action" value="create_target_draft">
                  <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
                  <input type="hidden" name="target_id" value="<?php echo (int)$target['id']; ?>">
                  <button type="submit" class="btn btn--sm"<?php echo $canDraft ? '' : ' disabled'; ?>><?php echo fitsc_icon('inbox'); ?>下書き作成</button>
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
