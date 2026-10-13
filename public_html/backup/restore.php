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


backup_web_header('復元補助', $user, ['subtitle' => '復元手順とコマンド例を表示します（自動復元は行いません）']);
backup_web_flash($flashType, $flashMessage);
$cmdIndex = 0;
$renderCommands = static function (array $commands) use (&$cmdIndex): void {
    foreach ($commands as $label => $command) {
        $cmdIndex++;
        $id = 'cmd-' . $cmdIndex;
        echo '<div class="bk-command">';
        echo '<div class="bk-command__label">' . backup_h((string)$label) . '</div>';
        echo '<div class="code-block"><pre id="' . $id . '" class="allow-select">' . backup_h((string)$command) . '</pre>';
        echo '<button type="button" class="btn btn--ghost btn--icon btn--sm" data-copy data-copy-target="#' . $id . '" aria-label="コピー" data-tip="コピー">' . fitsc_icon('copy') . '</button></div>';
        echo '</div>';
    }
};
?>
<div class="alert alert--info"><?= fitsc_icon('shield') ?><div class="alert__body">この画面は手順の確認用です。バックアップ本体のダウンロードや自動復元は行いません。コマンドは内容を確認してから SSH 等で実行してください。</div></div>

<section class="card">
  <div class="card__header">
    <form method="get" class="bk-job-picker">
      <span class="bk-job-picker__icon"><?= fitsc_icon('archive') ?></span>
      <label class="sr-only" for="job_id">バックアップジョブ</label>
      <select id="job_id" name="job_id" data-autosubmit>
        <?php foreach ($jobs as $job): ?>
          <option value="<?= (int)$job['id'] ?>" <?= (int)$job['id'] === $selectedId ? 'selected' : '' ?>>#<?= (int)$job['id'] ?> ・ <?= backup_h(backup_web_job_type_label((string)$job['job_type'])) ?> ・ <?= backup_h((string)$job['started_at']) ?> ・ <?= backup_h(backup_status_label((string)$job['status'])) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn" type="submit">表示</button></noscript>
    </form>
    <?php if ($plan): ?>
      <form method="post" data-busy>
        <input type="hidden" name="_csrf" value="<?= backup_h(backup_auth_csrf_token()) ?>">
        <input type="hidden" name="action" value="generate_note">
        <input type="hidden" name="job_id" value="<?= (int)$plan['job']['id'] ?>">
        <button class="btn btn--sm" type="submit"><?= fitsc_icon('save') ?>復元メモを保存</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if ($jobs === []): ?>
    <?= backup_web_empty('archive', '復元できるバックアップがありません') ?>
  <?php elseif ($plan): $job = $plan['job']; ?>
    <div class="card__body">
      <dl class="dl dl--grid bk-facts">
        <div><dt>種別</dt><dd><?= backup_h(backup_web_job_type_label((string)$job['job_type'])) ?> <?= backup_web_status_badge((string)$job['status']) ?></dd></div>
        <div><dt>実行</dt><dd class="text-sm"><?= backup_h((string)$job['started_at']) ?> → <?= backup_h(substr((string)($job['finished_at'] ?? ''), 11) ?: '—') ?></dd></div>
        <div><dt>DB 候補</dt><dd><?= count((array)$plan['database_items']) ?> 件</dd></div>
        <div><dt>ファイル候補</dt><dd><?= count((array)$plan['file_items']) ?> 件</dd></div>
      </dl>
      <p class="small-note mt-2 mono">ジョブキー: <?= backup_h((string)$job['job_key']) ?></p>
    </div>
  <?php endif; ?>
</section>

<?php if ($plan): ?>
  <?php foreach ((array)$plan['warnings'] as $warning): ?>
    <div class="alert alert--warning"><?= fitsc_icon('alert-triangle') ?><div class="alert__body"><?= backup_h((string)$warning) ?></div></div>
  <?php endforeach; ?>

  <section class="card">
    <div class="card__header card__header--plain"><div class="card__title"><?= fitsc_icon('list-checks') ?>共通の手順</div></div>
    <div class="card__body">
      <ol class="bk-steps">
        <?php foreach ((array)$plan['general_steps'] as $step): ?><li><?= backup_h((string)$step) ?></li><?php endforeach; ?>
      </ol>
    </div>
  </section>

  <section class="card" data-tabs-root>
    <div class="card__header card__header--tabs">
      <div class="tabs" data-tabs aria-label="復元候補">
        <button type="button" class="tab" data-tab="db"><?= fitsc_icon('database') ?>DB <span class="badge"><?= count((array)$plan['database_items']) ?></span></button>
        <button type="button" class="tab" data-tab="files"><?= fitsc_icon('folder') ?>ファイル <span class="badge"><?= count((array)$plan['file_items']) ?></span></button>
        <?php if ((array)$plan['missing_items'] !== []): ?>
          <button type="button" class="tab" data-tab="missing"><?= fitsc_icon('alert-triangle') ?>見つからない本体 <span class="badge badge--danger"><?= count((array)$plan['missing_items']) ?></span></button>
        <?php endif; ?>
      </div>
    </div>

    <div data-tab-panel="db">
      <?php if ((array)$plan['database_items'] === []): ?>
        <?= backup_web_empty('database', 'DB の復元候補はありません') ?>
      <?php else: ?>
        <div class="bk-candidates">
          <?php foreach ((array)$plan['database_items'] as $entry): ?>
            <details class="disclosure">
              <summary><?= fitsc_icon('database') ?><span class="bk-candidate__title"><strong><?= backup_h((string)$entry['dbname']) ?></strong><small class="mono"><?= backup_h((string)$entry['masked_path']) ?> ・ <?= backup_h(backup_format_bytes((int)$entry['bytes'])) ?></small></span></summary>
              <div class="bk-candidate__body"><?php $renderCommands((array)$entry['commands']); ?></div>
            </details>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div data-tab-panel="files" hidden>
      <?php if ((array)$plan['file_items'] === []): ?>
        <?= backup_web_empty('folder', 'ファイルの復元候補はありません') ?>
      <?php else: ?>
        <div class="bk-candidates">
          <?php foreach ((array)$plan['file_items'] as $entry): $item = $entry['item']; ?>
            <details class="disclosure">
              <summary><?= fitsc_icon('folder') ?><span class="bk-candidate__title"><strong><?= backup_h((string)($item['target_label'] ?? $item['target_key'] ?? 'files')) ?></strong><small class="mono"><?= backup_h((string)$entry['masked_path']) ?> ・ <?= backup_h(backup_format_bytes((int)$entry['bytes'])) ?></small></span></summary>
              <div class="bk-candidate__body"><?php $renderCommands((array)$entry['commands']); ?></div>
            </details>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if ((array)$plan['missing_items'] !== []): ?>
      <div data-tab-panel="missing" hidden>
        <div class="table-wrap is-stacked bk-table-flat">
          <table class="table table--compact">
            <thead><tr><th>種別</th><th>対象</th><th>記録されたパス</th></tr></thead>
            <tbody>
            <?php foreach ((array)$plan['missing_items'] as $entry): $item = $entry['item']; ?>
              <tr><td><?= backup_h((string)$item['item_type']) ?></td><td class="cell-main"><?= backup_h((string)($item['target_label'] ?? $item['target_key'])) ?></td><td class="mono text-xs allow-select"><?= backup_h((string)$entry['masked_path']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php backup_web_footer(); ?>
