<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

[$user, $mailPdo, $dbError] = mail_app_init();

$templates = [];
$organizations = [];
if ($mailPdo instanceof PDO && $dbError === '') {
    $templates = mail_list_templates($mailPdo, true);
    $organizations = mail_list_active_organizations($mailPdo);
}

if ($mailPdo instanceof PDO && $dbError === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    mail_auth_require_csrf();
    try {
        mail_require_permission_or_forbid($user, 'batch.edit');
        $source = (string)($_POST['source'] ?? 'template');
        $title = trim((string)($_POST['title'] ?? ''));
        $organizationIds = $_POST['organization_ids'] ?? [];
        if (!is_array($organizationIds)) {
            $organizationIds = [];
        }

        if ($source === 'custom') {
            $subject = trim((string)($_POST['custom_subject'] ?? ''));
            $body = (string)($_POST['custom_body'] ?? '');
            $bodyType = (string)($_POST['custom_body_type'] ?? 'plain');
            $batchId = mail_create_batch_from_message($mailPdo, $title, null, $subject, $body, $bodyType, $organizationIds, $user);
        } else {
            $templateId = (int)($_POST['template_id'] ?? 0);
            $template = mail_get_template($mailPdo, $templateId);
            if (!$template || (int)($template['is_active'] ?? 0) !== 1) {
                throw new InvalidArgumentException('使用するテンプレートを選択してください。');
            }
            $batchId = mail_create_batch_from_message(
                $mailPdo,
                $title,
                $templateId,
                (string)$template['subject_template'],
                (string)$template['body_template'],
                (string)$template['body_type'],
                $organizationIds,
                $user
            );
        }

        mail_flash_set('info', '送信バッチを作成しました。内容確認後、添付登録または送信へ進んでください。');
        mail_redirect('batches.php?batch_id=' . $batchId);
    } catch (Throwable $e) {
        mail_flash_set('danger', $e->getMessage());
        mail_redirect('compose.php');
    }
}

$defaultTitle = 'メール作成 ' . date('Y-m-d H:i');
mail_render_page_header('メール作成', $user, 'compose.php');
?>
<header class="page-head">
  <div>
    <h1>メール作成</h1>
    <p class="lead">テンプレートまたは直接入力した件名・本文を、選択した団体へ差し込み展開します。ここではまだ送信せず、送信バッチを作成します。</p>
  </div>
  <div class="head-actions"><a class="link-button secondary" href="templates.php">テンプレート管理</a></div>
</header>
<?php mail_render_db_error($dbError); ?>

