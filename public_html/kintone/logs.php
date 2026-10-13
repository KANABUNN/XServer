<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
$user = kintone_auth_require_view_access();
$pdo = kintone_pdo('org');
$audits = $pdo->query('SELECT * FROM kintone_audit_logs ORDER BY id DESC LIMIT 200')->fetchAll() ?: [];
$jobs = $pdo->query('SELECT * FROM kintone_sync_jobs ORDER BY id DESC LIMIT 100')->fetchAll() ?: [];
$items = $pdo->query('SELECT * FROM kintone_sync_job_items ORDER BY id DESC LIMIT 100')->fetchAll() ?: [];
$apps = [];
try {
    $apps = $pdo->query('SELECT app_key, display_name, app_role, status, last_sync_status, last_synced_at, last_error FROM kintone_apps ORDER BY FIELD(app_key, "organizations") DESC, app_key ASC')->fetchAll() ?: [];
} catch (Throwable $e) {
    error_log('[kintone logs apps] ' . $e->getMessage());
}
$failedItems = count(array_filter($items, static fn(array $r): bool => in_array((string)$r['status'], ['failed', 'error'], true)));
kintone_page_header('ログ', $user);
kintone_render_flash();
?>
<section class="card" data-tabs-root>
  <div class="card__header card__header--tabs">
    <div class="tabs" data-tabs data-tabs-hash aria-label="ログの種類">
      <button type="button" class="tab" data-tab="apps"><?= fitsc_icon('cloud') ?>アプリ状態</button>
      <button type="button" class="tab" data-tab="jobs"><?= fitsc_icon('refresh') ?>同期ジョブ <span class="badge"><?= count($jobs) ?></span></button>
      <button type="button" class="tab" data-tab="items"><?= fitsc_icon('list') ?>同期明細<?php if ($failedItems > 0): ?> <span class="badge badge--danger"><?= $failedItems ?></span><?php endif; ?></button>
      <button type="button" class="tab" data-tab="audit"><?= fitsc_icon('shield') ?>監査ログ</button>
    </div>
  </div>

  <div data-tab-panel="apps">
    <?php if ($apps === []): ?>
      <div class="empty"><span class="empty__icon"><?= fitsc_icon('cloud') ?></span><span>アプリ登録はありません。</span></div>
    <?php else: ?>
      <div class="table-wrap is-stacked kt-table-flat">
        <table class="table">
          <thead><tr><th>アプリ</th><th>ロール</th><th>接続</th><th>最終同期</th><th>最終エラー</th></tr></thead>
          <tbody>
          <?php foreach ($apps as $r): ?>
            <tr>
              <td><span class="cell-main"><?= kintone_h($r['display_name']) ?></span><span class="cell-sub mono"><?= kintone_h($r['app_key']) ?></span></td>
              <td><?= kintone_role_badge((string)$r['app_role']) ?></td>
              <td><?= kintone_status_badge((string)$r['status']) ?></td>
              <td><?= kintone_status_badge((string)($r['last_sync_status'] ?? '')) ?><span class="cell-sub"><?= kintone_h($r['last_synced_at'] ?? '') ?></span></td>
              <td class="kt-error-cell"><?= ($r['last_error'] ?? '') !== '' ? '<span class="text-danger allow-select">' . kintone_h($r['last_error']) . '</span>' : '<span class="text-subtle">—</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div data-tab-panel="jobs" hidden>
    <?php if ($jobs === []): ?>
      <div class="empty"><span class="empty__icon"><?= fitsc_icon('refresh') ?></span><span>同期ジョブはありません。</span></div>
    <?php else: ?>
      <div class="table-wrap is-stacked kt-table-flat">
        <table class="table table--compact">
          <thead><tr><th>ジョブ</th><th>状態</th><th>種別</th><th class="text-right">対象</th><th class="text-right">成功</th><th class="text-right">失敗</th><th>開始・終了</th><th>詳細</th></tr></thead>
          <tbody>
          <?php foreach ($jobs as $r): ?>
            <tr>
              <td><span class="cell-main mono">#<?= (int)$r['id'] ?></span><span class="cell-sub mono"><?= kintone_h($r['target_app']) ?></span></td>
              <td><?= kintone_status_badge((string)$r['status']) ?></td>
              <td class="mono text-xs"><?= kintone_h($r['job_type']) ?></td>
              <td class="text-right num"><?= (int)$r['total_items'] ?></td>
              <td class="text-right num text-success"><?= (int)$r['success_items'] ?></td>
              <td class="text-right num<?= (int)$r['failed_items'] > 0 ? ' text-danger' : '' ?>"><?= (int)$r['failed_items'] ?></td>
              <td class="text-xs nowrap"><?= kintone_h($r['started_at'] ?? '') ?><span class="cell-sub"><?= kintone_h($r['finished_at'] ?? '') ?></span></td>
              <td class="kt-detail-cell"><code class="allow-select"><?= kintone_h(mb_substr((string)($r['detail_json'] ?? $r['message'] ?? ''), 0, 220, 'UTF-8')) ?></code></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div data-tab-panel="items" hidden>
    <?php if ($items === []): ?>
      <div class="empty"><span class="empty__icon"><?= fitsc_icon('list') ?></span><span>同期明細はありません。</span></div>
    <?php else: ?>
      <div class="table-wrap is-stacked kt-table-flat">
        <table class="table table--compact">
          <thead><tr><th>明細</th><th>アプリ</th><th>対象</th><th>操作</th><th>状態</th><th>kintone ID</th><th>エラー</th><th>作成日時</th></tr></thead>
          <tbody>
          <?php foreach ($items as $r): ?>
            <tr>
              <td><span class="cell-main mono"><?= (int)$r['id'] ?></span><span class="cell-sub mono">ジョブ #<?= (int)$r['job_id'] ?></span></td>
              <td class="mono text-xs"><?= kintone_h($r['app_key'] ?? '') ?></td>
              <td><span class="mono allow-select"><?= kintone_h($r['organization_code'] ?? '') ?></span><?php if (($r['record_key'] ?? '') !== ''): ?><span class="cell-sub mono"><?= kintone_h($r['record_key']) ?></span><?php endif; ?></td>
              <td class="mono text-xs"><?= kintone_h($r['action_type']) ?></td>
              <td><?= kintone_status_badge((string)$r['status']) ?></td>
              <td class="mono"><?= kintone_h($r['kintone_record_id'] ?? '') ?></td>
              <td class="kt-error-cell"><?= ($r['error_message'] ?? '') !== '' ? '<span class="text-danger allow-select">' . kintone_h($r['error_message']) . '</span>' : '' ?></td>
              <td class="text-xs nowrap"><?= kintone_h($r['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div data-tab-panel="audit" hidden>
    <?php if ($audits === []): ?>
      <div class="empty"><span class="empty__icon"><?= fitsc_icon('shield') ?></span><span>監査ログはありません。</span></div>
    <?php else: ?>
      <div class="table-wrap is-stacked kt-table-flat">
        <table class="table table--compact">
          <thead><tr><th>日時</th><th>操作者</th><th>操作</th><th>対象</th><th>概要</th></tr></thead>
          <tbody>
          <?php foreach ($audits as $r): ?>
            <tr>
              <td class="text-xs nowrap"><?= kintone_h($r['created_at']) ?></td>
              <td><?= kintone_h($r['actor_display_name'] ?: $r['actor_login_id']) ?></td>
              <td class="mono text-xs"><?= kintone_h($r['action']) ?></td>
              <td class="mono text-xs"><?= kintone_h(($r['target_type'] ?? '') . ':' . ($r['target_id'] ?? '')) ?></td>
              <td class="kt-detail-cell"><code class="allow-select"><?= kintone_h(mb_substr((string)($r['summary_json'] ?? ''), 0, 240, 'UTF-8')) ?></code></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php kintone_page_footer(); ?>
