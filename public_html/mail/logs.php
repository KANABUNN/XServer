<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
[$user, $mailPdo, $dbError] = mail_app_init();
mail_require_permission_or_forbid($user, 'settings.manage');

$logs = [];
if ($mailPdo instanceof PDO && $dbError === '') {
    $logs = mail_list_audit_logs($mailPdo, 120);
}
mail_render_page_header('操作ログ', $user, 'logs.php', [
    'subtitle' => 'メール管理での主な操作の記録',
]);
?>
<?php mail_render_db_error($dbError); ?>
<?php if ($dbError === ''): ?>
<section class="card">
  <div class="card__header"><div class="card__title"><?php echo fitsc_icon('history'); ?>操作ログ <small><?php echo count($logs); ?>件</small></div></div>
  <?php if ($logs === []): ?>
    <div class="empty"><span class="empty__icon"><?php echo fitsc_icon('history'); ?></span><span>ログはありません。</span></div>
  <?php else: ?>
    <div class="table-wrap is-stacked">
      <table class="table table--compact">
        <thead><tr><th>日時</th><th>操作者</th><th>操作</th><th>対象</th><th>内容</th><th>IP</th></tr></thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
            <tr>
              <td><span class="cell-sub nowrap"><?php echo mail_h((string)$log['created_at']); ?></span></td>
              <td><span class="cell-main"><?php echo mail_h((string)$log['actor_display_name']); ?></span><span class="cell-sub"><?php echo mail_h((string)$log['actor_login_id']); ?></span></td>
              <td><code><?php echo mail_h((string)$log['action']); ?></code></td>
              <td><span class="cell-sub"><?php echo mail_h((string)$log['target_type']); ?> #<?php echo mail_h((string)$log['target_id']); ?></span></td>
              <td><?php if ((string)$log['summary_json'] !== ''): ?><details class="ml-log-detail"><summary>表示</summary><pre class="allow-select"><?php echo mail_h((string)$log['summary_json']); ?></pre></details><?php else: ?><span class="text-subtle">—</span><?php endif; ?></td>
              <td><span class="cell-sub mono"><?php echo mail_h((string)$log['ip_address']); ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php
mail_render_page_footer();
