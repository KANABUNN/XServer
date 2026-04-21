const STATUS_DEFINITIONS = {
  all: { label: 'すべて', className: 'status-all' },
  new: { label: '未確認', className: 'status-new' },
  reviewing: { label: '確認中', className: 'status-reviewing' },
  on_hold: { label: '保留', className: 'status-on-hold' },
  resolved: { label: '対応済', className: 'status-resolved' },
  rejected: { label: '差戻し', className: 'status-rejected' },
};

const adminState = {
  forms: [],
  activeFormId: 0,
  activeWorkspaceTab: 'overview',
  formSearch: '',
  entriesResult: null,
  activeEntryId: 0,
  activeEntryHistory: null,
};

function getStatusMeta(status) {
  return STATUS_DEFINITIONS[status] || STATUS_DEFINITIONS.new;
}

function switchWorkspaceTab(tab) {
  adminState.activeWorkspaceTab = tab;
  document.querySelectorAll('[data-workspace-tab]').forEach((button) => {
    button.classList.toggle('active', button.dataset.workspaceTab === tab);
  });
  document.querySelectorAll('[data-workspace-panel]').forEach((panel) => {
    panel.classList.toggle('hidden', panel.dataset.workspacePanel !== tab);
  });
}

function setWorkspaceButtonsDisabled(isDisabled) {
  document.querySelectorAll('[data-workspace-tab]').forEach((button) => {
    button.disabled = isDisabled;
  });
  document.querySelectorAll('[data-switch-workspace]').forEach((button) => {
    button.disabled = isDisabled;
  });
}

function createStatusBadge(status, label = null) {
  const meta = getStatusMeta(status);
  return `<span class="status-badge ${escapeHtml(meta.className)}">${escapeHtml(label || meta.label)}</span>`;
}

function fieldRowDataToHtml(field = {}) {
  const template = document.getElementById('field-row-template');
  const fragment = template.content.cloneNode(true);
  const root = fragment.querySelector('[data-field-row]');
  root.querySelector('[data-field="field_label"]').value = field.field_label || '';
  root.querySelector('[data-field="field_key"]').value = field.field_key || '';
  root.querySelector('[data-field="field_type"]').value = field.field_type || 'text';
  root.querySelector('[data-field="placeholder"]').value = field.placeholder || '';
  root.querySelector('[data-field="help_text"]').value = field.help_text || '';
  root.querySelector('[data-field="options_text"]').value = (field.options || []).join('\n');
  root.querySelector('[data-field="default_value"]').value = field.default_value || '';
  root.querySelector('[data-field="is_required"]').checked = Boolean(field.is_required);
  root.querySelector('[data-field="is_enabled"]').checked = field.is_enabled !== false;
  return fragment;
}

function collectFields() {
  return Array.from(document.querySelectorAll('[data-field-row]')).map((row) => ({
    field_label: row.querySelector('[data-field="field_label"]').value,
    field_key: row.querySelector('[data-field="field_key"]').value,
    field_type: row.querySelector('[data-field="field_type"]').value,
    placeholder: row.querySelector('[data-field="placeholder"]').value,
    help_text: row.querySelector('[data-field="help_text"]').value,
    options_text: row.querySelector('[data-field="options_text"]').value,
    default_value: row.querySelector('[data-field="default_value"]').value,
    is_required: row.querySelector('[data-field="is_required"]').checked,
    is_enabled: row.querySelector('[data-field="is_enabled"]').checked,
  }));
}

function getActiveForm() {
  return adminState.forms.find((form) => form.id === adminState.activeFormId) || null;
}

function renderOverallStats() {
  const total = adminState.forms.length;
  const active = adminState.forms.filter((form) => Boolean(form.is_active)).length;
  const inactive = total - active;
  document.getElementById('overall-form-count').textContent = String(total);
  document.getElementById('overall-active-form-count').textContent = String(active);
  document.getElementById('overall-inactive-form-count').textContent = String(inactive);
}

