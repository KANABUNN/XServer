<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
require_once dirname(__DIR__, 2) . '/apps/kintone_core/kintone_sync_service.php';

$user = kintone_auth_require_view_access();
$pdo = kintone_pdo('org');
$canAdmin = kintone_auth_user_has_role($user, 'admin');
$apps = [];
try {
    $apps = kintone_sync_list_apps(true);
} catch (Throwable $e) {
    error_log('[kintone sync apps] ' . $e->getMessage());
}
$appKey = trim((string)($_GET['app_key'] ?? ($apps[0]['app_key'] ?? 'organizations')));
$selected = null;
foreach ($apps as $app) {
    if ((string)$app['app_key'] === $appKey) {
        $selected = $app;
        break;
    }
}
if (!is_array($selected) && $apps !== []) {
    $selected = $apps[0];
    $appKey = (string)$selected['app_key'];
}
$role = is_array($selected) ? (string)$selected['app_role'] : 'manual';
$hasToken = is_array($selected) && (int)($selected['has_token'] ?? 0) === 1;
$latestJobs = [];
$failedJobs = [];
$lastItems = [];
$targets = [];
$cacheCount = 0;
$cacheRows = [];
if (is_array($selected)) {
    $stmt = $pdo->prepare('SELECT * FROM kintone_sync_jobs WHERE target_app = :app_key ORDER BY id DESC LIMIT 20');
    $stmt->execute([':app_key' => $appKey]);
    $latestJobs = $stmt->fetchAll() ?: [];
    $stmt = $pdo->prepare('SELECT id, job_key, failed_items, finished_at, status FROM kintone_sync_jobs WHERE target_app = :app_key AND failed_items > 0 ORDER BY id DESC LIMIT 10');
    $stmt->execute([':app_key' => $appKey]);
    $failedJobs = $stmt->fetchAll() ?: [];
    $stmt = $pdo->prepare('SELECT * FROM kintone_sync_job_items WHERE app_key = :app_key OR (app_key IS NULL AND :is_org = 1 AND organization_code IS NOT NULL) ORDER BY id DESC LIMIT 50');
    $stmt->execute([':app_key' => $appKey, ':is_org' => $appKey === 'organizations' ? 1 : 0]);
    $lastItems = $stmt->fetchAll() ?: [];
    if ($role === 'pull') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM kintone_app_records WHERE app_key = :app_key');
        $stmt->execute([':app_key' => $appKey]);
        $cacheCount = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT record_key, kintone_record_id, kintone_revision, synced_at FROM kintone_app_records WHERE app_key = :app_key ORDER BY synced_at DESC, id DESC LIMIT 20');
        $stmt->execute([':app_key' => $appKey]);
        $cacheRows = $stmt->fetchAll() ?: [];
    }
}
if ($canAdmin && $role === 'push' && $appKey === 'organizations') {
    $targets = $pdo->query('SELECT id, organization_code, organization_name, activity_status, last_synced_at FROM organizations WHERE is_active = 1 ORDER BY organization_code ASC LIMIT 200')->fetchAll() ?: [];
}

$directionLabel = $role === 'pull' ? 'kintone → XServer（キャッシュへ取得）' : ($role === 'push' ? 'XServer → kintone（正本を送信）' : '手動管理（自動同期なし）');
kintone_page_header('kintone同期', $user);
kintone_render_flash();
?>
<?php if (!is_array($selected)): ?>
<section class="card">
  <div class="empty">
    <span class="empty__icon"><?= fitsc_icon('cloud') ?></span>
    <span class="empty__title">有効なkintoneアプリがありません</span>
    <span>先にアプリ設定でアプリを登録してください。</span>
    <?php if ($canAdmin): ?><a class="btn btn--primary btn--sm" href="settings.php?app_key="><?= fitsc_icon('plus') ?>アプリを追加</a><?php endif; ?>
  </div>
