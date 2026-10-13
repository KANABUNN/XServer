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
mail_render_page_header('添付ファイル', $user, 'attachments.php', [
    'subtitle' => '共通添付・個別添付をまとめて登録し、識別番号や manifest.csv で団体に対応付けます',
]);
$canUpload = mail_auth_has_permission($user, 'attachment.upload');
?>
<?php mail_render_db_error($dbError); ?>
<?php if ($dbError === ''): ?>

<div class="split split--wide-left">
  <section class="card">
    <div class="card__header"><div class="card__title"><?php echo fitsc_icon('upload'); ?>まとめてアップロード</div></div>
    <?php if ($batches === []): ?>
      <div class="empty"><span class="empty__icon"><?php echo fitsc_icon('layers'); ?></span><span>送信バッチがありません。先にメールを作成してください。</span><a class="btn btn--sm" href="compose.php"><?php echo fitsc_icon('pen'); ?>メールを作成</a></div>
    <?php else: ?>
      <form method="post" enctype="multipart/form-data" class="card__body stack" data-busy>
        <?php echo mail_auth_csrf_field(); ?>
        <input type="hidden" name="action" value="upload">
        <div class="form-grid">
          <label class="field span-full"><span>対象のバッチ</span>
            <select name="batch_id" required data-autonav="attachments.php?batch_id={value}">
              <?php foreach ($batches as $batch): ?>
                <option value="<?php echo (int)$batch['id']; ?>"<?php echo mail_selected($selectedBatchId, (int)$batch['id']); ?>>#<?php echo (int)$batch['id']; ?> <?php echo mail_h((string)$batch['title']); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="field span-full">
            <span>添付の種類</span>
            <div class="grid grid--2">
              <label class="check-card"><input type="radio" name="upload_kind" value="individual" checked><span><strong>個別添付</strong><small>団体ごとに異なるファイル</small></span></label>
              <label class="check-card"><input type="radio" name="upload_kind" value="common"><span><strong>共通添付</strong><small>全員に同じファイル</small></span></label>
            </div>
          </div>
          <label class="field span-full"><span>ファイル（複数・ZIP 可）</span><input type="file" name="attachments[]" multiple required></label>
        </div>
        <details class="disclosure">
          <summary><?php echo fitsc_icon('help'); ?><span>ファイル名の付け方と manifest.csv</span></summary>
          <div class="disclosure__body stack stack--sm text-sm">
            <p>推奨ファイル名：<code>識別番号__資料種別__団体名.pdf</code>（例：<code>C001__代表者確認書__数学研究会.pdf</code>）</p>
            <p>ZIP に <code>manifest.csv</code> を入れると、<code>identifier,attachment_type,filename</code> 列で対応付けできます。</p>
            <div><button type="button" class="btn btn--sm" data-download-href="./template_file/attachment_manifest_template.csv" data-download-name="attachment_manifest_template.csv"><?php echo fitsc_icon('download'); ?>manifest.csv のひな形</button></div>
          </div>
        </details>
        <div class="form-actions form-actions--end">
          <button type="submit" class="btn btn--primary"<?php echo $canUpload ? '' : ' disabled'; ?>><?php echo fitsc_icon('upload'); ?>アップロードして対応付け</button>
        </div>
      </form>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card__header"><div class="card__title"><?php echo fitsc_icon('layers'); ?>選択中のバッチ</div></div>
    <div class="card__body stack">
      <?php if (!$selectedBatch): ?>
        <p class="small-note">バッチを選択してください。</p>
      <?php else: ?>
        <div>
          <strong><?php echo mail_h((string)$selectedBatch['title']); ?></strong>
          <p class="small-note">#<?php echo (int)$selectedBatch['id']; ?> ・ <?php echo mail_status_label((string)$selectedBatch['status']); ?></p>
        </div>
        <div class="grid grid--2">
          <div class="stat stat--compact stat--teal"><span class="stat__label">共通添付</span><span class="stat__value"><?php echo (int)$selectedBatch['common_attachment_count']; ?></span></div>
          <div class="stat stat--compact stat--info"><span class="stat__label">個別添付</span><span class="stat__value"><?php echo (int)$selectedBatch['individual_attachment_count']; ?></span></div>
        </div>
        <p class="small-note"><?php echo fitsc_icon('info', 'ico--sm'); ?> 要確認の添付が残っていると、Gmail下書きは作成できません。</p>
      <?php endif; ?>
    </div>
  </section>
</div>

