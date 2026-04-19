const publicState = {
  forms: [],
  activeFormId: null,
};

function renderTabs() {
  const root = document.getElementById('public-tab-list');
  if (!root) return;
  if (!publicState.forms.length) {
    root.innerHTML = '';
    return;
  }
  root.innerHTML = publicState.forms.map((form) => `
    <button type="button" class="tab-button ${form.id === publicState.activeFormId ? 'active' : ''}" data-form-tab="${form.id}">
      ${escapeHtml(form.name)}
    </button>
  `).join('');
}

function renderCustomField(field) {
  const name = `custom[${field.field_key}]`;
  const required = field.is_required ? 'required' : '';
  const help = field.help_text ? `<div class="small-note">${escapeHtml(field.help_text)}</div>` : '';
  const placeholder = escapeHtml(field.placeholder || '');
  const value = escapeHtml(field.default_value || '');

  if (field.field_type === 'textarea') {
    return `
      <label class="form-block">
        <span>${escapeHtml(field.field_label)}${field.is_required ? ' *' : ''}</span>
        <textarea name="${escapeHtml(name)}" rows="4" placeholder="${placeholder}" ${required}>${value}</textarea>
        ${help}
      </label>
    `;
  }
  if (field.field_type === 'select') {
    const defaultValue = field.default_value || '';
    const options = (field.options || []).map((option) => `<option value="${escapeHtml(option)}" ${option === defaultValue ? 'selected' : ''}>${escapeHtml(option)}</option>`).join('');
    return `
      <label class="form-block">
        <span>${escapeHtml(field.field_label)}${field.is_required ? ' *' : ''}</span>
        <select name="${escapeHtml(name)}" ${required}>
          <option value="">選択してください</option>
          ${options}
        </select>
        ${help}
      </label>
    `;
  }
  if (field.field_type === 'checkbox') {
    return `
      <label class="switch-card">
        <input type="checkbox" name="${escapeHtml(name)}" value="1" ${field.default_value === '1' ? 'checked' : ''}>
        <span>${escapeHtml(field.field_label)}${field.is_required ? ' *' : ''}</span>
      </label>
      ${help}
    `;
  }

  const type = field.field_type === 'number' ? 'number' : (field.field_type === 'date' ? 'date' : 'text');
  return `
    <label class="form-block">
      <span>${escapeHtml(field.field_label)}${field.is_required ? ' *' : ''}</span>
      <input type="${type}" name="${escapeHtml(name)}" value="${value}" placeholder="${placeholder}" ${required}>
      ${help}
    </label>
  `;
}

function renderActiveForm() {
  const root = document.getElementById('public-form-host');
  const message = document.getElementById('public-message');
  if (!root) return;

  const form = publicState.forms.find((item) => item.id === publicState.activeFormId);
  if (!form) {
    root.innerHTML = '<div class="empty-state">現在公開されているフォームはありません。</div>';
    return;
  }

  clearMessage(message);
  const settings = form.settings || {};
  const customFields = (form.fields || []).map(renderCustomField).join('');
  const dateField = settings.enable_date_field ? `
    <label class="form-block">
      <span>${escapeHtml(settings.date_label || '希望日')}${settings.date_required ? ' *' : ''}</span>
      <input type="date" name="submitted_date" ${settings.date_required ? 'required' : ''}>
    </label>
  ` : '';
  const fileField = settings.allow_file_upload ? `
    <label class="form-block">
      <span>${escapeHtml(settings.file_label || '添付ファイル')}${settings.file_required ? ' *' : ''}</span>
      <input type="file" name="uploaded_file" ${settings.file_required ? 'required' : ''}>
      <div class="small-note">許可拡張子: ${escapeHtml(settings.allowed_extensions || '')} / 上限 ${escapeHtml(String(settings.max_upload_size_mb || 5))}MB</div>
    </label>
  ` : '';

  root.innerHTML = `
    <form id="managed-public-form" class="stack-form">
      <input type="hidden" name="form_id" value="${form.id}">
      <div class="form-title-row">
        <div>
          <h2>${escapeHtml(form.name)}</h2>
          ${form.description ? `<p class="muted">${escapeHtml(form.description)}</p>` : ''}
        </div>
      </div>
      <label class="form-block">
        <span>メールアドレス *</span>
        <input type="email" name="email" required>
      </label>
      <label class="form-block">
        <span>団体名 *</span>
        <input type="text" name="organization_name" required>
      </label>
      ${dateField}
      ${customFields}
      ${fileField}
      <div class="actions-row">
        <button type="submit" class="btn primary">${escapeHtml(settings.submit_button_label || '送信する')}</button>
      </div>
      <div class="small-note">同一メールアドレスまたは団体名から再送信した場合は、履歴を残しつつ最新データへ更新します。</div>
    </form>
  `;

  const submitForm = document.getElementById('managed-public-form');
  submitForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(submitForm);
    const submitButton = submitForm.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    setMessage(message, '送信中です...', 'info');
    try {
      const result = await apiPostForm('api/submit.php', formData);
      if (!result.ok) {
        setMessage(message, result.message || '送信に失敗しました。', 'error');
        return;
      }
      setMessage(message, result.message || '送信しました。', 'success');
      submitForm.reset();
    } catch (error) {
      setMessage(message, '通信に失敗しました。', 'error');
    } finally {
      submitButton.disabled = false;
    }
  });
}

async function loadPublicForms() {
  const result = await apiGet('api/public_forms.php');
  const host = document.getElementById('public-form-host');
  if (!result.ok) {
    host.innerHTML = `<div class="empty-state">${escapeHtml(result.message || 'フォーム一覧の取得に失敗しました。')}</div>`;
    return;
  }
  publicState.forms = result.forms || [];
  publicState.activeFormId = publicState.forms[0]?.id || null;
  renderTabs();
  renderActiveForm();
}

document.addEventListener('click', (event) => {
  const button = event.target.closest('[data-form-tab]');
  if (!button) return;
  publicState.activeFormId = Number(button.dataset.formTab);
  renderTabs();
  renderActiveForm();
});

loadPublicForms();
