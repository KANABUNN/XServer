<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
[$user, $mailPdo, $dbError] = mail_app_init();
$editTemplate = null;

if ($mailPdo instanceof PDO && $dbError === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    mail_auth_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save') {
            mail_require_permission_or_forbid($user, 'template.edit');
            $id = mail_save_template($mailPdo, $_POST, $user);
            mail_audit_log($mailPdo, $user, 'mail.template.save', 'mail_template', (string)$id);
            mail_flash_set('info', 'テンプレートを保存しました。');
            mail_redirect('templates.php');
        } elseif ($action === 'toggle') {
            mail_require_permission_or_forbid($user, 'template.edit');
            $id = (int)($_POST['id'] ?? 0);
            $active = (int)($_POST['is_active'] ?? 0) === 1;
            mail_set_template_active($mailPdo, $id, $active);
            mail_audit_log($mailPdo, $user, 'mail.template.toggle', 'mail_template', (string)$id, ['is_active' => $active]);
            mail_flash_set('info', $active ? 'テンプレートを有効化しました。' : 'テンプレートを無効化しました。');
            mail_redirect('templates.php');
        }
    } catch (Throwable $e) {
        mail_flash_set('danger', mail_user_safe_error_message($e));
        mail_redirect('templates.php');
    }
}

$templates = [];
if ($mailPdo instanceof PDO && $dbError === '') {
    if (isset($_GET['edit'])) {
        $editTemplate = mail_get_template($mailPdo, (int)$_GET['edit']);
    }
    $templates = mail_list_templates($mailPdo, false);
}
$form = $editTemplate ?: [
    'id' => 0,
    'template_key' => '',
    'title' => '',
    'subject_template' => '【学生自治会】{{団体名}}への資料送付について',
    'body_template' => '-----------------------------------------------<br>福岡工業大学 学生自治会 総合管理事務局<br>sogokanri@bene.fit.ac.jp<br>内線 2472番<br>-----------------------------------------------',
    'body_type' => 'html',
    'variables_json' => '',
    'is_active' => 1,
];

$form['body_template'] = mail_admin_body_to_editor_html((string)($form['body_template'] ?? ''), (string)($form['body_type'] ?? 'html'));
$form['body_type'] = 'html';
mail_render_page_header('テンプレート', $user, 'templates.php', [
    'subtitle' => '件名・本文に {{団体名}} などの変数を置くと、送信時に団体ごとの値に置き換わります',
    'actions' => $editTemplate ? '<a class="btn btn--ghost" href="templates.php">' . fitsc_icon('plus') . '<span class="hide-mobile">新規作成に切替</span></a>' : '',
]);
$canEditTemplate = mail_auth_has_permission($user, 'template.edit');
$variableButtons = ['{{識別番号}}', '{{団体名}}', '{{代表者氏名}}', '{{メールアドレス}}', '{{区分}}', '{{identifier}}', '{{organization_name}}', '{{representative_name}}', '{{email}}'];
?>
<?php mail_render_db_error($dbError); ?>
<?php if ($dbError === ''): ?>

