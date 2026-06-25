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
?>
<section class="card"><form method="get"><label for="q">検索</label><input id="q" name="q" value="<?= kintone_h($q) ?>"><button class="btn" type="submit">検索</button></form></section>
<section class="card"><table><thead><tr><th>団体ID</th><th>団体名</th><th>代表者</th><th>メール</th><th>活動状態</th><th>部員数</th></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><td><?= kintone_h($r['organization_code']) ?></td><td><?= kintone_h($r['organization_name']) ?></td><td><?= kintone_h($r['representative_name'] ?? '') ?></td><td><?= kintone_h($r['representative_email'] ?? '') ?></td><td><?= kintone_h($r['activity_status']) ?></td><td><?= (int)$r['member_count'] ?></td></tr><?php endforeach; ?></tbody></table></section>
<?php kintone_page_footer(); ?>
