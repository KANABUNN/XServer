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
        mail_flash_set('danger', $e->getMessage());
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
    'body_template' => "{{団体名}}\n{{代表者氏名}} 様\n\n平素よりお世話になっております。\n学生自治会です。\n\n資料を添付いたしましたので、ご確認ください。\n\n識別番号：{{識別番号}}\n\nよろしくお願いいたします。",
    'body_type' => 'plain',
    'variables_json' => '',
    'is_active' => 1,
];

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
    <div class="panel-head"><h2><?php echo $editTemplate ? 'テンプレートを編集' : 'テンプレートを追加'; ?></h2><span class="muted">下書き生成前に本文を固定します</span></div>
    <form method="post" class="form-grid">
      <?php echo mail_auth_csrf_field(); ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">
      <label><span>テンプレートキー</span><input type="text" name="template_key" value="<?php echo mail_h((string)$form['template_key']); ?>" placeholder="空欄なら自動生成"></label>
      <label><span>表示名 *</span><input type="text" name="title" value="<?php echo mail_h((string)$form['title']); ?>" required></label>
      <label class="full"><span>件名 *</span><input type="text" name="subject_template" value="<?php echo mail_h((string)$form['subject_template']); ?>" required></label>
      <label><span>本文形式</span><select name="body_type"><option value="plain"<?php echo mail_selected($form['body_type'], 'plain'); ?>>プレーンテキスト</option><option value="html"<?php echo mail_selected($form['body_type'], 'html'); ?>>HTML</option></select></label>
      <label><span>状態</span><select name="is_active"><option value="1"<?php echo mail_selected($form['is_active'], 1); ?>>有効</option><option value="0"<?php echo mail_selected($form['is_active'], 0); ?>>無効</option></select></label>
      <label class="full"><span>本文 *</span><textarea name="body_template" rows="16" required><?php echo mail_h((string)$form['body_template']); ?></textarea></label>
      <div class="form-actions full">
        <button type="submit" class="primary"<?php echo mail_auth_has_permission($user, 'template.edit') ? '' : ' disabled'; ?>>保存</button>
        <?php if ($editTemplate): ?><a href="templates.php" class="secondary link-button">新規入力へ戻る</a><?php endif; ?>
      </div>
    </form>
  </section>

  <section class="panel">
    <h2>使用可能な基本変数</h2>
    <div class="variable-list">
      <code>{{識別番号}}</code>
      <code>{{団体名}}</code>
      <code>{{代表者氏名}}</code>
      <code>{{メールアドレス}}</code>
      <code>{{区分}}</code>
      <code>{{identifier}}</code>
      <code>{{organization_name}}</code>
      <code>{{representative_name}}</code>
      <code>{{email}}</code>
    </div>
    <p class="muted mt-14">未定義の変数が残った場合、その宛先は <strong>要確認</strong> としてバッチに登録されます。</p>
  </section>
</div>

<section class="panel mt-18">
  <div class="panel-head"><h2>テンプレート一覧</h2><span class="muted"><?php echo count($templates); ?>件</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>表示名</th><th>件名</th><th>形式</th><th>変数</th><th>状態</th><th>操作</th></tr></thead>
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
            <td><?php echo mail_h((string)$tpl['body_type']); ?></td>
            <td><?php echo mail_h(implode(', ', array_map('strval', $varInfo))); ?></td>
            <td><span class="badge"><?php echo mail_h(mail_bool_label($tpl['is_active'])); ?></span></td>
            <td class="action-cell">
              <a href="templates.php?edit=<?php echo (int)$tpl['id']; ?>" class="text-link">編集</a>
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
