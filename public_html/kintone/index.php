<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
$user = kintone_auth_require_view_access();
$pdo = kintone_pdo('org');
$counts = [
    'orgs' => (int)$pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn(),
    'active' => (int)$pdo->query('SELECT COUNT(*) FROM organizations WHERE activity_status="active"')->fetchColumn(),
    'inactive' => (int)$pdo->query('SELECT COUNT(*) FROM organizations WHERE activity_status="inactive"')->fetchColumn(),
    'review' => (int)$pdo->query('SELECT COUNT(*) FROM organization_change_logs WHERE risk_level="high" AND applied_at IS NULL')->fetchColumn(),
];
$latest = $pdo->query('SELECT * FROM roster_import_batches ORDER BY id DESC LIMIT 5')->fetchAll() ?: [];
$latestSync = $pdo->query('SELECT * FROM kintone_sync_jobs ORDER BY id DESC LIMIT 1')->fetch() ?: null;
$apps = [];
try {
    $apps = $pdo->query('SELECT app_key, display_name, app_role, status, last_sync_status, last_synced_at FROM kintone_apps WHERE is_active = 1 ORDER BY FIELD(app_key, "organizations") DESC, app_key ASC LIMIT 8')->fetchAll() ?: [];
} catch (Throwable $e) {
    error_log('[kintone dashboard apps] ' . $e->getMessage());
}
kintone_page_header('ダッシュボード', $user);
kintone_render_flash();
?>
<section class="card"><h2>状態</h2><p>団体数: <?= $counts['orgs'] ?> / 活動中: <?= $counts['active'] ?> / 活動不可: <?= $counts['inactive'] ?> / 高リスク未反映: <?= $counts['review'] ?></p></section>
<section class="card"><h2>kintoneアプリレジストリ</h2><div class="table-wrap"><table><thead><tr><th>app_key</th><th>表示名</th><th>ロール</th><th>状態</th><th>最終同期</th></tr></thead><tbody><?php foreach ($apps as $app): ?><tr><td><code><?= kintone_h($app['app_key']) ?></code></td><td><?= kintone_h($app['display_name']) ?></td><td><?= kintone_h($app['app_role']) ?></td><td><?= kintone_h($app['status']) ?> / <?= kintone_h($app['last_sync_status'] ?? '-') ?></td><td><?= kintone_h($app['last_synced_at'] ?? '-') ?></td></tr><?php endforeach; ?><?php if ($apps === []): ?><tr><td colspan="5" class="empty">アプリレジストリは未登録です。</td></tr><?php endif; ?></tbody></table></div><p><a class="btn" href="settings.php">アプリ設定を開く</a></p></section>
<section class="card"><h2>kintone同期</h2><?php if (is_array($latestSync)): ?><p>最新ジョブ: #<?= (int)$latestSync['id'] ?> / app_key: <code><?= kintone_h($latestSync['target_app']) ?></code> / 状態: <span class="badge"><?= kintone_h($latestSync['status']) ?></span> / 成功: <?= (int)$latestSync['success_items'] ?> / 失敗: <?= (int)$latestSync['failed_items'] ?> / 終了: <?= kintone_h($latestSync['finished_at'] ?? '-') ?></p><?php else: ?><p class="muted">同期ジョブはまだありません。</p><?php endif; ?><p><a class="btn" href="sync.php">同期画面を開く</a></p></section>
<section class="card"><h2>最新アップロード</h2><div class="table-wrap"><table><thead><tr><th>ID</th><th>ファイル</th><th>状態</th><th>行数</th><th>作成日時</th></tr></thead><tbody><?php foreach ($latest as $row): ?><tr><td><?= (int)$row['id'] ?></td><td><?= kintone_h($row['original_name']) ?></td><td><?= kintone_h($row['status']) ?></td><td><?= (int)$row['total_rows'] ?></td><td><?= kintone_h($row['created_at']) ?></td></tr><?php endforeach; ?><?php if ($latest === []): ?><tr><td colspan="5" class="empty">アップロード履歴はありません。</td></tr><?php endif; ?></tbody></table></div></section>
<?php kintone_page_footer(); ?>
