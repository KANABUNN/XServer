<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
$user = backup_web_require_user();
$manager = backup_web_manager();
$jobs = $manager->latestJobs(8);
$latest = $jobs[0] ?? null;
$alerts = $manager->unresolvedAlerts(8);
$items = is_array($latest) ? $manager->listItems((int)$latest['id']) : [];
$snapshot = $manager->latestStorageSnapshot();
$latestTone = $latest ? backup_web_status_tone((string)$latest['status']) : '';
backup_web_header('ダッシュボード', $user);
?>
<div class="stat-grid">
  <a class="stat<?= $latestTone !== '' ? ' stat--' . $latestTone : '' ?> bk-stat-link" href="<?= $latest ? 'detail.php?id=' . (int)$latest['id'] : 'history.php' ?>">
    <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('archive') ?></span>最新のバックアップ</span>
    <span class="stat__value"><?= $latest ? backup_h(backup_status_label((string)$latest['status'])) : '未実行' ?></span>
    <span class="stat__meta"><?= $latest ? backup_h((string)$latest['started_at']) . ' ・ ' . backup_h(backup_web_job_type_label((string)$latest['job_type'])) : 'まだ履歴がありません' ?></span>
  </a>
  <div class="stat stat--info">
    <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('database') ?></span>保存サイズ</span>
    <span class="stat__value"><?= $latest ? backup_h(backup_format_bytes((int)$latest['total_bytes'])) : '—' ?></span>
    <span class="stat__meta">成功 <?= $latest ? (int)$latest['success_items'] : 0 ?> ・ 失敗 <?= $latest ? (int)$latest['failed_items'] : 0 ?></span>
  </div>
  <a class="stat <?= $alerts !== [] ? 'stat--warning' : 'stat--success' ?> bk-stat-link" href="alerts.php">
    <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('bell') ?></span>未解決のアラート</span>
    <span class="stat__value"><?= count($alerts) ?></span>
    <span class="stat__meta"><?= $alerts !== [] ? '確認が必要です' : '問題はありません' ?></span>
  </a>
  <a class="stat<?= $snapshot ? ' stat--' . (backup_web_status_tone((string)$snapshot['status']) ?: 'teal') : '' ?> bk-stat-link" href="storage.php">
    <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('hard-drive') ?></span>ストレージ</span>
    <span class="stat__value"><?= $snapshot ? backup_h(backup_format_bytes((int)$snapshot['backup_root_bytes'])) : '未収集' ?></span>
    <span class="stat__meta"><?= $snapshot ? backup_h((string)$snapshot['collected_at']) . ' 収集' : '収集ジョブが未実行です' ?></span>
  </a>
</div>

<?php if ($alerts !== []): ?>
<section class="card bk-alerts">
  <div class="card__header">
    <div class="card__title"><?= fitsc_icon('alert-triangle') ?>未解決のアラート</div>
    <a class="btn btn--ghost btn--sm" href="alerts.php">対応する<?= fitsc_icon('arrow-right') ?></a>
  </div>
  <div class="list">
    <?php foreach ($alerts as $alert): ?>
      <div class="list-item">
        <span class="list-item__side"><?= backup_web_level_badge((string)$alert['level']) ?></span>
        <span class="list-item__main">
          <span class="list-item__title bk-wrap"><?= backup_h((string)$alert['message']) ?></span>
          <span class="list-item__meta"><?= backup_h((string)$alert['created_at']) ?></span>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<div class="split split--wide-left">
  <section class="card">
    <div class="card__header">
      <div class="card__title"><?= fitsc_icon('layers') ?>最新バックアップの内訳</div>
      <?php if ($latest): ?><a class="btn btn--ghost btn--sm" href="detail.php?id=<?= (int)$latest['id'] ?>">詳細<?= fitsc_icon('arrow-right') ?></a><?php endif; ?>
    </div>
    <?php if (!$latest): ?>
      <?= backup_web_empty('archive', 'バックアップはまだ実行されていません', '定期実行（cron）または <code>apps/jobs/backup_daily.php</code> で実行します。') ?>
    <?php elseif ($items === []): ?>
      <?= backup_web_empty('layers', '項目がありません') ?>
    <?php else: ?>
      <div class="table-wrap is-stacked">
        <table class="table table--compact">
          <thead><tr><th>対象</th><th>状態</th><th class="text-right">件数</th><th class="text-right">サイズ</th></tr></thead>
          <tbody>
          <?php foreach ($items as $item): ?>
            <tr>
              <td><span class="cell-main"><?= backup_h((string)$item['target_label']) ?></span><span class="cell-sub"><?= backup_h((string)$item['item_type']) ?> ・ <span class="mono"><?= backup_h(backup_mask_path((string)($item['source_path'] ?? ''))) ?></span></span></td>
              <td><?= backup_web_status_badge((string)$item['status']) ?><?php if (!empty($item['error_message'])): ?><span class="cell-sub text-danger"><?= backup_h((string)$item['error_message']) ?></span><?php endif; ?></td>
              <td class="text-right num"><?= $item['file_count'] !== null ? number_format((int)$item['file_count']) : '—' ?></td>
              <td class="text-right num nowrap"><?= backup_h(backup_format_bytes((int)$item['total_bytes'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card__header">
      <div class="card__title"><?= fitsc_icon('history') ?>直近の実行</div>
      <a class="btn btn--ghost btn--sm" href="history.php">すべて<?= fitsc_icon('arrow-right') ?></a>
    </div>
    <?php if ($jobs === []): ?>
      <?= backup_web_empty('history', '履歴はありません') ?>
    <?php else: ?>
      <div class="list">
        <?php foreach ($jobs as $job): ?>
          <a class="list-item" href="detail.php?id=<?= (int)$job['id'] ?>">
            <span class="list-item__main">
              <span class="list-item__title"><?= backup_h((string)$job['started_at']) ?></span>
              <span class="list-item__meta">#<?= (int)$job['id'] ?> ・ <?= backup_h(backup_web_job_type_label((string)$job['job_type'])) ?> ・ <?= backup_h(backup_format_bytes((int)$job['total_bytes'])) ?></span>
            </span>
            <span class="list-item__side"><?= backup_web_status_badge((string)$job['status']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php
backup_web_footer();
