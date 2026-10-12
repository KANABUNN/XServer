<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

[$user, $mailPdo, $dbError] = mail_app_init();

$templates = [];
$organizations = [];
$organizationsByCategory = [];
if ($mailPdo instanceof PDO && $dbError === '') {
    $templates = mail_list_templates($mailPdo, true);
    $organizations = mail_list_active_organizations($mailPdo);
    foreach ($organizations as $org) {
        $category = trim((string)($org['category'] ?? ''));
        if ($category === '') {
            $category = '未分類';
        }
        $organizationsByCategory[$category][] = $org;
    }
    ksort($organizationsByCategory, SORT_NATURAL);
}

if ($mailPdo instanceof PDO && $dbError === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    mail_auth_require_csrf();
    try {
        mail_require_permission_or_forbid($user, 'batch.edit');
        $title = trim((string)($_POST['title'] ?? ''));
        $templateId = (int)($_POST['template_id'] ?? 0);
        $subject = trim((string)($_POST['custom_subject'] ?? ''));
        $body = mail_admin_body_to_editor_html((string)($_POST['custom_body'] ?? ''), 'html');
        $bodyType = 'html';
        $organizationIds = $_POST['organization_ids'] ?? [];
        if (!is_array($organizationIds)) {
            $organizationIds = [];
        }

        if ($templateId > 0) {
            $template = mail_get_template($mailPdo, $templateId);
            if (!$template || (int)($template['is_active'] ?? 0) !== 1) {
                throw new InvalidArgumentException('使用するテンプレートを選択し直してください。');
            }
        } else {
            $templateId = null;
        }

        $batchId = mail_create_batch_from_message(
            $mailPdo,
            $title,
            $templateId,
            $subject,
            $body,
            $bodyType,
            $organizationIds,
            $user
        );

        mail_flash_set('info', '送信バッチを作成しました。内容確認後、添付登録またはGmail下書き作成へ進んでください。');
        mail_redirect('batches.php?batch_id=' . $batchId);
    } catch (Throwable $e) {
        mail_flash_set('danger', mail_user_safe_error_message($e));
        mail_redirect('compose.php');
    }
}

