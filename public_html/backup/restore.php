<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once dirname(__DIR__, 2) . '/apps/backup_core/restore_helper.php';

$user = backup_web_require_admin();
$flashType = null;
$flashMessage = null;
$jobs = backup_restore_latest_jobs(50);
$selectedId = (int)($_GET['job_id'] ?? ($jobs[0]['id'] ?? 0));
$plan = null;
if ($selectedId > 0) {
    try {
        $plan = backup_restore_plan_for_job($selectedId);
        backup_operation_log('restore.view', $user, 'backup_job', (string)$selectedId);
    } catch (Throwable $e) {
        $flashType = 'error';
        $flashMessage = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    backup_auth_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    $jobId = (int)($_POST['job_id'] ?? 0);
    try {
        if ($action === 'generate_note' && $jobId > 0) {
            $plan = backup_restore_plan_for_job($jobId);
            $path = backup_save_restore_note($plan, $user);
            $flashType = 'success';
            $flashMessage = '復元メモを生成しました: ' . backup_mask_path($path);
            $selectedId = $jobId;
        }
    } catch (Throwable $e) {
        $flashType = 'error';
        $flashMessage = $e->getMessage();
    }
}

backup_web_header('復元補助', $user);
backup_web_flash($flashType, $flashMessage);
?>
<section class="notice">
  <strong>安全方針:</strong> この画面は復元手順とコマンド例を表示するだけで、自動復元・バックアップ本体のダウンロードは行いません。実行はSSH等で内容確認後に行ってください。
</section>

<section class="card">
  <h2>復元対象バックアップの選択</h2>
  <form method="get">
    <label for="job_id">バックアップジョブ</label>
    <select id="job_id" name="job_id" onchange="this.form.submit()">
      <?php foreach ($jobs as $job): ?>
        <option value="<?= (int)$job['id'] ?>" <?= (int)$job['id'] === $selectedId ? 'selected' : '' ?>>
          #<?= (int)$job['id'] ?> / <?= backup_h((string)$job['job_type']) ?> / <?= backup_h((string)$job['started_at']) ?> / <?= backup_h((string)$job['status']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="btn" type="submit">表示</button></noscript>
  </form>
</section>

<?php if ($plan): $job = $plan['job']; ?>
<section class="section-title">
  <div>
    <h2>復元プラン</h2>
    <p class="muted">ジョブキー: <span class="mono"><?= backup_h((string)$job['job_key']) ?></span></p>
  </div>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= backup_h(backup_auth_csrf_token()) ?>">
    <input type="hidden" name="action" value="generate_note">
    <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
    <button class="btn" type="submit">復元メモを保存</button>
  </form>
</section>

<?php foreach ((array)$plan['warnings'] as $warning): ?>
  <div class="alert"><?= backup_h((string)$warning) ?></div>
<?php endforeach; ?>

<section class="grid">
  <div class="card"><h2>種別</h2><div class="metric"><?= backup_h((string)$job['job_type']) ?></div><p class="muted">状態: <?= backup_web_status_badge((string)$job['status']) ?></p></div>
  <div class="card"><h2>開始</h2><div class="metric small"><?= backup_h((string)$job['started_at']) ?></div><p class="muted">終了: <?= backup_h((string)($job['finished_at'] ?? '')) ?></p></div>
  <div class="card"><h2>DB候補</h2><div class="metric"><?= count((array)$plan['database_items']) ?></div><p class="muted">mysqldump復元候補</p></div>
  <div class="card"><h2>ファイル候補</h2><div class="metric"><?= count((array)$plan['file_items']) ?></div><p class="muted">tar.gz展開候補</p></div>
</section>

<section class="card">
  <h2>共通手順</h2>
  <ol>
    <?php foreach ((array)$plan['general_steps'] as $step): ?><li><?= backup_h((string)$step) ?></li><?php endforeach; ?>
  </ol>
</section>

<section class="section-title"><h2>DB復元候補</h2></section>
<?php foreach ((array)$plan['database_items'] as $entry): ?>
<section class="card">
  <h2><?= backup_h((string)$entry['dbname']) ?></h2>
  <p class="muted mono"><?= backup_h((string)$entry['masked_path']) ?> / <?= backup_h(backup_format_bytes((int)$entry['bytes'])) ?></p>
  <?php foreach ((array)$entry['commands'] as $label => $command): ?>
    <h3><?= backup_h((string)$label) ?></h3>
    <div class="command"><?= backup_h((string)$command) ?></div>
  <?php endforeach; ?>
</section>
<?php endforeach; ?>
<?php if ((array)$plan['database_items'] === []): ?><section class="card muted">DB復元候補はありません。</section><?php endif; ?>

<section class="section-title"><h2>ファイル復元候補</h2></section>
<?php foreach ((array)$plan['file_items'] as $entry): $item = $entry['item']; ?>
<section class="card">
  <h2><?= backup_h((string)($item['target_label'] ?? $item['target_key'] ?? 'files')) ?></h2>
  <p class="muted mono"><?= backup_h((string)$entry['masked_path']) ?> / <?= backup_h(backup_format_bytes((int)$entry['bytes'])) ?></p>
  <?php foreach ((array)$entry['commands'] as $label => $command): ?>
    <h3><?= backup_h((string)$label) ?></h3>
    <div class="command"><?= backup_h((string)$command) ?></div>
  <?php endforeach; ?>
</section>
<?php endforeach; ?>
<?php if ((array)$plan['file_items'] === []): ?><section class="card muted">ファイル復元候補はありません。</section><?php endif; ?>

<?php if ((array)$plan['missing_items'] !== []): ?>
<section class="card">
  <h2>現在見つからないバックアップ本体</h2>
  <table><thead><tr><th>種別</th><th>対象</th><th>記録パス</th></tr></thead><tbody>
    <?php foreach ((array)$plan['missing_items'] as $entry): $item = $entry['item']; ?>
      <tr><td><?= backup_h((string)$item['item_type']) ?></td><td><?= backup_h((string)($item['target_label'] ?? $item['target_key'])) ?></td><td class="mono"><?= backup_h((string)$entry['masked_path']) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table>
</section>
<?php endif; ?>
<?php endif; ?>
<?php backup_web_footer(); ?>
