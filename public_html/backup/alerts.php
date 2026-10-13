<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
$user = backup_web_require_user();
$isAdmin = backup_web_is_admin($user);
$flashType = null;
$flashMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = backup_web_require_admin();
    backup_auth_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'resolve') {
            $id = (int)($_POST['id'] ?? 0);
            $note = (string)($_POST['note'] ?? '');
            $ok = backup_alert_resolve($id, $user, $note);
            $flashType = $ok ? 'success' : 'error';
            $flashMessage = $ok ? 'アラートを解決済みにしました。' : '対象の未解決アラートが見つかりません。';
        } elseif ($action === 'run_rules') {
            $result = backup_run_alert_rules('manual');
            $flashType = 'success';
            $flashMessage = 'アラート判定を実行しました。検出/更新: ' . (int)($result['raised_count'] ?? 0) . ' 件';
        }
    } catch (Throwable $e) {
        $flashType = 'error';
        $flashMessage = $e->getMessage();
    }
}

$includeResolved = isset($_GET['all']) && $_GET['all'] === '1';
$alerts = backup_list_alerts($includeResolved, 150);
$csrf = backup_auth_csrf_token();
$actions = '';
if ($isAdmin) {
    $actions .= '<form method="post" data-busy><input type="hidden" name="_csrf" value="' . backup_h($csrf) . '"><input type="hidden" name="action" value="run_rules">'
        . '<button class="btn" type="submit">' . fitsc_icon('refresh') . '<span class="hide-mobile">判定を実行</span></button></form>';
}
backup_web_header('アラート', $user, [
    'subtitle' => '失敗・警告・容量逼迫・整合性欠損',
    'actions' => $actions,
]);
backup_web_flash($flashType, $flashMessage);
?>
<section class="card">
  <div class="card__header">
    <div class="card__title"><?= fitsc_icon('bell') ?><?= $includeResolved ? 'すべてのアラート' : '未解決のアラート' ?> <small><?= count($alerts) ?>件</small></div>
    <div class="segmented" role="group" aria-label="表示">
      <a href="alerts.php"<?= $includeResolved ? '' : ' class="is-active" aria-current="true"' ?>>未解決</a>
      <a href="alerts.php?all=1"<?= $includeResolved ? ' class="is-active" aria-current="true"' : '' ?>>すべて</a>
    </div>
  </div>
  <?php if ($alerts === []): ?>
    <?= backup_web_empty('check-circle', $includeResolved ? 'アラートはありません' : '未解決のアラートはありません') ?>
  <?php else: ?>
    <div class="list bk-alert-list">
      <?php foreach ($alerts as $alert): $resolved = (int)$alert['is_resolved'] === 1; ?>
        <div class="list-item bk-alert<?= $resolved ? ' is-resolved' : '' ?>">
          <span class="list-item__side bk-alert__level"><?= backup_web_level_badge((string)$alert['level']) ?></span>
          <span class="list-item__main">
            <span class="bk-alert__message allow-select"><?= nl2br(backup_h((string)$alert['message'])) ?></span>
            <span class="list-item__meta">
              <span class="mono"><?= backup_h((string)$alert['alert_key']) ?></span>
              ・ 検出 <?= backup_h((string)($alert['first_seen_at'] ?? $alert['created_at'] ?? '')) ?>
              <?php if (!empty($alert['last_seen_at'])): ?> ・ 最終確認 <?= backup_h((string)$alert['last_seen_at']) ?><?php endif; ?>
            </span>
          </span>
          <span class="list-item__side">
            <?php if ($resolved): ?>
              <span class="badge">解決済み</span>
            <?php elseif ($isAdmin): ?>
              <button type="button" class="btn btn--sm" data-resolve-alert="<?= (int)$alert['id'] ?>" data-alert-message="<?= backup_h((string)$alert['message']) ?>"><?= fitsc_icon('check') ?>解決済みにする</button>
            <?php else: ?>
              <span class="badge badge--warning badge--dot">未解決</span>
            <?php endif; ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php if ($isAdmin): ?>
<dialog class="modal" id="resolveDialog" aria-labelledby="resolveDialogTitle">
  <form method="post" class="modal__panel" data-busy>
    <div class="modal__header">
      <span class="modal__icon modal__icon--success"><?= fitsc_icon('check-circle') ?></span>
      <div>
        <h2 class="modal__title" id="resolveDialogTitle">アラートを解決済みにする</h2>
        <p class="modal__desc" data-resolve-message></p>
      </div>
      <button type="button" class="btn btn--ghost btn--icon modal__close" data-modal-close aria-label="閉じる"><?= fitsc_icon('x') ?></button>
    </div>
    <div class="modal__body">
      <input type="hidden" name="_csrf" value="<?= backup_h($csrf) ?>">
      <input type="hidden" name="action" value="resolve">
      <input type="hidden" name="id" value="">
      <label class="field"><span>対応メモ <span class="opt">任意</span></span><textarea name="note" rows="3" placeholder="原因や対応内容"></textarea></label>
    </div>
    <div class="modal__footer">
      <button type="button" class="btn btn--ghost" data-modal-close>キャンセル</button>
      <button type="submit" class="btn btn--primary"><?= fitsc_icon('check') ?>解決済みにする</button>
    </div>
  </form>
</dialog>
<?php endif; ?>
<?php backup_web_footer(); ?>
