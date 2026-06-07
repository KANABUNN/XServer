<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../../apps/mail_core/upload_service.php';
[$user, $mailPdo, $dbError] = mail_app_init();
$selectedBatchId = (int)($_GET['batch_id'] ?? ($_POST['batch_id'] ?? 0));

if ($mailPdo instanceof PDO && $dbError === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
        $postMaxSize = ini_get('post_max_size') ?: '不明';
        $uploadMaxSize = ini_get('upload_max_filesize') ?: '不明';
        mail_flash_set('danger', '添付の合計サイズがPHPの上限を超えている可能性があります。post_max_size=' . $postMaxSize . ' / upload_max_filesize=' . $uploadMaxSize . ' を確認のうえ、分割してアップロードしてください。');
        mail_redirect('attachments.php' . ($selectedBatchId > 0 ? '?batch_id=' . $selectedBatchId : ''));
    }
    mail_auth_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'upload') {
            mail_require_permission_or_forbid($user, 'attachment.upload');
            $result = mail_handle_attachment_upload($mailPdo, (int)$_POST['batch_id'], (string)$_POST['upload_kind'], $_FILES['attachments'] ?? [], $user);
            $message = '添付ファイルを登録しました。保存 ' . $result['saved'] . '件、要確認 ' . $result['needs_review'] . '件。';
            mail_flash_set($result['needs_review'] > 0 ? 'warn' : 'info', $message);
            mail_redirect('attachments.php?batch_id=' . (int)$_POST['batch_id']);
        } elseif ($action === 'approve') {
            mail_require_permission_or_forbid($user, 'attachment.upload');
            $id = (int)($_POST['attachment_id'] ?? 0);
            mail_approve_attachment($mailPdo, $id, $user);
            mail_refresh_batch_attachment_counts($mailPdo, (int)$_POST['batch_id']);
            mail_flash_set('info', '添付ファイルを確認済みにしました。');
            mail_redirect('attachments.php?batch_id=' . (int)$_POST['batch_id']);
        } elseif ($action === 'exclude') {
            mail_require_permission_or_forbid($user, 'attachment.upload');
            $id = (int)($_POST['attachment_id'] ?? 0);
            mail_exclude_attachment($mailPdo, $id, $user);
            mail_refresh_batch_attachment_counts($mailPdo, (int)$_POST['batch_id']);
            mail_flash_set('info', '添付ファイルを除外しました。');
            mail_redirect('attachments.php?batch_id=' . (int)$_POST['batch_id']);
        } elseif ($action === 'reassign') {
          mail_require_permission_or_forbid($user, 'attachment.upload');
          $id = (int)($_POST['attachment_id'] ?? 0);
          $organizationId = (int)($_POST['organization_id'] ?? 0);
          mail_reassign_attachment($mailPdo, $id, $organizationId, $user);
          mail_refresh_batch_attachment_counts($mailPdo, (int)$_POST['batch_id']);
          mail_flash_set('info', '添付の割当先団体を更新し、確認済みにしました。');
          mail_redirect('attachments.php?batch_id=' . (int)$_POST['batch_id']);
        }
    } catch (Throwable $e) {
        mail_flash_set('danger', mail_user_safe_error_message($e));
        mail_redirect('attachments.php' . ($selectedBatchId > 0 ? '?batch_id=' . $selectedBatchId : ''));
    }
}

$batches = [];
$selectedBatch = null;
$attachments = [];
$uploadBatches = [];
$activeOrganizations = [];
$missingOrgs = [];
if ($mailPdo instanceof PDO && $dbError === '') {
    $batches = mail_list_batches($mailPdo, 200);
    if ($selectedBatchId <= 0 && $batches !== []) {
        $selectedBatchId = (int)$batches[0]['id'];
    }
    if ($selectedBatchId > 0) {
        $selectedBatch = mail_get_batch($mailPdo, $selectedBatchId);
        if ($selectedBatch) {
            $attachments = mail_list_batch_attachments($mailPdo, $selectedBatchId);
            $missingOrgs = mail_list_orgs_missing_individual_attachment($mailPdo, $selectedBatchId);
        }
    }
    $uploadBatches = mail_list_upload_batches($mailPdo, 80);
    $activeOrganizations = mail_list_active_organizations($mailPdo);
}

mail_render_page_header('添付ファイル', $user, 'attachments.php');
?>
<header class="page-head">
  <div>
    <h1>添付ファイル</h1>
    <p class="lead">共通添付と個別添付を一括登録し、識別番号または manifest.csv で団体へ対応付けます。</p>
  </div>
