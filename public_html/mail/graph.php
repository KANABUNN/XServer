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
        mail_flash_set('danger', $e->getMessage());
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

mail_render_page_header('Graph下書き・送信', $user, 'graph.php');
?>
<header class="page-head">
  <div>
    <h1>Graph下書き・送信</h1>
    <p class="lead">承認済みバッチからOutlook下書きを作成し、必要に応じて作成済み下書きを送信します。初期運用は自治会管轄Entra IDのapp-only方式です。</p>
  </div>
</header>
<?php mail_render_db_error($dbError); ?>

<?php if ($dbError === ''): ?>
<div class="two-column-grid wide-left">
  <section class="panel">
    <div class="panel-head"><h2>Graph接続状態</h2><span class="muted">client_credentials</span></div>
    <div class="settings-grid">
      <div><span class="muted">Graph有効</span><strong><?php echo !empty($graph['enabled']) ? '有効' : '無効'; ?></strong></div>
      <div><span class="muted">設定状態</span><strong><?php echo $graphReady ? '利用可能' : '不足あり'; ?></strong></div>
      <div><span class="muted">送信UI</span><strong><?php echo $sendEnabled ? '有効' : '無効'; ?></strong></div>
      <div><span class="muted">認証方式</span><code><?php echo mail_h((string)($graph['auth_mode'] ?? 'client_credentials')); ?></code></div>
      <div><span class="muted">テナントID</span><code><?php echo mail_h((string)($graph['tenant_id'] ?? '')); ?></code></div>
      <div><span class="muted">送信用ユーザー</span><code><?php echo mail_h((string)($graph['sender_user_id'] ?? '')); ?></code></div>
    </div>
    <?php if (!$graphReady): ?>
      <div class="alert alert-warn mt-14">Graph設定が不足しています: <code><?php echo mail_h(implode(', ', $graphMissing)); ?></code>。<code>apps/mail_core/config.local.php</code> を確認してください。</div>
    <?php endif; ?>
    <?php if (!$sendEnabled): ?>
      <div class="alert alert-info mt-14">送信UIは無効です。送信まで使う場合は、Entra IDで <code>Mail.Send</code> を付与したうえで <code>graph.allow_send_from_ui = true</code> にしてください。</div>
    <?php endif; ?>
    <form method="post" class="inline-actions mt-14">
      <?php echo mail_auth_csrf_field(); ?>
      <input type="hidden" name="action" value="test">
      <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatchId; ?>">
      <button type="submit" class="secondary"<?php echo $graphReady && mail_auth_has_permission($user, 'settings.manage') ? '' : ' disabled'; ?>>Graph接続テスト</button>
      <a class="text-link" href="settings.php">設定を確認</a>
    </form>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>対象バッチ</h2><span class="muted"><?php echo count($batches); ?>件</span></div>
    <form method="get" class="stack-form">
      <label><span>バッチ選択</span>
        <select name="batch_id" onchange="this.form.submit()">
          <?php foreach ($batches as $batch): ?>
            <option value="<?php echo (int)$batch['id']; ?>"<?php echo mail_selected($selectedBatchId, (int)$batch['id']); ?>>#<?php echo (int)$batch['id']; ?> <?php echo mail_h((string)$batch['title']); ?> / <?php echo mail_h(mail_status_label((string)$batch['status'])); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <noscript><button type="submit" class="secondary">表示</button></noscript>
    </form>
  </section>
</div>

