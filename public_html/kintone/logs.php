<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
$user = kintone_auth_require_view_access();
$pdo = kintone_pdo('org');
$audits = $pdo->query('SELECT * FROM kintone_audit_logs ORDER BY id DESC LIMIT 200')->fetchAll() ?: [];
$apps = [];
try {
    $apps = $pdo->query('SELECT app_key, display_name, app_role, status, last_sync_status, last_synced_at, last_error FROM kintone_apps ORDER BY FIELD(app_key, "organizations") DESC, app_key ASC')->fetchAll() ?: [];
} catch (Throwable $e) {
    error_log('[kintone logs apps] ' . $e->getMessage());
}
kintone_page_header('ログ', $user);
kintone_render_flash();
?>
<section class="card" data-tabs-root>
  <div class="card__header card__header--tabs">
    <div class="tabs" data-tabs data-tabs-hash aria-label="ログの種類">
      <button type="button" class="tab" data-tab="apps"><?= fitsc_icon('cloud') ?>アプリ状態</button>
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
