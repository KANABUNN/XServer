<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
require_once dirname(__DIR__, 2) . '/apps/kintone_core/diff_service.php';
$user = kintone_auth_require_view_access();
$batchId = (int)($_GET['batch_id'] ?? 0);
if ($batchId < 1) {
    $batchId = (int)(kintone_pdo('org')->query('SELECT id FROM roster_import_batches ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 0);
}
$batch = null;
$changes = [];
if ($batchId > 0) {
    $stmt = kintone_pdo('org')->prepare('SELECT * FROM roster_import_batches WHERE id = :id');
    $stmt->execute([':id' => $batchId]);
    $batch = $stmt->fetch() ?: null;
    $changes = kintone_fetch_batch_changes($batchId);
}
$canApply = kintone_auth_user_has_role($user, 'admin');
kintone_page_header('差分・承認', $user);
kintone_render_flash();
if (!$batch): ?><section class="card"><p>差分対象のバッチがありません。</p></section><?php else: ?>
<section class="card"><h2>バッチ #<?= (int)$batch['id'] ?> <?= kintone_h($batch['original_name']) ?></h2><p>状態: <?= kintone_h($batch['status']) ?> / 行数: <?= (int)$batch['total_rows'] ?></p></section>
<form method="post" action="api/apply_import.php">
<?= kintone_auth_csrf_field() ?><input type="hidden" name="batch_id" value="<?= (int)$batch['id'] ?>">
<section class="card"><h2>変更一覧</h2><p class="muted">高リスク変更は既定で未選択です。反映前に個別確認してください。</p><table><thead><tr><th>反映</th><th>リスク</th><th>団体ID</th><th>項目</th><th>現在値</th><th>取込後</th></tr></thead><tbody>
<?php foreach ($changes as $c): $high = (string)$c['risk_level'] === 'high'; ?>
<tr><td><input type="checkbox" name="change_ids[]" value="<?= (int)$c['id'] ?>" <?= $high ? '' : 'checked' ?> <?= $canApply ? '' : 'disabled' ?>></td><td><?= kintone_h($c['risk_level']) ?></td><td><?= kintone_h($c['organization_code']) ?></td><td><?= kintone_h($c['field_name']) ?></td><td><?= kintone_h($c['old_value'] ?? '') ?></td><td><?= kintone_h($c['new_value'] ?? '') ?></td></tr>
<?php endforeach; ?>
</tbody></table><?php if ($canApply): ?><button class="primary" type="submit">選択した差分を反映する</button><?php else: ?><p>反映には admin 権限が必要です。</p><?php endif; ?></section></form>
<?php endif; kintone_page_footer(); ?>