<?php if ($dbError === ''): ?>
<section class="panel">
  <div class="panel-head"><h2>作成方法</h2><span class="muted">テンプレート / 直接入力</span></div>
  <form method="post" class="stack-form compose-form" id="composeForm">
    <?php echo mail_auth_csrf_field(); ?>
    <label><span>バッチ名 *</span><input type="text" name="title" value="<?php echo mail_h($defaultTitle); ?>" required></label>

    <div class="choice-grid">
      <label class="choice-card">
        <input type="radio" name="source" value="template" checked data-compose-source>
        <strong>テンプレートを使う</strong>
        <span>登録済みテンプレートの件名・本文を使います。</span>
      </label>
      <label class="choice-card">
        <input type="radio" name="source" value="custom" data-compose-source>
        <strong>直接入力する</strong>
        <span>この送信だけで使う件名・本文を作成します。</span>
      </label>
    </div>

    <div class="compose-source-block" id="templateSourceBlock">
      <label><span>使用テンプレート *</span>
        <select name="template_id">
          <?php foreach ($templates as $tpl): ?>
            <option value="<?php echo (int)$tpl['id']; ?>"><?php echo mail_h((string)$tpl['title']); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($templates === []): ?>
        <div class="alert alert-warn">有効なテンプレートがありません。先にテンプレートを作成するか、直接入力を選択してください。</div>
      <?php endif; ?>
    </div>

    <div class="compose-source-block is-hidden" id="customSourceBlock">
      <label><span>件名 *</span><input type="text" name="custom_subject" placeholder="例: 【学生自治会】{{団体名}}へのご連絡"></label>
      <label><span>本文 *</span><textarea name="custom_body" rows="12" placeholder="{{団体名}}\n{{代表者氏名}} 様\n\n本文を入力してください。"></textarea></label>
      <label><span>本文形式</span>
        <select name="custom_body_type">
          <option value="plain">プレーンテキスト</option>
          <option value="html">HTML</option>
        </select>
      </label>
      <div class="help-box">
        <strong>利用できる主な変数</strong>
        <div class="variable-list"><code>{{識別番号}}</code><code>{{団体名}}</code><code>{{代表者氏名}}</code><code>{{メールアドレス}}</code><code>{{区分}}</code></div>
      </div>
    </div>

    <section class="panel-subform">
      <div class="panel-head inner"><h2>送信対象</h2><span class="muted"><?php echo count($organizations); ?>件</span></div>
      <div class="toolbar-row">
        <label class="inline-check"><input type="checkbox" id="selectAllOrganizations"> 全選択 / 解除</label>
        <input type="search" id="organizationFilter" placeholder="団体名・識別番号・メールアドレスで絞り込み">
      </div>
      <div class="table-wrap recipient-table-wrap">
        <table class="recipient-table" id="recipientTable">
          <thead><tr><th>選択</th><th>識別番号</th><th>団体名</th><th>代表者</th><th>メールアドレス</th><th>区分</th></tr></thead>
          <tbody>
            <?php if ($organizations === []): ?><tr><td colspan="6" class="empty">有効な団体がありません。</td></tr><?php endif; ?>
            <?php foreach ($organizations as $org): ?>
              <?php $hasEmail = filter_var((string)($org['email'] ?? ''), FILTER_VALIDATE_EMAIL); ?>
              <tr data-filter-text="<?php echo mail_h(mb_strtolower((string)$org['identifier'] . ' ' . (string)$org['name'] . ' ' . (string)($org['representative_name'] ?? '') . ' ' . (string)($org['email'] ?? '') . ' ' . (string)($org['category'] ?? ''))); ?>"<?php echo $hasEmail ? '' : ' class="needs-review-row"'; ?>>
                <td><input type="checkbox" name="organization_ids[]" value="<?php echo (int)$org['id']; ?>" class="organization-check"<?php echo $hasEmail ? '' : ' disabled'; ?>></td>
                <td><code><?php echo mail_h((string)$org['identifier']); ?></code></td>
                <td><?php echo mail_h((string)$org['name']); ?></td>
                <td><?php echo mail_h((string)($org['representative_name'] ?? '')); ?></td>
                <td><?php echo $hasEmail ? mail_h((string)$org['email']) : '<span class="danger-text">未設定または形式不正</span>'; ?></td>
                <td><?php echo mail_h((string)($org['category'] ?? '')); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel-subform">
      <h3>Gmail下書きについて</h3>
      <p class="muted">現在のSMTP Relay経路では、Gmailの下書きフォルダへ保存することはできません。下書き保存を実装する場合は、Gmail APIの下書き作成権限を使う別経路を追加します。現時点では、ここで送信バッチを作成し、確認後にSMTP送信画面から直接送信します。</p>
    </section>

    <div class="form-actions">
      <button type="submit" class="primary"<?php echo mail_auth_has_permission($user, 'batch.edit') ? '' : ' disabled'; ?>>送信バッチを作成</button>
      <a class="link-button secondary" href="batches.php">既存バッチを見る</a>
    </div>
  </form>
</section>

<script>
(function () {
  const sourceRadios = Array.from(document.querySelectorAll('[data-compose-source]'));
  const templateBlock = document.getElementById('templateSourceBlock');
  const customBlock = document.getElementById('customSourceBlock');
  function syncSource() {
    const selected = sourceRadios.find(r => r.checked)?.value || 'template';
    templateBlock.classList.toggle('is-hidden', selected !== 'template');
    customBlock.classList.toggle('is-hidden', selected !== 'custom');
  }
  sourceRadios.forEach(r => r.addEventListener('change', syncSource));
  syncSource();

  const selectAll = document.getElementById('selectAllOrganizations');
  const checks = Array.from(document.querySelectorAll('.organization-check'));
  selectAll?.addEventListener('change', function () {
    checks.forEach(check => {
      const row = check.closest('tr');
      if (!check.disabled && row && row.style.display !== 'none') {
        check.checked = selectAll.checked;
      }
    });
  });

  const filter = document.getElementById('organizationFilter');
  const rows = Array.from(document.querySelectorAll('#recipientTable tbody tr[data-filter-text]'));
  filter?.addEventListener('input', function () {
    const q = filter.value.trim().toLowerCase();
    rows.forEach(row => {
      row.style.display = !q || row.dataset.filterText.includes(q) ? '' : 'none';
    });
  });
})();
</script>
<?php endif; ?>
<?php
mail_render_page_footer();
