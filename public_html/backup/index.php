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
backup_web_header('ダッシュボード', $user);
?>
<section class="grid">
  <div class="card">
    <h2>最新ジョブ状態</h2>
    <div class="metric"><?= $latest ? backup_web_status_badge((string)$latest['status']) : '未実行' ?></div>
    <p class="muted"><?= $latest ? backup_h((string)$latest['message']) : 'まだバックアップ履歴がありません。' ?></p>
  </div>
  <div class="card">
    <h2>最終実行</h2>
    <div class="metric"><?= $latest ? backup_h((string)$latest['started_at']) : '-' ?></div>
    <p class="muted">種別: <?= $latest ? backup_h((string)$latest['job_type']) : '-' ?></p>
  </div>
  <div class="card">
    <h2>保存サイズ</h2>
    <div class="metric"><?= $latest ? backup_h(backup_format_bytes((int)$latest['total_bytes'])) : '-' ?></div>
    <p class="muted">成功 <?= $latest ? (int)$latest['success_items'] : 0 ?> / 失敗 <?= $latest ? (int)$latest['failed_items'] : 0 ?></p>
  </div>
  <div class="card">
    <h2>未解決警告</h2>
    <div class="metric"><?= count($alerts) ?></div>
    <p class="muted">失敗・検証エラーはここに残ります。</p>
  </div>
  <div class="card">
    <h2>ストレージ収集</h2>
    <div class="metric"><?= $snapshot ? backup_web_status_badge((string)$snapshot['status']) : '未収集' ?></div>
    <p class="muted"><?= $snapshot ? backup_h((string)$snapshot['collected_at']) . ' / ' . backup_h(backup_format_bytes((int)$snapshot['backup_root_bytes'])) : 'backup_status_collect.php を実行してください。' ?></p>
  </div>
</section>

<?php if ($alerts !== []): ?>
<section class="card" style="margin-top:16px">
  <h2>未解決アラート</h2>
  <?php foreach ($alerts as $alert): ?>
    <div class="alert"><strong><?= backup_h((string)$alert['level']) ?></strong> <?= backup_h((string)$alert['message']) ?><br><span class="small"><?= backup_h((string)$alert['created_at']) ?></span></div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card" style="margin-top:16px">
  <h2>最新バックアップ内訳</h2>
  <?php if (!$latest): ?>
    <p class="muted">バックアップ未実行です。cronまたはCLIで <span class="mono">apps/jobs/backup_daily.php</span> を実行してください。</p>
  <?php else: ?>
    <table>
      <thead><tr><th>種別</th><th>対象</th><th>状態</th><th>件数</th><th>サイズ</th><th>SHA256</th></tr></thead>
      <tbody>
      <?php foreach ($items as $item): ?>
        <tr>
          <td><?= backup_h((string)$item['item_type']) ?></td>
          <td><strong><?= backup_h((string)$item['target_label']) ?></strong><br><span class="small muted"><?= backup_h(backup_mask_path((string)($item['source_path'] ?? ''))) ?></span></td>
          <td><?= backup_web_status_badge((string)$item['status']) ?><?php if (!empty($item['error_message'])): ?><br><span class="danger small"><?= backup_h((string)$item['error_message']) ?></span><?php endif; ?></td>
          <td><?= $item['file_count'] !== null ? (int)$item['file_count'] : '-' ?></td>
          <td><?= backup_h(backup_format_bytes((int)$item['total_bytes'])) ?></td>
          <td class="mono"><?= backup_h(substr((string)($item['sha256'] ?? ''), 0, 16)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p><a class="btn" href="detail.php?id=<?= (int)$latest['id'] ?>">詳細を見る</a></p>
  <?php endif; ?>
</section>

<section class="card" style="margin-top:16px">
  <h2>直近履歴</h2>
  <table>
    <thead><tr><th>ID</th><th>種別</th><th>開始</th><th>終了</th><th>状態</th><th>サイズ</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($jobs as $job): ?>
      <tr>
        <td><?= (int)$job['id'] ?></td>
        <td><?= backup_h((string)$job['job_type']) ?></td>
        <td><?= backup_h((string)$job['started_at']) ?></td>
        <td><?= backup_h((string)($job['finished_at'] ?? '-')) ?></td>
        <td><?= backup_web_status_badge((string)$job['status']) ?></td>
        <td><?= backup_h(backup_format_bytes((int)$job['total_bytes'])) ?></td>
        <td><a href="detail.php?id=<?= (int)$job['id'] ?>">詳細</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php
backup_web_footer();
