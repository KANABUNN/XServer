<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../../apps/mail_core/graph_client.php';

[$user, $mailPdo, $dbError] = mail_app_init();
$selectedBatchId = (int)($_GET['batch_id'] ?? $_POST['batch_id'] ?? 0);
$graph = mail_graph_config();
$maxDraftsPerRun = max(1, min(100, (int)($graph['max_drafts_per_run'] ?? 20)));
$maxSendsPerRun = max(1, min(100, (int)($graph['max_sends_per_run'] ?? 10)));
$sendEnabled = mail_graph_send_enabled();

if ($mailPdo instanceof PDO && $dbError === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    mail_auth_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'test') {
            mail_require_permission_or_forbid($user, 'settings.manage');
            $profile = mail_graph_test_connection();
            mail_flash_set('info', 'Graph接続に成功しました。送信用ユーザー: ' . (string)($profile['displayName'] ?? $profile['userPrincipalName'] ?? 'unknown'));
            mail_redirect('graph.php' . ($selectedBatchId > 0 ? '?batch_id=' . $selectedBatchId : ''));
        }

        if ($action === 'create_batch_drafts') {
            mail_require_permission_or_forbid($user, 'draft.create');
            $limit = max(1, min(100, (int)($_POST['limit'] ?? $maxDraftsPerRun)));
            $result = mail_graph_create_drafts_for_batch($mailPdo, $selectedBatchId, $user, $limit);
            mail_flash_set('info', '下書き作成を実行しました。成功: ' . $result['success'] . '件 / 失敗: ' . $result['failed'] . '件 / 残り: ' . $result['remaining'] . '件');
            mail_redirect('graph.php?batch_id=' . $selectedBatchId);
        }

        if ($action === 'create_target_draft') {
            mail_require_permission_or_forbid($user, 'draft.create');
            $targetId = (int)($_POST['target_id'] ?? 0);
            $result = mail_graph_create_draft_for_target($mailPdo, $targetId, $user);
            mail_flash_set('info', '下書きを作成しました。対象ID: ' . $targetId . ' / 添付: ' . (int)$result['attached_count'] . '件');
            mail_redirect('graph.php?batch_id=' . $selectedBatchId);
        }

        if ($action === 'send_batch_drafts') {
            mail_require_permission_or_forbid($user, 'send.execute');
            if ((string)($_POST['confirm_text'] ?? '') !== '送信') {
                throw new RuntimeException('一括送信を実行するには、確認欄に「送信」と入力してください。');
            }
            $limit = max(1, min(100, (int)($_POST['limit'] ?? $maxSendsPerRun)));
            $result = mail_graph_send_drafts_for_batch($mailPdo, $selectedBatchId, $user, $limit);
            mail_flash_set('info', '下書き送信を実行しました。成功: ' . $result['success'] . '件 / 失敗: ' . $result['failed'] . '件 / 残り: ' . $result['remaining'] . '件');
            mail_redirect('graph.php?batch_id=' . $selectedBatchId);
        }

        if ($action === 'send_target_draft') {
            mail_require_permission_or_forbid($user, 'send.execute');
            if ((string)($_POST['confirm_text'] ?? '') !== '送信') {
                throw new RuntimeException('送信を実行するには、確認欄に「送信」と入力してください。');
            }
            $targetId = (int)($_POST['target_id'] ?? 0);
            mail_graph_send_existing_draft($mailPdo, $targetId, $user);
            mail_flash_set('info', '下書きを送信しました。対象ID: ' . $targetId);
            mail_redirect('graph.php?batch_id=' . $selectedBatchId);
        }
    } catch (Throwable $e) {
        mail_flash_set('danger', mail_user_safe_error_message($e));
        mail_redirect('graph.php' . ($selectedBatchId > 0 ? '?batch_id=' . $selectedBatchId : ''));
    }
}

$batches = [];
$selectedBatch = null;
$targets = [];
$attachments = [];
$pendingAttachmentCount = 0;
$draftableCount = 0;
$sendableCount = 0;
$sentCount = 0;
[$graphReady, $graphMissing] = mail_graph_is_configured();

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
            $pendingAttachmentCount = mail_graph_pending_attachment_count($mailPdo, $selectedBatchId);
            $draftableCount = count(mail_graph_list_draftable_targets($mailPdo, $selectedBatchId, 100));
            $sendableCount = mail_graph_sendable_target_count($mailPdo, $selectedBatchId);
            $sentCount = mail_graph_sent_target_count($mailPdo, $selectedBatchId);
        }
    }
}
mail_render_page_header('Graph 下書き・送信', $user, 'graph.php', [
    'subtitle' => '保守用：Microsoft Graph で Outlook の下書き作成・送信を行います',
    'actions' => '<a class="btn btn--ghost" href="settings.php">' . fitsc_icon('settings') . '<span class="hide-mobile">送信設定</span></a>',
]);
?>
<?php mail_render_db_error($dbError); ?>
<?php if ($dbError === ''): ?>

