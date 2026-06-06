<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../../apps/mail_core/gmail_client.php';

[$user, $mailPdo, $dbError] = mail_app_init();
[$gmailReady, $gmailMissing] = mail_gmail_is_configured();
$maxDraftsPerRun = mail_gmail_max_drafts_per_run();
$selectedBatchId = (int)($_GET['batch_id'] ?? $_POST['batch_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbError === '' && $mailPdo instanceof PDO) {
    try {
        mail_auth_require_csrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create_batch_drafts') {
            mail_require_permission_or_forbid($user, 'draft.create');
            if ((string)($_POST['confirm_submit'] ?? '') !== '1') {
                throw new RuntimeException('Gmail下書き作成の確認が完了していません。');
            }
            $limit = max(1, min(100, (int)$maxDraftsPerRun));
            $result = mail_gmail_create_drafts_batch($mailPdo, $selectedBatchId, $user, $limit);
            mail_flash_set('info', 'Gmail下書き作成を実行しました。成功: ' . $result['success'] . '件 / 失敗: ' . $result['failed'] . '件 / 残り: ' . $result['remaining'] . '件');
            mail_redirect('drafts.php?batch_id=' . $selectedBatchId);
        }

        if ($action === 'create_target_draft') {
            mail_require_permission_or_forbid($user, 'draft.create');
            $targetId = (int)($_POST['target_id'] ?? 0);
            $result = mail_gmail_create_draft_target($mailPdo, $targetId, $user);
            mail_flash_set('info', 'Gmail下書きを作成しました。対象ID: ' . $targetId . ' / Draft ID: ' . $result['draft_id']);
            mail_redirect('drafts.php?batch_id=' . $selectedBatchId);
        }
    } catch (Throwable $e) {
        mail_flash_set('danger', $e->getMessage());
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

mail_render_page_header('Gmail下書き作成', $user, 'drafts.php');
?>
<header class="page-head">
  <div>
    <h1>Gmail下書き作成</h1>
    <p class="lead">承認済みバッチをGoogle Workspace Gmail API経由で、送信用アカウントのGmail下書きへ作成します。送信はGmail側で最終確認後に行います。</p>
  </div>
  <div class="head-actions"><a class="link-button secondary" href="settings.php">送信設定を確認</a></div>
</header>
<?php mail_render_db_error($dbError); ?>

<?php if ($dbError === ''): ?>
<section class="panel">
  <div class="panel-head"><h2>対象バッチ</h2><span class="muted">確認済みから下書き化</span></div>
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

<?php if ($selectedBatch): ?>
<div class="draft-work-grid mt-18">
<section class="panel">
  <div class="panel-head"><h2>Gmail下書き作成</h2><span class="muted">最大作成件数は送信設定で管理</span></div>
  <div class="summary-grid small two">
    <article><span>下書き作成可能</span><strong><?php echo $draftableCount; ?></strong></article>
    <article><span>下書き作成済み</span><strong><?php echo $draftedCount; ?></strong></article>
    <article><span>要確認添付</span><strong><?php echo $pendingAttachmentCount; ?></strong></article>
    <article><span>今回の最大作成件数</span><strong><?php echo (int)$maxDraftsPerRun; ?></strong></article>
  </div>

  <?php if (!$gmailReady): ?>
    <div class="alert alert-warn">Gmail API設定に不足があります。送信設定画面で確認してください: <code><?php echo mail_h(implode(', ', $gmailMissing)); ?></code></div>
  <?php elseif (!in_array((string)$selectedBatch['status'], ['approved','draft_created'], true) && $draftableCount > 0): ?>
    <div class="alert alert-warn">Gmail下書き作成には、バッチ状態を「確認済み」にする必要があります。送信バッチ画面で確認済みに変更してください。</div>
  <?php elseif ($pendingAttachmentCount > 0): ?>
    <div class="alert alert-warn">要確認・未対応の添付が残っています。<a class="text-link" href="attachments.php?batch_id=<?php echo (int)$selectedBatch['id']; ?>">添付ファイル画面</a>で確定してください。</div>
  <?php endif; ?>

  <form method="post" class="panel-subform danger-zone mt-14" id="bulkDraftForm" data-draft-count="<?php echo (int)$draftableCount; ?>" data-max-count="<?php echo (int)$maxDraftsPerRun; ?>">
    <?php echo mail_auth_csrf_field(); ?>
    <input type="hidden" name="action" value="create_batch_drafts">
    <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
    <input type="hidden" name="confirm_submit" value="1">
    <h3>一括Gmail下書き作成</h3>
    <p class="muted">宛先・件名・本文・添付を含むメールをGmailの下書きフォルダに作成します。ここでは送信しません。</p>
    <button type="submit" class="primary"<?php echo ($gmailReady && mail_auth_has_permission($user, 'draft.create') && in_array((string)$selectedBatch['status'], ['approved','draft_created'], true) && $pendingAttachmentCount === 0 && $draftableCount > 0) ? '' : ' disabled'; ?>>Gmail下書きを一括作成</button>
  </form>
</section>

<section class="panel">
  <div class="panel-head"><h2>対象メール一覧</h2><span class="muted">個別下書き作成</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>識別番号</th><th>団体</th><th>宛先</th><th>状態</th><th>下書きID</th><th>操作</th></tr></thead>
      <tbody>
        <?php if ($targets === []): ?><tr><td colspan="7" class="empty">対象メールはありません。</td></tr><?php endif; ?>
        <?php foreach ($targets as $target): ?>
          <?php $canDraft = $gmailReady && mail_auth_has_permission($user, 'draft.create') && in_array((string)$selectedBatch['status'], ['approved','draft_created'], true) && $pendingAttachmentCount === 0 && in_array((string)$target['status'], ['ready','failed'], true) && trim((string)($target['graph_message_id'] ?? '')) === ''; ?>
          <tr>
            <td><?php echo (int)$target['id']; ?></td>
            <td><code><?php echo mail_h((string)$target['identifier']); ?></code></td>
            <td><?php echo mail_h((string)$target['organization_name']); ?></td>
            <td><?php echo mail_h((string)$target['to_email']); ?></td>
            <td><span class="badge"><?php echo mail_h(mail_status_label((string)$target['status'])); ?></span><?php if (!empty($target['error_message'])): ?><br><span class="danger-text"><?php echo mail_h((string)$target['error_message']); ?></span><?php endif; ?></td>
            <td><code><?php echo mail_h((string)($target['graph_message_id'] ?? '')); ?></code></td>
            <td class="action-cell">
              <form method="post" class="inline-form">
                <?php echo mail_auth_csrf_field(); ?>
                <input type="hidden" name="action" value="create_target_draft">
                <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
                <input type="hidden" name="target_id" value="<?php echo (int)$target['id']; ?>">
                <button type="submit" class="secondary"<?php echo $canDraft ? '' : ' disabled'; ?>>下書き作成</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
</div>
<?php endif; ?>

<script>
(function () {
  const form = document.getElementById('bulkDraftForm');
  form?.addEventListener('submit', function (event) {
    const count = form.dataset.draftCount || '0';
    const max = form.dataset.maxCount || '0';
    const ok = window.confirm(`Gmail下書きを一括作成します。\n対象: ${count}件\n今回の最大作成件数: ${max}件\n\nGmail側で最終確認してから送信してください。`);
    if (!ok) {
      event.preventDefault();
    }
  });
})();
</script>
<?php endif; ?>
<?php
mail_render_page_footer();