<?php if ($selectedBatch): ?>
<section class="panel mt-18">
  <div class="panel-head">
    <div>
      <h2><?php echo mail_h((string)$selectedBatch['title']); ?></h2>
      <p class="muted">状態: <?php echo mail_h(mail_status_label((string)$selectedBatch['status'])); ?> / 対象: <?php echo (int)$selectedBatch['target_count']; ?>件</p>
    </div>
    <a class="text-link" href="batches.php?batch_id=<?php echo (int)$selectedBatch['id']; ?>">バッチ詳細へ</a>
  </div>

  <div class="summary-grid small">
    <article class="summary-card"><span>対象メール</span><strong><?php echo count($targets); ?></strong></article>
    <article class="summary-card"><span>下書き可能</span><strong><?php echo (int)$draftableCount; ?></strong></article>
    <article class="summary-card"><span>送信可能</span><strong><?php echo (int)$sendableCount; ?></strong></article>
    <article class="summary-card"><span>送信済み</span><strong><?php echo (int)$sentCount; ?></strong></article>
    <article class="summary-card"><span>要確認添付</span><strong><?php echo (int)$pendingAttachmentCount; ?></strong></article>
    <article class="summary-card"><span>添付総数</span><strong><?php echo count($attachments); ?></strong></article>
  </div>

  <?php if ((string)$selectedBatch['status'] !== 'approved' && $draftableCount > 0): ?>
    <div class="alert alert-warn">Graph下書き作成には、バッチ状態を「確認済み」にする必要があります。送信前レビューを完了してから実行してください。</div>
  <?php elseif ($pendingAttachmentCount > 0): ?>
    <div class="alert alert-warn">要確認・未対応の添付が残っています。<a class="text-link" href="attachments.php?batch_id=<?php echo (int)$selectedBatch['id']; ?>">添付ファイル画面</a>で確定してください。</div>
  <?php endif; ?>

  <div class="two-column-grid mt-14">
    <form method="post" class="panel-subform">
      <?php echo mail_auth_csrf_field(); ?>
      <input type="hidden" name="action" value="create_batch_drafts">
      <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
      <h3>1. Outlook下書き作成</h3>
      <p class="muted">Graphで送信用メールボックスのDraftsに下書きを作成します。添付もこの段階で追加されます。</p>
      <label><span class="muted">1回の最大作成件数</span><input type="text" name="limit" value="<?php echo (int)$maxDraftsPerRun; ?>" inputmode="numeric" style="max-width:120px"></label>
      <button type="submit" class="primary"<?php echo ($graphReady && mail_auth_has_permission($user, 'draft.create') && (string)$selectedBatch['status'] === 'approved' && $pendingAttachmentCount === 0 && $draftableCount > 0) ? '' : ' disabled'; ?>>下書きを一括作成</button>
    </form>

    <form method="post" class="panel-subform danger-zone">
      <?php echo mail_auth_csrf_field(); ?>
      <input type="hidden" name="action" value="send_batch_drafts">
      <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
      <h3>2. 作成済み下書きの送信</h3>
      <p class="muted">Graph message_id を保持している下書きだけを送信します。実行には管理者権限、Mail.Send権限、送信UIの有効化が必要です。</p>
      <label><span class="muted">1回の最大送信件数</span><input type="text" name="limit" value="<?php echo (int)$maxSendsPerRun; ?>" inputmode="numeric" style="max-width:120px"></label>
      <label><span class="muted">確認入力</span><input type="text" name="confirm_text" placeholder="送信"></label>
      <button type="submit" class="danger"<?php echo ($graphReady && $sendEnabled && mail_auth_has_permission($user, 'send.execute') && $sendableCount > 0) ? '' : ' disabled'; ?>>下書きを一括送信</button>
    </form>
  </div>
</section>

<section class="panel mt-18">
  <div class="panel-head"><h2>対象メール一覧</h2><span class="muted">個別実行も可能</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>識別番号</th><th>団体</th><th>宛先</th><th>状態</th><th>Graph message_id</th><th>操作</th></tr></thead>
      <tbody>
        <?php if ($targets === []): ?><tr><td colspan="7" class="empty">対象メールはありません。</td></tr><?php endif; ?>
        <?php foreach ($targets as $target): ?>
          <?php
            $canCreate = $graphReady && mail_auth_has_permission($user, 'draft.create') && (string)$selectedBatch['status'] === 'approved' && $pendingAttachmentCount === 0 && in_array((string)$target['status'], ['ready','failed'], true) && empty($target['graph_message_id']);
            $canSend = $graphReady && $sendEnabled && mail_auth_has_permission($user, 'send.execute') && (string)$target['status'] === 'draft_created' && !empty($target['graph_message_id']);
          ?>
          <tr>
            <td><?php echo (int)$target['id']; ?></td>
            <td><code><?php echo mail_h((string)$target['identifier']); ?></code></td>
            <td><?php echo mail_h((string)$target['organization_name']); ?></td>
            <td><?php echo mail_h((string)$target['to_email']); ?></td>
            <td><span class="badge"><?php echo mail_h(mail_status_label((string)$target['status'])); ?></span><?php if (!empty($target['error_message'])): ?><br><span class="danger-text"><?php echo mail_h((string)$target['error_message']); ?></span><?php endif; ?></td>
            <td><code><?php echo mail_h((string)($target['graph_message_id'] ?? '')); ?></code></td>
            <td class="action-cell">
              <form method="post" class="inline-form">
                <?php echo mail_auth_csrf_field(); ?>
                <input type="hidden" name="action" value="create_target_draft">
                <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
                <input type="hidden" name="target_id" value="<?php echo (int)$target['id']; ?>">
                <button type="submit" class="secondary"<?php echo $canCreate ? '' : ' disabled'; ?>>下書き作成</button>
              </form>
              <form method="post" class="inline-form">
                <?php echo mail_auth_csrf_field(); ?>
                <input type="hidden" name="action" value="send_target_draft">
                <input type="hidden" name="batch_id" value="<?php echo (int)$selectedBatch['id']; ?>">
                <input type="hidden" name="target_id" value="<?php echo (int)$target['id']; ?>">
                <input type="hidden" name="confirm_text" value="送信">
                <button type="submit" class="danger"<?php echo $canSend ? '' : ' disabled'; ?>>送信</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<section class="panel mt-18 feature-panel">
  <h2>実装済みの範囲</h2>
  <p>この画面では、Graph APIのアクセストークン取得、送信用ユーザー確認、Outlook下書き作成、3MB未満の通常添付、3MB以上150MB以下のアップロードセッション添付、既存下書き送信、処理ログ保存までを実行します。委任認証方式は後続実装用として設定枠のみ残しています。</p>
</section>
<?php endif; ?>
<?php
mail_render_page_footer();