function renderFormList() {
  const root = document.getElementById('form-list');
  if (!root) return;

  const keyword = adminState.formSearch.trim().toLowerCase();
  const filteredForms = adminState.forms.filter((form) => {
    if (!keyword) return true;
    return [form.name, form.slug, form.description]
      .filter(Boolean)
      .some((value) => String(value).toLowerCase().includes(keyword));
  });

  if (!filteredForms.length) {
    root.innerHTML = '<div class="empty-state">条件に一致するフォームはありません。</div>';
    return;
  }

  root.innerHTML = filteredForms.map((form) => {
    const isSelected = form.id === adminState.activeFormId;
    const fieldCount = Array.isArray(form.fields) ? form.fields.length : 0;
    return `
      <article class="form-nav-item ${isSelected ? 'selected' : ''}">
        <button type="button" class="form-nav-button" data-select-form="${form.id}">
          <div class="form-nav-main">
            <div>
              <strong>${escapeHtml(form.name)}</strong>
              <div class="meta-line">
                ${createStatusBadge(form.is_active ? 'resolved' : 'on_hold', form.is_active ? '公開中' : '非公開')}
                <span class="pill">slug: ${escapeHtml(form.slug)}</span>
              </div>
            </div>
            <span class="small-note">項目 ${fieldCount}件</span>
          </div>
          ${form.description ? `<div class="small-note">${escapeHtml(form.description)}</div>` : ''}
        </button>
      </article>
    `;
  }).join('');
}

function renderWorkspaceHeader(form) {
  const name = document.getElementById('workspace-form-name');
  const description = document.getElementById('workspace-form-description');
  const meta = document.getElementById('workspace-meta');

  if (!form) {
    name.textContent = '新しいフォームを作成します';
    description.textContent = '左の「新しいフォーム」から作成を始めるか、既存フォームを選択してください。';
    meta.innerHTML = '<span class="pill">未保存</span>';
    return;
  }

  name.textContent = form.name;
  description.textContent = form.description || 'フォームの説明は未設定です。';
  meta.innerHTML = `
    <span class="pill">ID: ${escapeHtml(String(form.id))}</span>
    <span class="pill">slug: ${escapeHtml(form.slug)}</span>
    ${createStatusBadge(form.is_active ? 'resolved' : 'on_hold', form.is_active ? '公開中' : '非公開')}
    <span class="pill">追加項目 ${escapeHtml(String((form.fields || []).length))}件</span>
  `;
}

function renderSelectedFormSidebar(form) {
  const pill = document.getElementById('selected-form-status-pill');
  const body = document.getElementById('selected-form-sidebar-body');

  if (!form) {
    pill.className = 'status-badge status-new';
    pill.textContent = '未選択';
    body.innerHTML = '<div class="empty-state">フォームを選択すると概要を表示します。</div>';
    return;
  }

  const summary = adminState.entriesResult?.summary || { total_count: 0, filtered_count: 0 };
  pill.className = `status-badge ${form.is_active ? 'status-resolved' : 'status-on-hold'}`;
  pill.textContent = form.is_active ? '公開中' : '非公開';

  body.innerHTML = `
    <article class="mini-info-card">
      <strong>${escapeHtml(form.name)}</strong>
      <div class="meta-line">
        <span class="pill">slug: ${escapeHtml(form.slug)}</span>
        <span class="pill">順序 ${escapeHtml(String(form.sort_order ?? 0))}</span>
      </div>
    </article>
    <article class="mini-info-card">
      <div class="mini-stat-grid compact">
        <div class="mini-stat-card"><span class="mini-stat-label">最新回答</span><strong>${escapeHtml(String(summary.total_count || 0))}</strong></div>
        <div class="mini-stat-card"><span class="mini-stat-label">絞込結果</span><strong>${escapeHtml(String(summary.filtered_count || 0))}</strong></div>
      </div>
    </article>
    <article class="mini-info-card">
      <div class="small-note">${form.description ? escapeHtml(form.description) : '説明は未設定です。'}</div>
    </article>
  `;
}