<?php if ($selectedBatch): ?>
  <?php if ((int)$selectedBatch['individual_attachment_count'] > 0): ?>
    <section class="card">
      <div class="card__header"><div class="card__title"><?php echo fitsc_icon('alert-triangle'); ?>個別添付がない団体 <small><?php echo count($missingOrgs); ?>件</small></div></div>
      <?php if ($missingOrgs === []): ?>
        <div class="card__body"><div class="alert alert--success"><?php echo fitsc_icon('check-circle'); ?><div class="alert__body">すべての送信対象に、確認済みの個別添付があります。</div></div></div>
      <?php else: ?>
        <div class="card__body"><div class="alert alert--warning"><?php echo fitsc_icon('alert-triangle'); ?><div class="alert__body">これらの団体には確認済みの個別添付がありません。このままでは共通添付のみ（または添付なし）で届きます。</div></div></div>
        <div class="table-wrap is-stacked">
          <table class="table table--compact">
            <thead><tr><th>団体</th><th>宛先</th><th>宛先の状態</th></tr></thead>
            <tbody>
              <?php foreach ($missingOrgs as $org): ?>
                <tr class="is-attention">
                  <td><span class="cell-main"><?php echo mail_h((string)$org['organization_name']); ?></span><span class="cell-sub"><code><?php echo mail_h((string)$org['identifier']); ?></code></span></td>
                  <td class="allow-select"><?php echo mail_h((string)($org['to_email'] ?? $org['email'] ?? '')); ?></td>
                  <td><?php echo mail_status_badge((string)$org['target_status']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <section class="card">
    <div class="card__header"><div class="card__title"><?php echo fitsc_icon('paperclip'); ?>添付の対応一覧 <small><?php echo count($attachments); ?>件</small></div></div>
    <?php if ($attachments === []): ?>
      <div class="empty"><span class="empty__icon"><?php echo fitsc_icon('paperclip'); ?></span><span>添付ファイルはまだ登録されていません。</span></div>
    <?php else: ?>
      <div class="table-wrap is-stacked">
        <table class="table attachment-table">
          <thead><tr><th>種別</th><th>団体</th><th>ファイル</th><th>判定</th><th>状態</th><th class="col-actions">操作</th></tr></thead>
          <tbody>
            <?php foreach ($attachments as $att): ?>
              <tr<?php echo (string)$att['status'] === 'needs_review' ? ' class="is-attention"' : ''; ?>>
                <td><span class="badge<?php echo (int)$att['is_common'] === 1 ? ' badge--teal' : ''; ?>"><?php echo (int)$att['is_common'] === 1 ? '共通' : '個別'; ?></span></td>
                <td><span class="cell-main"><?php echo mail_h((string)$att['organization_name']); ?></span><span class="cell-sub"><code><?php echo mail_h((string)$att['identifier']); ?></code></span></td>
                <td><span class="cell-main"><?php echo mail_h((string)$att['original_name']); ?></span><span class="cell-sub"><?php echo mail_h((string)$att['attachment_type']); ?> ・ <?php echo number_format(((int)$att['file_size']) / 1024, 1); ?> KB</span></td>
                <td><span class="cell-sub"><?php echo mail_h((string)$att['match_method']); ?> ・ <?php echo (int)$att['match_confidence']; ?>%</span></td>
                <td><?php echo mail_status_badge((string)$att['status']); ?></td>
                <td class="col-actions">
                  <div class="ml-att-actions">
                    <?php if ((string)$att['status'] !== 'approved'): ?>
                      <form method="post"><?php echo mail_auth_csrf_field(); ?><input type="hidden" name="action" value="approve"><input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>"><input type="hidden" name="attachment_id" value="<?php echo (int)$att['id']; ?>"><button type="submit" class="btn btn--sm"<?php echo $canUpload ? '' : ' disabled'; ?>><?php echo fitsc_icon('check'); ?>確認済みにする</button></form>
                    <?php endif; ?>
                    <?php if ((string)$att['status'] !== 'excluded'): ?>
                      <form method="post"><?php echo mail_auth_csrf_field(); ?><input type="hidden" name="action" value="exclude"><input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>"><input type="hidden" name="attachment_id" value="<?php echo (int)$att['id']; ?>"><button type="submit" class="btn btn--ghost btn--sm text-danger"<?php echo $canUpload ? '' : ' disabled'; ?>><?php echo fitsc_icon('x'); ?>除外</button></form>
                    <?php endif; ?>
                    <?php if ((int)$att['is_common'] === 0): ?>
                      <form method="post" class="ml-reassign">
                        <?php echo mail_auth_csrf_field(); ?>
                        <input type="hidden" name="action" value="reassign">
                        <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
                        <input type="hidden" name="attachment_id" value="<?php echo (int)$att['id']; ?>">
                        <select name="organization_id" required aria-label="割り当てる団体">
                          <option value="">団体を選択…</option>
                          <?php foreach ($activeOrganizations as $org): ?>
                            <option value="<?php echo (int)$org['id']; ?>"<?php echo mail_selected((int)($att['organization_id'] ?? 0), (int)$org['id']); ?>><?php echo mail_h((string)$org['identifier'] . ' ' . (string)$org['name']); ?></option>
                          <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn--sm"<?php echo $canUpload ? '' : ' disabled'; ?>>割当</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<details class="disclosure">
  <summary><?php echo fitsc_icon('history'); ?><span>アップロード履歴</span><span class="badge"><?php echo count($uploadBatches); ?></span></summary>
  <div class="table-wrap is-stacked">
    <table class="table table--compact">
      <thead><tr><th>ID</th><th>バッチ</th><th>種別</th><th>元ファイル</th><th class="num">数</th><th>状態</th><th>日時</th></tr></thead>
      <tbody>
        <?php if ($uploadBatches === []): ?><tr><td colspan="7" class="empty">履歴はありません。</td></tr><?php endif; ?>
        <?php foreach ($uploadBatches as $upload): ?>
          <tr>
            <td>#<?php echo (int)$upload['id']; ?></td>
            <td><?php echo $upload['mail_batch_id'] ? '#' . (int)$upload['mail_batch_id'] . ' ' . mail_h((string)$upload['batch_title']) : '—'; ?></td>
            <td><?php echo (string)$upload['upload_kind'] === 'common' ? '共通' : '個別'; ?></td>
            <td><?php echo mail_h((string)$upload['original_name']); ?></td>
            <td class="num"><?php echo (int)$upload['file_count']; ?></td>
            <td><?php echo mail_status_badge((string)$upload['status']); ?></td>
            <td><span class="cell-sub nowrap"><?php echo mail_h((string)$upload['created_at']); ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</details>
<?php endif; ?>
<?php
mail_render_page_footer();