<div class="split">
  <section class="card">
    <div class="card__header">
      <div class="card__title"><?php echo fitsc_icon('cloud'); ?>Graph 接続</div>
      <?php echo $graphReady ? '<span class="badge badge--success badge--dot">利用可能</span>' : '<span class="badge badge--warning badge--dot">不足あり</span>'; ?>
    </div>
    <div class="card__body stack">
      <dl class="dl">
        <div><dt>Graph</dt><dd><?php echo !empty($graph['enabled']) ? '有効' : '無効'; ?></dd></div>
        <div><dt>送信 UI</dt><dd><?php echo $sendEnabled ? '有効' : '無効'; ?></dd></div>
        <div><dt>認証方式</dt><dd><code><?php echo mail_h((string)($graph['auth_mode'] ?? 'client_credentials')); ?></code></dd></div>
        <div><dt>テナントID</dt><dd><code><?php echo mail_h((string)($graph['tenant_id'] ?? '')); ?></code></dd></div>
        <div><dt>送信用ユーザー</dt><dd><code><?php echo mail_h((string)($graph['sender_user_id'] ?? '')); ?></code></dd></div>
      </dl>
      <?php if (!$graphReady): ?>
        <div class="alert alert--warning"><?php echo fitsc_icon('alert-triangle'); ?><div class="alert__body">Graph の設定が不足しています：<code><?php echo mail_h(implode(', ', $graphMissing)); ?></code></div></div>
      <?php endif; ?>
      <?php if (!$sendEnabled): ?>
        <div class="alert alert--info"><?php echo fitsc_icon('info'); ?><div class="alert__body">送信 UI は無効です。送信まで使う場合は Entra ID で <code>Mail.Send</code> を付与し、<code>graph.allow_send_from_ui = true</code> にしてください。</div></div>
      <?php endif; ?>
      <form method="post" data-busy>
        <?php echo mail_auth_csrf_field(); ?>
        <input type="hidden" name="action" value="test">
        <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatchId; ?>">
        <button type="submit" class="btn"<?php echo $graphReady && mail_auth_has_permission($user, 'settings.manage') ? '' : ' disabled'; ?>><?php echo fitsc_icon('zap'); ?>接続テスト</button>
      </form>
    </div>
  </section>

  <section class="card">
    <div class="card__header"><div class="card__title"><?php echo fitsc_icon('layers'); ?>対象のバッチ <small><?php echo count($batches); ?>件</small></div></div>
    <div class="card__body">
      <form method="get">
        <label class="field"><span>バッチ</span>
          <select name="batch_id" data-autosubmit>
            <?php foreach ($batches as $batch): ?>
              <option value="<?php echo (int)$batch['id']; ?>"<?php echo mail_selected($selectedBatchId, (int)$batch['id']); ?>>#<?php echo (int)$batch['id']; ?> <?php echo mail_h((string)$batch['title']); ?> / <?php echo mail_h(mail_status_label((string)$batch['status'])); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <noscript><button type="submit" class="btn">表示</button></noscript>
      </form>
    </div>
  </section>
</div>

