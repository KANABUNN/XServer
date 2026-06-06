<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
[$user, $mailPdo, $dbError] = mail_app_init();
$selectedBatchId = (int)($_GET['batch_id'] ?? 0);

function mail_lookup_account_labels_for_batches(array $batches): array
{
    $ids = [];
    foreach ($batches as $batch) {
        $id = (int)($batch['created_by_account_id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if ($ids === []) {
        return [];
    }

    try {
        $accountPdo = mail_pdo('account');
        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $i => $id) {
            $key = ':id_' . $i;
            $placeholders[] = $key;
            $params[$key] = $id;
        }
        $stmt = $accountPdo->prepare('SELECT id, login_id, display_name FROM shared_accounts WHERE id IN (' . implode(',', $placeholders) . ')');
        $stmt->execute($params);
        $labels = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $display = trim((string)($row['display_name'] ?? ''));
            $login = trim((string)($row['login_id'] ?? ''));
            $labels[(int)$row['id']] = $display !== '' ? $display . ($login !== '' ? ' / ' . $login : '') : $login;
        }
        return $labels;
    } catch (Throwable $e) {
        return [];
    }
}


function mail_delete_batch(PDO $pdo, int $batchId, array $actor): array
{
    if ($batchId <= 0) {
        throw new InvalidArgumentException('削除するバッチを選択してください。');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM mail_batches WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $batchId]);
        $batch = $stmt->fetch();
        if (!$batch) {
            throw new InvalidArgumentException('対象のバッチが見つかりません。');
        }

        $status = (string)($batch['status'] ?? '');
        if (in_array($status, ['draft_created', 'sent'], true)) {
            throw new RuntimeException('Gmail下書き作成済み、または送信済みのバッチは削除できません。Gmail側の下書きや送信履歴と不整合になるためです。');
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM mail_batch_targets WHERE batch_id = :id');
        $stmt->execute([':id' => $batchId]);
        $targetCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM mail_attachments WHERE mail_batch_id = :id');
        $stmt->execute([':id' => $batchId]);
        $attachmentCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM mail_batch_targets WHERE batch_id = :id AND (status = "draft_created" OR (graph_message_id IS NOT NULL AND graph_message_id <> ""))');
        $stmt->execute([':id' => $batchId]);
        $draftCreatedTargetCount = (int)$stmt->fetchColumn();
        if ($draftCreatedTargetCount > 0) {
            throw new RuntimeException('Gmail下書きIDを持つ対象が含まれるため、このバッチは削除できません。');
        }

        mail_audit_log($pdo, $actor, 'mail.batch.delete', 'mail_batch', (string)$batchId, [
            'title' => (string)($batch['title'] ?? ''),
            'status' => $status,
            'target_count' => $targetCount,
            'attachment_count' => $attachmentCount,
        ]);

        $stmt = $pdo->prepare('DELETE FROM mail_batches WHERE id = :id');
        $stmt->execute([':id' => $batchId]);

        $pdo->commit();
        return [
            'title' => (string)($batch['title'] ?? ''),
            'target_count' => $targetCount,
            'attachment_count' => $attachmentCount,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function mail_batch_creator_label(array $batch, array $creatorLabels): string
{
    $id = (int)($batch['created_by_account_id'] ?? 0);
    if ($id <= 0) {
        return '不明';
    }
    return $creatorLabels[$id] ?? ('アカウントID: ' . $id);
}

if ($mailPdo instanceof PDO && $dbError === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    mail_auth_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'status') {
            mail_require_permission_or_forbid($user, 'batch.edit');
            $batchId = (int)($_POST['batch_id'] ?? 0);
            $status = (string)($_POST['status'] ?? 'prepared');
            if (!in_array($status, ['prepared', 'reviewing', 'approved', 'cancelled'], true)) {
                throw new InvalidArgumentException('この画面から設定できない状態です。');
            }
            mail_update_batch_status($mailPdo, $batchId, $status, $user);
            mail_flash_set('info', 'バッチ状態を更新しました。');
            mail_redirect('batches.php?batch_id=' . $batchId);
        }

        if ($action === 'delete') {
            mail_require_permission_or_forbid($user, 'batch.edit');
            $batchId = (int)($_POST['batch_id'] ?? 0);
            $confirmBatchId = trim((string)($_POST['confirm_batch_id'] ?? ''));
            if ($confirmBatchId !== (string)$batchId) {
                throw new InvalidArgumentException('削除確認として、対象バッチIDを正しく入力してください。');
            }
            $result = mail_delete_batch($mailPdo, $batchId, $user);
            mail_flash_set('info', '送信バッチ「' . $result['title'] . '」を削除しました。対象メール ' . $result['target_count'] . '件、添付対応 ' . $result['attachment_count'] . '件も削除されています。');
            mail_redirect('batches.php');
        }
    } catch (Throwable $e) {
        mail_flash_set('danger', $e->getMessage());
        mail_redirect('batches.php');
    }
}

