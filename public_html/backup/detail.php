<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
$user = backup_web_require_user();
$manager = backup_web_manager();
$id = max(0, (int)($_GET['id'] ?? 0));
$job = $id > 0 ? $manager->getJob($id) : null;
if (!$job) {
    http_response_code(404);
    backup_web_header('見つかりません', $user, ['back' => 'history.php']);
    echo '<section class="card">' . backup_web_empty('archive', 'このジョブは見つかりませんでした', '<a href="history.php">実行履歴へ戻る</a>') . '</section>';
    backup_web_footer();
    exit;
}
$items = $manager->listItems((int)$job['id']);
$tone = backup_web_status_tone((string)$job['status']);
backup_web_header('バックアップ #' . (int)$job['id'], $user, [
    'subtitle' => backup_web_job_type_label((string)$job['job_type']) . ' ・ ' . (string)$job['started_at'],
    'back' => 'history.php',
]);
?>
<div class="stat-grid">
  <div class="stat<?= $tone !== '' ? ' stat--' . $tone : '' ?>">
    <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('activity') ?></span>状態</span>
    <span class="stat__value"><?= backup_h(backup_status_label((string)$job['status'])) ?></span>
    <span class="stat__meta bk-wrap"><?= backup_h((string)$job['message']) ?></span>
  </div>
  <div class="stat stat--info">
    <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('database') ?></span>サイズ</span>
    <span class="stat__value"><?= backup_h(backup_format_bytes((int)$job['total_bytes'])) ?></span>
    <span class="stat__meta">成功 <?= (int)($job['success_items'] ?? 0) ?> ・ 失敗 <?= (int)($job['failed_items'] ?? 0) ?></span>
  </div>
  <div class="stat stat--teal">
    <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('clock') ?></span>実行時間</span>
    <span class="stat__value bk-stat-small"><?= backup_h(substr((string)$job['started_at'], 11, 5)) ?> → <?= backup_h(substr((string)($job['finished_at'] ?? ''), 11, 5) ?: '—') ?></span>
    <span class="stat__meta"><?= backup_h(backup_web_trigger_label((string)($job['trigger_type'] ?? ''))) ?>実行</span>
  </div>
</div>

<section class="card">
  <div class="card__body">
    <dl class="dl">
      <div><dt>ジョブキー</dt><dd class="mono allow-select"><?= backup_h((string)$job['job_key']) ?></dd></div>
      <div><dt>保存先</dt><dd class="mono allow-select"><?= backup_h(backup_mask_path((string)($job['backup_root'] ?? ''))) ?></dd></div>
      <div><dt>manifest SHA256</dt><dd class="mono allow-select bk-hash"><?= backup_h((string)($job['manifest_sha256'] ?? '—')) ?></dd></div>
    </dl>
    <p class="small-note mt-2"><?= fitsc_icon('lock', 'ico--sm') ?> バックアップ本体は Web からダウンロードできません。復元時は SSH またはファイルマネージャーで非公開領域から取得してください。</p>
  </div>
</section>

<section class="card">
  <div class="card__header">
    <div class="card__title"><?= fitsc_icon('layers') ?>項目 <small><?= count($items) ?>件</small></div>
  </div>
  <?php if ($items === []): ?>
    <?= backup_web_empty('layers', '項目がありません') ?>
  <?php else: ?>
    <div class="table-wrap is-stacked">
      <table class="table">
        <thead><tr><th>対象</th><th>状態</th><th class="text-right">件数</th><th class="text-right">サイズ</th><th>保存先・SHA256</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
          <tr>
            <td><span class="cell-main"><?= backup_h((string)$item['target_label']) ?></span><span class="cell-sub"><?= backup_h((string)$item['item_type']) ?> ・ <span class="mono"><?= backup_h(backup_mask_path((string)($item['source_path'] ?? ''))) ?></span></span></td>
            <td><?= backup_web_status_badge((string)$item['status']) ?><?php if (!empty($item['error_message'])): ?><span class="cell-sub text-danger"><?= backup_h((string)$item['error_message']) ?></span><?php endif; ?></td>
            <td class="text-right num"><?= $item['file_count'] !== null ? number_format((int)$item['file_count']) : '—' ?></td>
            <td class="text-right num nowrap"><?= backup_h(backup_format_bytes((int)$item['total_bytes'])) ?></td>
            <td><span class="mono text-xs allow-select"><?= backup_h(backup_mask_path((string)($item['backup_path'] ?? ''))) ?></span><span class="cell-sub mono bk-hash allow-select"><?= backup_h((string)($item['sha256'] ?? '')) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php
backup_web_footer();
