<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
$user = kintone_auth_require_view_access();
$rows = kintone_pdo('org')->query('SELECT * FROM organization_change_logs WHERE risk_level="high" AND applied_at IS NULL ORDER BY created_at DESC LIMIT 200')->fetchAll() ?: [];
kintone_page_header('レビュー待ち', $user);
?>
<section class="card"><h2>高リスク・未反映の変更</h2><table><thead><tr><th>作成日時</th><th>バッチ</th><th>団体ID</th><th>項目</th><th>現在値</th><th>取込後</th></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><td><?= kintone_h($r['created_at']) ?></td><td><?= (int)$r['batch_id'] ?></td><td><?= kintone_h($r['organization_code']) ?></td><td><?= kintone_h($r['field_name']) ?></td><td><?= kintone_h($r['old_value'] ?? '') ?></td><td><?= kintone_h($r['new_value'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></section>
<?php kintone_page_footer(); ?>
