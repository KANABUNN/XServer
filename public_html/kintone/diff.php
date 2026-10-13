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
$pendingCount = count(array_filter($changes, static fn(array $c): bool => empty($c['applied_at'])));
kintone_page_header('差分・承認', $user, ['subtitle' => '取り込んだ名簿と団体マスタの違いを確認して反映します']);
kintone_render_flash();
if (!$batch): ?>
<section class="card">
  <div class="empty">
    <span class="empty__icon"><?= fitsc_icon('diff') ?></span>
    <span class="empty__title">確認する差分はありません</span>
    <span>名簿CSVをアップロードすると、ここに差分が表示されます。</span>
    <?php if (kintone_user_can_operate($user)): ?><a class="btn btn--primary btn--sm" href="upload.php"><?= fitsc_icon('upload') ?>名簿をアップロード</a><?php endif; ?>
  </div>
</section>
<?php else: ?>
<div class="steps" aria-label="反映までの手順">
  <div class="step is-done"><span class="step__label">1. アップロード</span></div>
  <div class="step is-active"><span class="step__label">2. 差分の確認</span></div>
  <div class="step"><span class="step__label">3. 最終確認・反映</span></div>
</div>

<section class="card">
  <div class="card__header">
    <div>
      <div class="card__title"><?= fitsc_icon('file-text') ?><span class="truncate"><?= kintone_h($batch['original_name']) ?></span></div>
      <div class="card__subtitle">バッチ #<?= (int)$batch['id'] ?> ・ <?= (int)$batch['total_rows'] ?>行</div>
    </div>
    <?= kintone_status_badge((string)$batch['status']) ?>
  </div>
  <div class="card__body">
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
  </div>
</section>

<form method="post" action="confirm_apply.php" data-kt-diff>
  <?= kintone_auth_csrf_field() ?><input type="hidden" name="batch_id" value="<?= (int)$batch['id'] ?>">
  <section class="card">
    <div class="card__header">
      <div class="card__title"><?= fitsc_icon('list') ?>変更一覧 <small><?= count($changes) ?>件（未反映 <?= $pendingCount ?>）</small></div>
      <div class="segmented" role="group" aria-label="リスクで絞り込み" data-risk-filter>
        <button type="button" class="is-active" data-risk="all">すべて</button>
        <button type="button" data-risk="high">高</button>
        <button type="button" data-risk="medium">中</button>
        <button type="button" data-risk="low">低</button>
      </div>
    </div>
    <?php if ($changes === []): ?>
      <div class="empty"><span class="empty__icon"><?= fitsc_icon('check-circle') ?></span><span>このバッチに変更はありません。</span></div>
    <?php else: ?>
      <div class="table-wrap is-stacked kt-table-flat">
        <table class="table">
          <thead><tr>
            <th class="kt-col-check"><?php if ($canApply): ?><label class="kt-check-all" aria-label="表示中をすべて選択"><input type="checkbox" data-check-all="change_ids[]"></label><?php endif; ?></th>
            <th>リスク</th><th>団体ID</th><th>項目</th><th>現在値</th><th></th><th>取込後</th><th>状態</th>
          </tr></thead>
          <tbody>
          <?php foreach ($changes as $c): $high = (string)$c['risk_level'] === 'high'; $applied = !empty($c['applied_at']); $field = (string)$c['field_name']; ?>
            <tr data-risk="<?= kintone_h($c['risk_level']) ?>"<?= $applied ? ' class="is-muted"' : '' ?>>
              <td class="kt-col-check"><input type="checkbox" name="change_ids[]" value="<?= (int)$c['id'] ?>" aria-label="この変更を選択" <?= (!$high && !$applied) ? 'checked' : '' ?> <?= ($canApply && !$applied) ? '' : 'disabled' ?>></td>
              <td><?= kintone_risk_badge((string)$c['risk_level']) ?></td>
              <td class="mono allow-select"><?= kintone_h($c['organization_code']) ?></td>
              <td><span class="cell-main"><?= kintone_h(kintone_field_label($field)) ?></span><?php if (kintone_field_label($field) !== $field): ?><span class="cell-sub mono"><?= kintone_h($field) ?></span><?php endif; ?></td>
              <td class="kt-old"><?= kintone_change_value($field, $c['old_value'] ?? null) ?></td>
              <td class="kt-arrow" aria-hidden="true"><?= fitsc_icon('arrow-right', 'ico--sm') ?></td>
              <td class="kt-new"><?= kintone_change_value($field, $c['new_value'] ?? null) ?></td>
              <td><?= $applied ? '<span class="badge badge--success">反映済み</span>' : '<span class="badge">未反映</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <div class="card__footer kt-diff-actions">
      <?php if ($canApply): ?>
        <span class="text-sm text-muted kt-diff-actions__note"><?= fitsc_icon('info', 'ico--sm') ?>高リスクの変更は初期状態で未選択です。<span data-selected-count></span></span>
        <button class="btn" type="submit" name="selection_mode" value="selected"><?= fitsc_icon('list-checks') ?>選択した差分を確認</button>
        <button class="btn btn--primary" type="submit" name="selection_mode" value="safe_only"><?= fitsc_icon('check') ?>高リスク以外を承認へ</button>
      <?php else: ?>
        <span class="text-sm text-muted kt-diff-actions__note"><?= fitsc_icon('lock', 'ico--sm') ?>反映には管理者権限が必要です。</span>
      <?php endif; ?>
    </div>
  </section>
</form>
<?php endif; ?>
<?php kintone_page_footer(); ?>