function renderOverview(form) {
  const settings = form?.settings || {};
  const summary = adminState.entriesResult?.summary || { total_count: 0, filtered_count: 0, status_counts: [] };
  const fieldCount = (form?.fields || []).length;
  const requiredFieldCount = (form?.fields || []).filter((field) => Boolean(field.is_required)).length;
  const newCount = (summary.status_counts || []).find((item) => item.status === 'new')?.count || 0;

  document.getElementById('overview-kpi-status').textContent = form ? (form.is_active ? '公開中' : '非公開') : '未選択';
  document.getElementById('overview-kpi-fields').textContent = String(fieldCount);
  document.getElementById('overview-kpi-entries').textContent = String(summary.total_count || 0);
  document.getElementById('overview-kpi-new').textContent = String(newCount);

  const infoRoot = document.getElementById('overview-form-info');
  if (!form) {
    infoRoot.innerHTML = '<div class="empty-state">フォームを選択すると基本情報を表示します。</div>';
  } else {
    const infoItems = [
      ['フォーム名', form.name],
      ['slug', form.slug],
      ['公開状態', form.is_active ? '公開中' : '非公開'],
      ['表示順', String(form.sort_order ?? 0)],
      ['追加項目数', `${fieldCount}件（必須 ${requiredFieldCount}件）`],
    ];
    infoRoot.innerHTML = infoItems.map(([label, value]) => `
      <div class="info-grid-item">
        <dt>${escapeHtml(label)}</dt>
        <dd>${escapeHtml(value)}</dd>
      </div>
    `).join('');
  }

  const settingPills = document.getElementById('overview-setting-pills');
  if (!form) {
    settingPills.innerHTML = '';
  } else {
    const pills = [
      settings.enable_date_field ? '日付入力あり' : '日付入力なし',
      settings.date_required ? '日付必須' : '日付任意',
      settings.allow_file_upload ? '添付あり' : '添付なし',
      settings.file_required ? '添付必須' : '添付任意',
      `送信ボタン: ${settings.submit_button_label || '送信する'}`,
    ];
    settingPills.innerHTML = pills.map((item) => `<span class="pill">${escapeHtml(item)}</span>`).join('');
  }

  const statusRoot = document.getElementById('overview-status-summary');
  if (!form) {
    statusRoot.innerHTML = '<div class="empty-state">回答状況はここに表示されます。</div>';
  } else {
    renderStatusSummary(statusRoot, summary.status_counts || []);
  }

  const fieldRoot = document.getElementById('overview-field-preview');
  if (!form || !fieldCount) {
    fieldRoot.innerHTML = '<div class="empty-state">追加項目はまだ設定されていません。</div>';
  } else {
    fieldRoot.innerHTML = form.fields.map((field) => `
      <article class="mini-info-card">
        <div class="list-item-header compact-row">
          <strong>${escapeHtml(field.field_label)}</strong>
          <span class="pill">${escapeHtml(field.field_type)}</span>
        </div>
        <div class="meta-line">
          ${field.is_required ? '<span class="pill">必須</span>' : '<span class="pill">任意</span>'}
          ${field.is_enabled ? '<span class="pill">有効</span>' : '<span class="pill">無効</span>'}
        </div>
        ${field.help_text ? `<div class="small-note">${escapeHtml(field.help_text)}</div>` : ''}
      </article>
    `).join('');
  }
}

function fillEditor(form) {
  const editor = document.getElementById('form-editor');
  if (!editor) return;

  editor.reset();
  editor.elements.id.value = form?.id || 0;
  editor.elements.name.value = form?.name || '';
  editor.elements.slug.value = form?.slug || '';
  editor.elements.description.value = form?.description || '';
  editor.elements.is_active.checked = form ? Boolean(form.is_active) : true;
  editor.elements.sort_order.value = form?.sort_order ?? 0;
  editor.elements.enable_date_field.checked = form?.settings?.enable_date_field ?? true;
  editor.elements.date_required.checked = form?.settings?.date_required ?? false;
  editor.elements.date_label.value = form?.settings?.date_label || '希望日';
  editor.elements.allow_file_upload.checked = form?.settings?.allow_file_upload ?? false;
  editor.elements.file_required.checked = form?.settings?.file_required ?? false;
  editor.elements.file_label.value = form?.settings?.file_label || '添付ファイル';
  editor.elements.allowed_extensions.value = form?.settings?.allowed_extensions || 'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,zip';
  editor.elements.max_upload_size_mb.value = form?.settings?.max_upload_size_mb ?? 5;
  editor.elements.submit_button_label.value = form?.settings?.submit_button_label || '送信する';
  editor.elements.completion_message.value = form?.settings?.completion_message || '送信を受け付けました。';

  const fieldRoot = document.getElementById('field-builder-list');
  fieldRoot.innerHTML = '';
  (form?.fields || []).forEach((field) => fieldRoot.appendChild(fieldRowDataToHtml(field)));
}

