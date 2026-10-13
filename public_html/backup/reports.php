<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
$user = backup_web_require_user();
$manager = backup_web_manager();
$reports = $manager->latestReports(30);
backup_web_header('レポート', $user, ['subtitle' => '月次バックアップレポートの生成記録']);
?>
<section class="card">
  <div class="card__header">
    <div class="card__title"><?= fitsc_icon('file-text') ?>月次レポート <small><?= count($reports) ?>件</small></div>
    <span class="text-xs text-subtle"><?= fitsc_icon('lock', 'ico--sm') ?> 本文はサーバー上の保存先で確認します</span>
  </div>
  <?php if ($reports === []): ?>
    <?= backup_web_empty('file-text', 'レポートはまだ生成されていません', '定期実行（cron）または <code>apps/jobs/backup_monthly_report.php</code> で生成します。') ?>
  <?php else: ?>
    <div class="table-wrap is-stacked">
      <table class="table">
        <thead><tr><th>レポート</th><th>生成日時</th><th>状態</th><th>保存先・SHA256</th></tr></thead>
        <tbody>
        <?php foreach ($reports as $report): ?>
          <tr>
            <td><span class="cell-main"><?= backup_h((string)$report['report_key']) ?></span><span class="cell-sub"><?= backup_h(backup_web_job_type_label((string)$report['report_type'])) ?> ・ #<?= (int)$report['id'] ?></span></td>
            <td class="nowrap"><?= backup_h((string)$report['generated_at']) ?></td>
            <td><?= backup_web_status_badge((string)$report['status']) ?></td>
            <td><span class="mono text-xs allow-select bk-wrap"><?= backup_h(backup_mask_path((string)$report['report_path'])) ?></span><span class="cell-sub mono bk-hash allow-select"><?= backup_h((string)($report['report_sha256'] ?? '')) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php
backup_web_footer();
