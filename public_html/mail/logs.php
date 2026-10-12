<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
[$user, $mailPdo, $dbError] = mail_app_init();
mail_require_permission_or_forbid($user, 'settings.manage');

$logs = [];
if ($mailPdo instanceof PDO && $dbError === '') {
    $logs = mail_list_audit_logs($mailPdo, 120);
}
mail_render_page_header('操作ログ', $user, 'logs.php');
?>
<header class="page-head">
  <div>
    <h1>操作ログ</h1>
    <p class="lead">mail.fit-sc.jp 内の主要操作ログです。共通アカウント側のログとは別に、メール管理単位で保存します。</p>
  </div>
</header>
<?php mail_render_db_error($dbError); ?>
<?php if ($dbError === ''): ?>
<section class="panel">
  <div class="panel-head"><h2>操作ログ</h2><span class="muted"><?php echo count($logs); ?>件</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>日時</th><th>操作者</th><th>操作</th><th>対象</th><th>概要</th><th>IP</th></tr></thead>
      <tbody>
        <?php if ($logs === []): ?><tr><td colspan="6" class="empty">ログはありません。</td></tr><?php endif; ?>
        <?php foreach ($logs as $log): ?>
          <tr>
            <td><?php echo mail_h((string)$log['created_at']); ?></td>
            <td><?php echo mail_h((string)$log['actor_display_name']); ?><br><span class="muted"><?php echo mail_h((string)$log['actor_login_id']); ?></span></td>
            <td><code><?php echo mail_h((string)$log['action']); ?></code></td>
            <td><?php echo mail_h((string)$log['target_type']); ?> #<?php echo mail_h((string)$log['target_id']); ?></td>
            <td><pre class="preview-snippet"><?php echo mail_h((string)$log['summary_json']); ?></pre></td>
            <td><?php echo mail_h((string)$log['ip_address']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
<?php
mail_render_page_footer();
