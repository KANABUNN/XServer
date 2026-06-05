<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
[$user, $mailPdo, $dbError] = mail_app_init();
$selectedBatchId = (int)($_GET['batch_id'] ?? 0);

if ($mailPdo instanceof PDO && $dbError === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    mail_auth_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            mail_require_permission_or_forbid($user, 'batch.edit');
            $batchId = mail_create_batch_from_template($mailPdo, (int)($_POST['template_id'] ?? 0), (string)($_POST['title'] ?? ''), $user);
            mail_flash_set('info', '送信バッチを作成しました。本文は団体ごとに展開済みです。');
            mail_redirect('batches.php?batch_id=' . $batchId);
        } elseif ($action === 'status') {
            mail_require_permission_or_forbid($user, 'batch.edit');
            $batchId = (int)($_POST['batch_id'] ?? 0);
            $status = (string)($_POST['status'] ?? 'prepared');
            mail_update_batch_status($mailPdo, $batchId, $status, $user);
            mail_flash_set('info', 'バッチ状態を更新しました。');
            mail_redirect('batches.php?batch_id=' . $batchId);
        }
    } catch (Throwable $e) {
        mail_flash_set('danger', $e->getMessage());
        mail_redirect('batches.php');
    }
}

$templates = [];
$batches = [];
$selectedBatch = null;
$targets = [];
$attachments = [];
if ($mailPdo instanceof PDO && $dbError === '') {
    $templates = mail_list_templates($mailPdo, true);
    $batches = mail_list_batches($mailPdo, 150);
    if ($selectedBatchId <= 0 && $batches !== []) {
        $selectedBatchId = (int)$batches[0]['id'];
    }
    if ($selectedBatchId > 0) {
        $selectedBatch = mail_get_batch($mailPdo, $selectedBatchId);
        if ($selectedBatch) {
            $targets = mail_list_batch_targets($mailPdo, $selectedBatchId, 1000);
            $attachments = mail_list_batch_attachments($mailPdo, $selectedBatchId);
        }
    }
}

mail_render_page_header('送信バッチ', $user, 'batches.php');
?>
<header class="page-head">
  <div>
    <h1>送信バッチ</h1>
    <p class="lead">テンプレートと団体DBから、団体ごとの件名・本文を確定した処理単位を作成します。</p>
  </div>
</header>
<?php mail_render_db_error($dbError); ?>

<?php if ($dbError === ''): ?>
<div class="two-column-grid">
  <section class="panel">
    <div class="panel-head"><h2>新規バッチ作成</h2><span class="muted">有効な団体すべてを対象</span></div>
    <?php if ($templates === []): ?>
      <p class="empty">有効なテンプレートがありません。先にテンプレートを作成してください。</p>
    <?php else: ?>
      <form method="post" class="form-grid">
        <?php echo mail_auth_csrf_field(); ?>
        <input type="hidden" name="action" value="create">
        <label class="full"><span>バッチ名 *</span><input type="text" name="title" placeholder="例: 2026年度 公認団体資料送付" required></label>
        <label class="full"><span>使用テンプレート *</span><select name="template_id" required><?php foreach ($templates as $tpl): ?><option value="<?php echo (int)$tpl['id']; ?>"><?php echo mail_h((string)$tpl['title']); ?></option><?php endforeach; ?></select></label>
        <div class="form-actions full"><button type="submit" class="primary"<?php echo mail_auth_has_permission($user, 'batch.edit') ? '' : ' disabled'; ?>>バッチを作成</button></div>
      </form>
    <?php endif; ?>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>バッチ一覧</h2><span class="muted"><?php echo count($batches); ?>件</span></div>
    <div class="table-wrap compact-table">
      <table>
        <thead><tr><th>ID</th><th>名称</th><th>状態</th><th>対象</th></tr></thead>
        <tbody>
          <?php if ($batches === []): ?><tr><td colspan="4" class="empty">バッチはまだありません。</td></tr><?php endif; ?>
          <?php foreach ($batches as $batch): ?>
            <tr class="<?php echo (int)$batch['id'] === $selectedBatchId ? 'is-selected-row' : ''; ?>">
              <td><?php echo (int)$batch['id']; ?></td>
              <td><a href="batches.php?batch_id=<?php echo (int)$batch['id']; ?>"><?php echo mail_h((string)$batch['title']); ?></a><br><span class="muted"><?php echo mail_h((string)$batch['template_title']); ?></span></td>
              <td><span class="badge"><?php echo mail_h(mail_status_label((string)$batch['status'])); ?></span></td>
              <td><?php echo (int)$batch['target_count']; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<?php if ($selectedBatch): ?>