<div class="split split--sidebar">
  <section class="card">
    <div class="card__header">
      <div class="card__title"><?php echo fitsc_icon($editTemplate ? 'edit' : 'plus'); ?><?php echo $editTemplate ? 'テンプレートを編集' : '新しいテンプレート'; ?></div>
      <?php if ($editTemplate): ?><span class="badge">#<?php echo (int)$form['id']; ?></span><?php endif; ?>
    </div>
    <form method="post" class="card__body stack" data-busy>
      <?php echo mail_auth_csrf_field(); ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">
      <input type="hidden" name="body_type" value="html">
      <div class="form-grid form-grid--3">
        <label class="field"><span>表示名 <span class="req">必須</span></span><input type="text" name="title" value="<?php echo mail_h((string)$form['title']); ?>" required></label>
        <label class="field"><span>キー <span class="opt">空欄で自動</span></span><input type="text" name="template_key" value="<?php echo mail_h((string)$form['template_key']); ?>" spellcheck="false"></label>
        <label class="field"><span>状態</span><select name="is_active"><option value="1"<?php echo mail_selected($form['is_active'], 1); ?>>有効</option><option value="0"<?php echo mail_selected($form['is_active'], 0); ?>>無効</option></select></label>
      </div>
      <label class="field"><span>件名 <span class="req">必須</span></span><input type="text" name="subject_template" id="templateSubject" data-variable-insert-target value="<?php echo mail_h((string)$form['subject_template']); ?>" required></label>
      <div class="field editor-field">
        <span>本文 <span class="req">必須</span></span>
        <textarea name="body_template" id="templateBody" class="html-editor-source" data-html-editor-input required><?php echo mail_h((string)$form['body_template']); ?></textarea>
        <div class="html-editor-wrap">
          <div class="html-editor-toolbar" data-editor-toolbar="templateBodyEditor" role="toolbar" aria-label="書式">
            <button type="button" data-editor-command="bold" title="太字"><strong>B</strong></button>
            <button type="button" data-editor-command="italic" title="斜体"><em>I</em></button>
            <button type="button" data-editor-command="underline" title="下線"><u>U</u></button>
            <button type="button" data-editor-command="insertUnorderedList" title="箇条書き"><?php echo fitsc_icon('list', 'ico--sm'); ?></button>
            <span class="html-editor-sep" aria-hidden="true"></span>
            <select data-editor-size title="文字サイズ" aria-label="文字サイズ">
              <option value="3">標準</option>
              <option value="2">小さめ</option>
              <option value="4">大きめ</option>
              <option value="5">見出し</option>
            </select>
            <label class="editor-color-picker" title="文字色"><input type="color" data-editor-color value="#1b2430" aria-label="文字色"></label>
            <button type="button" data-editor-command="removeFormat" title="書式を解除"><?php echo fitsc_icon('rotate-ccw', 'ico--sm'); ?></button>
          </div>
          <div id="templateBodyEditor" class="html-editor" contenteditable="true" data-variable-insert-target data-html-editor="templateBody" data-placeholder="本文を入力してください。"><?php echo mail_admin_sanitize_editor_html((string)$form['body_template']); ?></div>
        </div>
      </div>
      <div class="form-actions form-actions--end">
        <?php if ($editTemplate): ?><a class="btn btn--ghost" href="templates.php">キャンセル</a><?php endif; ?>
        <button type="submit" class="btn btn--primary"<?php echo $canEditTemplate ? '' : ' disabled'; ?>><?php echo fitsc_icon('save'); ?>保存</button>
      </div>
    </form>
  </section>

  <aside class="card sticky-col">
    <div class="card__header card__header--plain"><div class="card__title"><?php echo fitsc_icon('tag'); ?>差し込み変数</div></div>
    <div class="card__body stack stack--sm">
      <div class="variable-list">
        <?php foreach ($variableButtons as $variable): ?>
          <button type="button" class="chip variable-chip" data-insert-variable="<?php echo mail_h($variable); ?>" data-insert-targets="templateSubject,templateBodyEditor"><?php echo mail_h($variable); ?></button>
        <?php endforeach; ?>
      </div>
      <p class="small-note">最後にカーソルを置いた件名または本文に挿入します。</p>
      <p class="small-note">置き換えられない変数が残った宛先は「要確認」になります。</p>
    </div>
  </aside>
</div>

<section class="card">
  <div class="card__header"><div class="card__title"><?php echo fitsc_icon('template'); ?>テンプレート一覧 <small><?php echo count($templates); ?>件</small></div></div>
  <?php if ($templates === []): ?>
    <div class="empty"><span class="empty__icon"><?php echo fitsc_icon('template'); ?></span><span>テンプレートがありません。</span></div>
  <?php else: ?>
    <div class="table-wrap is-stacked">
      <table class="table">
        <thead><tr><th>表示名</th><th>件名</th><th>変数</th><th>状態</th><th class="col-actions"></th></tr></thead>
        <tbody>
          <?php foreach ($templates as $tpl): ?>
            <?php
              $varInfo = [];
              if (is_string($tpl['variables_json'] ?? null) && $tpl['variables_json'] !== '') {
                  $decoded = json_decode((string)$tpl['variables_json'], true);
                  $varInfo = is_array($decoded['variables'] ?? null) ? $decoded['variables'] : [];
              }
            ?>
            <tr<?php echo (int)$tpl['id'] === (int)$form['id'] && $editTemplate ? ' class="is-selected"' : ''; ?>>
              <td><span class="cell-main"><?php echo mail_h((string)$tpl['title']); ?></span><span class="cell-sub"><code><?php echo mail_h((string)$tpl['template_key']); ?></code></span></td>
              <td><?php echo mail_h((string)$tpl['subject_template']); ?></td>
              <td><div class="cluster gap-1"><?php foreach ($varInfo as $var): ?><span class="chip"><?php echo mail_h((string)$var); ?></span><?php endforeach; ?><?php if ($varInfo === []): ?><span class="text-subtle">—</span><?php endif; ?></div></td>
              <td><?php echo mail_active_badge($tpl['is_active']); ?></td>
              <td class="col-actions">
                <div class="btn-group">
                  <a class="btn btn--ghost btn--sm" href="templates.php?edit=<?php echo (int)$tpl['id']; ?>"><?php echo fitsc_icon('pen'); ?>編集</a>
                  <form method="post">
                    <?php echo mail_auth_csrf_field(); ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?php echo (int)$tpl['id']; ?>">
                    <input type="hidden" name="is_active" value="<?php echo (int)$tpl['is_active'] === 1 ? 0 : 1; ?>">
                    <button type="submit" class="btn btn--ghost btn--sm"<?php echo $canEditTemplate ? '' : ' disabled'; ?>><?php echo (int)$tpl['is_active'] === 1 ? '無効化' : '有効化'; ?></button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php
mail_render_page_footer();