<?php if ($selectedBatch): ?>
  <div class="stat-grid">
    <div class="stat"><span class="stat__label">対象メール</span><span class="stat__value"><?php echo count($targets); ?></span></div>
    <div class="stat stat--info"><span class="stat__label">下書き可能</span><span class="stat__value"><?php echo (int)$draftableCount; ?></span></div>
    <div class="stat stat--teal"><span class="stat__label">送信可能</span><span class="stat__value"><?php echo (int)$sendableCount; ?></span></div>
    <div class="stat stat--success"><span class="stat__label">送信済み</span><span class="stat__value"><?php echo (int)$sentCount; ?></span></div>
    <div class="stat stat--warning"><span class="stat__label">要確認の添付</span><span class="stat__value"><?php echo (int)$pendingAttachmentCount; ?></span></div>
  </div>
  <?php if ((string)$selectedBatch['status'] !== 'approved' && $draftableCount > 0): ?>
    <div class="alert alert--warning"><?php echo fitsc_icon('alert-triangle'); ?><div class="alert__body">下書きを作成するには、バッチの状態を「確認済み」にしてください。</div></div>
  <?php elseif ($pendingAttachmentCount > 0): ?>
    <div class="alert alert--warning"><?php echo fitsc_icon('paperclip'); ?><div class="alert__body">要確認の添付があります。<a href="attachments.php?batch_id=<?php echo (int)$selectedBatch['id']; ?>">添付ファイル画面</a>で確定してください。</div></div>
  <?php endif; ?>

  <div class="split">
    <section class="card">
      <div class="card__header"><div class="card__title"><span class="ml-step-num">1</span>Outlook 下書きを作成</div></div>
      <form method="post" class="card__body stack" data-busy>
        <?php echo mail_auth_csrf_field(); ?>
        <input type="hidden" name="action" value="create_batch_drafts">
        <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
        <p class="small-note">送信用メールボックスの下書きフォルダに作成します。添付もこの段階で追加されます。</p>
        <label class="field"><span>1回の最大作成件数</span><input type="text" name="limit" value="<?php echo (int)$maxDraftsPerRun; ?>" inputmode="numeric" style="max-width:140px"></label>
        <div><button type="submit" class="btn btn--primary"<?php echo ($graphReady && mail_auth_has_permission($user, 'draft.create') && (string)$selectedBatch['status'] === 'approved' && $pendingAttachmentCount === 0 && $draftableCount > 0) ? '' : ' disabled'; ?>><?php echo fitsc_icon('inbox'); ?>下書きを一括作成</button></div>
      </form>
    </section>

    <section class="card card--danger">
      <div class="card__header"><div class="card__title"><span class="ml-step-num">2</span>作成済みの下書きを送信</div></div>
      <form method="post" class="card__body stack" data-busy data-confirm="作成済みの下書きを送信します。取り消すことはできません。" data-confirm-title="メールを送信しますか？" data-confirm-ok="送信する">
        <?php echo mail_auth_csrf_field(); ?>
        <input type="hidden" name="action" value="send_batch_drafts">
        <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
        <p class="small-note">Graph の message_id を持つ下書きだけを送信します。管理者権限・Mail.Send 権限・送信 UI の有効化が必要です。</p>
        <div class="form-grid">
          <label class="field"><span>1回の最大送信件数</span><input type="text" name="limit" value="<?php echo (int)$maxSendsPerRun; ?>" inputmode="numeric"></label>
          <label class="field"><span>確認のため「送信」と入力</span><input type="text" name="confirm_text" placeholder="送信" autocomplete="off"></label>
        </div>
        <div><button type="submit" class="btn btn--danger"<?php echo ($graphReady && $sendEnabled && mail_auth_has_permission($user, 'send.execute') && $sendableCount > 0) ? '' : ' disabled'; ?>><?php echo fitsc_icon('send'); ?>下書きを一括送信</button></div>
      </form>
    </section>
  </div>

  <section class="card">
    <div class="card__header"><div class="card__title"><?php echo fitsc_icon('mail'); ?>対象メール <small><?php echo count($targets); ?>件</small></div></div>
    <div class="table-wrap is-stacked">
      <table class="table">
        <thead><tr><th>団体</th><th>宛先</th><th>状態</th><th>message_id</th><th class="col-actions"></th></tr></thead>
        <tbody>
          <?php if ($targets === []): ?><tr><td colspan="5" class="empty">対象メールはありません。</td></tr><?php endif; ?>
          <?php foreach ($targets as $target): ?>
            <?php
              $canCreate = $graphReady && mail_auth_has_permission($user, 'draft.create') && (string)$selectedBatch['status'] === 'approved' && $pendingAttachmentCount === 0 && in_array((string)$target['status'], ['ready', 'failed'], true) && empty($target['graph_message_id']);
              $canSend = $graphReady && $sendEnabled && mail_auth_has_permission($user, 'send.execute') && (string)$target['status'] === 'draft_created' && !empty($target['graph_message_id']);
            ?>
            <tr<?php echo !empty($target['error_message']) ? ' class="is-error"' : ''; ?>>
              <td><span class="cell-main"><?php echo mail_h((string)$target['organization_name']); ?></span><span class="cell-sub"><code><?php echo mail_h((string)$target['identifier']); ?></code> ・ #<?php echo (int)$target['id']; ?></span></td>
              <td class="allow-select"><?php echo mail_h((string)$target['to_email']); ?></td>
              <td><?php echo mail_status_badge((string)$target['status']); ?><?php if (!empty($target['error_message'])): ?><span class="cell-sub text-danger"><?php echo mail_h((string)$target['error_message']); ?></span><?php endif; ?></td>
              <td><?php echo !empty($target['graph_message_id']) ? '<code class="text-xs">' . mail_h((string)$target['graph_message_id']) . '</code>' : '<span class="text-subtle">—</span>'; ?></td>
              <td class="col-actions">
                <div class="btn-group">
                  <form method="post" data-busy>
                    <?php echo mail_auth_csrf_field(); ?>
                    <input type="hidden" name="action" value="create_target_draft">
                    <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
                    <input type="hidden" name="target_id" value="<?php echo (int)$target['id']; ?>">
                    <button type="submit" class="btn btn--sm"<?php echo $canCreate ? '' : ' disabled'; ?>>下書き作成</button>
                  </form>
                  <form method="post" data-busy data-confirm="<?php echo mail_h((string)$target['organization_name']); ?> へ送信します。" data-confirm-title="このメールを送信しますか？" data-confirm-ok="送信する">
                    <?php echo mail_auth_csrf_field(); ?>
                    <input type="hidden" name="action" value="send_target_draft">
                    <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
                    <input type="hidden" name="target_id" value="<?php echo (int)$target['id']; ?>">
                    <input type="hidden" name="confirm_text" value="送信">
                    <button type="submit" class="btn btn--danger-ghost btn--sm"<?php echo $canSend ? '' : ' disabled'; ?>>送信</button>
                  </form>
                </div>
              </td>
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