</section>
<?php else: ?>
<section class="card">
  <div class="card__header">
    <form method="get" class="kt-app-picker">
      <span class="kt-app-picker__icon"><?= fitsc_icon($role === 'pull' ? 'download' : ($role === 'push' ? 'upload' : 'database')) ?></span>
      <label class="sr-only" for="app_key">アプリ</label>
      <select id="app_key" name="app_key" data-autosubmit>
        <?php foreach ($apps as $app): ?>
          <option value="<?= kintone_h($app['app_key']) ?>" <?= (string)$app['app_key'] === $appKey ? 'selected' : '' ?>><?= kintone_h($app['display_name'] . '（' . $app['app_key'] . '）') ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn" type="submit">表示</button></noscript>
    </form>
    <?php if ($canAdmin): ?><a class="btn btn--ghost btn--sm" href="settings.php?app_key=<?= rawurlencode($appKey) ?>"><?= fitsc_icon('settings') ?>アプリ設定</a><?php endif; ?>
  </div>
  <div class="card__body stack">
    <dl class="dl dl--grid kt-facts">
      <div class="kt-facts__wide"><dt>同期方向</dt><dd><?= kintone_role_badge($role) ?> <span class="text-sm text-muted"><?= kintone_h($directionLabel) ?></span></dd></div>
      <div><dt>kintoneアプリID</dt><dd class="mono"><?= kintone_h($selected['kintone_app_id']) ?></dd></div>
      <div><dt>接続</dt><dd><?= kintone_status_badge((string)$selected['status']) ?></dd></div>
      <div><dt>APIトークン</dt><dd><?= $hasToken ? '<span class="badge badge--success badge--dot">登録済み</span>' : '<span class="badge badge--danger badge--dot">未登録</span>' ?></dd></div>
      <div><dt>最終同期</dt><dd class="text-sm"><?= kintone_h($selected['last_synced_at'] ?? '—') ?></dd></div>
    </dl>
    <?php if (!empty($selected['last_error'])): ?>
      <div class="alert alert--warning"><?= fitsc_icon('alert-triangle') ?><div class="alert__body"><strong class="alert__title">前回のエラー</strong><span class="allow-select"><?= kintone_h($selected['last_error']) ?></span></div></div>
    <?php endif; ?>

    <?php if (!$canAdmin): ?>
      <div class="alert alert--neutral"><?= fitsc_icon('lock') ?><div class="alert__body">同期の実行は管理者のみ可能です。</div></div>
    <?php elseif (!$hasToken): ?>
      <div class="alert alert--warning"><?= fitsc_icon('key') ?><div class="alert__body">このアプリのAPIトークンが未登録です。<a href="settings.php?app_key=<?= rawurlencode($appKey) ?>">アプリ設定</a>で登録してください。</div></div>
    <?php elseif ($role === 'manual'): ?>
      <div class="alert alert--neutral"><?= fitsc_icon('info') ?><div class="alert__body">手動管理のアプリは自動同期しません。</div></div>
    <?php endif; ?>
  </div>
  <?php if ($canAdmin && $hasToken && $role !== 'manual'): ?>
    <div class="card__footer form-actions--between">
      <?php if ($failedJobs !== []): ?>
        <form method="post" action="api/sync_kintone.php" class="cluster kt-retry" data-busy>
          <?= kintone_auth_csrf_field() ?>
          <input type="hidden" name="action" value="retry_failed">
          <label class="sr-only" for="source_job_id">再同期するジョブ</label>
          <select id="source_job_id" name="source_job_id">
            <?php foreach ($failedJobs as $job): ?><option value="<?= (int)$job['id'] ?>">#<?= (int)$job['id'] ?> 失敗 <?= (int)$job['failed_items'] ?>件 ・ <?= kintone_h($job['finished_at'] ?? '') ?></option><?php endforeach; ?>
          </select>
          <button class="btn" type="submit"><?= fitsc_icon('rotate-ccw') ?>失敗分を再同期</button>
        </form>
      <?php else: ?><span></span><?php endif; ?>
      <form method="post" action="api/sync_kintone.php" data-busy data-confirm="<?= $role === 'pull' ? 'kintoneのレコードをXServerのキャッシュへ取り込みます。' : 'XServerの団体データをkintoneへ送信します。' ?>" data-confirm-title="同期を実行しますか？" data-confirm-ok="実行する" data-confirm-tone="default">
        <?= kintone_auth_csrf_field() ?>
        <input type="hidden" name="action" value="sync_app">
        <input type="hidden" name="app_key" value="<?= kintone_h($appKey) ?>">
        <button class="btn btn--primary" type="submit"><?= fitsc_icon('refresh') ?><?= $role === 'pull' ? 'kintoneから取得' : 'kintoneへ送信' ?></button>
      </form>
    </div>
  <?php endif; ?>
</section>

