<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
$user = backup_web_require_admin();
$manager = backup_web_manager();
$id = max(0, (int)($_GET['id'] ?? 0));
$job = $id > 0 ? $manager->getJob($id) : null;
if (!$job) {
    http_response_code(404);
    backup_web_header('見つかりません', $user);
    echo '<section class="card"><h2>ジョブが見つかりません</h2><p><a href="history.php">履歴へ戻る</a></p></section>';
    backup_web_footer();
    exit;
}
$items = $manager->listItems((int)$job['id']);
backup_web_header('バックアップ詳細', $user);
?>
<section class="grid">
  <div class="card"><h2>ジョブ</h2><div class="metric">#<?= (int)$job['id'] ?></div><p class="mono"><?= backup_h((string)$job['job_key']) ?></p></div>
  <div class="card"><h2>状態</h2><div class="metric"><?= backup_web_status_badge((string)$job['status']) ?></div><p class="muted"><?= backup_h((string)$job['message']) ?></p></div>
  <div class="card"><h2>サイズ</h2><div class="metric"><?= backup_h(backup_format_bytes((int)$job['total_bytes'])) ?></div><p class="muted">manifest: <?= backup_h(substr((string)($job['manifest_sha256'] ?? ''), 0, 16)) ?></p></div>
</section>

<section class="card" style="margin-top:16px">
  <h2>保存先</h2>
  <p class="mono"><?= backup_h(backup_mask_path((string)($job['backup_root'] ?? ''))) ?></p>
  <p class="muted">この画面ではバックアップ本体のダウンロードは提供しません。復元時はSSHまたはXServerファイルマネージャーでWeb非公開領域から取得してください。</p>
</section>

<section class="card" style="margin-top:16px">
  <h2>項目一覧</h2>
  <table>
    <thead><tr><th>ID</th><th>種別</th><th>対象</th><th>状態</th><th>件数</th><th>サイズ</th><th>SHA256</th><th>保存先</th></tr></thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td><?= (int)$item['id'] ?></td>
        <td><?= backup_h((string)$item['item_type']) ?></td>
        <td><strong><?= backup_h((string)$item['target_label']) ?></strong><br><span class="small muted"><?= backup_h(backup_mask_path((string)($item['source_path'] ?? ''))) ?></span></td>
        <td><?= backup_web_status_badge((string)$item['status']) ?><?php if (!empty($item['error_message'])): ?><br><span class="danger small"><?= backup_h((string)$item['error_message']) ?></span><?php endif; ?></td>
        <td><?= $item['file_count'] !== null ? (int)$item['file_count'] : '-' ?></td>
        <td><?= backup_h(backup_format_bytes((int)$item['total_bytes'])) ?></td>
        <td class="mono"><?= backup_h((string)($item['sha256'] ?? '')) ?></td>
        <td class="mono"><?= backup_h(backup_mask_path((string)($item['backup_path'] ?? ''))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php
backup_web_footer();
