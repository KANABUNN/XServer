<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once dirname(__DIR__, 2) . '/apps/backup_core/integrity_checker.php';

$user = backup_web_require_user();
$isAdmin = backup_web_is_admin($user);
$flashType = null;
$flashMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = backup_web_require_admin();
    backup_auth_require_csrf();
    try {
        $result = backup_run_integrity_check('manual', $user);
        $flashType = 'success';
        $flashMessage = '整合性チェックを実行しました。ID: ' . (int)($result['id'] ?? 0);
        header('Location: integrity.php?id=' . (int)($result['id'] ?? 0), true, 302);
        exit;
    } catch (Throwable $e) {
        $flashType = 'error';
        $flashMessage = $e->getMessage();
    }
}

$checks = backup_latest_integrity_checks(30);
$selectedId = (int)($_GET['id'] ?? ($checks[0]['id'] ?? 0));
$selected = $selectedId > 0 ? backup_get_integrity_check($selectedId) : null;
$items = $selected ? backup_integrity_items((int)$selected['id'], 300) : [];
$runButton = '';
if ($isAdmin) {
    $runButton = '<form method="post" data-busy><input type="hidden" name="_csrf" value="' . backup_h(backup_auth_csrf_token()) . '">'
        . '<button class="btn btn--primary" type="submit">' . fitsc_icon('play') . '<span class="hide-mobile">今すぐ実行</span></button></form>';
}
backup_web_header('整合性チェック', $user, [
    'subtitle' => 'DB に記録された添付・JSON と実ファイルの有無を照合',
    'actions' => $runButton,
]);
backup_web_flash($flashType, $flashMessage);
$selTone = $selected ? backup_web_status_tone((string)$selected['status']) : '';
?>
<div class="stat-grid">
  <div class="stat<?= $selTone !== '' ? ' stat--' . $selTone : '' ?>">
    <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('shield') ?></span><?= $selected ? 'チェック #' . (int)$selected['id'] : '最新の状態' ?></span>
    <span class="stat__value"><?= $selected ? backup_h(backup_status_label((string)$selected['status'])) : '未実行' ?></span>
    <span class="stat__meta"><?= $selected ? backup_h((string)$selected['finished_at']) : '整合性チェックはまだ実行されていません' ?></span>
  </div>
  <div class="stat stat--info">
    <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('list-checks') ?></span>確認件数</span>
    <span class="stat__value"><?= $selected ? number_format((int)$selected['checked_items']) : '—' ?></span>
    <span class="stat__meta">正常 <?= $selected ? number_format((int)$selected['ok_items']) : 0 ?> 件</span>
  </div>
  <div class="stat <?= $selected && (int)$selected['missing_items'] > 0 ? 'stat--danger' : 'stat--success' ?>">
    <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('x-circle') ?></span>欠損</span>
    <span class="stat__value"><?= $selected ? number_format((int)$selected['missing_items']) : '—' ?></span>
    <span class="stat__meta">復元または DB 記録の修正が必要</span>
  </div>
  <div class="stat <?= $selected && (int)$selected['warning_items'] > 0 ? 'stat--warning' : 'stat--teal' ?>">
    <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('alert-triangle') ?></span>警告</span>
    <span class="stat__value"><?= $selected ? number_format((int)$selected['warning_items']) : '—' ?></span>
    <span class="stat__meta">DB 未設定などで確認できない項目</span>
  </div>
</div>

<?php if ($selected): ?>
<section class="card">
  <div class="card__header">
    <div class="card__title"><?= fitsc_icon('alert-circle') ?>検出された項目 <small>欠損・警告のみ（最大300件）</small></div>
  </div>
  <?php if ($items === []): ?>
    <?= backup_web_empty('check-circle', '欠損・警告はありません') ?>
  <?php else: ?>
    <div class="table-wrap is-stacked">
      <table class="table table--compact">
        <thead><tr><th>状態</th><th>アプリ・参照元</th><th>パス</th><th>内容</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
          <tr>
            <td><?= backup_web_status_badge((string)$item['item_status']) ?></td>
            <td><span class="cell-main"><?= backup_h((string)$item['app_key']) ?></span><span class="cell-sub mono"><?= backup_h((string)$item['source_table']) ?> #<?= backup_h((string)$item['source_id']) ?></span></td>
            <td><span class="mono text-xs allow-select bk-wrap"><?= backup_h((string)$item['relative_path']) ?></span><span class="cell-sub mono allow-select bk-wrap"><?= backup_h((string)$item['resolved_path']) ?></span></td>
            <td class="text-sm"><?= backup_h((string)$item['message']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
  <div class="card__header">
    <div class="card__title"><?= fitsc_icon('history') ?>実行履歴</div>
  </div>
  <?php if ($checks === []): ?>
    <?= backup_web_empty('history', '履歴はありません') ?>
  <?php else: ?>
    <div class="table-wrap is-stacked">
      <table class="table table--compact">
        <thead><tr><th>開始</th><th>状態</th><th class="text-right">確認</th><th class="text-right">欠損</th><th>終了</th><th class="col-actions"></th></tr></thead>
        <tbody>
        <?php foreach ($checks as $check): $isSel = $selected && (int)$check['id'] === (int)$selected['id']; ?>
          <tr data-href="integrity.php?id=<?= (int)$check['id'] ?>" class="bk-row-link<?= $isSel ? ' is-selected' : '' ?>">
            <td><span class="cell-main nowrap"><?= backup_h((string)$check['started_at']) ?></span><span class="cell-sub mono">#<?= (int)$check['id'] ?> <?= backup_h((string)$check['check_key']) ?></span></td>
            <td><?= backup_web_status_badge((string)$check['status']) ?></td>
            <td class="text-right num"><?= number_format((int)$check['checked_items']) ?></td>
            <td class="text-right num<?= (int)$check['missing_items'] > 0 ? ' text-danger fw-bold' : '' ?>"><?= number_format((int)$check['missing_items']) ?></td>
            <td class="text-xs nowrap"><?= backup_h((string)($check['finished_at'] ?? '')) ?></td>
            <td class="col-actions"><a class="btn btn--ghost btn--icon btn--sm" href="integrity.php?id=<?= (int)$check['id'] ?>" aria-label="#<?= (int)$check['id'] ?> を表示"><?= fitsc_icon('chevron-right') ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php backup_web_footer(); ?>
