<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
$user = kintone_auth_require_operator_access();
kintone_page_header('名簿アップロード', $user, ['subtitle' => '部員名簿CSVを解析して、団体マスタとの差分を作成します']);
kintone_render_flash();
?>
<div class="kt-narrow stack stack--lg">
  <div class="steps" aria-label="反映までの手順">
    <div class="step is-active"><span class="step__label">1. アップロード</span></div>
    <div class="step"><span class="step__label">2. 差分の確認</span></div>
    <div class="step"><span class="step__label">3. 最終確認・反映</span></div>
  </div>

  <section class="card">
    <div class="card__header card__header--plain">
      <div class="card__title"><?= fitsc_icon('upload') ?>部員名簿CSV</div>
      <a class="btn btn--ghost btn--sm" href="api/download_template.php?csrf_token=<?= kintone_h(kintone_auth_csrf_token()) ?>"><?= fitsc_icon('download') ?>テンプレート</a>
    </div>
    <form method="post" action="api/upload_roster.php" enctype="multipart/form-data" data-busy>
      <div class="card__body stack">
        <?= kintone_auth_csrf_field() ?>
        <label class="field">
          <span>CSVファイル <span class="req">必須</span></span>
          <input type="file" name="roster_csv" accept=".csv,text/csv" required>
          <span class="field__hint">必須の列：団体ID・団体名・氏名。代表者・代表メール・活動可否は自動で判定します。</span>
        </label>
        <label class="field">
          <span>文字コード</span>
          <select id="encoding" name="encoding">
            <option value="auto">自動判定（推奨）</option>
            <option value="UTF-8">UTF-8</option>
            <option value="SJIS-win">Shift_JIS / CP932</option>
          </select>
          <span class="field__hint">文字化けした場合のみ指定してください。</span>
        </label>
      </div>
      <div class="card__footer form-actions--end">
        <button class="btn btn--primary" type="submit"><?= fitsc_icon('play') ?>解析する</button>
      </div>
    </form>
  </section>
</div>
<?php kintone_page_footer(); ?>
