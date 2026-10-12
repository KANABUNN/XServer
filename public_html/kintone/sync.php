<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
require_once dirname(__DIR__, 2) . '/apps/kintone_core/kintone_sync_service.php';

$user = kintone_auth_require_view_access();
$pdo = kintone_pdo('org');
$canAdmin = kintone_auth_user_has_role($user, 'admin');
$apps = [];
try {
    $apps = kintone_sync_list_apps(true);
} catch (Throwable $e) {
    error_log('[kintone sync apps] ' . $e->getMessage());
}
$appKey = trim((string)($_GET['app_key'] ?? ($apps[0]['app_key'] ?? 'organizations')));
$selected = null;
foreach ($apps as $app) {
    if ((string)$app['app_key'] === $appKey) {
        $selected = $app;
        break;
    }
}
if (!is_array($selected) && $apps !== []) {
    $selected = $apps[0];
    $appKey = (string)$selected['app_key'];
}
$role = is_array($selected) ? (string)$selected['app_role'] : 'manual';
$hasToken = is_array($selected) && (int)($selected['has_token'] ?? 0) === 1;
$latestJobs = [];
$failedJobs = [];
$lastItems = [];
$targets = [];
$cacheCount = 0;
$cacheRows = [];
if (is_array($selected)) {
    $stmt = $pdo->prepare('SELECT * FROM kintone_sync_jobs WHERE target_app = :app_key ORDER BY id DESC LIMIT 20');
    $stmt->execute([':app_key' => $appKey]);
    $latestJobs = $stmt->fetchAll() ?: [];
    $stmt = $pdo->prepare('SELECT id, job_key, failed_items, finished_at, status FROM kintone_sync_jobs WHERE target_app = :app_key AND failed_items > 0 ORDER BY id DESC LIMIT 10');
    $stmt->execute([':app_key' => $appKey]);
    $failedJobs = $stmt->fetchAll() ?: [];
    $stmt = $pdo->prepare('SELECT * FROM kintone_sync_job_items WHERE app_key = :app_key OR (app_key IS NULL AND :is_org = 1 AND organization_code IS NOT NULL) ORDER BY id DESC LIMIT 50');
    $stmt->execute([':app_key' => $appKey, ':is_org' => $appKey === 'organizations' ? 1 : 0]);
    $lastItems = $stmt->fetchAll() ?: [];
    if ($role === 'pull') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM kintone_app_records WHERE app_key = :app_key');
        $stmt->execute([':app_key' => $appKey]);
        $cacheCount = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT record_key, kintone_record_id, kintone_revision, synced_at FROM kintone_app_records WHERE app_key = :app_key ORDER BY synced_at DESC, id DESC LIMIT 20');
        $stmt->execute([':app_key' => $appKey]);
        $cacheRows = $stmt->fetchAll() ?: [];
    }
}
if ($canAdmin && $role === 'push' && $appKey === 'organizations') {
    $targets = $pdo->query('SELECT id, organization_code, organization_name, activity_status, last_synced_at FROM organizations WHERE is_active = 1 ORDER BY organization_code ASC LIMIT 200')->fetchAll() ?: [];
}

function kintone_sync_badge(string $status): string
{
    return match ($status) {
        'success', 'connected' => '<span class="badge badge-success">' . kintone_h($status) . '</span>',
        'partial', 'not_connected' => '<span class="badge badge-warn">' . kintone_h($status) . '</span>',
        'failed', 'error' => '<span class="badge badge-danger">' . kintone_h($status) . '</span>',
        'running' => '<span class="badge">running</span>',
        default => '<span class="badge">' . kintone_h($status) . '</span>',
    };
}

