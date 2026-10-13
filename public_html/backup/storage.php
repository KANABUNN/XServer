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
$ratio = $disk['used_ratio'] ?? null;
$ratioPct = is_numeric($ratio) ? max(0, min(100, (float)$ratio * 100)) : null;
$latestTone = $latest ? backup_web_status_tone((string)$latest['status']) : '';
backup_web_header('ストレージ', $user);
?>
<div class="stat-grid">
  <div class="stat<?= $latestTone !== '' ? ' stat--' . $latestTone : '' ?>">
    <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('activity') ?></span>最新の収集</span>
    <span class="stat__value"><?= $latest ? backup_h(backup_status_label((string)$latest['status'])) : '未収集' ?></span>
    <span class="stat__meta"><?= $latest ? backup_h((string)$latest['collected_at']) : '収集ジョブが未実行です' ?></span>
  </div>
  <div class="stat stat--info">
    <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('archive') ?></span>バックアップ保存量</span>
    <span class="stat__value"><?= $latest ? backup_h(backup_format_bytes((int)$latest['backup_root_bytes'])) : '—' ?></span>
    <span class="stat__meta">private_backups 配下</span>
  </div>
  <div class="stat <?= $ratioPct === null ? '' : ($ratioPct >= 90 ? 'stat--danger' : ($ratioPct >= 75 ? 'stat--warning' : 'stat--success')) ?>">
    <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('hard-drive') ?></span>ディスク使用率</span>
    <span class="stat__value"><?= $ratioPct !== null ? backup_h((string)round($ratioPct, 1)) . '%' : '—' ?></span>
    <?php if ($ratioPct !== null): ?><span class="progress<?= $ratioPct >= 90 ? ' progress--danger' : ($ratioPct >= 75 ? ' progress--warning' : ' progress--success') ?>"><span style="width: <?= $ratioPct ?>%"></span></span><?php endif; ?>
    <span class="stat__meta">空き <?= isset($disk['free_bytes']) ? backup_h(backup_format_bytes((int)$disk['free_bytes'])) : '—' ?></span>
  </div>
  <div class="stat stat--teal">
    <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('file-text') ?></span>清掃ログ</span>
    <span class="stat__value"><?= !empty($cleanupLog['exists']) ? 'あり' : 'なし' ?></span>
    <span class="stat__meta"><?= backup_h((string)($cleanupLog['mtime'] ?? '—')) ?></span>
  </div>
</div>

<?php if ($latest && (string)$latest['status'] !== 'success'): ?>
  <div class="alert alert--warning"><?= fitsc_icon('alert-triangle') ?><div class="alert__body"><strong class="alert__title">収集時の警告</strong><span class="allow-select"><?= backup_h((string)($payload['maintenance_error'] ?? '不明な警告')) ?></span></div></div>
<?php endif; ?>

