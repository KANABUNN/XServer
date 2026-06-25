<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
$user = kintone_auth_require_view_access();
$rows = kintone_pdo('org')->query('SELECT * FROM kintone_audit_logs ORDER BY id DESC LIMIT 200')->fetchAll() ?: [];
kintone_page_header('ログ', $user);
?>
<section class="card"><h2>監査ログ</h2><table><thead><tr><th>日時</th><th>操作者</th><th>操作</th><th>対象</th><th>概要</th></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><td><?= kintone_h($r['created_at']) ?></td><td><?= kintone_h($r['actor_display_name'] ?: $r['actor_login_id']) ?></td><td><?= kintone_h($r['action']) ?></td><td><?= kintone_h(($r['target_type'] ?? '') . ':' . ($r['target_id'] ?? '')) ?></td><td><code><?= kintone_h(mb_substr((string)($r['summary_json'] ?? ''), 0, 200, 'UTF-8')) ?></code></td></tr><?php endforeach; ?></tbody></table></section>
<?php kintone_page_footer(); ?>