kintone_page_header('kintone同期', $user);
kintone_render_flash();
?>
<section class="card">
  <h2>同期対象アプリ</h2>
  <form method="get" class="form-actions">
    <label for="app_key" class="sr-only">アプリ</label>
    <select id="app_key" name="app_key" onchange="this.form.submit()">
      <?php foreach ($apps as $app): ?>
        <option value="<?= kintone_h($app['app_key']) ?>" <?= (string)$app['app_key'] === $appKey ? 'selected' : '' ?>><?= kintone_h($app['app_key'] . ' / ' . $app['display_name'] . ' / ' . $app['app_role']) ?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="secondary" type="submit">表示</button></noscript>
    <a class="btn" href="settings.php<?= $appKey !== '' ? '?app_key=' . rawurlencode($appKey) : '' ?>">アプリ設定</a>
  </form>
  <?php if (!is_array($selected)): ?>
    <p class="alert alert-warning">有効なkintoneアプリがありません。先にアプリレジストリへ登録してください。</p>
  <?php else: ?>
    <p>app_key: <code><?= kintone_h($appKey) ?></code> / ロール: <span class="badge"><?= kintone_h($role) ?></span> / アプリID: <?= kintone_h($selected['kintone_app_id']) ?> / 状態: <?= kintone_sync_badge((string)$selected['status']) ?> / トークン: <?= $hasToken ? '<span class="badge badge-success">登録済み</span>' : '<span class="badge badge-danger">未登録</span>' ?></p>
    <p class="muted">最終同期: <?= kintone_h($selected['last_synced_at'] ?? '-') ?> / 最終エラー: <?= kintone_h($selected['last_error'] ?? '-') ?></p>
  <?php endif; ?>
</section>

<?php if (is_array($selected)): ?>
<section class="card">
  <h2><?= $role === 'pull' ? 'kintone → XServerキャッシュ' : ($role === 'push' ? 'XServer正本 → kintone' : '手動アプリ') ?></h2>
  <?php if (!$canAdmin): ?>
    <p class="alert alert-warning">同期実行はadminのみ可能です。viewer/operatorはログ閲覧のみです。</p>
  <?php elseif (!$hasToken): ?>
    <p class="alert alert-warning">先にこのアプリのAPIトークンを保存してください。</p>
    <p><a class="primary" href="settings.php?app_key=<?= rawurlencode($appKey) ?>">アプリ設定へ</a></p>
  <?php elseif ($role === 'manual'): ?>
    <p class="muted">manualアプリは自動同期しません。設定・参照用の予約枠です。</p>
  <?php else: ?>
    <form method="post" action="api/sync_kintone.php" class="form-actions">
      <?= kintone_auth_csrf_field() ?>
      <input type="hidden" name="action" value="sync_app">
      <input type="hidden" name="app_key" value="<?= kintone_h($appKey) ?>">
      <button class="primary" type="submit" onclick="return confirm('<?= $role === 'pull' ? 'kintoneからキャッシュへ取り込みます。' : 'XServer正本をkintoneへ同期します。' ?>実行しますか？');"><?= $role === 'pull' ? 'pull同期を実行' : 'push同期を実行' ?></button>
      <a class="secondary" href="settings.php?app_key=<?= rawurlencode($appKey) ?>">アプリ設定</a>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($canAdmin && is_array($selected) && $hasToken && $failedJobs !== []): ?>
<section class="card"><h2>失敗分のみ再同期</h2><form method="post" action="api/sync_kintone.php" class="form-actions">
  <?= kintone_auth_csrf_field() ?>
  <input type="hidden" name="action" value="retry_failed">
  <select name="source_job_id"><?php foreach ($failedJobs as $job): ?><option value="<?= (int)$job['id'] ?>">#<?= (int)$job['id'] ?> <?= kintone_h($job['status']) ?> / failed <?= (int)$job['failed_items'] ?> / <?= kintone_h($job['finished_at'] ?? '') ?></option><?php endforeach; ?></select>
  <button class="primary" type="submit">選択ジョブの失敗分を再同期</button>
</form></section>
<?php endif; ?>

