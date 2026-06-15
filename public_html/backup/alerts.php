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
backup_web_header('アラート', $user);
backup_web_flash($flashType, $flashMessage);
?>
<section class="section-title">
  <div>
    <h2>バックアップ運用アラート</h2>
    <p class="muted">未解決の失敗・警告・容量逼迫・整合性欠損を確認します。</p>
  </div>
  <div>
    <?php if ($isAdmin): ?>
      <form method="post" class="inline-form">
        <input type="hidden" name="_csrf" value="<?= backup_h(backup_auth_csrf_token()) ?>">
        <input type="hidden" name="action" value="run_rules">
        <button class="btn" type="submit">判定を今すぐ実行</button>
      </form>
    <?php endif; ?>
    <a class="btn" href="alerts.php<?= $includeResolved ? '' : '?all=1' ?>"><?= $includeResolved ? '未解決のみ' : '解決済みも表示' ?></a>
  </div>
</section>

<section class="card">
  <table>
    <thead><tr><th>ID</th><th>重要度</th><th>キー</th><th>内容</th><th>検出</th><th>最終確認</th><th>状態</th><th>操作</th></tr></thead>
    <tbody>
    <?php foreach ($alerts as $alert): ?>
      <tr>
        <td><?= (int)$alert['id'] ?></td>
        <td><?= backup_web_level_badge((string)$alert['level']) ?></td>
        <td class="mono"><?= backup_h((string)$alert['alert_key']) ?></td>
        <td><?= nl2br(backup_h((string)$alert['message'])) ?></td>
        <td><?= backup_h((string)($alert['first_seen_at'] ?? $alert['created_at'] ?? '')) ?></td>
        <td><?= backup_h((string)($alert['last_seen_at'] ?? '')) ?></td>
        <td><?= (int)$alert['is_resolved'] === 1 ? '<span class="status status-muted">解決済み</span>' : '<span class="status status-warning">未解決</span>' ?></td>
        <td>
          <?php if ($isAdmin && (int)$alert['is_resolved'] !== 1): ?>
            <form method="post">
              <input type="hidden" name="_csrf" value="<?= backup_h(backup_auth_csrf_token()) ?>">
              <input type="hidden" name="action" value="resolve">
              <input type="hidden" name="id" value="<?= (int)$alert['id'] ?>">
              <textarea name="note" rows="2" placeholder="解決メモ任意"></textarea>
              <button class="btn" type="submit">解決済みにする</button>
            </form>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($alerts === []): ?>
      <tr><td colspan="8" class="muted">表示対象のアラートはありません。</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>
<?php backup_web_footer(); ?>
