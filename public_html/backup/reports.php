<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
$user = backup_web_require_user();
$manager = backup_web_manager();
$reports = $manager->latestReports(30);
backup_web_header('レポート', $user);
?>
<section class="card">
  <h2>月次バックアップレポート</h2>
  <?php if ($reports === []): ?>
    <p class="muted">まだレポートが生成されていません。cron または CLI で <span class="mono">apps/jobs/backup_monthly_report.php</span> を実行してください。</p>
  <?php else: ?>
    <table>
      <thead><tr><th>ID</th><th>レポート</th><th>生成日時</th><th>状態</th><th>保存先</th><th>SHA256</th></tr></thead>
      <tbody>
      <?php foreach ($reports as $report): ?>
        <tr>
          <td><?= (int)$report['id'] ?></td>
          <td><strong><?= backup_h((string)$report['report_key']) ?></strong><br><span class="small muted"><?= backup_h((string)$report['report_type']) ?></span></td>
          <td><?= backup_h((string)$report['generated_at']) ?></td>
          <td><?= backup_web_status_badge((string)$report['status']) ?></td>
          <td class="mono"><?= backup_h(backup_mask_path((string)$report['report_path'])) ?></td>
          <td class="mono"><?= backup_h(substr((string)($report['report_sha256'] ?? ''), 0, 16)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted">レポート本文はWebから直接ダウンロードできません。必要な場合はサーバー上の保存先から確認してください。</p>
  <?php endif; ?>
</section>
<?php
backup_web_footer();
