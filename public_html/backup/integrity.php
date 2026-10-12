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
backup_web_header('整合性チェック', $user);
backup_web_flash($flashType, $flashMessage);
?>
<section class="section-title">
  <div>
    <h2>DB・ファイル整合性チェック</h2>
    <p class="muted">Forms添付、Mail添付、SwitchBot詳細JSONについて、DB上の相対パスと実ファイルの存在を照合します。</p>
  </div>
  <?php if ($isAdmin): ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= backup_h(backup_auth_csrf_token()) ?>">
      <button class="btn" type="submit">今すぐ実行</button>
    </form>
  <?php endif; ?>
</section>

<section class="grid">
  <div class="card"><h2>最新状態</h2><div class="metric"><?= $selected ? backup_web_status_badge((string)$selected['status']) : '未実行' ?></div><p class="muted"><?= $selected ? backup_h((string)$selected['finished_at']) : 'backup_integrity_check.php を実行してください。' ?></p></div>
  <div class="card"><h2>確認件数</h2><div class="metric"><?= $selected ? number_format((int)$selected['checked_items']) : '-' ?></div><p class="muted">OK <?= $selected ? number_format((int)$selected['ok_items']) : 0 ?> 件</p></div>
  <div class="card"><h2>欠損</h2><div class="metric danger"><?= $selected ? number_format((int)$selected['missing_items']) : '-' ?></div><p class="muted">欠損は復元またはDB記録修正が必要です。</p></div>
  <div class="card"><h2>警告</h2><div class="metric warn"><?= $selected ? number_format((int)$selected['warning_items']) : '-' ?></div><p class="muted">DB未設定などの確認不能項目です。</p></div>
</section>

<section class="section-title"><h2>実行履歴</h2></section>
<section class="card">
<table>
<thead><tr><th>ID</th><th>キー</th><th>開始</th><th>終了</th><th>状態</th><th>確認</th><th>欠損</th><th></th></tr></thead>
<tbody>
<?php foreach ($checks as $check): ?>
<tr>
  <td><?= (int)$check['id'] ?></td>
  <td class="mono"><?= backup_h((string)$check['check_key']) ?></td>
  <td><?= backup_h((string)$check['started_at']) ?></td>
  <td><?= backup_h((string)($check['finished_at'] ?? '')) ?></td>
  <td><?= backup_web_status_badge((string)$check['status']) ?></td>
  <td><?= number_format((int)$check['checked_items']) ?></td>
  <td><?= number_format((int)$check['missing_items']) ?></td>
  <td><a class="btn" href="integrity.php?id=<?= (int)$check['id'] ?>">詳細</a></td>
</tr>
<?php endforeach; ?>
<?php if ($checks === []): ?><tr><td colspan="8" class="muted">履歴はありません。</td></tr><?php endif; ?>
</tbody>
</table>
</section>

<?php if ($selected): ?>
<section class="section-title"><h2>検出項目</h2><span class="muted">欠損・警告のみ最大300件表示</span></section>
<section class="card">
<table>
<thead><tr><th>ID</th><th>アプリ</th><th>テーブル</th><th>参照ID</th><th>状態</th><th>相対パス</th><th>推定パス</th><th>内容</th></tr></thead>
<tbody>
<?php foreach ($items as $item): ?>
<tr>
  <td><?= (int)$item['id'] ?></td>
  <td><?= backup_h((string)$item['app_key']) ?></td>
  <td class="mono"><?= backup_h((string)$item['source_table']) ?></td>
  <td><?= backup_h((string)$item['source_id']) ?></td>
  <td><?= backup_web_status_badge((string)$item['item_status']) ?></td>
  <td class="mono"><?= backup_h((string)$item['relative_path']) ?></td>
  <td class="mono"><?= backup_h((string)$item['resolved_path']) ?></td>
  <td><?= backup_h((string)$item['message']) ?></td>
</tr>
<?php endforeach; ?>
<?php if ($items === []): ?><tr><td colspan="8" class="muted">欠損・警告項目はありません。</td></tr><?php endif; ?>
</tbody>
</table>
</section>
<?php endif; ?>
<?php backup_web_footer(); ?>