function renderStatusSummary(root, statusCounts = []) {
  if (!root) return;
  const normalized = statusCounts.length ? statusCounts : Object.entries(STATUS_DEFINITIONS)
    .filter(([status]) => status !== 'all')
    .map(([status, meta]) => ({ status, label: meta.label, count: 0, class: meta.className }));

  root.innerHTML = normalized.map((item) => `
    <article class="status-summary-card ${escapeHtml(item.class || getStatusMeta(item.status).className)}">
      <span class="small-note">${escapeHtml(item.label || getStatusMeta(item.status).label)}</span>
      <strong>${escapeHtml(String(item.count || 0))}</strong>
    </article>
  `).join('');
}

function renderEntryList(entries = []) {
  const root = document.getElementById('entry-list');
  if (!root) return;

  if (!entries.length) {
    root.innerHTML = '<div class="empty-state">条件に一致する回答はありません。</div>';
    renderEntryDetail(null);
    return;
  }

  root.innerHTML = entries.map((entry) => {
    const isSelected = entry.id === adminState.activeEntryId;
    return `
      <article class="response-card ${isSelected ? 'selected' : ''}" data-entry-card="${entry.id}">
        <button type="button" class="response-card-button" data-select-entry="${entry.id}">
          <div class="list-item-header">
            <div>
              <strong>${escapeHtml(entry.organization_name)}</strong>
              <div class="meta-line">
                ${createStatusBadge(entry.status, entry.status_label)}
                <span class="pill">${escapeHtml(entry.submitter_email)}</span>
                ${entry.submitted_date ? `<span class="pill">日付: ${escapeHtml(entry.submitted_date)}</span>` : ''}
              </div>
            </div>
            <span class="small-note">更新 ${escapeHtml(entry.updated_at)}</span>
          </div>
          <div class="response-preview-grid">
            ${(entry.payload_preview || []).slice(0, 4).map((item) => `
              <div class="preview-item"><strong>${escapeHtml(item.label)}:</strong> ${escapeHtml(item.value || '—')}</div>
            `).join('')}
          </div>
          <div class="meta-line response-footer-line">
            <span class="pill">履歴 ${escapeHtml(String(entry.revision_count))}件</span>
            ${entry.uploaded_original_name ? `<span class="pill">添付あり</span>` : ''}
            ${entry.admin_note ? '<span class="pill">管理メモあり</span>' : ''}
          </div>
        </button>
      </article>
    `;
  }).join('');
}

function renderEntrySummaryBlock(summary) {
  const statusRoot = document.getElementById('entry-status-summary');
  renderStatusSummary(statusRoot, summary?.status_counts || []);
  const message = document.getElementById('entry-summary');
  const total = summary?.total_count || 0;
  const filtered = summary?.filtered_count || 0;
  message.textContent = `最新回答 ${total}件 / 現在の絞り込み結果 ${filtered}件`;
}

