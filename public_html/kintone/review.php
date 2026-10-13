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
kintone_page_header('レビュー待ち', $user, ['subtitle' => '確定した値は「差分・承認」から団体マスタへ反映します']);
kintone_render_flash();
$activityOptions = ['active' => '活動中', 'inactive' => '活動不可', 'needs_review' => '要確認', 'unknown' => '不明'];
?>
<section class="card">
  <div class="card__header">
    <div class="card__title"><?= fitsc_icon('inbox') ?>未反映の変更 <small><?= count($rows) ?>件</small></div>
    <?php if ($batchId > 0): ?>
      <a class="chip kt-filter-chip" href="review.php" aria-label="バッチの絞り込みを解除">バッチ #<?= $batchId ?><?= fitsc_icon('x', 'ico--sm') ?></a>
    <?php endif; ?>
  </div>
  <?php if ($rows === []): ?>
    <div class="empty"><span class="empty__icon"><?= fitsc_icon('check-circle') ?></span><span class="empty__title">レビュー待ちはありません</span><span>すべての変更が確認済みです。</span></div>
  <?php else: ?>
    <div class="table-wrap is-stacked kt-table-flat">
      <table class="table">
        <thead><tr><th>リスク</th><th>団体ID</th><th>項目</th><th>現在値</th><th>取込後・確定値</th><th class="col-actions"></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $field = (string)$r['field_name']; $formId = 'resolve-' . (int)$r['id']; ?>
          <tr>
            <td><span class="kt-badges"><?= kintone_risk_badge((string)$r['risk_level']) ?><?php if ((int)$r['carried_over'] === 1): ?><span class="badge badge--orange" title="前回のバッチから未解決のまま">持ち越し</span><?php endif; ?></span></td>
            <td>
              <span class="cell-main mono allow-select"><?= kintone_h($r['organization_code']) ?></span>
              <span class="cell-sub"><a href="diff.php?batch_id=<?= (int)$r['batch_id'] ?>">バッチ #<?= (int)$r['batch_id'] ?></a> ・ <?= kintone_h($r['created_at']) ?></span>
            </td>
            <td><span class="cell-main"><?= kintone_h(kintone_field_label($field)) ?></span><?php if (kintone_field_label($field) !== $field): ?><span class="cell-sub mono"><?= kintone_h($field) ?></span><?php endif; ?></td>
            <td class="kt-old"><?= kintone_change_value($field, $r['old_value'] ?? null) ?></td>
            <td class="kt-resolve-cell">
              <?php if ($canResolve): ?>
                <?php if ($field === 'activity_status'): ?>
                  <select name="new_value" form="<?= $formId ?>" aria-label="確定値">
                    <?php foreach ($activityOptions as $v => $label): ?>
                      <option value="<?= $v ?>" <?= (string)$r['new_value'] === $v ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <input type="text" name="new_value" form="<?= $formId ?>" value="<?= kintone_h($r['new_value'] ?? '') ?>" aria-label="確定値">
                <?php endif; ?>
              <?php else: ?>
                <?= kintone_change_value($field, $r['new_value'] ?? null) ?>
              <?php endif; ?>
            </td>
            <td class="col-actions">
              <?php if ($canResolve): ?>
                <div class="kt-row-actions">
                  <form method="post" action="api/resolve_review.php" id="<?= $formId ?>" data-busy>
                    <?= kintone_auth_csrf_field() ?>
                    <input type="hidden" name="action" value="resolve">
                    <input type="hidden" name="change_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="batch_id" value="<?= (int)$r['batch_id'] ?>">
                    <button type="submit" class="btn btn--sm"><?= fitsc_icon('check') ?>確定</button>
                  </form>
                  <form method="post" action="api/resolve_review.php" data-confirm="この変更を反映対象から除外します。" data-confirm-title="変更を除外しますか？" data-confirm-ok="除外する">
                    <?= kintone_auth_csrf_field() ?>
                    <input type="hidden" name="action" value="exclude">
                    <input type="hidden" name="change_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="batch_id" value="<?= (int)$r['batch_id'] ?>">
                    <button type="submit" class="btn btn--ghost btn--sm" data-tip="除外"><?= fitsc_icon('x') ?><span class="sr-only">除外</span></button>
                  </form>
                </div>
              <?php else: ?>
                <span class="text-subtle text-xs">担当者以上で操作</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php kintone_page_footer(); ?>
