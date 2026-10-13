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
mail_render_page_header('メール作成', $user, 'compose.php', [
    'subtitle' => '本文を作成し、送信対象を選んで送信バッチにします（ここでは送信しません）',
    'actions' => '<a class="btn btn--ghost" href="templates.php">' . fitsc_icon('template') . '<span class="hide-mobile">テンプレート管理</span></a>',
]);
$variableButtons = ['{{識別番号}}', '{{団体名}}', '{{代表者氏名}}', '{{メールアドレス}}', '{{区分}}'];
?>
<?php mail_render_db_error($dbError); ?>

<?php if ($dbError === ''): ?>
<form method="post" class="ml-compose" id="composeForm" data-busy>
  <?php echo mail_auth_csrf_field(); ?>
  <input type="hidden" name="custom_body_type" value="html">

  <section class="card ml-compose__main">
    <div class="card__body stack">
      <div class="form-grid">
        <label class="field"><span>バッチ名 <span class="req">必須</span></span><input type="text" name="title" value="<?php echo mail_h($defaultTitle); ?>" required></label>
        <label class="field"><span>テンプレート</span>
          <select name="template_id" id="templateSelect">
            <option value="0">使わずに直接入力する</option>
            <?php foreach ($templates as $tpl): ?>
              <option value="<?php echo (int)$tpl['id']; ?>"><?php echo mail_h((string)$tpl['title']); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <?php if ($templates === []): ?>
        <div class="alert alert--warning"><?php echo fitsc_icon('alert-triangle'); ?><div class="alert__body">有効なテンプレートがありません。件名と本文を直接入力してください。</div></div>
      <?php endif; ?>

      <label class="field"><span>件名 <span class="req">必須</span></span><input type="text" name="custom_subject" id="composeSubject" data-variable-insert-target placeholder="例: 【学生自治会】{{団体名}}へのご連絡" required></label>

      <div class="field editor-field">
        <span>本文 <span class="req">必須</span></span>
        <textarea name="custom_body" id="composeBody" class="html-editor-source" data-html-editor-input required></textarea>
        <div class="html-editor-wrap">
          <div class="html-editor-toolbar" data-editor-toolbar="composeBodyEditor" role="toolbar" aria-label="書式">
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
          <div id="composeBodyEditor" class="html-editor" contenteditable="true" data-variable-insert-target data-html-editor="composeBody" data-placeholder="本文を入力してください。"></div>
        </div>
      </div>
    </div>
  </section>

  <aside class="ml-compose__side stack">
    <section class="card">
      <div class="card__header card__header--plain"><div class="card__title"><?php echo fitsc_icon('users'); ?>送信対象</div></div>
      <div class="card__body stack stack--sm">
        <div class="ml-recipient-count"><strong id="selectedRecipientCount">0</strong><span>団体を選択中</span></div>
        <button type="button" class="btn btn--block" data-modal-open="recipientModal"><?php echo fitsc_icon('users'); ?>送信対象を選ぶ</button>
      </div>
    </section>

    <section class="card">
      <div class="card__header card__header--plain"><div class="card__title"><?php echo fitsc_icon('tag'); ?>差し込み変数</div></div>
      <div class="card__body stack stack--sm">
        <div class="variable-list">
          <?php foreach ($variableButtons as $variable): ?>
            <button type="button" class="chip variable-chip" data-insert-variable="<?php echo mail_h($variable); ?>" data-insert-targets="composeSubject,composeBodyEditor"><?php echo mail_h($variable); ?></button>
          <?php endforeach; ?>
        </div>
        <p class="small-note">クリックすると、カーソルのある件名または本文に挿入されます。送信時に団体ごとの値に置き換わります。</p>
      </div>
    </section>

    <button type="submit" class="btn btn--primary btn--lg btn--block"<?php echo mail_auth_has_permission($user, 'batch.edit') ? '' : ' disabled'; ?>><?php echo fitsc_icon('layers'); ?>送信バッチを作成</button>
  </aside>

  <dialog class="modal modal--xl modal--full" id="recipientModal" aria-labelledby="recipientModalTitle">
    <div class="modal__panel">
      <div class="modal__header">
        <span class="modal__icon"><?php echo fitsc_icon('users', 'ico--lg'); ?></span>
        <div><h2 class="modal__title" id="recipientModalTitle">送信対象の団体を選ぶ</h2><p class="modal__desc">メールアドレスが未設定の団体は選択できません。</p></div>
        <button type="button" class="btn btn--ghost btn--icon modal__close" data-modal-close aria-label="閉じる"><?php echo fitsc_icon('x'); ?></button>
      </div>
      <div class="ml-recipient-toolbar">
        <div class="segmented" role="tablist" aria-label="表示切替">
          <button type="button" class="is-active" data-recipient-view="tree" aria-selected="true"><?php echo fitsc_icon('grid', 'ico--sm'); ?>区分ごと</button>
          <button type="button" data-recipient-view="list" aria-selected="false"><?php echo fitsc_icon('list', 'ico--sm'); ?>一覧</button>
        </div>
        <span class="input-icon ml-recipient-search"><?php echo fitsc_icon('search'); ?><input type="search" id="organizationFilter" placeholder="団体名・識別番号・メールで絞り込み" aria-label="団体を絞り込み"></span>
        <label class="check"><input type="checkbox" id="selectAllOrganizations">表示中をすべて選択</label>
      </div>
      <div class="modal__body">
        <div class="recipient-view" id="recipientTreeView">
          <?php if ($organizationsByCategory === []): ?>
            <div class="empty"><span class="empty__icon"><?php echo fitsc_icon('building'); ?></span><span>有効な団体がありません。</span></div>
          <?php endif; ?>
          <?php foreach ($organizationsByCategory as $category => $categoryOrgs): ?>
            <section class="recipient-category">
              <h3 class="section-title"><?php echo mail_h($category); ?> <span class="badge"><?php echo count($categoryOrgs); ?></span></h3>
              <div class="recipient-card-grid">
                <?php foreach ($categoryOrgs as $org): ?>
                  <?php $hasEmail = filter_var((string)($org['email'] ?? ''), FILTER_VALIDATE_EMAIL); ?>
                  <label class="check-card recipient-card<?php echo $hasEmail ? '' : ' is-disabled'; ?>" data-recipient-row data-filter-text="<?php echo mail_h(mb_strtolower((string)$org['identifier'] . ' ' . (string)$org['name'] . ' ' . (string)($org['representative_name'] ?? '') . ' ' . (string)($org['email'] ?? '') . ' ' . (string)($org['category'] ?? ''))); ?>">
                    <input type="checkbox" name="organization_ids[]" value="<?php echo (int)$org['id']; ?>" data-org-check<?php echo $hasEmail ? '' : ' disabled'; ?>>
                    <span>
                      <strong><?php echo mail_h((string)$org['name']); ?></strong>
                      <small><code><?php echo mail_h((string)$org['identifier']); ?></code> <?php echo $hasEmail ? mail_h((string)$org['email']) : '<span class="text-danger">メール未設定</span>'; ?></small>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endforeach; ?>
        </div>

        <div class="recipient-view is-hidden" id="recipientListView">
          <div class="table-wrap">
            <table class="table table--compact recipient-table" id="recipientTable">
              <thead><tr><th class="col-check"></th><th>識別番号</th><th>団体名</th><th>代表者</th><th>メールアドレス</th><th>区分</th></tr></thead>
              <tbody>
                <?php if ($organizations === []): ?><tr><td colspan="6" class="empty">有効な団体がありません。</td></tr><?php endif; ?>
                <?php foreach ($organizations as $org): ?>
                  <?php $hasEmail = filter_var((string)($org['email'] ?? ''), FILTER_VALIDATE_EMAIL); ?>
                  <tr data-recipient-row data-filter-text="<?php echo mail_h(mb_strtolower((string)$org['identifier'] . ' ' . (string)$org['name'] . ' ' . (string)($org['representative_name'] ?? '') . ' ' . (string)($org['email'] ?? '') . ' ' . (string)($org['category'] ?? ''))); ?>"<?php echo $hasEmail ? '' : ' class="is-attention"'; ?>>
                    <td><input type="checkbox" name="organization_ids[]" value="<?php echo (int)$org['id']; ?>" data-org-check<?php echo $hasEmail ? '' : ' disabled'; ?> aria-label="<?php echo mail_h((string)$org['name']); ?> を選択"></td>
                    <td><code><?php echo mail_h((string)$org['identifier']); ?></code></td>
                    <td><?php echo mail_h((string)$org['name']); ?></td>
                    <td><?php echo mail_h((string)($org['representative_name'] ?? '')); ?></td>
                    <td><?php echo $hasEmail ? mail_h((string)$org['email']) : '<span class="text-danger">未設定または形式不正</span>'; ?></td>
                    <td><?php echo mail_h((string)($org['category'] ?? '')); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="modal__footer modal__footer--split">
        <span class="text-muted text-sm"><strong id="selectedRecipientCountModal">0</strong> 団体を選択中</span>
        <button type="button" class="btn btn--primary" data-modal-close><?php echo fitsc_icon('check'); ?>選択を確定</button>
      </div>
    </div>
  </dialog>
</form>

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
    if (!tpl) return;
    subjectInput.value = tpl.subject_template || '';
    bodyInput.value = tpl.body_template || '';
    if (bodyEditor) {
      bodyEditor.innerHTML = tpl.body_template || '';
      bodyEditor.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });

  const viewButtons = Array.from(document.querySelectorAll('[data-recipient-view]'));
  const treeView = document.getElementById('recipientTreeView');
  const listView = document.getElementById('recipientListView');
  viewButtons.forEach(button => {
    button.addEventListener('click', function () {
      const view = button.dataset.recipientView;
      viewButtons.forEach(b => {
        b.classList.toggle('is-active', b === button);
        b.setAttribute('aria-selected', b === button ? 'true' : 'false');
      });
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
    countLabels.forEach(label => {
      if (label.textContent !== String(selected.size)) {
        label.textContent = String(selected.size);
        label.animate?.([{ transform: 'scale(1.25)' }, { transform: 'none' }], { duration: 260, easing: 'cubic-bezier(.34,1.4,.64,1)' });
      }
    });
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
