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
$canOperate = kintone_user_can_operate($user);
$isAdmin = kintone_auth_user_has_role($user, 'admin');
kintone_page_header('ダッシュボード', $user, [
    'actions' => $canOperate ? '<a class="btn btn--primary hide-mobile" href="upload.php">' . fitsc_icon('upload') . '名簿をアップロード</a>' : '',
]);
kintone_render_flash();
?>
<div class="stat-grid">
  <a class="stat kt-stat-link" href="organizations.php">
    <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('building') ?></span>登録団体</span>
    <span class="stat__value"><?= $counts['orgs'] ?></span>
  </a>
  <a class="stat stat--success kt-stat-link" href="organizations.php">
    <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('check-circle') ?></span>活動中</span>
    <span class="stat__value"><?= $counts['active'] ?></span>
  </a>
  <a class="stat stat--danger kt-stat-link" href="organizations.php">
    <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('x-circle') ?></span>活動不可</span>
    <span class="stat__value"><?= $counts['inactive'] ?></span>
  </a>
  <a class="stat <?= $counts['review'] > 0 ? 'stat--warning' : 'stat--teal' ?> kt-stat-link" href="review.php">
    <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('alert-triangle') ?></span>高リスク・未反映</span>
    <span class="stat__value"><?= $counts['review'] ?></span>
  </a>
</div>

<section class="card">
  <div class="card__header card__header--plain">
    <div class="card__title"><?= fitsc_icon('sparkles') ?>名簿反映の流れ</div>
  </div>
  <div class="card__body">
    <ol class="kt-flow">
      <li><a href="upload.php"><span class="kt-flow__icon"><?= fitsc_icon('upload') ?></span><strong>名簿アップロード</strong><small>部員名簿CSVを解析</small></a></li>
      <li><a href="diff.php"><span class="kt-flow__icon"><?= fitsc_icon('diff') ?></span><strong>差分の確認</strong><small>変更点とリスクを確認</small></a></li>
      <li><a href="review.php"><span class="kt-flow__icon"><?= fitsc_icon('inbox') ?></span><strong>レビュー</strong><small>要確認の値を確定・除外</small></a></li>
      <li><a href="diff.php"><span class="kt-flow__icon"><?= fitsc_icon('check-circle') ?></span><strong>承認・反映</strong><small>団体マスタへ反映</small></a></li>
      <li><a href="sync.php"><span class="kt-flow__icon"><?= fitsc_icon('refresh') ?></span><strong>kintone同期</strong><small>各アプリへ送信・取得</small></a></li>
    </ol>
  </div>
</section>

<div class="split">
  <section class="card">
    <div class="card__header">
      <div class="card__title"><?= fitsc_icon('cloud') ?>kintoneアプリ</div>
      <?php if ($isAdmin): ?><a class="btn btn--ghost btn--sm" href="settings.php"><?= fitsc_icon('settings') ?>アプリ設定</a><?php endif; ?>
    </div>
    <?php if ($apps === []): ?>
      <div class="empty"><span class="empty__icon"><?= fitsc_icon('cloud') ?></span><span>アプリはまだ登録されていません。</span></div>
    <?php else: ?>
      <div class="list">
        <?php foreach ($apps as $app): ?>
          <a class="list-item" href="sync.php?app_key=<?= rawurlencode((string)$app['app_key']) ?>">
            <span class="list-item__icon"><?= fitsc_icon((string)$app['app_role'] === 'pull' ? 'download' : ((string)$app['app_role'] === 'push' ? 'upload' : 'database'), 'ico--sm') ?></span>
            <span class="list-item__main">
              <span class="list-item__title"><?= kintone_h($app['display_name']) ?></span>
              <span class="list-item__meta"><span class="mono"><?= kintone_h($app['app_key']) ?></span> ・ 最終同期 <?= kintone_h($app['last_synced_at'] ?? '—') ?></span>
            </span>
            <span class="list-item__side"><?= kintone_role_badge((string)$app['app_role']) ?><?= kintone_status_badge((string)($app['last_sync_status'] ?: $app['status'])) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <div class="stack">
    <section class="card">
      <div class="card__header">
        <div class="card__title"><?= fitsc_icon('refresh') ?>最新の同期</div>
        <a class="btn btn--ghost btn--sm" href="sync.php">同期画面<?= fitsc_icon('arrow-right') ?></a>
      </div>
      <?php if (is_array($latestSync)): ?>
        <div class="card__body">
          <div class="kt-sync-summary">
            <div class="kt-sync-summary__head">
              <span class="mono">#<?= (int)$latestSync['id'] ?></span>
              <span class="mono text-subtle"><?= kintone_h($latestSync['target_app']) ?></span>
              <?= kintone_status_badge((string)$latestSync['status']) ?>
            </div>
            <dl class="kt-sync-summary__nums">
              <div><dt>成功</dt><dd class="text-success"><?= (int)$latestSync['success_items'] ?></dd></div>
              <div><dt>失敗</dt><dd class="<?= (int)$latestSync['failed_items'] > 0 ? 'text-danger' : '' ?>"><?= (int)$latestSync['failed_items'] ?></dd></div>
              <div><dt>終了</dt><dd class="text-sm"><?= kintone_h($latestSync['finished_at'] ?? '—') ?></dd></div>
            </dl>
          </div>
        </div>
      <?php else: ?>
        <div class="empty"><span class="empty__icon"><?= fitsc_icon('refresh') ?></span><span>同期ジョブはまだありません。</span></div>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card__header">
        <div class="card__title"><?= fitsc_icon('file-text') ?>最近のアップロード</div>
        <a class="btn btn--ghost btn--sm" href="diff.php">差分を見る<?= fitsc_icon('arrow-right') ?></a>
      </div>
      <?php if ($latest === []): ?>
        <div class="empty"><span class="empty__icon"><?= fitsc_icon('upload') ?></span><span>アップロード履歴はありません。</span></div>
      <?php else: ?>
        <div class="list">
          <?php foreach ($latest as $row): ?>
            <a class="list-item" href="diff.php?batch_id=<?= (int)$row['id'] ?>">
              <span class="list-item__icon"><?= fitsc_icon('file-text', 'ico--sm') ?></span>
              <span class="list-item__main">
                <span class="list-item__title"><?= kintone_h($row['original_name']) ?></span>
                <span class="list-item__meta">#<?= (int)$row['id'] ?> ・ <?= (int)$row['total_rows'] ?>行 ・ <?= kintone_h($row['created_at']) ?></span>
              </span>
              <span class="list-item__side"><?= kintone_status_badge((string)$row['status']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php kintone_page_footer(); ?>