function renderHistoryBlocks(history = { revisions: [], status_logs: [] }) {
  const revisions = history.revisions || [];
  const statusLogs = history.status_logs || [];

  const revisionHtml = revisions.length ? revisions.map((item) => `
    <article class="timeline-item">
      <div class="timeline-item-header">
        <strong>Revision ${escapeHtml(String(item.revision_number))}</strong>
        <span class="small-note">${escapeHtml(item.created_at)}</span>
      </div>
      <div class="meta-line">
        <span class="pill">${escapeHtml(item.organization_name)}</span>
        <span class="pill">${escapeHtml(item.submitter_email)}</span>
        ${item.submitted_date ? `<span class="pill">日付: ${escapeHtml(item.submitted_date)}</span>` : ''}
      </div>
      <div class="preview-list dense">
        ${(item.payload_preview || []).map((payload) => `<div class="preview-item"><strong>${escapeHtml(payload.label)}:</strong> ${escapeHtml(payload.value || '—')}</div>`).join('')}
      </div>
      ${item.uploaded_original_name ? `<div class="inline-actions"><a class="btn" href="api/download_revision.php?revision_id=${item.id}">添付を取得</a></div>` : ''}
    </article>
  `).join('') : '<div class="empty-state">更新履歴はまだありません。</div>';

  const statusHtml = statusLogs.length ? statusLogs.map((item) => `
    <article class="timeline-item">
      <div class="timeline-item-header">
        <div class="meta-line">
          ${item.previous_status_label ? `<span class="pill">${escapeHtml(item.previous_status_label)}</span>` : '<span class="pill">初回設定</span>'}
          <span class="small-note">→</span>
          ${createStatusBadge(item.next_status, item.next_status_label)}
        </div>
        <span class="small-note">${escapeHtml(item.created_at)}</span>
      </div>
      <div class="small-note">変更者: ${escapeHtml(item.changed_by_name || '不明')}</div>
      ${item.note ? `<div class="timeline-note">${escapeHtml(item.note)}</div>` : ''}
    </article>
  `).join('') : '<div class="empty-state">状態変更履歴はまだありません。</div>';

  return `
    <section class="subcard">
      <h4>更新履歴</h4>
      <div class="timeline-list">${revisionHtml}</div>
    </section>
    <section class="subcard">
      <h4>状態変更履歴</h4>
      <div class="timeline-list">${statusHtml}</div>
    </section>
  `;
}

function renderEntryDetail(entry, history = null) {
  const empty = document.getElementById('entry-detail-empty');
  const root = document.getElementById('entry-detail');
  if (!root || !empty) return;

  if (!entry) {
    empty.classList.remove('hidden');
    root.classList.add('hidden');
    root.innerHTML = '';
    return;
  }

  empty.classList.add('hidden');
  root.classList.remove('hidden');

  const statusOptions = Object.entries(STATUS_DEFINITIONS)
    .filter(([status]) => status !== 'all')
    .map(([status, meta]) => `<option value="${escapeHtml(status)}" ${status === entry.status ? 'selected' : ''}>${escapeHtml(meta.label)}</option>`)
    .join('');

  const payloadHtml = (entry.payload_preview || []).length ? (entry.payload_preview || []).map((item) => `
    <div class="detail-data-row">
      <dt>${escapeHtml(item.label)}</dt>
      <dd>${escapeHtml(item.value || '—')}</dd>
    </div>
  `).join('') : '<div class="empty-state">追加項目データはありません。</div>';

  const historyHtml = history
    ? renderHistoryBlocks(history)
    : '<div class="empty-state">履歴を読み込み中です...</div>';

  root.innerHTML = `
    <div class="detail-header">
      <div>
        <h3>${escapeHtml(entry.organization_name)}</h3>
        <div class="meta-line">
          ${createStatusBadge(entry.status, entry.status_label)}
          <span class="pill">${escapeHtml(entry.submitter_email)}</span>
          ${entry.submitted_date ? `<span class="pill">日付: ${escapeHtml(entry.submitted_date)}</span>` : ''}
        </div>
      </div>
      <div class="inline-actions">
        ${entry.uploaded_original_name && entry.latest_revision_id ? `<a class="btn" href="api/download_revision.php?revision_id=${entry.latest_revision_id}">最新添付を取得</a>` : ''}
      </div>
    </div>

    <section class="subcard">
      <h4>状態管理</h4>
      <div class="two-col">
        <label>
          <span>状態</span>
          <select id="entry-detail-status">${statusOptions}</select>
        </label>
        <label>
          <span>最終更新</span>
          <input type="text" value="${escapeHtml(entry.status_updated_at || '未更新')}" readonly>
        </label>
      </div>
      <label>
        <span>管理メモ</span>
        <textarea id="entry-detail-note" rows="4" placeholder="内部メモや対応内容を記録">${escapeHtml(entry.admin_note || '')}</textarea>
      </label>
      <div class="meta-line">
        <span class="pill">作成 ${escapeHtml(entry.created_at)}</span>
        <span class="pill">更新 ${escapeHtml(entry.updated_at)}</span>
        ${entry.status_updated_by_name ? `<span class="pill">更新者 ${escapeHtml(entry.status_updated_by_name)}</span>` : ''}
      </div>
      <div class="inline-actions">
        <button type="button" class="btn primary" id="save-entry-status-button" data-entry-id="${entry.id}">状態を保存</button>
        <button type="button" class="btn" id="reload-entry-history-button" data-entry-id="${entry.id}">履歴を再読込</button>
      </div>
    </section>

    <section class="subcard">
      <h4>回答内容</h4>
      <dl class="detail-data-grid">${payloadHtml}</dl>
    </section>

    <section class="detail-history-stack">
      ${historyHtml}
    </section>
  `;
}

