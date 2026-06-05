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

mail_render_page_header('ダッシュボード', $user, 'index.php');
?>
<header class="page-head">
  <div>
    <h1>メール半自動化管理</h1>
    <p class="lead">団体別の差し込みメール、個別添付、Google Workspace SMTP送信を管理する画面です。</p>
  </div>
  <div class="head-actions">
    <a class="secondary link-button" href="./api/health.php" target="_blank" rel="noopener">接続確認</a>
  </div>
</header>

<?php mail_render_db_error($dbError); ?>
<?php if ($dbError === ''): ?>
  <div class="alert alert-info">
    <strong>次段階の管理機能を追加済みです。</strong>
    <p>団体CSV取込、テンプレート作成、送信バッチ作成、添付ファイル一括アップロード、対応付け確認、Google Workspace SMTP送信まで操作できます。Graph連携は将来用の予備経路として残しています。</p>
  </div>
<?php endif; ?>

<div class="summary-grid">
  <article class="summary-card"><span>活動中団体</span><strong><?php echo (int)$counts['organizations']; ?></strong></article>
  <article class="summary-card"><span>テンプレート</span><strong><?php echo (int)$counts['templates']; ?></strong></article>
  <article class="summary-card"><span>送信バッチ</span><strong><?php echo (int)$counts['batches']; ?></strong></article>
  <article class="summary-card"><span>要確認添付</span><strong><?php echo (int)$counts['attachments_need_review']; ?></strong></article>
</div>

<div class="dashboard-grid">
  <section class="panel">
    <div class="panel-head">
      <h2>最近の送信バッチ</h2>
      <a href="batches.php" class="text-link">一覧へ</a>
    </div>
    <?php if ($recentBatches === []): ?>
      <p class="empty">まだ送信バッチはありません。</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>ID</th><th>名称</th><th>状態</th><th>対象数</th><th>添付</th><th>作成日時</th></tr></thead>
          <tbody>
            <?php foreach ($recentBatches as $batch): ?>
              <tr>
                <td><?php echo (int)$batch['id']; ?></td>
                <td><a href="batches.php?batch_id=<?php echo (int)$batch['id']; ?>"><?php echo mail_h((string)$batch['title']); ?></a></td>
                <td><span class="badge"><?php echo mail_h(mail_status_label((string)$batch['status'])); ?></span></td>
                <td><?php echo (int)$batch['target_count']; ?></td>
                <td>共通 <?php echo (int)$batch['common_attachment_count']; ?> / 個別 <?php echo (int)$batch['individual_attachment_count']; ?></td>
                <td><?php echo mail_h((string)$batch['created_at']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="panel">
    <div class="panel-head">
      <h2>最近の添付アップロード</h2>
      <a href="attachments.php" class="text-link">一覧へ</a>
    </div>
    <?php if ($recentUploads === []): ?>
      <p class="empty">まだアップロード履歴はありません。</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>ID</th><th>バッチ</th><th>種別</th><th>数</th><th>状態</th><th>作成日時</th></tr></thead>
          <tbody>
            <?php foreach ($recentUploads as $upload): ?>
              <tr>
                <td><?php echo (int)$upload['id']; ?></td>
                <td><?php echo $upload['mail_batch_id'] !== null ? (int)$upload['mail_batch_id'] : '-'; ?></td>
                <td><?php echo mail_h((string)$upload['upload_kind']); ?></td>
                <td><?php echo (int)$upload['file_count']; ?></td>
                <td><span class="badge"><?php echo mail_h(mail_status_label((string)$upload['status'])); ?></span></td>
                <td><?php echo mail_h((string)$upload['created_at']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<section class="panel mt-18">
  <h2>次の操作</h2>
  <ol class="flow-list">
    <li><a href="organizations.php">団体データ</a>でCSV取込または手入力を行う。</li>
    <li><a href="templates.php">テンプレート</a>で件名・本文と変数を作る。</li>
    <li><a href="batches.php">送信バッチ</a>で対象団体へ本文を展開する。</li>
    <li><a href="attachments.php">添付ファイル</a>で共通添付・個別添付を一括登録する。</li>
    <li><a href="delivery.php">GW SMTP送信</a>でSMTP接続確認後、承認済みバッチを送信する。</li>
  </ol>
</section>
<?php
mail_render_page_footer();
