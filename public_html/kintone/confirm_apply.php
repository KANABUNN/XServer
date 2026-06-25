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

kintone_page_header('反映前の最終確認', $user);
kintone_render_flash();
?>
<section class="card">
  <h2>変更サマリ</h2>
  <ul>
    <li>🔴 高リスク変更 <?= (int)$summary['high'] ?>件（代表メール変更 <?= (int)$summary['email_changes'] ?>件 / active→inactive <?= (int)$summary['active_to_inactive'] ?>件）</li>
    <li>🟡 中リスク変更 <?= (int)$summary['medium'] ?>件</li>
    <li>🟢 低リスク変更 <?= (int)$summary['low'] ?>件</li>
  </ul>
  <p class="muted">この画面はサーバ側PHPで生成されます。高リスク変更を含む場合、画面上のチェックに加えて apply_import.php 側でも確認フラグを検証します。</p>
</section>
<section class="card">
  <h2>反映予定の差分</h2>
  <table>
    <thead><tr><th>リスク</th><th>団体ID</th><th>項目</th><th>現在値</th><th>反映後</th></tr></thead>
    <tbody>
      <?php foreach ($changes as $c): ?>
      <tr>
        <td><?= kintone_h($c['risk_level']) ?></td>
        <td><?= kintone_h($c['organization_code']) ?></td>
        <td><?= kintone_h($c['field_name']) ?></td>
        <td><?= kintone_h($c['old_value'] ?? '') ?></td>
        <td><?= kintone_h($c['new_value'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<section class="card">
  <form method="post" action="api/apply_import.php">
    <?= kintone_auth_csrf_field() ?>
    <input type="hidden" name="confirm_stage" value="1">
    <input type="hidden" name="batch_id" value="<?= (int)$batchId ?>">
    <?php foreach ($changes as $c): ?><input type="hidden" name="change_ids[]" value="<?= (int)$c['id'] ?>"><?php endforeach; ?>
    <?php if ((int)$summary['high'] > 0): ?>
      <label><input type="checkbox" name="high_risk_confirm" value="1" required> 高リスク変更を含みます。内容を確認しました。</label>
    <?php else: ?>
      <input type="hidden" name="high_risk_confirm" value="0">
    <?php endif; ?>
    <div class="form-actions">
      <button class="primary" type="submit">反映する</button>
      <a class="secondary" href="diff.php?batch_id=<?= (int)$batchId ?>">戻る</a>
    </div>
  </form>
</section>
<?php kintone_page_footer(); ?>
