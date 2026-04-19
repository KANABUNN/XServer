const adminState = {
  forms: [],
  activeFormId: 0,
};

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

function renderFormList() {
  const root = document.getElementById('form-list');
  if (!root) return;
  if (!adminState.forms.length) {
    root.innerHTML = '<div class="empty-state">まだフォームは登録されていません。</div>';
    return;
  }
  root.innerHTML = adminState.forms.map((form) => `
    <article class="list-item ${form.id === adminState.activeFormId ? 'selected' : ''}">
      <div class="list-item-header">
        <div>
          <strong>${escapeHtml(form.name)}</strong>
          <div class="meta-line">
            <span class="pill">${form.is_active ? '公開中' : '非公開'}</span>
            <span class="pill">slug: ${escapeHtml(form.slug)}</span>
          </div>
        </div>
        <button type="button" class="btn" data-select-form="${form.id}">編集</button>
      </div>
      ${form.description ? `<div class="small-note">${escapeHtml(form.description)}</div>` : ''}
    </article>
  `).join('');
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

function renderEntryList(entries = []) {
  const root = document.getElementById('entry-list');
  if (!root) return;
  if (!entries.length) {
    root.innerHTML = '<div class="empty-state">このフォームにはまだ送信データがありません。</div>';
    return;
  }
  root.innerHTML = entries.map((entry) => `
    <article class="list-item">
      <div class="list-item-header">
        <div>
          <strong>${escapeHtml(entry.organization_name)}</strong>
          <div class="meta-line">
            <span class="pill">${escapeHtml(entry.submitter_email)}</span>
            ${entry.submitted_date ? `<span class="pill">日付: ${escapeHtml(entry.submitted_date)}</span>` : ''}
            <span class="pill">更新: ${escapeHtml(entry.updated_at)}</span>
            <span class="pill">履歴: ${escapeHtml(String(entry.revision_count))}件</span>
          </div>
        </div>
        <div class="inline-actions">
          <button type="button" class="btn" data-history-button="${entry.id}">履歴を見る</button>
        </div>
      </div>
      <div class="preview-list">
        ${(entry.payload_preview || []).map((item) => `<div class="preview-item"><strong>${escapeHtml(item.label)}:</strong> ${escapeHtml(item.value || '—')}</div>`).join('')}
        ${entry.uploaded_original_name ? `<div class="preview-item"><strong>添付:</strong> ${escapeHtml(entry.uploaded_original_name)}</div>` : ''}
      </div>
    </article>
  `).join('');
}

function renderHistory(history = []) {
  const root = document.getElementById('entry-history');
  if (!root) return;
  if (!history.length) {
    root.classList.add('hidden');
    root.innerHTML = '';
    return;
  }
  root.classList.remove('hidden');
  root.innerHTML = `
    <div class="section-title-row">
      <h3>更新履歴</h3>
      <button type="button" class="btn" id="close-history-button">閉じる</button>
    </div>
    ${history.map((item) => `
      <article class="history-item">
        <div class="list-item-header">
          <div>
            <strong>Revision ${escapeHtml(String(item.revision_number))}</strong>
            <div class="meta-line">
              <span class="pill">${escapeHtml(item.organization_name)}</span>
              <span class="pill">${escapeHtml(item.submitter_email)}</span>
              <span class="pill">${escapeHtml(item.created_at)}</span>
              ${item.submitted_date ? `<span class="pill">日付: ${escapeHtml(item.submitted_date)}</span>` : ''}
            </div>
          </div>
          ${item.uploaded_original_name ? `<a class="btn" href="api/download_revision.php?revision_id=${item.id}">添付を取得</a>` : ''}
        </div>
        <div class="preview-list">
          ${(item.payload_preview || []).map((entry) => `<div class="preview-item"><strong>${escapeHtml(entry.label)}:</strong> ${escapeHtml(entry.value || '—')}</div>`).join('')}
          ${item.uploaded_original_name ? `<div class="preview-item"><strong>添付:</strong> ${escapeHtml(item.uploaded_original_name)}</div>` : ''}
        </div>
      </article>
    `).join('')}
  `;
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
  if (selectId) {
    adminState.activeFormId = selectId;
  } else if (!adminState.forms.some((form) => form.id === adminState.activeFormId)) {
    adminState.activeFormId = adminState.forms[0]?.id || 0;
  }
  renderFormList();
  const current = adminState.forms.find((form) => form.id === adminState.activeFormId) || null;
  fillEditor(current);
  if (current) {
    await loadEntries(current.id);
  } else {
    document.getElementById('entry-list').innerHTML = '<div class="empty-state">フォームを作成すると送信一覧が表示されます。</div>';
    renderHistory([]);
  }
}

async function loadEntries(formId) {
  const summary = document.getElementById('entry-summary');
  summary.textContent = '送信履歴を読み込み中です...';
  const result = await apiGet(`api/admin_entries.php?form_id=${formId}`);
  if (!result.ok) {
    summary.textContent = result.message || '送信履歴の取得に失敗しました。';
    renderEntryList([]);
    renderHistory([]);
    return;
  }
  summary.textContent = `${result.form.name} の最新送信データです。`; 
  renderEntryList(result.entries || []);
  renderHistory([]);
}

async function loadHistory(submissionId) {
  const result = await apiGet(`api/admin_entry_history.php?submission_id=${submissionId}`);
  if (!result.ok) {
    alert(result.message || '履歴の取得に失敗しました。');
    return;
  }
  renderHistory(result.history || []);
}

document.getElementById('new-form-button')?.addEventListener('click', () => {
  adminState.activeFormId = 0;
  renderFormList();
  fillEditor(null);
  renderHistory([]);
  document.getElementById('entry-list').innerHTML = '<div class="empty-state">保存後に送信データを表示します。</div>';
  document.getElementById('entry-summary').textContent = '新しいフォームを作成中です。';
});

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
  renderFormList();
  const form = adminState.forms.find((item) => item.id === adminState.activeFormId) || null;
  fillEditor(form);
  if (form) {
    await loadEntries(form.id);
  }
});

document.getElementById('refresh-entries-button')?.addEventListener('click', async () => {
  if (adminState.activeFormId > 0) {
    await loadEntries(adminState.activeFormId);
  }
});

document.getElementById('entry-list')?.addEventListener('click', async (event) => {
  const button = event.target.closest('[data-history-button]');
  if (!button) return;
  await loadHistory(Number(button.dataset.historyButton));
});

document.getElementById('entry-history')?.addEventListener('click', (event) => {
  if (event.target.id === 'close-history-button') {
    renderHistory([]);
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
  renderFormList();
  fillEditor(result.form || null);
  if (adminState.activeFormId > 0) {
    await loadEntries(adminState.activeFormId);
  }
});

loadForms();