</header>
<?php mail_render_db_error($dbError); ?>

<?php if ($dbError === ''): ?>
<div class="two-column-grid">
  <section class="panel">
    <div class="panel-head"><h2>一括アップロード</h2><span class="muted">複数ファイル・ZIP対応</span></div>
    <?php if ($batches === []): ?>
      <p class="empty">送信バッチがありません。先に送信バッチを作成してください。</p>
    <?php else: ?>
      <form method="post" enctype="multipart/form-data" class="form-grid">
        <?php echo mail_auth_csrf_field(); ?>
        <input type="hidden" name="action" value="upload">
        <label class="full"><span>対象バッチ</span><select name="batch_id" required onchange="location.href='attachments.php?batch_id=' + encodeURIComponent(this.value)"><?php foreach ($batches as $batch): ?><option value="<?php echo (int)$batch['id']; ?>"<?php echo mail_selected($selectedBatchId, (int)$batch['id']); ?>>#<?php echo (int)$batch['id']; ?> <?php echo mail_h((string)$batch['title']); ?></option><?php endforeach; ?></select></label>
        <label><span>添付種別</span><select name="upload_kind"><option value="individual">個別添付</option><option value="common">共通添付</option></select></label>
        <label><span>ファイル</span><input type="file" name="attachments[]" multiple required></label>
        <div class="full help-box">
          <strong>推奨ファイル名</strong>
          <p><code>識別番号__資料種別__団体名.pdf</code> 例: <code>C001__代表者確認書__数学研究会.pdf</code></p>
          <p>ZIP内に <code>manifest.csv</code> を入れる場合は、<code>identifier,attachment_type,filename</code> の列を使用できます。</p>
          <div class="template-downloads mt-14">
            <button type="button" class="link-button secondary" data-download-href="./template_file/attachment_manifest_template.csv" data-download-name="attachment_manifest_template.csv">manifest.csvテンプレートをダウンロード</button>
          </div>
        </div>
        <div class="form-actions full"><button type="submit" class="primary"<?php echo mail_auth_has_permission($user, 'attachment.upload') ? '' : ' disabled'; ?>>アップロードして対応付け</button></div>
      </form>
    <?php endif; ?>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>現在のバッチ</h2><span class="muted">添付数</span></div>
    <?php if (!$selectedBatch): ?>
      <p class="empty">バッチを選択してください。</p>
    <?php else: ?>
      <p><strong>#<?php echo (int)$selectedBatch['id']; ?> <?php echo mail_h((string)$selectedBatch['title']); ?></strong></p>
      <div class="summary-grid small two">
        <article class="summary-card"><span>共通添付</span><strong><?php echo (int)$selectedBatch['common_attachment_count']; ?></strong></article>
        <article class="summary-card"><span>個別添付</span><strong><?php echo (int)$selectedBatch['individual_attachment_count']; ?></strong></article>
      </div>
      <p class="muted">要確認が残っている場合、送信へ進めない運用にします。</p>
    <?php endif; ?>
  </section>
</div>

<?php if ($selectedBatch): ?>
<?php if ((int)$selectedBatch['individual_attachment_count'] > 0): ?>
<section class="panel mt-18">
  <div class="panel-head"><h2>承認済みの個別添付が無い団体</h2><span class="muted"><?php echo count($missingOrgs); ?>件</span></div>
  <?php if ($missingOrgs === []): ?>
    <p class="empty">全ての送信対象団体に、承認済みの個別添付が1件以上あります。</p>
  <?php else: ?>
    <div class="alert alert-warn">これらの団体には承認済みの個別添付がありません。このまま送信すると共通添付のみ（または添付なし）で届きます。</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>識別番号</th><th>団体</th><th>宛先</th><th>宛先状態</th></tr></thead>
        <tbody>
          <?php foreach ($missingOrgs as $org): ?>
            <tr class="needs-review-row">
              <td><code><?php echo mail_h((string)$org['identifier']); ?></code></td>
              <td><?php echo mail_h((string)$org['organization_name']); ?></td>
              <td><?php echo mail_h((string)($org['to_email'] ?? $org['email'] ?? '')); ?></td>
              <td><span class="badge"><?php echo mail_h(mail_status_label((string)$org['target_status'])); ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>
