<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
$user = kintone_auth_require_operator_access();
$csrf = kintone_auth_csrf_token();
kintone_page_header('名簿アップロード', $user);
kintone_render_flash();
?>
<section class="card"><h2>CSVアップロード</h2><p class="muted">UTF-8(BOM可) または Shift_JIS(CP932) のCSVに対応します。xlsxはPhase 4対象です。</p>
<form method="post" action="api/upload_roster.php" enctype="multipart/form-data">
<?= kintone_auth_csrf_field() ?>
<label for="roster_csv">部員名簿CSV</label>
<input id="roster_csv" type="file" name="roster_csv" accept=".csv,text/csv" required>
<button class="primary" type="submit">解析して差分を作成</button>
</form>
<p><a class="btn" href="api/download_template.php?csrf_token=<?= kintone_h($csrf) ?>">CSVテンプレートをダウンロード</a></p>
</section>
<?php kintone_page_footer(); ?>