function buildEntriesQuery(formId, filters) {
  const params = new URLSearchParams({ form_id: String(formId) });
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== '' && value !== null && typeof value !== 'undefined') {
      params.set(key, String(value));
    }
  });
  return params.toString();
}

function getEntryFilterValues() {
  const form = document.getElementById('entry-filter-form');
  if (!form) {
    return { query: '', status: 'all', date_from: '', date_to: '', limit: '100' };
  }
  return {
    query: form.elements.query.value.trim(),
    status: form.elements.status.value,
    date_from: form.elements.date_from.value,
    date_to: form.elements.date_to.value,
    limit: form.elements.limit.value,
  };
}

function setEntryFilterValues(filters = {}) {
  const form = document.getElementById('entry-filter-form');
  if (!form) return;
  form.elements.query.value = filters.query || '';
  form.elements.status.value = filters.status || 'all';
  form.elements.date_from.value = filters.date_from || '';
  form.elements.date_to.value = filters.date_to || '';
  form.elements.limit.value = String(filters.limit || 100);
}

function populateStatusFilter() {
  const select = document.getElementById('entry-status-filter');
  if (!select) return;
  select.innerHTML = Object.entries(STATUS_DEFINITIONS)
    .map(([status, meta]) => `<option value="${escapeHtml(status)}">${escapeHtml(meta.label)}</option>`)
    .join('');
  select.value = 'all';
}

async function loadForms(selectId = null) {
  const result = await apiGet('api/admin_forms.php');
  const message = document.getElementById('admin-message');
  if (!result.ok) {
    setMessage(message, result.message || 'フォーム一覧の取得に失敗しました。', 'error');
    return;
  }

  clearMessage(message);
  adminState.forms = result.forms || [];
  renderOverallStats();

  if (selectId !== null) {
    adminState.activeFormId = selectId;
  } else if (!adminState.forms.some((form) => form.id === adminState.activeFormId)) {
    adminState.activeFormId = adminState.forms[0]?.id || 0;
  }

  renderFormList();

  const current = getActiveForm();
  renderWorkspaceHeader(current);
  renderSelectedFormSidebar(current);
  renderOverview(current);
  fillEditor(current);

  if (current) {
    setWorkspaceButtonsDisabled(false);
    await loadEntries(current.id, null, true);
  } else {
    adminState.entriesResult = null;
    adminState.activeEntryId = 0;
    setWorkspaceButtonsDisabled(false);
    renderEntrySummaryBlock({ total_count: 0, filtered_count: 0, status_counts: [] });
    renderEntryList([]);
  }
}