<section class="panel mt-18">
  <div class="panel-head">
    <div><h2><?php echo mail_h((string)$selectedBatch['title']); ?></h2><p class="muted">テンプレート: <?php echo mail_h((string)$selectedBatch['template_title']); ?> / 作成日時: <?php echo mail_h((string)$selectedBatch['created_at']); ?></p></div>
    <form method="post" class="inline-actions">
      <?php echo mail_auth_csrf_field(); ?>
      <input type="hidden" name="action" value="status">
      <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
      <select name="status">
        <?php foreach (['prepared' => '準備済み', 'reviewing' => '確認中', 'approved' => '確認済み', 'cancelled' => '取消'] as $key => $label): ?>
          <option value="<?php echo mail_h($key); ?>"<?php echo mail_selected($selectedBatch['status'], $key); ?>><?php echo mail_h($label); ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="secondary"<?php echo mail_auth_has_permission($user, 'batch.edit') ? '' : ' disabled'; ?>>状態更新</button>
    </form>
  </div>
  <div class="summary-grid small">
    <article class="summary-card"><span>対象メール</span><strong><?php echo (int)$selectedBatch['target_count']; ?></strong></article>
    <article class="summary-card"><span>共通添付</span><strong><?php echo (int)$selectedBatch['common_attachment_count']; ?></strong></article>
    <article class="summary-card"><span>個別添付</span><strong><?php echo (int)$selectedBatch['individual_attachment_count']; ?></strong></article>
    <article class="summary-card"><span>状態</span><strong class="small-strong"><?php echo mail_h(mail_status_label((string)$selectedBatch['status'])); ?></strong></article>
  </div>
  <p class="muted">承認済みのバッチは、Graph下書き画面からOutlook下書きとして作成できます。</p>
  <p><a class="text-link" href="graph.php?batch_id=<?php echo (int)$selectedBatch['id']; ?>">Graph下書き作成へ進む</a></p>
</section>

<section class="panel mt-18">
  <div class="panel-head"><h2>生成済みメールプレビュー</h2><span class="muted"><?php echo count($targets); ?>件</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>識別番号</th><th>団体</th><th>宛先</th><th>件名</th><th>状態</th><th>本文冒頭</th></tr></thead>
      <tbody>
        <?php if ($targets === []): ?><tr><td colspan="6" class="empty">対象がありません。</td></tr><?php endif; ?>
        <?php foreach ($targets as $target): ?>
          <tr>
            <td><code><?php echo mail_h((string)$target['identifier']); ?></code></td>
            <td><?php echo mail_h((string)$target['organization_name']); ?></td>
            <td><?php echo mail_h((string)$target['to_email']); ?></td>
            <td><?php echo mail_h((string)$target['rendered_subject']); ?></td>
            <td><span class="badge"><?php echo mail_h(mail_status_label((string)$target['status'])); ?></span><?php if (!empty($target['error_message'])): ?><br><span class="danger-text"><?php echo mail_h((string)$target['error_message']); ?></span><?php endif; ?></td>
            <td><pre class="preview-snippet"><?php echo mail_h(mb_substr((string)$target['rendered_body'], 0, 180)); ?></pre></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel mt-18">
  <div class="panel-head"><h2>添付対応状況</h2><a href="attachments.php?batch_id=<?php echo (int)$selectedBatch['id']; ?>" class="text-link">添付を登録する</a></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>種別</th><th>識別番号</th><th>団体</th><th>ファイル名</th><th>資料種別</th><th>判定</th><th>状態</th></tr></thead>
      <tbody>
        <?php if ($attachments === []): ?><tr><td colspan="7" class="empty">添付ファイルはまだ登録されていません。</td></tr><?php endif; ?>
        <?php foreach ($attachments as $att): ?>
          <tr>
            <td><?php echo (int)$att['is_common'] === 1 ? '共通' : '個別'; ?></td>
            <td><code><?php echo mail_h((string)$att['identifier']); ?></code></td>
            <td><?php echo mail_h((string)$att['organization_name']); ?></td>
            <td><?php echo mail_h((string)$att['original_name']); ?></td>
            <td><?php echo mail_h((string)$att['attachment_type']); ?></td>
            <td><?php echo mail_h((string)$att['match_method']); ?> / <?php echo (int)$att['match_confidence']; ?>%</td>
            <td><span class="badge"><?php echo mail_h(mail_status_label((string)$att['status'])); ?></span></td>
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
