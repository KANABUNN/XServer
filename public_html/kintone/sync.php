<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
require_once dirname(__DIR__, 2) . '/apps/kintone_core/kintone_rest_client.php';

$user = kintone_auth_require_view_access();
$pdo = kintone_pdo('org');
$canAdmin = kintone_auth_user_has_role($user, 'admin');

$credential = null;
try {
    $credential = kintone_credentials_default();
} catch (Throwable $e) {
    error_log('[kintone sync credential] ' . $e->getMessage());
}
$activeCount = (int)$pdo->query('SELECT COUNT(*) FROM organizations WHERE is_active = 1')->fetchColumn();
$connected = is_array($credential) && trim((string)($credential['api_token_encrypted'] ?? '')) !== '' && (int)($credential['kintone_app_id'] ?? 0) > 0;
$latestJobs = $pdo->query('SELECT * FROM kintone_sync_jobs ORDER BY id DESC LIMIT 20')->fetchAll() ?: [];
$failedJobs = $pdo->query('SELECT id, job_key, failed_items, finished_at, status FROM kintone_sync_jobs WHERE failed_items > 0 ORDER BY id DESC LIMIT 10')->fetchAll() ?: [];
$lastItems = $pdo->query('SELECT * FROM kintone_sync_job_items ORDER BY id DESC LIMIT 50')->fetchAll() ?: [];
$targets = [];
if ($canAdmin) {
    $targets = $pdo->query('SELECT id, organization_code, organization_name, activity_status, last_synced_at FROM organizations WHERE is_active = 1 ORDER BY organization_code ASC LIMIT 200')->fetchAll() ?: [];
}

function kintone_sync_badge(string $status): string
{
    return match ($status) {
        'success' => '<span class="badge badge-success">success</span>',
        'partial' => '<span class="badge badge-warn">partial</span>',
        'failed' => '<span class="badge badge-danger">failed</span>',
        'running' => '<span class="badge">running</span>',
        default => '<span class="badge">' . kintone_h($status) . '</span>',
    };
}

kintone_page_header('kintone同期', $user);
kintone_render_flash();
?>
<section class="card">
  <h2>同期状態</h2>
  <p>接続情報: <?= $connected ? '<span class="badge badge-success">登録済み</span>' : '<span class="badge badge-danger">未設定</span>' ?> / 活動中同期対象: <?= $activeCount ?>件</p>
  <?php if (is_array($credential)): ?>
    <p class="muted">サブドメイン: <?= kintone_h($credential['kintone_subdomain'] ?? '') ?> / アプリID: <?= kintone_h($credential['kintone_app_id'] ?? '') ?> / 最終テスト: <?= kintone_h($credential['last_tested_at'] ?? '-') ?></p>
  <?php endif; ?>
  <?php if (!$canAdmin): ?>
    <p class="alert alert-warning">kintone同期の実行はadminのみ可能です。viewer/operatorはログ閲覧のみです。</p>
  <?php elseif (!$connected): ?>
    <p class="alert alert-warning">先に接続設定でサブドメイン・アプリID・APIトークンを保存してください。</p>
    <p><a class="primary" href="settings.php">接続設定へ</a></p>
  <?php else: ?>
    <form method="post" action="api/sync_kintone.php" class="form-actions">
      <?= kintone_auth_csrf_field() ?>
      <input type="hidden" name="action" value="sync_all">
      <button class="primary" type="submit" onclick="return confirm('活動中団体をkintoneへ同期します。実行しますか？');">活動中団体をすべて同期</button>
      <a class="secondary" href="settings.php">接続設定</a>
    </form>
  <?php endif; ?>
</section>