async function loadEntries(formId, filters = null, preserveActiveEntry = true) {
  const activeFilters = filters || getEntryFilterValues();
  const queryString = buildEntriesQuery(formId, activeFilters);
  const result = await apiGet(`api/admin_entries.php?${queryString}`);
  if (!result.ok) {
    document.getElementById('entry-summary').textContent = result.message || '回答一覧の取得に失敗しました。';
    renderEntrySummaryBlock({ total_count: 0, filtered_count: 0, status_counts: [] });
    renderEntryList([]);
    return;
  }

  adminState.entriesResult = result;
  setEntryFilterValues(result.filters || activeFilters);
  renderEntrySummaryBlock(result.summary || {});
  renderEntryList(result.entries || []);
  renderSelectedFormSidebar(getActiveForm());
  renderOverview(getActiveForm());

  const entries = result.entries || [];
  if (!entries.length) {
    adminState.activeEntryId = 0;
    adminState.activeEntryHistory = null;
    renderEntryDetail(null);
    return;
  }

  const existingSelection = preserveActiveEntry ? adminState.activeEntryId : 0;
  const nextEntry = entries.find((entry) => entry.id === existingSelection) || entries[0];
  adminState.activeEntryId = nextEntry.id;
  renderEntryList(entries);
  await selectEntry(nextEntry.id, false);
}

