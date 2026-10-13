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
        mail_flash_set('danger', mail_user_safe_error_message($e));
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
mail_render_page_header('送信バッチ', $user, 'batches.php', [
    'subtitle' => '宛先ごとの本文プレビュー・添付の確認・状態の更新',
    'actions' => '<a class="btn btn--primary" href="compose.php">' . fitsc_icon('plus') . '<span class="hide-mobile">新しいメール</span></a>',
]);
$canEditBatch = mail_auth_has_permission($user, 'batch.edit');
?>
<?php mail_render_db_error($dbError); ?>

<?php if ($dbError === ''): ?>
<div class="split split--sidebar-left ml-batches">
  <section class="card ml-batch-list">
    <div class="card__header">
      <div class="card__title"><?php echo fitsc_icon('layers'); ?>バッチ <small><?php echo count($batches); ?>件</small></div>
    </div>
    <?php if ($batches === []): ?>
      <div class="empty"><span class="empty__icon"><?php echo fitsc_icon('layers'); ?></span><span>バッチはまだありません。</span></div>
    <?php else: ?>
      <div class="list ml-scroll-list">
        <?php foreach ($batches as $batch): ?>
          <?php $isSelected = (int)$batch['id'] === $selectedBatchId; ?>
          <a class="list-item<?php echo $isSelected ? ' is-selected' : ''; ?>" href="batches.php?batch_id=<?php echo (int)$batch['id']; ?>"<?php echo $isSelected ? ' aria-current="true"' : ''; ?>>
            <span class="list-item__main">
              <span class="list-item__title"><?php echo mail_h((string)$batch['title']); ?></span>
              <span class="list-item__meta">#<?php echo (int)$batch['id']; ?> ・ <?php echo (int)$batch['target_count']; ?>件 ・ <?php echo mail_h(mail_batch_creator_label($batch, $creatorLabels)); ?></span>
            </span>
            <span class="list-item__side"><?php echo mail_status_badge((string)$batch['status']); ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <div class="stack stack--lg">
    <?php if (!$selectedBatch): ?>
      <section class="card"><div class="empty"><span class="empty__icon"><?php echo fitsc_icon('layers'); ?></span><span>左の一覧からバッチを選択してください。</span></div></section>
    <?php else: ?>
      <section class="card">
        <div class="card__header">
          <div>
            <div class="card__title"><?php echo mail_h((string)$selectedBatch['title']); ?> <?php echo mail_status_badge((string)$selectedBatch['status']); ?></div>
            <p class="card__subtitle">#<?php echo (int)$selectedBatch['id']; ?> ・ <?php echo mail_h(mail_batch_creator_label($selectedBatch, $creatorLabels)); ?> ・ <?php echo mail_h((string)$selectedBatch['created_at']); ?></p>
          </div>
          <div class="card__actions">
            <a class="btn" href="attachments.php?batch_id=<?php echo (int)$selectedBatch['id']; ?>"><?php echo fitsc_icon('paperclip'); ?>添付</a>
            <a class="btn btn--primary" href="drafts.php?batch_id=<?php echo (int)$selectedBatch['id']; ?>"><?php echo fitsc_icon('inbox'); ?>Gmail下書きへ</a>
          </div>
        </div>
        <div class="card__body stack">
          <div class="stat-grid ml-mini-stats">
            <div class="stat stat--compact"><span class="stat__label">送信対象</span><span class="stat__value"><?php echo (int)$selectedBatch['target_count']; ?></span></div>
            <div class="stat stat--compact stat--teal"><span class="stat__label">共通添付</span><span class="stat__value"><?php echo (int)$selectedBatch['common_attachment_count']; ?></span></div>
            <div class="stat stat--compact stat--info"><span class="stat__label">個別添付</span><span class="stat__value"><?php echo (int)$selectedBatch['individual_attachment_count']; ?></span></div>
          </div>
          <form method="post" class="ml-status-form" data-busy>
            <?php echo mail_auth_csrf_field(); ?>
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
            <label class="field"><span>バッチの状態</span>
              <select name="status"<?php echo $canEditBatch ? '' : ' disabled'; ?>>
                <?php foreach (['prepared' => '準備済み', 'reviewing' => '確認中', 'approved' => '確認済み', 'cancelled' => '取消'] as $key => $label): ?>
                  <option value="<?php echo mail_h($key); ?>"<?php echo mail_selected($selectedBatch['status'], $key); ?>><?php echo mail_h($label); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <button type="submit" class="btn"<?php echo $canEditBatch ? '' : ' disabled'; ?>><?php echo fitsc_icon('check'); ?>更新</button>
            <span class="small-note">Gmail下書きを作るには「確認済み」にします。</span>
          </form>
        </div>
      </section>

      <section class="card">
        <div class="card__header">
          <div class="card__title"><?php echo fitsc_icon('mail'); ?>宛先ごとのプレビュー <small><?php echo count($targets); ?>件</small></div>
        </div>
        <div class="table-wrap is-stacked">
          <table class="table">
            <thead><tr><th>団体</th><th>宛先</th><th>件名</th><th>状態</th><th class="col-actions"></th></tr></thead>
            <tbody>
              <?php if ($targets === []): ?><tr><td colspan="5" class="empty">対象がありません。</td></tr><?php endif; ?>
              <?php foreach ($targets as $target): ?>
                <tr<?php echo !empty($target['error_message']) ? ' class="is-error"' : ''; ?>>
                  <td><span class="cell-main"><?php echo mail_h((string)$target['organization_name']); ?></span><span class="cell-sub"><code><?php echo mail_h((string)$target['identifier']); ?></code></span></td>
                  <td class="allow-select"><?php echo mail_h((string)$target['to_email']); ?></td>
                  <td><?php echo mail_h((string)$target['rendered_subject']); ?></td>
                  <td><?php echo mail_status_badge((string)$target['status']); ?><?php if (!empty($target['error_message'])): ?><span class="cell-sub text-danger"><?php echo mail_h((string)$target['error_message']); ?></span><?php endif; ?></td>
                  <td class="col-actions"><button type="button" class="btn btn--ghost btn--sm" data-preview-open data-preview-org="<?php echo mail_h((string)$target['organization_name']); ?>" data-preview-to="<?php echo mail_h((string)$target['to_email']); ?>" data-preview-subject="<?php echo mail_h((string)$target['rendered_subject']); ?>" data-preview-body="<?php echo mail_h((string)$target['rendered_body']); ?>"><?php echo fitsc_icon('eye'); ?>プレビュー</button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="card">
        <div class="card__header">
          <div class="card__title"><?php echo fitsc_icon('paperclip'); ?>添付の対応状況 <small><?php echo count($attachments); ?>件</small></div>
          <a class="btn btn--ghost btn--sm" href="attachments.php?batch_id=<?php echo (int)$selectedBatch['id']; ?>">添付を登録<?php echo fitsc_icon('arrow-right'); ?></a>
        </div>
        <?php if ($attachments === []): ?>
          <div class="empty"><span class="empty__icon"><?php echo fitsc_icon('paperclip'); ?></span><span>添付ファイルはまだ登録されていません。</span></div>
        <?php else: ?>
          <div class="table-wrap is-stacked">
            <table class="table table--compact">
              <thead><tr><th>種別</th><th>団体</th><th>ファイル</th><th>判定</th><th>状態</th></tr></thead>
              <tbody>
                <?php foreach ($attachments as $att): ?>
                  <tr<?php echo (string)$att['status'] === 'needs_review' ? ' class="is-attention"' : ''; ?>>
                    <td><span class="badge<?php echo (int)$att['is_common'] === 1 ? ' badge--teal' : ''; ?>"><?php echo (int)$att['is_common'] === 1 ? '共通' : '個別'; ?></span></td>
                    <td><span class="cell-main"><?php echo mail_h((string)$att['organization_name']); ?></span><span class="cell-sub"><code><?php echo mail_h((string)$att['identifier']); ?></code></span></td>
                    <td><span class="cell-main"><?php echo mail_h((string)$att['original_name']); ?></span><span class="cell-sub"><?php echo mail_h((string)$att['attachment_type']); ?></span></td>
                    <td><span class="cell-sub"><?php echo mail_h((string)$att['match_method']); ?> ・ <?php echo (int)$att['match_confidence']; ?>%</span></td>
                    <td><?php echo mail_status_badge((string)$att['status']); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <?php $canDeleteBatch = $canEditBatch && !in_array((string)$selectedBatch['status'], ['draft_created', 'sent'], true); ?>
      <section class="card card--danger">
        <div class="card__header"><div class="card__title"><?php echo fitsc_icon('trash'); ?>バッチの削除</div></div>
        <div class="card__body">
          <form method="post" class="danger-zone" data-delete-batch-form
            data-confirm="送信バッチ #<?php echo (int)$selectedBatch['id']; ?> を削除します。送信対象と添付の対応情報も削除されます。" data-confirm-title="バッチを削除しますか？" data-confirm-ok="削除する">
            <?php echo mail_auth_csrf_field(); ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
            <div class="danger-zone__text">
              <strong>確認のためバッチID「<?php echo (int)$selectedBatch['id']; ?>」を入力</strong>
              <span><?php echo in_array((string)$selectedBatch['status'], ['draft_created', 'sent'], true)
                  ? 'Gmail下書き作成済み・送信済みのバッチは、履歴との不整合を防ぐため削除できません。'
                  : 'アップロード済みのファイル本体と送信ログは残ります。'; ?></span>
            </div>
            <div class="input-group">
              <input type="text" name="confirm_batch_id" inputmode="numeric" autocomplete="off" placeholder="<?php echo (int)$selectedBatch['id']; ?>" aria-label="削除確認のバッチID" style="max-width:120px"<?php echo $canDeleteBatch ? '' : ' disabled'; ?>>
              <button type="submit" class="btn btn--danger"<?php echo $canDeleteBatch ? '' : ' disabled'; ?>><?php echo fitsc_icon('trash'); ?>削除</button>
            </div>
          </form>
        </div>
      </section>

      <dialog class="modal modal--lg modal--full" id="previewModal" aria-labelledby="previewModalTitle">
        <div class="modal__panel">
          <div class="modal__header">
            <span class="modal__icon"><?php echo fitsc_icon('mail', 'ico--lg'); ?></span>
            <div>
              <h2 class="modal__title" id="previewModalTitle">メールプレビュー</h2>
              <p class="modal__desc" id="previewMeta"></p>
            </div>
            <button type="button" class="btn btn--ghost btn--icon modal__close" data-modal-close aria-label="閉じる"><?php echo fitsc_icon('x'); ?></button>
          </div>
          <div class="modal__body stack ml-preview">
            <div class="ml-preview__subject"><span class="text-subtle text-xs">件名</span><strong id="previewSubject" class="allow-select"></strong></div>
            <iframe class="mail-preview-frame" id="previewBodyFrame" sandbox="" title="本文プレビュー"></iframe>
          </div>
        </div>
      </dialog>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  const modal = document.getElementById('previewModal');
  const meta = document.getElementById('previewMeta');
  const subject = document.getElementById('previewSubject');
  const bodyFrame = document.getElementById('previewBodyFrame');
  if (!modal) return;
  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch] || ch));
  }
  function looksLikeHtml(value) {
    return /<\s*(p|div|br|span|strong|b|em|i|u|ul|ol|li|table|h[1-6]|blockquote|a)\b|<\s*\//i.test(value || '');
  }
  function plainToHtml(value) {
    const text = String(value || '').replace(/\r\n|\r/g, '\n').trim();
    if (!text) return '';
    return text.split(/\n{2,}/).map(p => '<p>' + p.split('\n').map(escapeHtml).join('<br>') + '</p>').join('');
  }
  document.querySelectorAll('[data-preview-open]').forEach(button => {
    button.addEventListener('click', function () {
      meta.textContent = `${button.dataset.previewOrg || ''} ・ ${button.dataset.previewTo || ''}`;
      subject.textContent = button.dataset.previewSubject || '';
      const rawBody = button.dataset.previewBody || '';
      const bodyHtml = looksLikeHtml(rawBody) ? rawBody : plainToHtml(rawBody);
      if (bodyFrame) {
        bodyFrame.srcdoc = '<!doctype html><html><head><meta charset="UTF-8"><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Sans","Yu Gothic UI",sans-serif;line-height:1.8;color:#1b2430;margin:20px;font-size:15px;} p{margin:0 0 1em;} table{border-collapse:collapse;} td,th{border:1px solid #d8e0ea;padding:6px 8px;}</style></head><body>' + bodyHtml + '</body></html>';
      }
      UI.openModal(modal);
    });
  });
})();
</script>
<?php endif; ?>
<?php
mail_render_page_footer();