<?php if ($canAdmin && $connected && $failedJobs !== []): ?>
<section class="card">
  <h2>失敗分のみ再同期</h2>
  <form method="post" action="api/sync_kintone.php" class="form-actions">
    <?= kintone_auth_csrf_field() ?>
    <input type="hidden" name="action" value="retry_failed">
    <label for="source_job_id" class="sr-only">再同期元ジョブ</label>
    <select id="source_job_id" name="source_job_id">
      <?php foreach ($failedJobs as $job): ?>
        <option value="<?= (int)$job['id'] ?>">#<?= (int)$job['id'] ?> <?= kintone_h($job['status']) ?> / failed <?= (int)$job['failed_items'] ?> / <?= kintone_h($job['finished_at'] ?? '') ?></option>
      <?php endforeach; ?>
    </select>
    <button class="primary" type="submit">選択ジョブの失敗分を再同期</button>
  </form>
</section>
<?php endif; ?>

<?php if ($canAdmin && $connected): ?>
<section class="card">
  <h2>個別同期</h2>
  <p class="muted">大量同期前のテスト用です。最大200件まで表示しています。</p>
  <form method="post" action="api/sync_kintone.php">
    <?= kintone_auth_csrf_field() ?>
    <input type="hidden" name="action" value="sync_selected">
    <div class="table-wrap"><table><thead><tr><th></th><th>団体ID</th><th>団体名</th><th>活動状態</th><th>最終同期</th></tr></thead><tbody>
      <?php foreach ($targets as $org): ?>
        <tr><td><input type="checkbox" name="organization_ids[]" value="<?= (int)$org['id'] ?>"></td><td><?= kintone_h($org['organization_code']) ?></td><td><?= kintone_h($org['organization_name']) ?></td><td><?= kintone_h($org['activity_status']) ?></td><td><?= kintone_h($org['last_synced_at'] ?? '-') ?></td></tr>
      <?php endforeach; ?>
      <?php if ($targets === []): ?><tr><td colspan="5" class="empty">同期対象がありません。</td></tr><?php endif; ?>
    </tbody></table></div>
    <div class="form-actions"><button class="secondary" type="submit">選択団体のみ同期</button></div>
  </form>
</section>
<?php endif; ?>

<section class="card">
  <h2>同期ジョブ</h2>
  <div class="table-wrap"><table><thead><tr><th>ID</th><th>状態</th><th>種別</th><th>対象</th><th>成功</th><th>失敗</th><th>開始</th><th>終了</th><th>メッセージ</th></tr></thead><tbody>
    <?php foreach ($latestJobs as $job): ?>
      <tr><td>#<?= (int)$job['id'] ?></td><td><?= kintone_sync_badge((string)$job['status']) ?></td><td><?= kintone_h($job['job_type']) ?></td><td><?= (int)$job['total_items'] ?></td><td><?= (int)$job['success_items'] ?></td><td><?= (int)$job['failed_items'] ?></td><td><?= kintone_h($job['started_at'] ?? '') ?></td><td><?= kintone_h($job['finished_at'] ?? '') ?></td><td><?= kintone_h($job['message'] ?? '') ?></td></tr>
    <?php endforeach; ?>
    <?php if ($latestJobs === []): ?><tr><td colspan="9" class="empty">同期ジョブはまだありません。</td></tr><?php endif; ?>
  </tbody></table></div>
</section>

<section class="card">
  <h2>直近の同期明細</h2>
  <div class="table-wrap"><table><thead><tr><th>ID</th><th>Job</th><th>団体ID</th><th>操作</th><th>状態</th><th>kintone ID</th><th>エラー</th><th>作成日時</th></tr></thead><tbody>
    <?php foreach ($lastItems as $item): ?>
      <tr><td><?= (int)$item['id'] ?></td><td>#<?= (int)$item['job_id'] ?></td><td><?= kintone_h($item['organization_code'] ?? '') ?></td><td><?= kintone_h($item['action_type']) ?></td><td><?= kintone_sync_badge((string)$item['status']) ?></td><td><?= kintone_h($item['kintone_record_id'] ?? '') ?></td><td><?= kintone_h($item['error_message'] ?? '') ?></td><td><?= kintone_h($item['created_at']) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($lastItems === []): ?><tr><td colspan="8" class="empty">同期明細はまだありません。</td></tr><?php endif; ?>
  </tbody></table></div>
</section>
<?php kintone_page_footer(); ?>
