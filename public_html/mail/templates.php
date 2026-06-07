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
    'body_template' => '<p>{{団体名}}<br>{{代表者氏名}} 様</p><p>平素よりお世話になっております。<br>学生自治会です。</p><p>資料を添付いたしましたので、ご確認ください。</p><p>識別番号：{{識別番号}}</p><p>よろしくお願いいたします。</p>',
    'body_type' => 'html',
    'variables_json' => '',
    'is_active' => 1,
];

$form['body_template'] = mail_admin_body_to_editor_html((string)($form['body_template'] ?? ''), (string)($form['body_type'] ?? 'html'));
$form['body_type'] = 'html';

mail_render_page_header('テンプレート', $user, 'templates.php');
?>
<header class="page-head">
  <div>
    <h1>テンプレート</h1>
    <p class="lead">件名・本文に <code>{{団体名}}</code> のような変数を置き、送信バッチ作成時に団体DBの値を代入します。</p>
  </div>
</header>
<?php mail_render_db_error($dbError); ?>

<?php if ($dbError === ''): ?>
<div class="two-column-grid wide-left">
  <section class="panel">
    <div class="panel-head"><h2><?php echo $editTemplate ? 'テンプレートを編集' : 'テンプレートを追加'; ?></h2><span class="muted">変数名はクリックで挿入できます</span></div>
    <form method="post" class="form-grid">
      <?php echo mail_auth_csrf_field(); ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">
      <label><span>テンプレートキー</span><input type="text" name="template_key" value="<?php echo mail_h((string)$form['template_key']); ?>" placeholder="空欄なら自動生成"></label>
      <label><span>表示名 *</span><input type="text" name="title" value="<?php echo mail_h((string)$form['title']); ?>" required></label>
      <label class="full"><span>件名 *</span><input type="text" name="subject_template" id="templateSubject" data-variable-insert-target value="<?php echo mail_h((string)$form['subject_template']); ?>" required></label>
      <input type="hidden" name="body_type" value="html">
      <label><span>状態</span><select name="is_active"><option value="1"<?php echo mail_selected($form['is_active'], 1); ?>>有効</option><option value="0"<?php echo mail_selected($form['is_active'], 0); ?>>無効</option></select></label>
      <label class="full"><span>本文 * <small class="muted">HTML形式で保存されます</small></span>
        <textarea name="body_template" id="templateBody" class="html-editor-source" data-html-editor-input required><?php echo mail_h((string)$form['body_template']); ?></textarea>
        <div class="html-editor-wrap">
          <div class="html-editor-toolbar" data-editor-toolbar="templateBodyEditor">
            <button type="button" data-editor-command="bold"><strong>B</strong></button>
            <button type="button" data-editor-command="italic"><em>I</em></button>
            <button type="button" data-editor-command="underline"><u>U</u></button>
            <button type="button" data-editor-command="insertUnorderedList">箇条書き</button>
            <select data-editor-size title="文字サイズ">
              <option value="3">標準</option>
              <option value="2">小さめ</option>
              <option value="4">大きめ</option>
              <option value="5">見出し</option>
            </select>
            <label class="editor-color-picker">文字色 <input type="color" data-editor-color value="#1b2430"></label>
            <button type="button" data-editor-command="removeFormat">書式解除</button>
          </div>
          <div id="templateBodyEditor" class="html-editor" contenteditable="true" data-variable-insert-target data-html-editor="templateBody" data-placeholder="本文を入力してください。"><?php echo mail_admin_sanitize_editor_html((string)$form['body_template']); ?></div>
        </div>
      </label>
      <div class="form-actions full">
        <button type="submit" class="primary"<?php echo mail_auth_has_permission($user, 'template.edit') ? '' : ' disabled'; ?>>保存</button>
        <?php if ($editTemplate): ?><button type="button" class="secondary link-button" data-nav-href="templates.php">新規入力へ戻る</button><?php endif; ?>
      </div>
    </form>
  </section>

  <section class="panel">
    <h2>使用可能な基本変数</h2>
    <div class="variable-list vertical">
      <button type="button" class="variable-chip" data-insert-variable="{{識別番号}}" data-insert-targets="templateSubject,templateBodyEditor">{{識別番号}}</button>
      <button type="button" class="variable-chip" data-insert-variable="{{団体名}}" data-insert-targets="templateSubject,templateBodyEditor">{{団体名}}</button>
      <button type="button" class="variable-chip" data-insert-variable="{{代表者氏名}}" data-insert-targets="templateSubject,templateBodyEditor">{{代表者氏名}}</button>
      <button type="button" class="variable-chip" data-insert-variable="{{メールアドレス}}" data-insert-targets="templateSubject,templateBodyEditor">{{メールアドレス}}</button>
      <button type="button" class="variable-chip" data-insert-variable="{{区分}}" data-insert-targets="templateSubject,templateBodyEditor">{{区分}}</button>
      <button type="button" class="variable-chip" data-insert-variable="{{identifier}}" data-insert-targets="templateSubject,templateBodyEditor">{{identifier}}</button>
      <button type="button" class="variable-chip" data-insert-variable="{{organization_name}}" data-insert-targets="templateSubject,templateBodyEditor">{{organization_name}}</button>
      <button type="button" class="variable-chip" data-insert-variable="{{representative_name}}" data-insert-targets="templateSubject,templateBodyEditor">{{representative_name}}</button>
      <button type="button" class="variable-chip" data-insert-variable="{{email}}" data-insert-targets="templateSubject,templateBodyEditor">{{email}}</button>
    </div>
    <p class="muted mt-14">挿入先は、最後にカーソルを置いた件名または本文です。どちらにもカーソルがない場合は件名に挿入します。</p>
    <p class="muted mt-14">未定義の変数が残った場合、その宛先は <strong>要確認</strong> としてバッチに登録されます。</p>
  </section>
