<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
$user = kintone_auth_require_operator_access();
kintone_page_header('名簿アップロード', $user);
kintone_render_flash();
?>
<section class="card">
  <h2>部員名簿CSVアップロード</h2>
  <p class="muted">団体ID、団体名、氏名を必須列として、代表者・代表メール・活動可否を自動判定します。</p>
  <form method="post" action="api/upload_roster.php" enctype="multipart/form-data">
    <?= kintone_auth_csrf_field() ?>
    <label>CSVファイル<br><input type="file" name="roster_csv" accept=".csv,text/csv" required></label>
    <label for="encoding">文字コード</label>
    <select id="encoding" name="encoding">
      <option value="auto">自動判定（推奨）</option>
      <option value="UTF-8">UTF-8</option>
      <option value="SJIS-win">Shift_JIS / CP932</option>
    </select>
    <p class="muted">文字化けする場合のみ、UTF-8 または Shift_JIS / CP932 を手動指定してください。EUC-JPは誤判定防止のため選択肢に含めていません。</p>
    <button class="primary" type="submit">解析する</button>
  </form>
</section>
<section class="card">
  <h2>テンプレート</h2>
  <p><a href="api/download_template.php?csrf_token=<?= kintone_h(kintone_auth_csrf_token()) ?>">CSVテンプレートをダウンロード</a></p>
</section>
<?php kintone_page_footer(); ?>