async function selectEntry(entryId, scrollIntoView = true) {
  const entries = adminState.entriesResult?.entries || [];
  const entry = entries.find((item) => item.id === entryId) || null;
  adminState.activeEntryId = entryId;
  renderEntryList(entries);
  renderEntryDetail(entry, null);
  adminState.activeEntryHistory = null;

  if (!entry) {
    return;
  }

  if (scrollIntoView) {
    document.querySelector(`[data-entry-card="${entryId}"]`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  const result = await apiGet(`api/admin_entry_history.php?submission_id=${entryId}`);
  if (!result.ok) {
    renderEntryDetail(entry, { revisions: [], status_logs: [] });
    return;
  }

  adminState.activeEntryHistory = result.history || { revisions: [], status_logs: [] };
  renderEntryDetail(entry, adminState.activeEntryHistory);
}

async function saveCurrentEntryStatus(entryId) {
  const statusSelect = document.getElementById('entry-detail-status');
  const noteInput = document.getElementById('entry-detail-note');
  if (!statusSelect || !noteInput) return;

  const result = await apiPost('api/admin_entry_status.php', {
    submission_id: Number(entryId),
    status: statusSelect.value,
    admin_note: noteInput.value,
  });

  if (!result.ok) {
    alert(result.message || '状態更新に失敗しました。');
    return;
  }

  await loadEntries(adminState.activeFormId, getEntryFilterValues(), true);
  await selectEntry(Number(entryId), false);
}

function startNewFormMode() {
  adminState.activeFormId = 0;
  adminState.entriesResult = null;
  adminState.activeEntryId = 0;
  adminState.activeEntryHistory = null;
  renderFormList();
  renderWorkspaceHeader(null);
  renderSelectedFormSidebar(null);
  renderOverview(null);
  fillEditor(null);
  renderEntrySummaryBlock({ total_count: 0, filtered_count: 0, status_counts: [] });
  renderEntryList([]);
  switchWorkspaceTab('settings');
}

function buildCsvUrl() {
  if (!adminState.activeFormId) return '';
  const query = buildEntriesQuery(adminState.activeFormId, getEntryFilterValues());
  return `api/export_csv.php?${query}`;
}

function bindEvents() {
  document.querySelectorAll('[data-workspace-tab]').forEach((button) => {
    button.addEventListener('click', () => {
      switchWorkspaceTab(button.dataset.workspaceTab);
    });
  });

  document.querySelectorAll('[data-switch-workspace]').forEach((button) => {
    button.addEventListener('click', () => {
      switchWorkspaceTab(button.dataset.switchWorkspace);
    });
  });

  document.getElementById('form-search')?.addEventListener('input', (event) => {
    adminState.formSearch = event.target.value;
    renderFormList();
  });

  document.getElementById('new-form-button')?.addEventListener('click', startNewFormMode);

  document.getElementById('add-field-button')?.addEventListener('click', () => {
    document.getElementById('field-builder-list').appendChild(fieldRowDataToHtml());
  });

  document.getElementById('field-builder-list')?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-remove-field]');
    if (!button) return;
    button.closest('[data-field-row]')?.remove();
  });

  document.getElementById('form-list')?.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-select-form]');
    if (!button) return;
    adminState.activeFormId = Number(button.dataset.selectForm);
    adminState.activeEntryId = 0;
    renderFormList();
    const form = getActiveForm();
    renderWorkspaceHeader(form);
    renderSelectedFormSidebar(form);
    renderOverview(form);
    fillEditor(form);
    if (form) {
      switchWorkspaceTab('overview');
      await loadEntries(form.id, null, false);
    }
  });

  document.getElementById('refresh-entries-button')?.addEventListener('click', async () => {
    if (!adminState.activeFormId) return;
    await loadEntries(adminState.activeFormId, null, true);
  });

  document.getElementById('entry-filter-form')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!adminState.activeFormId) return;
    await loadEntries(adminState.activeFormId, getEntryFilterValues(), false);
  });

  document.getElementById('reset-filter-button')?.addEventListener('click', async () => {
    setEntryFilterValues({ query: '', status: 'all', date_from: '', date_to: '', limit: 100 });
    if (!adminState.activeFormId) return;
    await loadEntries(adminState.activeFormId, getEntryFilterValues(), false);
  });

  document.getElementById('export-csv-button')?.addEventListener('click', () => {
    const url = buildCsvUrl();
    if (!url) {
      alert('フォームを選択してください。');
      return;
    }
    window.location.href = url;
  });

  document.getElementById('entry-list')?.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-select-entry]');
    if (!button) return;
    await selectEntry(Number(button.dataset.selectEntry));
  });

  document.getElementById('entry-detail')?.addEventListener('click', async (event) => {
    const saveButton = event.target.closest('#save-entry-status-button');
    if (saveButton) {
      await saveCurrentEntryStatus(Number(saveButton.dataset.entryId));
      return;
    }
    const reloadButton = event.target.closest('#reload-entry-history-button');
    if (reloadButton) {
      await selectEntry(Number(reloadButton.dataset.entryId), false);
    }
  });

  document.getElementById('form-editor')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const message = document.getElementById('admin-message');
    const payload = {
      action: 'save',
      form: {
        id: Number(form.elements.id.value || 0),
        name: form.elements.name.value,
        slug: form.elements.slug.value,
        description: form.elements.description.value,
        is_active: form.elements.is_active.checked,
        sort_order: Number(form.elements.sort_order.value || 0),
        enable_date_field: form.elements.enable_date_field.checked,
        date_required: form.elements.date_required.checked,
        date_label: form.elements.date_label.value,
        allow_file_upload: form.elements.allow_file_upload.checked,
        file_required: form.elements.file_required.checked,
        file_label: form.elements.file_label.value,
        allowed_extensions: form.elements.allowed_extensions.value,
        max_upload_size_mb: Number(form.elements.max_upload_size_mb.value || 5),
        submit_button_label: form.elements.submit_button_label.value,
        completion_message: form.elements.completion_message.value,
      },
      fields: collectFields(),
    };

    setMessage(message, '保存中です...', 'info');
    const result = await apiPost('api/admin_forms.php', payload);
    if (!result.ok) {
      setMessage(message, result.message || '保存に失敗しました。', 'error');
      return;
    }

    setMessage(message, result.message || '保存しました。', 'success');
    adminState.forms = result.forms || [];
    adminState.activeFormId = result.form?.id || adminState.activeFormId;
    renderOverallStats();
    renderFormList();
    renderWorkspaceHeader(result.form || getActiveForm());
    renderSelectedFormSidebar(result.form || getActiveForm());
    renderOverview(result.form || getActiveForm());
    fillEditor(result.form || getActiveForm());
    if (adminState.activeFormId > 0) {
      await loadEntries(adminState.activeFormId, null, false);
    }
  });
}

function initAdminPage() {
  populateStatusFilter();
  bindEvents();
  switchWorkspaceTab('overview');
  loadForms();
}

initAdminPage();