<section class="panel mt-18">
  <div class="panel-head"><h2>添付対応一覧</h2><span class="muted"><?php echo count($attachments); ?>件</span></div>
  <div class="table-wrap attachment-table-wrap">
    <table class="attachment-table">
      <thead><tr><th>種別</th><th>識別番号</th><th>団体</th><th>ファイル名</th><th>資料種別</th><th>判定方法</th><th>状態</th><th>操作</th></tr></thead>
      <tbody>
        <?php if ($attachments === []): ?><tr><td colspan="8" class="empty">添付ファイルはまだ登録されていません。</td></tr><?php endif; ?>
        <?php foreach ($attachments as $att): ?>
          <tr class="<?php echo (string)$att['status'] === 'needs_review' ? 'needs-review-row' : ''; ?>">
            <td><?php echo (int)$att['is_common'] === 1 ? '共通' : '個別'; ?></td>
            <td><code><?php echo mail_h((string)$att['identifier']); ?></code></td>
            <td><?php echo mail_h((string)$att['organization_name']); ?></td>
            <td><?php echo mail_h((string)$att['original_name']); ?><br><span class="muted"><?php echo number_format(((int)$att['file_size']) / 1024, 1); ?> KB</span></td>
            <td><?php echo mail_h((string)$att['attachment_type']); ?></td>
            <td><?php echo mail_h((string)$att['match_method']); ?> / <?php echo (int)$att['match_confidence']; ?>%</td>
            <td><span class="badge"><?php echo mail_h(mail_status_label((string)$att['status'])); ?></span></td>
            <td class="action-cell attachment-action-cell">
              <?php if ((string)$att['status'] !== 'approved'): ?>
                <form method="post" class="inline-form"><?php echo mail_auth_csrf_field(); ?><input type="hidden" name="action" value="approve"><input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>"><input type="hidden" name="attachment_id" value="<?php echo (int)$att['id']; ?>"><button type="submit" class="text-button"<?php echo mail_auth_has_permission($user, 'attachment.upload') ? '' : ' disabled'; ?>>確認済み</button></form>
              <?php endif; ?>
              <?php if ((string)$att['status'] !== 'excluded'): ?>
                <form method="post" class="inline-form"><?php echo mail_auth_csrf_field(); ?><input type="hidden" name="action" value="exclude"><input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>"><input type="hidden" name="attachment_id" value="<?php echo (int)$att['id']; ?>"><button type="submit" class="text-button danger-text"<?php echo mail_auth_has_permission($user, 'attachment.upload') ? '' : ' disabled'; ?>>除外</button></form>
              <?php endif; ?>
              <?php if ((int)$att['is_common'] === 0): ?>
                <form method="post" class="inline-form reassign-form">
                  <?php echo mail_auth_csrf_field(); ?>
                  <input type="hidden" name="action" value="reassign">
                  <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
                  <input type="hidden" name="attachment_id" value="<?php echo (int)$att['id']; ?>">
                  <select name="organization_id" required>
                    <option value="">団体を選択…</option>
                    <?php foreach ($activeOrganizations as $org): ?>
                      <option value="<?php echo (int)$org['id']; ?>"<?php echo mail_selected((int)($att['organization_id'] ?? 0), (int)$org['id']); ?>><?php echo mail_h((string)$org['identifier'] . ' ' . (string)$org['name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="text-button"<?php echo mail_auth_has_permission($user, 'attachment.upload') ? '' : ' disabled'; ?>>団体を割当</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<section class="panel mt-18">
  <div class="panel-head"><h2>アップロード履歴</h2><span class="muted"><?php echo count($uploadBatches); ?>件</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>バッチ</th><th>種別</th><th>元ファイル</th><th>数</th><th>状態</th><th>作成日時</th></tr></thead>
      <tbody>
        <?php if ($uploadBatches === []): ?><tr><td colspan="7" class="empty">履歴はありません。</td></tr><?php endif; ?>
        <?php foreach ($uploadBatches as $upload): ?>
          <tr>
            <td><?php echo (int)$upload['id']; ?></td>
            <td><?php echo $upload['mail_batch_id'] ? '#' . (int)$upload['mail_batch_id'] . ' ' . mail_h((string)$upload['batch_title']) : '-'; ?></td>
            <td><?php echo mail_h((string)$upload['upload_kind']); ?></td>
            <td><?php echo mail_h((string)$upload['original_name']); ?></td>
            <td><?php echo (int)$upload['file_count']; ?></td>
            <td><span class="badge"><?php echo mail_h(mail_status_label((string)$upload['status'])); ?></span></td>
            <td><?php echo mail_h((string)$upload['created_at']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
<?php
mail_render_page_footer();
