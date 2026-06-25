<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
$user = kintone_auth_require_view_access();
$batchId = (int)($_GET['batch_id'] ?? 0);
$params = [];
$sql = 'SELECT c.*, EXISTS(SELECT 1 FROM organization_change_logs p WHERE p.batch_id != c.batch_id AND p.organization_code = c.organization_code AND p.field_name = c.field_name AND p.applied_at IS NULL) AS carried_over FROM organization_change_logs c WHERE c.applied_at IS NULL';
if ($batchId > 0) {
    $sql .= ' AND c.batch_id = :batch_id';
    $params[':batch_id'] = $batchId;
}
$sql .= ' ORDER BY c.risk_level DESC, carried_over DESC, c.created_at DESC, c.id DESC LIMIT 300';
$stmt = kintone_pdo('org')->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll() ?: [];
$canResolve = kintone_auth_has_any_role(['operator', 'admin']);
kintone_page_header('レビュー待ち', $user);
kintone_render_flash();
?>
<section class="card">
  <h2>未解決・未反映の変更</h2>
  <p class="muted">operatorはレビュー値の修正・除外まで実行できます。実反映はadminのみ、差分画面の二段確認を経由して行います。</p>
  <table>
    <thead><tr><th>作成日時</th><th>持ち越し</th><th>バッチ</th><th>団体ID</th><th>リスク</th><th>項目</th><th>現在値</th><th>取込後/確定値</th><th>操作</th></tr></thead>
    <tbody>
      <?php if ($rows === []): ?><tr><td colspan="9">レビュー待ちの差分はありません。</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): $field = (string)$r['field_name']; ?>
      <tr>
        <td><?= kintone_h($r['created_at']) ?></td>
        <td><?= (int)$r['carried_over'] === 1 ? '前回も未解決' : '' ?></td>
        <td><a href="diff.php?batch_id=<?= (int)$r['batch_id'] ?>"><?= (int)$r['batch_id'] ?></a></td>
        <td><?= kintone_h($r['organization_code']) ?></td>
        <td><?= kintone_h($r['risk_level']) ?></td>
        <td><?= kintone_h($field) ?></td>
        <td><?= kintone_h($r['old_value'] ?? '') ?></td>
        <td><?= kintone_h($r['new_value'] ?? '') ?></td>
        <td>
          <?php if ($canResolve): ?>
          <form method="post" action="api/resolve_review.php" class="inline-form">
            <?= kintone_auth_csrf_field() ?>
            <input type="hidden" name="action" value="resolve">
            <input type="hidden" name="change_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="batch_id" value="<?= (int)$r['batch_id'] ?>">
            <?php if ($field === 'activity_status'): ?>
              <select name="new_value">
                <?php foreach (['active'=>'active','inactive'=>'inactive','needs_review'=>'needs_review','unknown'=>'unknown'] as $v => $label): ?>
                <option value="<?= $v ?>" <?= (string)$r['new_value'] === $v ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <input type="text" name="new_value" value="<?= kintone_h($r['new_value'] ?? '') ?>" size="18">
            <?php endif; ?>
            <button type="submit" class="secondary">確定</button>
          </form>
          <form method="post" action="api/resolve_review.php" class="inline-form">
            <?= kintone_auth_csrf_field() ?>
            <input type="hidden" name="action" value="exclude">
            <input type="hidden" name="change_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="batch_id" value="<?= (int)$r['batch_id'] ?>">
            <button type="submit" class="secondary">除外</button>
          </form>
          <?php else: ?>
            <span class="muted">operator以上で操作可</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php kintone_page_footer(); ?>
