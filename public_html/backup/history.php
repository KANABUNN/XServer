<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
$user = backup_web_require_user();
$manager = backup_web_manager();
$jobs = $manager->latestJobs(100);
backup_web_header('実行履歴', $user);
?>
<section class="card">
  <h2>バックアップ実行履歴</h2>
  <table>
    <thead><tr><th>ID</th><th>ジョブキー</th><th>種別</th><th>トリガー</th><th>開始</th><th>終了</th><th>状態</th><th>成功/失敗</th><th>サイズ</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($jobs as $job): ?>
      <tr>
        <td><?= (int)$job['id'] ?></td>
        <td class="mono"><?= backup_h((string)$job['job_key']) ?></td>
        <td><?= backup_h((string)$job['job_type']) ?></td>
        <td><?= backup_h((string)$job['trigger_type']) ?></td>
        <td><?= backup_h((string)$job['started_at']) ?></td>
        <td><?= backup_h((string)($job['finished_at'] ?? '-')) ?></td>
        <td><?= backup_web_status_badge((string)$job['status']) ?></td>
        <td><?= (int)$job['success_items'] ?> / <?= (int)$job['failed_items'] ?></td>
        <td><?= backup_h(backup_format_bytes((int)$job['total_bytes'])) ?></td>
        <td><a href="detail.php?id=<?= (int)$job['id'] ?>">詳細</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php
backup_web_footer();