$templateJson = [];
foreach ($templates as $tpl) {
    $templateJson[] = [
        'id' => (int)$tpl['id'],
        'title' => (string)$tpl['title'],
        'subject_template' => (string)$tpl['subject_template'],
        'body_template' => mail_admin_body_to_editor_html((string)$tpl['body_template'], (string)($tpl['body_type'] ?? 'html')),
        'body_type' => 'html',
    ];
}
$templateJsonText = json_encode($templateJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
$defaultTitle = 'メール作成 ' . date('Y-m-d H:i');
mail_render_page_header('メール作成', $user, 'compose.php');
?>
<header class="page-head">
  <div>
    <h1>メール作成</h1>
    <p class="lead">テンプレートを下敷きに編集するか、直接入力した件名・本文を、選択した団体へ差し込み展開します。ここではまだ送信せず、送信バッチを作成します。</p>
  </div>
  <div class="head-actions"><button type="button" class="link-button secondary" data-nav-href="templates.php">テンプレート管理</button></div>
</header>
<?php mail_render_db_error($dbError); ?>

<?php if ($dbError === ''): ?>
<section class="panel">
  <form method="post" class="stack-form compose-form" id="composeForm">
    <?php echo mail_auth_csrf_field(); ?>
    <div class="compose-layout">
      <div class="compose-main">
        <div class="panel-head"><h2>メール本文</h2><span class="muted">テンプレート選択 / 直接入力</span></div>
        <label><span>バッチ名 *</span><input type="text" name="title" value="<?php echo mail_h($defaultTitle); ?>" required></label>

        <label><span>使用テンプレート</span>
          <select name="template_id" id="templateSelect">
            <option value="0">直接入力する</option>
            <?php foreach ($templates as $tpl): ?>
              <option value="<?php echo (int)$tpl['id']; ?>"><?php echo mail_h((string)$tpl['title']); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php if ($templates === []): ?>
          <div class="alert alert-warn">有効なテンプレートがありません。件名・本文を直接入力してください。</div>
        <?php endif; ?>

        <label><span>件名 *</span><input type="text" name="custom_subject" id="composeSubject" data-variable-insert-target placeholder="例: 【学生自治会】{{団体名}}へのご連絡" required></label>
        <input type="hidden" name="custom_body_type" value="html">
        <div class="full editor-field">
          <span class="editor-field-label">本文 * <small class="muted">HTML形式で保存されます</small></span>
          <textarea name="custom_body" id="composeBody" class="html-editor-source" data-html-editor-input required></textarea>
          <div class="html-editor-wrap">
            <div class="html-editor-toolbar" data-editor-toolbar="composeBodyEditor">
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
            <div id="composeBodyEditor" class="html-editor" contenteditable="true" data-variable-insert-target data-html-editor="composeBody" data-placeholder="本文を入力してください。"></div>
          </div>
        </div>
      </div>

      <aside class="compose-side">
        <section class="side-card">
          <h3>利用できる変数</h3>
          <div class="variable-list vertical">
            <button type="button" class="variable-chip" data-insert-variable="{{識別番号}}" data-insert-targets="composeSubject,composeBodyEditor">{{識別番号}}</button>
            <button type="button" class="variable-chip" data-insert-variable="{{団体名}}" data-insert-targets="composeSubject,composeBodyEditor">{{団体名}}</button>
            <button type="button" class="variable-chip" data-insert-variable="{{代表者氏名}}" data-insert-targets="composeSubject,composeBodyEditor">{{代表者氏名}}</button>
            <button type="button" class="variable-chip" data-insert-variable="{{メールアドレス}}" data-insert-targets="composeSubject,composeBodyEditor">{{メールアドレス}}</button>
            <button type="button" class="variable-chip" data-insert-variable="{{区分}}" data-insert-targets="composeSubject,composeBodyEditor">{{区分}}</button>
          </div>
          <p class="muted">件名・本文内に記載すると、送信対象団体ごとの値に置換されます。</p>
        </section>

        <section class="side-card recipient-summary" id="recipientSummary">
          <h3>送信対象</h3>
          <strong><span id="selectedRecipientCount">0</span>件選択中</strong>
          <p class="muted">対象団体は別画面でまとめて選択します。</p>
          <button type="button" class="secondary" data-modal-open="recipientModal">送信対象を選択</button>
        </section>
      </aside>
    </div>

    <div class="form-actions">
      <button type="submit" class="primary"<?php echo mail_auth_has_permission($user, 'batch.edit') ? '' : ' disabled'; ?>>送信バッチを作成</button>
      <button type="button" class="link-button secondary" data-nav-href="batches.php">既存バッチを見る</button>
    </div>

    <div class="modal-backdrop" id="recipientModal" hidden>
      <div class="modal-card large-modal" role="dialog" aria-modal="true" aria-labelledby="recipientModalTitle">
        <div class="modal-head">
          <div>
            <h2 id="recipientModalTitle">送信対象団体の選択</h2>
            <p class="muted">階層表示では区分ごとに、一覧表示では検索しながら選択できます。</p>
          </div>
          <button type="button" class="text-button modal-close" data-modal-close="recipientModal">閉じる</button>
        </div>
        <div class="modal-toolbar">
          <div class="segmented-control" role="tablist" aria-label="表示切替">
            <button type="button" class="is-active" data-recipient-view="tree">階層表示</button>
            <button type="button" data-recipient-view="list">一覧表示</button>
          </div>
          <label class="inline-check"><input type="checkbox" id="selectAllOrganizations"> 表示中を全選択 / 解除</label>
          <input type="search" id="organizationFilter" placeholder="団体名・識別番号・メールアドレスで絞り込み">
        </div>

        <div class="recipient-view" id="recipientTreeView">
          <?php if ($organizationsByCategory === []): ?>
            <p class="empty">有効な団体がありません。</p>
          <?php endif; ?>
          <?php foreach ($organizationsByCategory as $category => $categoryOrgs): ?>
            <section class="recipient-category">
              <h3><?php echo mail_h($category); ?> <span class="muted">/<?php echo count($categoryOrgs); ?>件</span></h3>
              <div class="recipient-card-grid">
                <?php foreach ($categoryOrgs as $org): ?>
                  <?php $hasEmail = filter_var((string)($org['email'] ?? ''), FILTER_VALIDATE_EMAIL); ?>
                  <label class="recipient-card <?php echo $hasEmail ? '' : 'is-disabled'; ?>" data-recipient-row data-filter-text="<?php echo mail_h(mb_strtolower((string)$org['identifier'] . ' ' . (string)$org['name'] . ' ' . (string)($org['representative_name'] ?? '') . ' ' . (string)($org['email'] ?? '') . ' ' . (string)($org['category'] ?? ''))); ?>">
                    <input type="checkbox" name="organization_ids[]" value="<?php echo (int)$org['id']; ?>" data-org-check<?php echo $hasEmail ? '' : ' disabled'; ?>>
                    <span><strong><?php echo mail_h((string)$org['name']); ?></strong><code><?php echo mail_h((string)$org['identifier']); ?></code></span>
                    <small><?php echo $hasEmail ? mail_h((string)$org['email']) : 'メール未設定または形式不正'; ?></small>
                  </label>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endforeach; ?>
        </div>

        <div class="recipient-view is-hidden" id="recipientListView">
          <div class="table-wrap recipient-table-wrap">
            <table class="recipient-table" id="recipientTable">
              <thead><tr><th>選択</th><th>識別番号</th><th>団体名</th><th>代表者</th><th>メールアドレス</th><th>区分</th></tr></thead>
              <tbody>
                <?php if ($organizations === []): ?><tr><td colspan="6" class="empty">有効な団体がありません。</td></tr><?php endif; ?>
                <?php foreach ($organizations as $org): ?>
                  <?php $hasEmail = filter_var((string)($org['email'] ?? ''), FILTER_VALIDATE_EMAIL); ?>
                  <tr data-recipient-row data-filter-text="<?php echo mail_h(mb_strtolower((string)$org['identifier'] . ' ' . (string)$org['name'] . ' ' . (string)($org['representative_name'] ?? '') . ' ' . (string)($org['email'] ?? '') . ' ' . (string)($org['category'] ?? ''))); ?>"<?php echo $hasEmail ? '' : ' class="needs-review-row"'; ?>>
                    <td><input type="checkbox" name="organization_ids[]" value="<?php echo (int)$org['id']; ?>" data-org-check<?php echo $hasEmail ? '' : ' disabled'; ?>></td>
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
        </div>

        <div class="modal-actions">
          <span class="muted"><span id="selectedRecipientCountModal">0</span>件選択中</span>
          <button type="button" class="primary" data-modal-close="recipientModal">選択を反映</button>
        </div>
      </div>
    </div>
  </form>
</section>

<script type="application/json" id="composeTemplateData"><?php echo $templateJsonText; ?></script>
<script>
(function () {
  const templateSelect = document.getElementById('templateSelect');
  const subjectInput = document.getElementById('composeSubject');
  const bodyInput = document.getElementById('composeBody');
  const bodyEditor = document.getElementById('composeBodyEditor');
  const templateData = JSON.parse(document.getElementById('composeTemplateData')?.textContent || '[]');
  const templates = new Map(templateData.map(t => [String(t.id), t]));

  templateSelect?.addEventListener('change', function () {
    const tpl = templates.get(String(templateSelect.value));
    if (!tpl) {
      return;
    }
    subjectInput.value = tpl.subject_template || '';
    bodyInput.value = tpl.body_template || '';
    if (bodyEditor) {
      bodyEditor.innerHTML = tpl.body_template || '';
      bodyEditor.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });

  const modalButtons = document.querySelectorAll('[data-modal-open], [data-modal-close]');
  modalButtons.forEach(button => {
    button.addEventListener('click', function () {
      const id = button.dataset.modalOpen || button.dataset.modalClose;
      const modal = document.getElementById(id);
      if (!modal) return;
      modal.hidden = Boolean(button.dataset.modalClose);
    });
  });
  document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', function (event) {
      if (event.target === backdrop) backdrop.hidden = true;
    });
  });

  const viewButtons = Array.from(document.querySelectorAll('[data-recipient-view]'));
  const treeView = document.getElementById('recipientTreeView');
  const listView = document.getElementById('recipientListView');
  viewButtons.forEach(button => {
    button.addEventListener('click', function () {
      const view = button.dataset.recipientView;
      viewButtons.forEach(b => b.classList.toggle('is-active', b === button));
      treeView.classList.toggle('is-hidden', view !== 'tree');
      listView.classList.toggle('is-hidden', view !== 'list');
    });
  });

  const checks = Array.from(document.querySelectorAll('[data-org-check]'));
  const countLabels = [document.getElementById('selectedRecipientCount'), document.getElementById('selectedRecipientCountModal')].filter(Boolean);
  function syncChecks(value, checked) {
    checks.forEach(check => {
      if (check.value === value && !check.disabled) check.checked = checked;
    });
  }
  function updateCount() {
    const selected = new Set(checks.filter(c => c.checked && !c.disabled).map(c => c.value));
    countLabels.forEach(label => { label.textContent = String(selected.size); });
  }
  checks.forEach(check => {
    check.addEventListener('change', function () {
      syncChecks(check.value, check.checked);
      updateCount();
    });
  });

  const filter = document.getElementById('organizationFilter');
  const rows = Array.from(document.querySelectorAll('[data-recipient-row]'));
  filter?.addEventListener('input', function () {
    const q = filter.value.trim().toLowerCase();
    rows.forEach(row => {
      row.style.display = !q || row.dataset.filterText.includes(q) ? '' : 'none';
    });
  });

  const selectAll = document.getElementById('selectAllOrganizations');
  selectAll?.addEventListener('change', function () {
    rows.forEach(row => {
      if (row.style.display === 'none') return;
      row.querySelectorAll('[data-org-check]').forEach(check => {
        if (!check.disabled) syncChecks(check.value, selectAll.checked);
      });
    });
    updateCount();
  });

  updateCount();
})();
</script>
<?php endif; ?>
<?php
mail_render_page_footer();
