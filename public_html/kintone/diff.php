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
$summary = ['high' => 0, 'medium' => 0, 'low' => 0, 'email_changes' => 0, 'active_to_inactive' => 0, 'total' => 0];
if ($batchId > 0) {
    $stmt = kintone_pdo('org')->prepare('SELECT * FROM roster_import_batches WHERE id = :id');
    $stmt->execute([':id' => $batchId]);
    $batch = $stmt->fetch() ?: null;
    $changes = kintone_fetch_batch_changes($batchId);
    $summary = kintone_change_summary(array_values(array_filter($changes, static fn(array $c): bool => empty($c['applied_at']))));
}
$canApply = kintone_auth_user_has_role($user, 'admin');
kintone_page_header('差分・承認', $user);
kintone_render_flash();
if (!$batch): ?>
<section class="card"><p>差分対象のバッチがありません。</p></section>
<?php else: ?>
<section class="card">
  <h2>バッチ #<?= (int)$batch['id'] ?> <?= kintone_h($batch['original_name']) ?></h2>
  <p>状態: <?= kintone_h($batch['status']) ?> / 行数: <?= (int)$batch['total_rows'] ?></p>
  <ul>
    <li>🔴 高リスク <?= (int)$summary['high'] ?>件（代表メール変更 <?= (int)$summary['email_changes'] ?>件 / active→inactive <?= (int)$summary['active_to_inactive'] ?>件）</li>
    <li>🟡 中リスク <?= (int)$summary['medium'] ?>件</li>
    <li>🟢 低リスク <?= (int)$summary['low'] ?>件</li>
  </ul>
</section>
<form method="post" action="confirm_apply.php">
<?= kintone_auth_csrf_field() ?><input type="hidden" name="batch_id" value="<?= (int)$batch['id'] ?>">
<section class="card"><h2>変更一覧</h2><p class="muted">高リスク変更は既定で未選択です。反映前にPHPの確認中間ページを必ず通過します。</p><table><thead><tr><th>反映</th><th>リスク</th><th>団体ID</th><th>項目</th><th>現在値</th><th>取込後</th><th>状態</th></tr></thead><tbody>
<?php foreach ($changes as $c): $high = (string)$c['risk_level'] === 'high'; $applied = !empty($c['applied_at']); ?>
<tr>
  <td><input type="checkbox" name="change_ids[]" value="<?= (int)$c['id'] ?>" <?= (!$high && !$applied) ? 'checked' : '' ?> <?= ($canApply && !$applied) ? '' : 'disabled' ?>></td>
  <td><?= kintone_h($c['risk_level']) ?></td>
  <td><?= kintone_h($c['organization_code']) ?></td>
  <td><?= kintone_h($c['field_name']) ?></td>
  <td><?= kintone_h($c['old_value'] ?? '') ?></td>
  <td><?= kintone_h($c['new_value'] ?? '') ?></td>
  <td><?= $applied ? '反映済み' : '未反映' ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php if ($canApply): ?>
  <button class="primary" type="submit" name="selection_mode" value="safe_only">警告以外を承認へ進む</button>
  <button class="secondary" type="submit" name="selection_mode" value="selected">選択した差分を確認する</button>
<?php else: ?><p>反映には admin 権限が必要です。</p><?php endif; ?>
</section></form>
<?php endif; kintone_page_footer(); ?>