<?php if ($canAdmin && $role === 'push' && $appKey === 'organizations' && $hasToken): ?>
<details class="disclosure kt-disclosure">
  <summary><?= fitsc_icon('list-checks') ?><span>団体を選んで同期</span><small class="text-subtle">大量同期前の動作確認用（最大200件）</small></summary>
  <form method="post" action="api/sync_kintone.php" data-busy>
    <?= kintone_auth_csrf_field() ?><input type="hidden" name="action" value="sync_selected"><input type="hidden" name="app_key" value="organizations">
    <?php if ($targets === []): ?>
      <div class="empty"><span>同期対象の団体がありません。</span></div>
    <?php else: ?>
      <div class="table-wrap kt-table-flat kt-scroll-table">
        <table class="table table--compact">
          <thead><tr><th class="kt-col-check"><label class="kt-check-all" aria-label="すべて選択"><input type="checkbox" data-check-all="organization_ids[]"></label></th><th>団体</th><th>活動状態</th><th>最終同期</th></tr></thead>
          <tbody>
          <?php foreach ($targets as $org): ?>
            <tr>
              <td class="kt-col-check"><input type="checkbox" name="organization_ids[]" value="<?= (int)$org['id'] ?>" aria-label="<?= kintone_h($org['organization_name']) ?> を選択"></td>
              <td><span class="cell-main"><?= kintone_h($org['organization_name']) ?></span><span class="cell-sub mono"><?= kintone_h($org['organization_code']) ?></span></td>
              <td><?= kintone_status_badge((string)$org['activity_status']) ?></td>
              <td class="text-xs"><?= kintone_h($org['last_synced_at'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <div class="form-actions form-actions--end kt-disclosure__foot">
      <span class="text-sm text-muted" data-selected-count></span>
      <button class="btn" type="submit"><?= fitsc_icon('send') ?>選択した団体を同期</button>
    </div>
  </form>
</details>
<?php endif; ?>

<?php if ($role === 'pull'): ?>
<section class="card">
  <div class="card__header">
    <div class="card__title"><?= fitsc_icon('database') ?>取得済みキャッシュ <small><?= $cacheCount ?>件</small></div>
    <span class="text-xs text-subtle">kintone側にないレコードは削除せず、取得日時で鮮度を判断します</span>
  </div>
  <?php if ($cacheRows === []): ?>
    <div class="empty"><span>キャッシュはまだありません。</span></div>
  <?php else: ?>
    <div class="table-wrap is-stacked kt-table-flat">
      <table class="table table--compact">
        <thead><tr><th>record_key</th><th>kintone ID</th><th>revision</th><th>取得日時</th></tr></thead>
        <tbody>
        <?php foreach ($cacheRows as $row): ?>
          <tr><td class="mono allow-select"><?= kintone_h($row['record_key'] ?? '') ?></td><td class="mono"><?= kintone_h($row['kintone_record_id']) ?></td><td class="mono"><?= kintone_h($row['kintone_revision'] ?? '') ?></td><td class="text-xs"><?= kintone_h($row['synced_at']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card" data-tabs-root>
  <div class="card__header card__header--tabs">
    <div class="tabs" data-tabs aria-label="同期の履歴">
      <button type="button" class="tab" data-tab="jobs"><?= fitsc_icon('refresh') ?>同期ジョブ</button>
      <button type="button" class="tab" data-tab="items"><?= fitsc_icon('list') ?>直近の明細</button>
    </div>
  </div>
  <div data-tab-panel="jobs">
    <?php if ($latestJobs === []): ?>
      <div class="empty"><span>同期ジョブはまだありません。</span></div>
    <?php else: ?>
      <div class="table-wrap is-stacked kt-table-flat">
        <table class="table table--compact">
          <thead><tr><th>ジョブ</th><th>状態</th><th>種別</th><th class="text-right">対象</th><th class="text-right">成功</th><th class="text-right">失敗</th><th>開始・終了</th><th>メッセージ</th></tr></thead>
          <tbody>
          <?php foreach ($latestJobs as $job): ?>
            <tr>
              <td class="mono">#<?= (int)$job['id'] ?></td>
              <td><?= kintone_status_badge((string)$job['status']) ?></td>
              <td class="mono text-xs"><?= kintone_h($job['job_type']) ?></td>
              <td class="text-right num"><?= (int)$job['total_items'] ?></td>
              <td class="text-right num text-success"><?= (int)$job['success_items'] ?></td>
              <td class="text-right num<?= (int)$job['failed_items'] > 0 ? ' text-danger' : '' ?>"><?= (int)$job['failed_items'] ?></td>
              <td class="text-xs nowrap"><?= kintone_h($job['started_at'] ?? '') ?><span class="cell-sub"><?= kintone_h($job['finished_at'] ?? '') ?></span></td>
              <td class="kt-error-cell text-sm allow-select"><?= kintone_h($job['message'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <div data-tab-panel="items" hidden>
    <?php if ($lastItems === []): ?>
      <div class="empty"><span>同期明細はまだありません。</span></div>
    <?php else: ?>
      <div class="table-wrap is-stacked kt-table-flat">
        <table class="table table--compact">
          <thead><tr><th>明細</th><th>対象</th><th>操作</th><th>状態</th><th>kintone ID</th><th>エラー</th><th>作成日時</th></tr></thead>
          <tbody>
          <?php foreach ($lastItems as $item): ?>
            <tr>
              <td><span class="mono"><?= (int)$item['id'] ?></span><span class="cell-sub mono">ジョブ #<?= (int)$item['job_id'] ?></span></td>
              <td><span class="mono allow-select"><?= kintone_h($item['organization_code'] ?? '') ?></span><?php if (($item['record_key'] ?? '') !== ''): ?><span class="cell-sub mono"><?= kintone_h($item['record_key']) ?></span><?php endif; ?></td>
              <td class="mono text-xs"><?= kintone_h($item['action_type']) ?></td>
              <td><?= kintone_status_badge((string)$item['status']) ?></td>
              <td class="mono"><?= kintone_h($item['kintone_record_id'] ?? '') ?></td>
              <td class="kt-error-cell"><?= ($item['error_message'] ?? '') !== '' ? '<span class="text-danger allow-select">' . kintone_h($item['error_message']) . '</span>' : '' ?></td>
              <td class="text-xs nowrap"><?= kintone_h($item['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
<?php kintone_page_footer(); ?>
