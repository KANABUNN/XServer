<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
$user = kintone_auth_require_view_access();
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $stmt = kintone_pdo('org')->prepare('SELECT * FROM organizations WHERE organization_code LIKE :q OR organization_name LIKE :q ORDER BY organization_name ASC LIMIT 200');
    $stmt->execute([':q' => '%' . $q . '%']);
    $rows = $stmt->fetchAll() ?: [];
} else {
    $rows = kintone_pdo('org')->query('SELECT * FROM organizations ORDER BY organization_name ASC, id ASC LIMIT 200')->fetchAll() ?: [];
}
kintone_page_header('団体マスタ', $user);
kintone_render_flash();
?>
<section class="card">
  <div class="card__header">
    <div class="card__title"><?= fitsc_icon('building') ?>団体一覧 <small><?= count($rows) ?>件<?= count($rows) >= 200 ? '（先頭200件）' : '' ?></small></div>
    <form method="get" class="cluster" role="search">
      <div class="search-toggle" data-search-toggle data-submit-on-clear<?= $q !== '' ? ' data-open' : '' ?>>
        <button type="button" class="btn btn--ghost btn--icon search-toggle__button" aria-label="団体を検索"><?= fitsc_icon('search') ?></button>
        <div class="search-toggle__field">
          <?= fitsc_icon('search') ?>
          <input type="search" name="q" value="<?= kintone_h($q) ?>" placeholder="団体ID・団体名" aria-label="団体を検索">
          <button type="button" class="btn btn--ghost btn--icon search-toggle__clear" aria-label="検索を閉じる"><?= fitsc_icon('x') ?></button>
        </div>
      </div>
      <noscript><button type="submit" class="btn">検索</button></noscript>
    </form>
  </div>
  <?php if ($rows === []): ?>
    <div class="empty"><span class="empty__icon"><?= fitsc_icon('building') ?></span><span><?= $q !== '' ? '「' . kintone_h($q) . '」に一致する団体はありません。' : '団体はまだ登録されていません。' ?></span></div>
  <?php else: ?>
    <div class="table-wrap is-stacked kt-table-flat">
      <table class="table">
        <thead><tr><th>団体</th><th>代表者</th><th>代表メール</th><th>活動状態</th><th class="text-right">部員数</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><span class="cell-main"><?= kintone_h($r['organization_name']) ?></span><span class="cell-sub mono allow-select"><?= kintone_h($r['organization_code']) ?></span></td>
            <td><?= ($r['representative_name'] ?? '') !== '' ? kintone_h($r['representative_name']) : '<span class="text-subtle">—</span>' ?></td>
            <td class="allow-select"><?= ($r['representative_email'] ?? '') !== '' ? kintone_h($r['representative_email']) : '<span class="text-subtle">—</span>' ?></td>
            <td><?= kintone_status_badge((string)$r['activity_status']) ?></td>
            <td class="text-right num"><?= (int)$r['member_count'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php kintone_page_footer(); ?>