</div>

<section class="panel mt-18">
  <div class="panel-head"><h2>テンプレート一覧</h2><span class="muted"><?php echo count($templates); ?>件</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>表示名</th><th>件名</th><th>本文形式</th><th>変数</th><th>状態</th><th>操作</th></tr></thead>
      <tbody>
        <?php if ($templates === []): ?><tr><td colspan="7" class="empty">テンプレートがありません。</td></tr><?php endif; ?>
        <?php foreach ($templates as $tpl): ?>
          <?php
            $varInfo = [];
            if (is_string($tpl['variables_json'] ?? null) && $tpl['variables_json'] !== '') {
                $decoded = json_decode((string)$tpl['variables_json'], true);
                $varInfo = is_array($decoded['variables'] ?? null) ? $decoded['variables'] : [];
            }
          ?>
          <tr>
            <td><?php echo (int)$tpl['id']; ?></td>
            <td><?php echo mail_h((string)$tpl['title']); ?><br><span class="muted"><?php echo mail_h((string)$tpl['template_key']); ?></span></td>
            <td><?php echo mail_h((string)$tpl['subject_template']); ?></td>
            <td><span class="badge">HTML</span></td>
            <td><?php echo mail_h(implode(', ', array_map('strval', $varInfo))); ?></td>
            <td><span class="badge"><?php echo mail_h(mail_bool_label($tpl['is_active'])); ?></span></td>
            <td class="action-cell">
              <button type="button" class="text-link" data-nav-href="templates.php?edit=<?php echo (int)$tpl['id']; ?>">編集</button>
              <form method="post" class="inline-form">
                <?php echo mail_auth_csrf_field(); ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?php echo (int)$tpl['id']; ?>">
                <input type="hidden" name="is_active" value="<?php echo (int)$tpl['is_active'] === 1 ? 0 : 1; ?>">
                <button type="submit" class="text-button"<?php echo mail_auth_has_permission($user, 'template.edit') ? '' : ' disabled'; ?>><?php echo (int)$tpl['is_active'] === 1 ? '無効化' : '有効化'; ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
<?php
mail_render_page_footer();
