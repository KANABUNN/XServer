<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
$user = backup_web_require_user();
$manager = backup_web_manager();
$jobs = $manager->latestJobs(100);
backup_web_header('実行履歴', $user);
?>
<section class="card">
  <div class="card__header">
    <div class="card__title"><?= fitsc_icon('history') ?>バックアップ実行履歴 <small>最新 <?= count($jobs) ?> 件</small></div>
  </div>
  <?php if ($jobs === []): ?>
    <?= backup_web_empty('history', '履歴はありません') ?>
  <?php else: ?>
    <div class="table-wrap is-stacked">
      <table class="table">
        <thead><tr><th>開始</th><th>種別</th><th>状態</th><th class="text-right">成功 / 失敗</th><th class="text-right">サイズ</th><th>終了</th><th class="col-actions"></th></tr></thead>
        <tbody>
        <?php foreach ($jobs as $job): ?>
          <tr data-href="detail.php?id=<?= (int)$job['id'] ?>" class="bk-row-link">
            <td><span class="cell-main nowrap"><?= backup_h((string)$job['started_at']) ?></span><span class="cell-sub mono">#<?= (int)$job['id'] ?> <?= backup_h((string)$job['job_key']) ?></span></td>
            <td><?= backup_h(backup_web_job_type_label((string)$job['job_type'])) ?><span class="cell-sub"><?= backup_h(backup_web_trigger_label((string)$job['trigger_type'])) ?></span></td>
            <td><?= backup_web_status_badge((string)$job['status']) ?></td>
            <td class="text-right num"><span class="text-success"><?= (int)$job['success_items'] ?></span> / <span class="<?= (int)$job['failed_items'] > 0 ? 'text-danger fw-bold' : '' ?>"><?= (int)$job['failed_items'] ?></span></td>
            <td class="text-right num nowrap"><?= backup_h(backup_format_bytes((int)$job['total_bytes'])) ?></td>
            <td class="text-xs nowrap"><?= backup_h((string)($job['finished_at'] ?? '—')) ?></td>
            <td class="col-actions"><a class="btn btn--ghost btn--icon btn--sm" href="detail.php?id=<?= (int)$job['id'] ?>" aria-label="#<?= (int)$job['id'] ?> の詳細"><?= fitsc_icon('chevron-right') ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php
backup_web_footer();
