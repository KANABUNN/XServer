<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
$user = backup_web_require_user();
$manager = backup_web_manager();
$snapshots = $manager->latestStorageSnapshots(20);
$latest = $snapshots[0] ?? null;
$payload = [];
if ($latest && isset($latest['payload_json'])) {
    $decoded = json_decode((string)$latest['payload_json'], true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
$directories = is_array($payload['directories'] ?? null) ? $payload['directories'] : [];
$dbCounts = is_array($payload['db_counts'] ?? null) ? $payload['db_counts'] : [];
$policies = is_array($payload['policies'] ?? null) ? $payload['policies'] : [];
$disk = is_array($payload['disk'] ?? null) ? $payload['disk'] : [];
$cleanupLog = is_array($payload['cleanup_log'] ?? null) ? $payload['cleanup_log'] : [];
backup_web_header('ストレージ状況', $user);
?>
<section class="grid">
  <div class="card">
    <h2>最新収集状態</h2>
    <div class="metric"><?= $latest ? backup_web_status_badge((string)$latest['status']) : '未収集' ?></div>
    <p class="muted"><?= $latest ? backup_h((string)$latest['collected_at']) : 'backup_status_collect.php を実行してください。' ?></p>
  </div>
  <div class="card">
    <h2>バックアップ保存量</h2>
    <div class="metric"><?= $latest ? backup_h(backup_format_bytes((int)$latest['backup_root_bytes'])) : '-' ?></div>
    <p class="muted">private_backups 配下</p>
  </div>
  <div class="card">
    <h2>清掃cronログ</h2>
    <div class="metric"><?= !empty($cleanupLog['exists']) ? 'あり' : 'なし' ?></div>
    <p class="muted"><?= backup_h((string)($cleanupLog['mtime'] ?? '-')) ?></p>
  </div>
  <div class="card">
    <h2>ディスク使用率</h2>
    <?php $ratio = $disk['used_ratio'] ?? null; ?>
    <div class="metric"><?= is_numeric($ratio) ? backup_h((string)round((float)$ratio * 100, 1)) . '%' : '-' ?></div>
    <?php if (is_numeric($ratio)): ?><div class="bar"><span style="width:<?= max(0, min(100, (float)$ratio * 100)) ?>%"></span></div><?php endif; ?>
    <p class="muted">空き: <?= isset($disk['free_bytes']) ? backup_h(backup_format_bytes((int)$disk['free_bytes'])) : '-' ?></p>
  </div>
</section>

<?php if ($latest && (string)$latest['status'] !== 'success'): ?>
<section class="card" style="margin-top:16px">
  <h2>収集警告</h2>
  <p class="warn"><?= backup_h((string)($payload['maintenance_error'] ?? '不明な警告')) ?></p>
</section>
<?php endif; ?>

<section class="card" style="margin-top:16px">
  <h2>ディレクトリ使用量</h2>
  <?php if ($directories === []): ?>
    <p class="muted">ディレクトリ情報がありません。</p>
  <?php else: ?>
    <table>
      <thead><tr><th>区分</th><th>パス</th><th>存在</th><th>ファイル</th><th>ディレクトリ</th><th>サイズ</th><th>最終更新</th></tr></thead>
      <tbody>
      <?php foreach ($directories as $key => $stats): if (!is_array($stats)) continue; ?>
        <tr>
          <td><strong><?= backup_h((string)$key) ?></strong></td>
          <td class="mono"><?= backup_h((string)($stats['masked_path'] ?? backup_mask_path((string)($stats['path'] ?? '')))) ?></td>
          <td><?= !empty($stats['exists']) ? '<span class="ok">あり</span>' : '<span class="danger">なし</span>' ?></td>
          <td><?= (int)($stats['files'] ?? 0) ?></td>
          <td><?= (int)($stats['dirs'] ?? 0) ?></td>
          <td><?= backup_h(backup_format_bytes((int)($stats['bytes'] ?? 0))) ?></td>
          <td><?= backup_h((string)($stats['latest_mtime'] ?? '-')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="card" style="margin-top:16px">
  <h2>保持ポリシー</h2>
  <?php if ($policies === []): ?>
    <p class="muted">既存 storage_maintenance.php からポリシーを取得できませんでした。</p>
  <?php else: ?>
    <table>
      <thead><tr><th>キー</th><th>値</th></tr></thead>
      <tbody>
      <?php foreach ($policies as $key => $value): ?>
        <tr><td><?= backup_h((string)$key) ?></td><td><?= backup_h((string)$value) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="card" style="margin-top:16px">
  <h2>DB蓄積状況</h2>
  <?php if ($dbCounts === []): ?>
    <p class="muted">DB件数情報がありません。</p>
  <?php else: ?>
    <table>
      <thead><tr><th>対象</th><th>総行数</th><th>保持期間超過</th><th>保持日数</th></tr></thead>
      <tbody>
      <?php foreach ($dbCounts as $row): if (!is_array($row)) continue; ?>
        <tr>
          <td><?= backup_h((string)($row['label'] ?? '')) ?></td>
          <td><?= (int)($row['total_rows'] ?? 0) ?></td>
          <td><?= (int)($row['rows_older_than_retention'] ?? 0) ?></td>
          <td><?= (int)($row['retention_days'] ?? 0) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="card" style="margin-top:16px">
  <h2>storage_cleanup.log 末尾</h2>
  <?php if (empty($cleanupLog['tail'])): ?>
    <p class="muted">ログ末尾を取得できませんでした。</p>
  <?php else: ?>
    <div class="pre"><?= backup_h((string)$cleanupLog['tail']) ?></div>
  <?php endif; ?>
</section>

<section class="card" style="margin-top:16px">
  <h2>収集履歴</h2>
  <table>
    <thead><tr><th>ID</th><th>収集日時</th><th>状態</th><th>総量</th><th>Forms live</th><th>Forms archive</th><th>Backup root</th></tr></thead>
    <tbody>
    <?php foreach ($snapshots as $snapshot): ?>
      <tr>
        <td><?= (int)$snapshot['id'] ?></td>
        <td><?= backup_h((string)$snapshot['collected_at']) ?></td>
        <td><?= backup_web_status_badge((string)$snapshot['status']) ?></td>
        <td><?= backup_h(backup_format_bytes((int)$snapshot['total_bytes'])) ?></td>
        <td><?= backup_h(backup_format_bytes((int)$snapshot['forms_live_bytes'])) ?></td>
        <td><?= backup_h(backup_format_bytes((int)$snapshot['forms_archive_bytes'])) ?></td>
        <td><?= backup_h(backup_format_bytes((int)$snapshot['backup_root_bytes'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php
backup_web_footer();
