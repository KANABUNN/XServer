<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
[$user, $mailPdo, $dbError] = mail_app_init();

$selectedBatchId = (int)($_GET['batch_id'] ?? 0);
$batches = [];
$logs = [];
$successCount = 0;
$failedCount = 0;
if ($mailPdo instanceof PDO && $dbError === '') {
    $batches = mail_list_batches($mailPdo, 200);
    $logs = mail_list_send_logs($mailPdo, $selectedBatchId > 0 ? $selectedBatchId : null, 300);
    foreach ($logs as $log) {
        if ((string)$log['result'] === 'success') {
            $successCount++;
        } elseif ((string)$log['result'] === 'failed') {
            $failedCount++;
        }
    }
}
mail_render_page_header('送信ログ', $user, 'send_logs.php', [
    'subtitle' => '宛先ごとの送信結果（操作ログとは別に記録）',
]);
?>
<?php mail_render_db_error($dbError); ?>
<?php if ($dbError === ''): ?>

<div class="stat-grid">
  <div class="stat"><span class="stat__label"><span class="stat__icon"><?php echo fitsc_icon('activity'); ?></span>ログ件数</span><span class="stat__value"><?php echo count($logs); ?></span></div>
  <div class="stat stat--success"><span class="stat__label"><span class="stat__icon"><?php echo fitsc_icon('check-circle'); ?></span>成功</span><span class="stat__value"><?php echo $successCount; ?></span></div>
  <div class="stat <?php echo $failedCount > 0 ? 'stat--danger' : 'stat--teal'; ?>"><span class="stat__label"><span class="stat__icon"><?php echo fitsc_icon('x-circle'); ?></span>失敗</span><span class="stat__value"><?php echo $failedCount; ?></span></div>
</div>

<section class="card">
  <div class="card__header">
    <div class="card__title"><?php echo fitsc_icon('activity'); ?>送信ログ</div>
    <form method="get">
      <label class="sr-only" for="sendLogBatch">送信バッチ</label>
      <select name="batch_id" id="sendLogBatch" data-autosubmit style="width:auto;max-width:360px">
        <option value="0"<?php echo mail_selected($selectedBatchId, 0); ?>>すべてのバッチ</option>
        <?php foreach ($batches as $batch): ?>
          <option value="<?php echo (int)$batch['id']; ?>"<?php echo mail_selected($selectedBatchId, (int)$batch['id']); ?>>#<?php echo (int)$batch['id']; ?> <?php echo mail_h((string)$batch['title']); ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit" class="btn">表示</button></noscript>
    </form>
  </div>
  <?php if ($logs === []): ?>
    <div class="empty"><span class="empty__icon"><?php echo fitsc_icon('activity'); ?></span><span>送信ログはありません。</span></div>
  <?php else: ?>
    <div class="table-wrap is-stacked">
      <table class="table">
        <thead><tr><th>日時</th><th>バッチ</th><th>団体 / 宛先</th><th>操作</th><th>結果</th><th>詳細</th></tr></thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
            <?php $isFailed = (string)$log['result'] === 'failed'; ?>
            <tr<?php echo $isFailed ? ' class="is-error"' : ''; ?>>
              <td><span class="cell-sub nowrap"><?php echo mail_h((string)$log['created_at']); ?></span></td>
              <td><?php echo $log['batch_id'] !== null ? '#' . (int)$log['batch_id'] : '—'; ?><span class="cell-sub"><?php echo mail_h((string)($log['batch_title'] ?? '')); ?></span></td>
              <td><span class="cell-main"><?php echo mail_h((string)($log['organization_name'] ?? '')); ?></span><span class="cell-sub allow-select"><?php echo mail_h((string)($log['to_email'] ?? '')); ?><?php echo !empty($log['identifier']) ? ' ・ ' . mail_h((string)$log['identifier']) : ''; ?></span></td>
              <td><code><?php echo mail_h((string)$log['action']); ?></code></td>
              <td><?php echo $isFailed ? '<span class="badge badge--danger badge--dot">失敗</span>' : '<span class="badge badge--success badge--dot">' . mail_h((string)$log['result']) . '</span>'; ?></td>
              <td>
                <?php $detail = !empty($log['error_message']) ? (string)$log['error_message'] : (string)($log['response_body'] ?? ''); ?>
                <?php if ($detail !== ''): ?>
                  <details class="ml-log-detail"><summary><?php echo $isFailed ? 'エラー内容' : '応答'; ?></summary><pre class="allow-select<?php echo $isFailed ? ' text-danger' : ''; ?>"><?php echo mail_h($detail); ?></pre></details>
                <?php else: ?><span class="text-subtle">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php
mail_render_page_footer();
