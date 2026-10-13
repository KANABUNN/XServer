<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
require_once dirname(__DIR__, 2) . '/apps/kintone_core/diff_service.php';
$user = kintone_auth_require_admin_access();
kintone_auth_require_csrf();
$batchId = (int)($_POST['batch_id'] ?? 0);
$mode = (string)($_POST['selection_mode'] ?? 'selected');
$changeIds = $_POST['change_ids'] ?? [];
if (!is_array($changeIds)) {
    $changeIds = [];
}
try {
    if ($batchId < 1) {
        throw new InvalidArgumentException('対象バッチが不正です。');
    }
    if ($mode === 'safe_only') {
        $changeIds = kintone_fetch_safe_change_ids($batchId);
    } else {
        $changeIds = array_values(array_unique(array_filter(array_map('intval', $changeIds), static fn(int $id): bool => $id > 0)));
    }
    $changes = kintone_fetch_changes_by_ids($batchId, $changeIds);
    if ($changes === []) {
        throw new InvalidArgumentException('反映対象が選択されていません。');
    }
    $summary = kintone_change_summary($changes);
} catch (Throwable $e) {
    kintone_set_flash('error', $e instanceof InvalidArgumentException ? $e->getMessage() : '確認画面の生成に失敗しました。');
    header('Location: diff.php?batch_id=' . $batchId, true, 302);
    exit;
}

kintone_page_header('反映前の最終確認', $user, ['subtitle' => '団体マスタへ反映する内容を確認してください']);
kintone_render_flash();
$hasHigh = (int)$summary['high'] > 0;
?>
<div class="steps" aria-label="反映までの手順">
  <div class="step is-done"><span class="step__label">1. アップロード</span></div>
  <div class="step is-done"><span class="step__label">2. 差分の確認</span></div>
  <div class="step is-active"><span class="step__label">3. 最終確認・反映</span></div>
</div>

<div class="kt-risk-summary">
  <div class="kt-risk-summary__item kt-risk-summary__item--high">
    <span class="kt-risk-summary__label"><?= fitsc_icon('alert-triangle', 'ico--sm') ?>高リスク</span>
    <strong><?= (int)$summary['high'] ?></strong>
    <small>代表メール変更 <?= (int)$summary['email_changes'] ?> ・ 活動中→活動不可 <?= (int)$summary['active_to_inactive'] ?></small>
  </div>
  <div class="kt-risk-summary__item kt-risk-summary__item--medium">
    <span class="kt-risk-summary__label"><?= fitsc_icon('alert-circle', 'ico--sm') ?>中リスク</span>
    <strong><?= (int)$summary['medium'] ?></strong>
  </div>
  <div class="kt-risk-summary__item kt-risk-summary__item--low">
    <span class="kt-risk-summary__label"><?= fitsc_icon('check-circle', 'ico--sm') ?>低リスク</span>
    <strong><?= (int)$summary['low'] ?></strong>
  </div>
</div>

<form method="post" action="api/apply_import.php" data-busy>
  <?= kintone_auth_csrf_field() ?>
  <input type="hidden" name="confirm_stage" value="1">
  <input type="hidden" name="batch_id" value="<?= (int)$batchId ?>">
  <?php foreach ($changes as $c): ?><input type="hidden" name="change_ids[]" value="<?= (int)$c['id'] ?>"><?php endforeach; ?>
  <section class="card">
    <div class="card__header">
      <div class="card__title"><?= fitsc_icon('list-checks') ?>反映する変更 <small><?= count($changes) ?>件</small></div>
    </div>
    <div class="table-wrap is-stacked kt-table-flat">
      <table class="table">
        <thead><tr><th>リスク</th><th>団体ID</th><th>項目</th><th>現在値</th><th></th><th>反映後</th></tr></thead>
        <tbody>
        <?php foreach ($changes as $c): $field = (string)$c['field_name']; ?>
          <tr>
            <td><?= kintone_risk_badge((string)$c['risk_level']) ?></td>
            <td class="mono allow-select"><?= kintone_h($c['organization_code']) ?></td>
            <td><span class="cell-main"><?= kintone_h(kintone_field_label($field)) ?></span><?php if (kintone_field_label($field) !== $field): ?><span class="cell-sub mono"><?= kintone_h($field) ?></span><?php endif; ?></td>
            <td class="kt-old"><?= kintone_change_value($field, $c['old_value'] ?? null) ?></td>
            <td class="kt-arrow" aria-hidden="true"><?= fitsc_icon('arrow-right', 'ico--sm') ?></td>
            <td class="kt-new"><?= kintone_change_value($field, $c['new_value'] ?? null) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card__footer form-actions--between kt-confirm-footer">
      <?php if ($hasHigh): ?>
        <label class="check kt-high-confirm">
          <input type="checkbox" name="high_risk_confirm" value="1" required>
          <span>高リスクの変更を含むことを確認しました</span>
        </label>
      <?php else: ?>
        <input type="hidden" name="high_risk_confirm" value="0">
        <span></span>
      <?php endif; ?>
      <div class="cluster">
        <a class="btn btn--ghost" href="diff.php?batch_id=<?= (int)$batchId ?>"><?= fitsc_icon('arrow-left') ?>戻る</a>
        <button class="btn btn--primary" type="submit"><?= fitsc_icon('check') ?>団体マスタへ反映</button>
      </div>
    </div>
  </section>
</form>
<?php kintone_page_footer(); ?>
