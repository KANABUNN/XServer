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
mail_render_page_header('送信ログ', $user, 'send_logs.php');
?>
<header class="page-head">
  <div>
    <h1>送信ログ</h1>
    <p class="lead">SMTP送信・テスト送信の宛先単位の結果ログです。操作ログ（ログ画面）とは別に、配送の成否を記録します。</p>
  </div>
</header>
<?php mail_render_db_error($dbError); ?>
<?php if ($dbError === ''): ?>
<section class="panel">
  <div class="panel-head"><h2>絞り込み</h2><span class="muted">バッチ単位</span></div>
  <form method="get" class="stack-form">
    <label><span>送信バッチ</span>
      <select name="batch_id" onchange="this.form.submit()">
        <option value="0"<?php echo mail_selected($selectedBatchId, 0); ?>>すべてのバッチ</option>
        <?php foreach ($batches as $batch): ?>
          <option value="<?php echo (int)$batch['id']; ?>"<?php echo mail_selected($selectedBatchId, (int)$batch['id']); ?>>#<?php echo (int)$batch['id']; ?> <?php echo mail_h((string)$batch['title']); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <noscript><button type="submit" class="secondary">表示</button></noscript>
  </form>
</section>

<div class="summary-grid small">
  <article class="summary-card"><span>ログ件数</span><strong><?php echo count($logs); ?></strong></article>
  <article class="summary-card"><span>成功</span><strong><?php echo $successCount; ?></strong></article>
  <article class="summary-card"><span>失敗</span><strong class="danger-text"><?php echo $failedCount; ?></strong></article>
  <article class="summary-card"><span>対象</span><strong class="small-strong"><?php echo $selectedBatchId > 0 ? '#' . $selectedBatchId : '全件'; ?></strong></article>
</div>

<section class="panel mt-18">
  <div class="panel-head"><h2>送信ログ</h2><span class="muted"><?php echo count($logs); ?>件</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>日時</th><th>バッチ</th><th>識別番号</th><th>団体</th><th>宛先</th><th>操作</th><th>結果</th><th>詳細</th></tr></thead>
      <tbody>
        <?php if ($logs === []): ?><tr><td colspan="8" class="empty">送信ログはありません。</td></tr><?php endif; ?>
        <?php foreach ($logs as $log): ?>
          <?php $isFailed = (string)$log['result'] === 'failed'; ?>
          <tr class="<?php echo $isFailed ? 'needs-review-row' : ''; ?>">
            <td><?php echo mail_h((string)$log['created_at']); ?></td>
            <td><?php echo $log['batch_id'] !== null ? '#' . (int)$log['batch_id'] : '-'; ?><br><span class="muted"><?php echo mail_h((string)($log['batch_title'] ?? '')); ?></span></td>
            <td><code><?php echo mail_h((string)($log['identifier'] ?? '')); ?></code></td>
            <td><?php echo mail_h((string)($log['organization_name'] ?? '')); ?></td>
            <td><?php echo mail_h((string)($log['to_email'] ?? '')); ?></td>
            <td><code><?php echo mail_h((string)$log['action']); ?></code></td>
            <td><strong class="<?php echo $isFailed ? 'danger-text' : ''; ?>"><?php echo mail_h((string)$log['result']); ?></strong></td>
            <td>
              <?php if (!empty($log['error_message'])): ?>
                <pre class="preview-snippet danger-text"><?php echo mail_h((string)$log['error_message']); ?></pre>
              <?php else: ?>
                <pre class="preview-snippet"><?php echo mail_h((string)($log['response_body'] ?? '')); ?></pre>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
<?php
mail_render_page_footer();