<?php if ($canAdmin && $role === 'push' && $appKey === 'organizations' && $hasToken): ?>
<section class="card"><h2>団体個別同期</h2><p class="muted">大量同期前のテスト用です。最大200件まで表示しています。</p><form method="post" action="api/sync_kintone.php">
  <?= kintone_auth_csrf_field() ?><input type="hidden" name="action" value="sync_selected"><input type="hidden" name="app_key" value="organizations">
  <div class="table-wrap"><table><thead><tr><th></th><th>団体ID</th><th>団体名</th><th>活動状態</th><th>最終同期</th></tr></thead><tbody><?php foreach ($targets as $org): ?><tr><td><input type="checkbox" name="organization_ids[]" value="<?= (int)$org['id'] ?>"></td><td><?= kintone_h($org['organization_code']) ?></td><td><?= kintone_h($org['organization_name']) ?></td><td><?= kintone_h($org['activity_status']) ?></td><td><?= kintone_h($org['last_synced_at'] ?? '-') ?></td></tr><?php endforeach; ?><?php if ($targets === []): ?><tr><td colspan="5" class="empty">同期対象がありません。</td></tr><?php endif; ?></tbody></table></div>
  <div class="form-actions"><button class="secondary" type="submit">選択団体のみ同期</button></div>
</form></section>
<?php endif; ?>

<?php if ($role === 'pull'): ?>
<section class="card"><h2>pullキャッシュ</h2><p>キャッシュ済み: <?= $cacheCount ?>件。未登場レコードは削除せず、<code>synced_at</code> の鮮度で判断します。</p><div class="table-wrap"><table><thead><tr><th>record_key</th><th>kintone ID</th><th>revision</th><th>synced_at</th></tr></thead><tbody><?php foreach ($cacheRows as $row): ?><tr><td><?= kintone_h($row['record_key'] ?? '') ?></td><td><?= kintone_h($row['kintone_record_id']) ?></td><td><?= kintone_h($row['kintone_revision'] ?? '') ?></td><td><?= kintone_h($row['synced_at']) ?></td></tr><?php endforeach; ?><?php if ($cacheRows === []): ?><tr><td colspan="4" class="empty">キャッシュはまだありません。</td></tr><?php endif; ?></tbody></table></div></section>
<?php endif; ?>

<section class="card"><h2>同期ジョブ</h2><div class="table-wrap"><table><thead><tr><th>ID</th><th>状態</th><th>種別</th><th>対象</th><th>成功</th><th>失敗</th><th>開始</th><th>終了</th><th>メッセージ</th></tr></thead><tbody><?php foreach ($latestJobs as $job): ?><tr><td>#<?= (int)$job['id'] ?></td><td><?= kintone_sync_badge((string)$job['status']) ?></td><td><?= kintone_h($job['job_type']) ?></td><td><?= (int)$job['total_items'] ?></td><td><?= (int)$job['success_items'] ?></td><td><?= (int)$job['failed_items'] ?></td><td><?= kintone_h($job['started_at'] ?? '') ?></td><td><?= kintone_h($job['finished_at'] ?? '') ?></td><td><?= kintone_h($job['message'] ?? '') ?></td></tr><?php endforeach; ?><?php if ($latestJobs === []): ?><tr><td colspan="9" class="empty">同期ジョブはまだありません。</td></tr><?php endif; ?></tbody></table></div></section>
<section class="card"><h2>直近の同期明細</h2><div class="table-wrap"><table><thead><tr><th>ID</th><th>Job</th><th>record_key</th><th>団体ID</th><th>操作</th><th>状態</th><th>kintone ID</th><th>エラー</th><th>作成日時</th></tr></thead><tbody><?php foreach ($lastItems as $item): ?><tr><td><?= (int)$item['id'] ?></td><td>#<?= (int)$item['job_id'] ?></td><td><?= kintone_h($item['record_key'] ?? '') ?></td><td><?= kintone_h($item['organization_code'] ?? '') ?></td><td><?= kintone_h($item['action_type']) ?></td><td><?= kintone_sync_badge((string)$item['status']) ?></td><td><?= kintone_h($item['kintone_record_id'] ?? '') ?></td><td><?= kintone_h($item['error_message'] ?? '') ?></td><td><?= kintone_h($item['created_at']) ?></td></tr><?php endforeach; ?><?php if ($lastItems === []): ?><tr><td colspan="9" class="empty">同期明細はまだありません。</td></tr><?php endif; ?></tbody></table></div></section>
<?php kintone_page_footer(); ?>
