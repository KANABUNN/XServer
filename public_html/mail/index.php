<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
[$user, $mailPdo, $dbError] = mail_app_init();

$counts = [
    'organizations' => 0,
    'templates' => 0,
    'batches' => 0,
    'draft_batches' => 0,
    'attachments_need_review' => 0,
    'send_logs' => 0,
];
$recentBatches = [];
$recentUploads = [];
if ($mailPdo instanceof PDO && $dbError === '') {
    $counts = mail_dashboard_counts($mailPdo);
    $recentBatches = mail_recent_batches($mailPdo, 8);
    $recentUploads = mail_recent_uploads($mailPdo, 8);
}
mail_render_page_header('ダッシュボード', $user, 'index.php', [
    'subtitle' => '団体別の差し込みメール・添付・Gmail下書きを管理',
    'actions' => '<a class="btn btn--ghost btn--icon" href="./api/health.php" target="_blank" rel="noopener" aria-label="接続確認" data-tip="接続確認" data-tip-pos="bottom">' . fitsc_icon('activity') . '</a>'
        . '<a class="btn btn--primary hide-mobile" href="compose.php">' . fitsc_icon('pen') . 'メールを作成</a>',
]);
?>
<?php mail_render_db_error($dbError); ?>

<div class="stat-grid">
  <a class="stat ml-stat-link" href="organizations.php">
    <span class="stat__label"><span class="stat__icon"><?php echo fitsc_icon('building'); ?></span>活動中の団体</span>
    <span class="stat__value"><?php echo (int)$counts['organizations']; ?></span>
  </a>
  <a class="stat stat--teal ml-stat-link" href="templates.php">
    <span class="stat__label"><span class="stat__icon"><?php echo fitsc_icon('template'); ?></span>テンプレート</span>
    <span class="stat__value"><?php echo (int)$counts['templates']; ?></span>
  </a>
  <a class="stat stat--info ml-stat-link" href="batches.php">
    <span class="stat__label"><span class="stat__icon"><?php echo fitsc_icon('layers'); ?></span>送信バッチ</span>
    <span class="stat__value"><?php echo (int)$counts['batches']; ?></span>
  </a>
  <a class="stat <?php echo (int)$counts['attachments_need_review'] > 0 ? 'stat--warning' : 'stat--success'; ?> ml-stat-link" href="attachments.php">
    <span class="stat__label"><span class="stat__icon"><?php echo fitsc_icon('paperclip'); ?></span>要確認の添付</span>
    <span class="stat__value"><?php echo (int)$counts['attachments_need_review']; ?></span>
  </a>
</div>

<section class="card">
  <div class="card__header card__header--plain">
    <div class="card__title"><?php echo fitsc_icon('sparkles'); ?>送信までの流れ</div>
  </div>
  <div class="card__body">
    <ol class="ml-flow">
      <li><a href="organizations.php"><span class="ml-flow__icon"><?php echo fitsc_icon('building'); ?></span><strong>団体データ</strong><small>CSV取込・手入力</small></a></li>
      <li><a href="templates.php"><span class="ml-flow__icon"><?php echo fitsc_icon('template'); ?></span><strong>テンプレート</strong><small>件名・本文と変数</small></a></li>
      <li><a href="compose.php"><span class="ml-flow__icon"><?php echo fitsc_icon('pen'); ?></span><strong>メール作成</strong><small>送信対象を選んでバッチ化</small></a></li>
      <li><a href="attachments.php"><span class="ml-flow__icon"><?php echo fitsc_icon('paperclip'); ?></span><strong>添付ファイル</strong><small>共通・個別を一括登録</small></a></li>
      <li><a href="drafts.php"><span class="ml-flow__icon"><?php echo fitsc_icon('inbox'); ?></span><strong>Gmail下書き</strong><small>確認済みバッチを下書き化</small></a></li>
    </ol>
  </div>
</section>

<div class="split">
  <section class="card">
    <div class="card__header">
      <div class="card__title"><?php echo fitsc_icon('layers'); ?>最近の送信バッチ</div>
      <a class="btn btn--ghost btn--sm" href="batches.php">すべて見る<?php echo fitsc_icon('arrow-right'); ?></a>
    </div>
    <?php if ($recentBatches === []): ?>
      <div class="empty"><span class="empty__icon"><?php echo fitsc_icon('layers'); ?></span><span>まだ送信バッチはありません。</span><a class="btn btn--sm" href="compose.php"><?php echo fitsc_icon('pen'); ?>メールを作成</a></div>
    <?php else: ?>
      <div class="list">
        <?php foreach ($recentBatches as $batch): ?>
          <a class="list-item" href="batches.php?batch_id=<?php echo (int)$batch['id']; ?>">
            <span class="list-item__icon"><?php echo fitsc_icon('send', 'ico--sm'); ?></span>
            <span class="list-item__main">
              <span class="list-item__title"><?php echo mail_h((string)$batch['title']); ?></span>
              <span class="list-item__meta">#<?php echo (int)$batch['id']; ?> ・ <?php echo (int)$batch['target_count']; ?>件 ・ 添付 共通<?php echo (int)$batch['common_attachment_count']; ?>/個別<?php echo (int)$batch['individual_attachment_count']; ?> ・ <?php echo mail_h((string)$batch['created_at']); ?></span>
            </span>
            <span class="list-item__side"><?php echo mail_status_badge((string)$batch['status']); ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card__header">
      <div class="card__title"><?php echo fitsc_icon('upload'); ?>最近の添付アップロード</div>
      <a class="btn btn--ghost btn--sm" href="attachments.php">すべて見る<?php echo fitsc_icon('arrow-right'); ?></a>
    </div>
    <?php if ($recentUploads === []): ?>
      <div class="empty"><span class="empty__icon"><?php echo fitsc_icon('upload'); ?></span><span>まだアップロード履歴はありません。</span></div>
    <?php else: ?>
      <div class="list">
        <?php foreach ($recentUploads as $upload): ?>
          <div class="list-item">
            <span class="list-item__icon"><?php echo fitsc_icon('paperclip', 'ico--sm'); ?></span>
            <span class="list-item__main">
              <span class="list-item__title"><?php echo (string)$upload['upload_kind'] === 'common' ? '共通添付' : '個別添付'; ?> ・ <?php echo (int)$upload['file_count']; ?>ファイル</span>
              <span class="list-item__meta">#<?php echo (int)$upload['id']; ?><?php echo $upload['mail_batch_id'] !== null ? ' ・ バッチ #' . (int)$upload['mail_batch_id'] : ''; ?> ・ <?php echo mail_h((string)$upload['created_at']); ?></span>
            </span>
            <span class="list-item__side"><?php echo mail_status_badge((string)$upload['status']); ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php
mail_render_page_footer();