$batches = [];
$creatorLabels = [];
$selectedBatch = null;
$targets = [];
$attachments = [];
if ($mailPdo instanceof PDO && $dbError === '') {
    $batches = mail_list_batches($mailPdo, 150);
    $creatorLabels = mail_lookup_account_labels_for_batches($batches);
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
    <p class="lead">作成済みの送信バッチを確認し、対象別プレビュー・添付確認・状態更新を行います。</p>
  </div>
  <div class="head-actions"><a class="link-button primary" href="compose.php">新規メール作成</a></div>
</header>
<?php mail_render_db_error($dbError); ?>

<?php if ($dbError === ''): ?>
<div class="two-column-grid batch-grid">
  <section class="panel">
    <div class="panel-head"><h2>バッチ一覧</h2><span class="muted"><?php echo count($batches); ?>件</span></div>
    <div class="table-wrap compact-table">
      <table>
        <thead><tr><th>ID</th><th>名称</th><th>状態</th><th>対象</th><th>作成者</th></tr></thead>
        <tbody>
          <?php if ($batches === []): ?><tr><td colspan="5" class="empty">バッチはまだありません。</td></tr><?php endif; ?>
          <?php foreach ($batches as $batch): ?>
            <tr class="<?php echo (int)$batch['id'] === $selectedBatchId ? 'is-selected-row' : ''; ?>">
              <td><?php echo (int)$batch['id']; ?></td>
              <td><a href="batches.php?batch_id=<?php echo (int)$batch['id']; ?>"><?php echo mail_h((string)$batch['title']); ?></a></td>
              <td><span class="badge"><?php echo mail_h(mail_status_label((string)$batch['status'])); ?></span></td>
              <td><?php echo (int)$batch['target_count']; ?></td>
              <td><?php echo mail_h(mail_batch_creator_label($batch, $creatorLabels)); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>選択中バッチ詳細</h2><span class="muted"><?php echo $selectedBatch ? '#' . (int)$selectedBatch['id'] : ''; ?></span></div>
    <?php if (!$selectedBatch): ?>
      <p class="empty">バッチを選択してください。</p>
    <?php else: ?>
      <dl class="detail-list">
        <div><dt>バッチ名</dt><dd><?php echo mail_h((string)$selectedBatch['title']); ?></dd></div>
        <div><dt>作成者</dt><dd><?php echo mail_h(mail_batch_creator_label($selectedBatch, $creatorLabels)); ?></dd></div>
        <div><dt>作成日時</dt><dd><?php echo mail_h((string)$selectedBatch['created_at']); ?></dd></div>
        <div><dt>対象数</dt><dd><?php echo (int)$selectedBatch['target_count']; ?>件</dd></div>
        <div><dt>添付</dt><dd>共通 <?php echo (int)$selectedBatch['common_attachment_count']; ?>件 / 個別 <?php echo (int)$selectedBatch['individual_attachment_count']; ?>件</dd></div>
        <div><dt>状態</dt><dd><span class="badge"><?php echo mail_h(mail_status_label((string)$selectedBatch['status'])); ?></span></dd></div>
      </dl>
      <form method="post" class="inline-actions mt-14">
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
      <div class="form-actions mt-14">
        <a class="link-button secondary" href="attachments.php?batch_id=<?php echo (int)$selectedBatch['id']; ?>">添付を登録・確認</a>
        <a class="link-button secondary" href="drafts.php?batch_id=<?php echo (int)$selectedBatch['id']; ?>">Gmail下書き作成</a>
      </div>

      <?php $canDeleteBatch = mail_auth_has_permission($user, 'batch.edit') && !in_array((string)$selectedBatch['status'], ['draft_created', 'sent'], true); ?>
      <form method="post" class="panel-subform danger-zone mt-14" data-delete-batch-form>
        <?php echo mail_auth_csrf_field(); ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
        <h3>このバッチを削除</h3>
        <p class="muted">削除すると、このバッチの送信対象メールと添付対応情報も削除されます。アップロード済みファイル本体と送信ログは保持されます。</p>
        <?php if (in_array((string)$selectedBatch['status'], ['draft_created', 'sent'], true)): ?>
          <div class="alert alert-warn">Gmail下書き作成済み、または送信済みのバッチは、Gmail側や送信履歴との不整合を避けるため削除できません。</div>
        <?php endif; ?>
        <label><span class="muted">削除確認: バッチID <strong>#<?php echo (int)$selectedBatch['id']; ?></strong> を入力</span><input type="text" name="confirm_batch_id" inputmode="numeric" autocomplete="off" placeholder="<?php echo (int)$selectedBatch['id']; ?>"<?php echo $canDeleteBatch ? '' : ' disabled'; ?>></label>
        <button type="submit" class="danger"<?php echo $canDeleteBatch ? '' : ' disabled'; ?>>送信バッチを削除</button>
      </form>
    <?php endif; ?>
  </section>
</div>

<?php if ($selectedBatch): ?>
<div class="summary-grid small mt-18">
  <article class="summary-card"><span>対象メール</span><strong><?php echo (int)$selectedBatch['target_count']; ?></strong></article>
  <article class="summary-card"><span>共通添付</span><strong><?php echo (int)$selectedBatch['common_attachment_count']; ?></strong></article>
  <article class="summary-card"><span>個別添付</span><strong><?php echo (int)$selectedBatch['individual_attachment_count']; ?></strong></article>
  <article class="summary-card"><span>状態</span><strong class="small-strong"><?php echo mail_h(mail_status_label((string)$selectedBatch['status'])); ?></strong></article>
</div>

<section class="panel mt-18">
  <div class="panel-head"><h2>送信対象別プレビュー</h2><span class="muted"><?php echo count($targets); ?>件</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>識別番号</th><th>団体</th><th>宛先</th><th>件名</th><th>状態</th><th>操作</th></tr></thead>
      <tbody>
        <?php if ($targets === []): ?><tr><td colspan="6" class="empty">対象がありません。</td></tr><?php endif; ?>
        <?php foreach ($targets as $target): ?>
          <tr>
            <td><code><?php echo mail_h((string)$target['identifier']); ?></code></td>
            <td><?php echo mail_h((string)$target['organization_name']); ?></td>
            <td><?php echo mail_h((string)$target['to_email']); ?></td>
            <td><?php echo mail_h((string)$target['rendered_subject']); ?></td>
            <td><span class="badge"><?php echo mail_h(mail_status_label((string)$target['status'])); ?></span><?php if (!empty($target['error_message'])): ?><br><span class="danger-text"><?php echo mail_h((string)$target['error_message']); ?></span><?php endif; ?></td>
            <td><button type="button" class="secondary" data-preview-open data-preview-org="<?php echo mail_h((string)$target['organization_name']); ?>" data-preview-to="<?php echo mail_h((string)$target['to_email']); ?>" data-preview-subject="<?php echo mail_h((string)$target['rendered_subject']); ?>" data-preview-body="<?php echo mail_h((string)$target['rendered_body']); ?>">プレビュー</button></td>
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

<div class="modal-backdrop" id="previewModal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="previewModalTitle">
    <div class="modal-head">
      <div>
        <h2 id="previewModalTitle">メールプレビュー</h2>
        <p class="muted" id="previewMeta"></p>
      </div>
      <button type="button" class="text-button modal-close" data-preview-close>閉じる</button>
    </div>
    <dl class="detail-list">
      <div><dt>件名</dt><dd id="previewSubject"></dd></div>
    </dl>
    <pre class="mail-preview-body" id="previewBody"></pre>
  </div>
</div>

<script>
(function () {
  const modal = document.getElementById('previewModal');
  const meta = document.getElementById('previewMeta');
  const subject = document.getElementById('previewSubject');
  const body = document.getElementById('previewBody');
  document.querySelectorAll('[data-preview-open]').forEach(button => {
    button.addEventListener('click', function () {
      meta.textContent = `${button.dataset.previewOrg || ''} / ${button.dataset.previewTo || ''}`;
      subject.textContent = button.dataset.previewSubject || '';
      body.textContent = button.dataset.previewBody || '';
      modal.hidden = false;
    });
  });
  document.querySelector('[data-preview-close]')?.addEventListener('click', function () {
    modal.hidden = true;
  });
  modal?.addEventListener('click', function (event) {
    if (event.target === modal) modal.hidden = true;
  });

  document.querySelectorAll('[data-delete-batch-form]').forEach(form => {
    form.addEventListener('submit', function (event) {
      const batchId = form.querySelector('input[name="batch_id"]')?.value || '';
      const confirmed = window.confirm('送信バッチ #' + batchId + ' を削除します。対象メールと添付対応情報も削除されます。実行してよろしいですか？');
      if (!confirmed) {
        event.preventDefault();
      }
    });
  });
})();
</script>
<?php endif; ?>
<?php endif; ?>
<?php
mail_render_page_footer();