<section class="card" data-tabs-root>
  <div class="card__header card__header--tabs">
    <div class="tabs" data-tabs data-tabs-hash aria-label="ストレージの詳細">
      <button type="button" class="tab" data-tab="dirs"><?= fitsc_icon('folder') ?>ディレクトリ</button>
      <button type="button" class="tab" data-tab="db"><?= fitsc_icon('database') ?>DB蓄積</button>
      <button type="button" class="tab" data-tab="policy"><?= fitsc_icon('sliders') ?>保持ポリシー</button>
      <button type="button" class="tab" data-tab="log"><?= fitsc_icon('file-text') ?>清掃ログ</button>
      <button type="button" class="tab" data-tab="history"><?= fitsc_icon('history') ?>収集履歴</button>
    </div>
  </div>

  <div data-tab-panel="dirs">
    <?php if ($directories === []): ?>
      <?= backup_web_empty('folder', 'ディレクトリ情報がありません') ?>
    <?php else: ?>
      <div class="table-wrap is-stacked bk-table-flat">
        <table class="table table--compact">
          <thead><tr><th>区分</th><th>存在</th><th class="text-right">ファイル</th><th class="text-right">ディレクトリ</th><th class="text-right">サイズ</th><th>最終更新</th></tr></thead>
          <tbody>
          <?php foreach ($directories as $key => $stats): if (!is_array($stats)) continue; ?>
            <tr>
              <td><span class="cell-main"><?= backup_h((string)$key) ?></span><span class="cell-sub mono allow-select"><?= backup_h((string)($stats['masked_path'] ?? backup_mask_path((string)($stats['path'] ?? '')))) ?></span></td>
              <td><?= !empty($stats['exists']) ? '<span class="badge badge--success badge--dot">あり</span>' : '<span class="badge badge--danger badge--dot">なし</span>' ?></td>
              <td class="text-right num"><?= number_format((int)($stats['files'] ?? 0)) ?></td>
              <td class="text-right num"><?= number_format((int)($stats['dirs'] ?? 0)) ?></td>
              <td class="text-right num nowrap"><?= backup_h(backup_format_bytes((int)($stats['bytes'] ?? 0))) ?></td>
              <td class="text-xs nowrap"><?= backup_h((string)($stats['latest_mtime'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div data-tab-panel="db" hidden>
    <?php if ($dbCounts === []): ?>
      <?= backup_web_empty('database', 'DB件数の情報がありません') ?>
    <?php else: ?>
      <div class="table-wrap is-stacked bk-table-flat">
        <table class="table table--compact">
          <thead><tr><th>対象</th><th class="text-right">総行数</th><th class="text-right">保持期間超過</th><th class="text-right">保持日数</th></tr></thead>
          <tbody>
          <?php foreach ($dbCounts as $row): if (!is_array($row)) continue; $over = (int)($row['rows_older_than_retention'] ?? 0); ?>
            <tr>
              <td class="cell-main"><?= backup_h((string)($row['label'] ?? '')) ?></td>
              <td class="text-right num"><?= number_format((int)($row['total_rows'] ?? 0)) ?></td>
              <td class="text-right num<?= $over > 0 ? ' text-warning fw-bold' : '' ?>"><?= number_format($over) ?></td>
              <td class="text-right num"><?= (int)($row['retention_days'] ?? 0) ?> 日</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div data-tab-panel="policy" hidden>
    <?php if ($policies === []): ?>
      <?= backup_web_empty('sliders', 'ポリシーを取得できませんでした') ?>
    <?php else: ?>
      <div class="card__body">
        <dl class="dl">
          <?php foreach ($policies as $key => $value): ?>
            <div><dt class="mono"><?= backup_h((string)$key) ?></dt><dd><?= backup_h(is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE)) ?></dd></div>
          <?php endforeach; ?>
        </dl>
      </div>
    <?php endif; ?>
  </div>

  <div data-tab-panel="log" hidden>
    <?php if (empty($cleanupLog['tail'])): ?>
      <?= backup_web_empty('file-text', 'ログを取得できませんでした') ?>
    <?php else: ?>
      <div class="card__body"><pre class="bk-log allow-select"><?= backup_h((string)$cleanupLog['tail']) ?></pre></div>
    <?php endif; ?>
  </div>

  <div data-tab-panel="history" hidden>
    <?php if ($snapshots === []): ?>
      <?= backup_web_empty('history', '収集履歴はありません') ?>
    <?php else: ?>
      <div class="table-wrap is-stacked bk-table-flat">
        <table class="table table--compact">
          <thead><tr><th>収集日時</th><th>状態</th><th class="text-right">総量</th><th class="text-right">Forms（公開中）</th><th class="text-right">Forms（アーカイブ）</th><th class="text-right">バックアップ</th></tr></thead>
          <tbody>
          <?php foreach ($snapshots as $snapshot): ?>
            <tr>
              <td class="nowrap"><?= backup_h((string)$snapshot['collected_at']) ?><span class="cell-sub mono">#<?= (int)$snapshot['id'] ?></span></td>
              <td><?= backup_web_status_badge((string)$snapshot['status']) ?></td>
              <td class="text-right num nowrap"><?= backup_h(backup_format_bytes((int)$snapshot['total_bytes'])) ?></td>
              <td class="text-right num nowrap"><?= backup_h(backup_format_bytes((int)$snapshot['forms_live_bytes'])) ?></td>
              <td class="text-right num nowrap"><?= backup_h(backup_format_bytes((int)$snapshot['forms_archive_bytes'])) ?></td>
              <td class="text-right num nowrap"><?= backup_h(backup_format_bytes((int)$snapshot['backup_root_bytes'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php
backup_web_footer();
