const publicState = {
  forms: [],
  filteredForms: [],
  activeFormId: null,
  searchQuery: '',
};

function normalizeText(value = '') {
  return String(value).toLowerCase().trim();
}

function statusPillClass(status = '') {
  const safe = String(status || '').toLowerCase();
  return `period-pill period-${safe}`;
}

// P0: 必須/任意バッジ。required属性で読み上げが行われるためバッジは aria-hidden。
function requiredBadge(isRequired) {
  return isRequired
    ? ' <em class="required-badge" aria-hidden="true">必須</em>'
    : ' <em class="optional-badge" aria-hidden="true">任意</em>';
}

function saveDraft(formId, values) {
  try {
    localStorage.setItem(`forms-public-draft-${formId}`, JSON.stringify(values));
  } catch (error) {
    // noop
  }
}

function loadDraft(formId) {
  try {
    const raw = localStorage.getItem(`forms-public-draft-${formId}`);
    return raw ? JSON.parse(raw) : {};
  } catch (error) {
    return {};
  }
}

function clearDraft(formId) {
  try {
    localStorage.removeItem(`forms-public-draft-${formId}`);
  } catch (error) {
    // noop
  }
}

// P1: ドラフトに有効な値が1つでもあるか判定（空オブジェクト/空文字のみは「無」とみなす）
function draftHasContent(draft) {
  if (!draft || typeof draft !== 'object') return false;
  if (draft.email || draft.organization_name || draft.submitted_date) return true;
  const custom = draft.custom || {};
  return Object.values(custom).some((v) => String(v ?? '').trim() !== '');
}

function getActiveForm() {
  return publicState.forms.find((item) => item.id === publicState.activeFormId) || null;
}

function filterForms() {
  const q = normalizeText(publicState.searchQuery);
  if (!q) {
    publicState.filteredForms = [...publicState.forms];
  } else {
    publicState.filteredForms = publicState.forms.filter((form) => {
      const haystack = [form.name, form.description, form.slug]
        .map((value) => normalizeText(value))
        .join(' ');
      return haystack.includes(q);
    });
  }

  const activeExists = publicState.filteredForms.some((form) => form.id === publicState.activeFormId);
  if (!activeExists) {
    publicState.activeFormId = publicState.filteredForms[0]?.id || null;
  }
}

function renderSidebar() {
  const nav = document.getElementById('public-form-nav');
  const count = document.getElementById('public-form-count');
  if (!nav || !count) return;

  count.textContent = String(publicState.forms.length);

  if (!publicState.filteredForms.length) {
    nav.innerHTML = '<div class="empty-state">条件に合うフォームがありません。検索条件を変更してください。</div>';
    return;
  }

  nav.innerHTML = publicState.filteredForms.map((form) => {
    const settings = form.settings || {};
    const availability = form.availability || {};
    const fieldCount = Array.isArray(form.fields) ? form.fields.length : 0;
    const isSelected = form.id === publicState.activeFormId;
    const chips = [
      settings.enable_date_field ? '日付' : null,
      settings.allow_file_upload ? '添付可' : null,
      `${fieldCount} 項目`,
    ].filter(Boolean);

    return `
      <button type="button" class="public-form-nav-item ${isSelected ? 'selected' : ''}" data-form-tab="${form.id}">
        <div class="public-form-nav-item__top">
          <div>
            <strong>${escapeHtml(form.name)}</strong>
            <div class="small-note">${escapeHtml(form.slug || '')}</div>
          </div>
          <span class="${statusPillClass(availability.status || 'open')}">${escapeHtml(availability.label || '受付中')}</span>
        </div>
        ${form.description ? `<p class="public-form-nav-item__description">${escapeHtml(form.description)}</p>` : ''}
        <div class="public-form-nav-item__chips">
          ${chips.map((chip) => `<span class="pill subtle-pill">${escapeHtml(chip)}</span>`).join('')}
        </div>
      </button>
    `;
  }).join('');
}

function renderHero() {
  const title = document.getElementById('public-hero-title');
  const description = document.getElementById('public-hero-description');
  const meta = document.getElementById('public-hero-meta');
  const highlights = document.getElementById('public-hero-highlights');
  const form = getActiveForm();

  if (!title || !description || !meta || !highlights) return;

  if (!form) {
    title.textContent = 'フォームを選択してください';
    description.textContent = 'サイドバーから提出先を選択すると、ここにフォーム概要と入力欄が表示されます。';
    meta.innerHTML = '';
    highlights.innerHTML = '';
    return;
  }

  const settings = form.settings || {};
  const availability = form.availability || {};
  const fieldCount = Array.isArray(form.fields) ? form.fields.length : 0;
  const visibleFieldCount = 2 + (settings.enable_date_field ? 1 : 0) + fieldCount + (settings.allow_file_upload ? 1 : 0);
  const customRequiredCount = (form.fields || []).filter((f) => f.is_required).length;
  const requiredTotal = 2
    + (settings.enable_date_field && settings.date_required ? 1 : 0)
    + (settings.allow_file_upload && settings.file_required ? 1 : 0)
    + customRequiredCount;

  title.textContent = form.name || '名称未設定';
  description.textContent = form.description || 'このフォームの説明はまだ設定されていません。';
  meta.innerHTML = `
    <span class="${statusPillClass(availability.status || 'open')}">${escapeHtml(availability.label || '受付中')}</span>
  `;

  // P1: highlightsは「受付状況」と「必須項目数」の2枚に絞る（旧4枚を撤去）
  highlights.innerHTML = `
    <article class="public-highlight-card">
      <span class="mini-stat-label">受付状況</span>
      <strong>${escapeHtml(availability.label || '受付中')}</strong>
      <p class="small-note allow-select">${escapeHtml(availability.note || '現在このフォームは利用可能です。')}</p>
    </article>
    <article class="public-highlight-card">
      <span class="mini-stat-label">必須項目</span>
      <strong>${requiredTotal} <span class="highlight-card__sub">/ 全 ${visibleFieldCount}</span></strong>
      <p class="small-note">送信前に入力が必要な項目数の目安です。</p>
    </article>
  `;
}

function renderCustomField(field, draft = {}) {
  const name = `custom[${field.field_key}]`;
  const required = field.is_required ? 'required' : '';
  const help = field.help_text ? `<div class="small-note allow-select">${escapeHtml(field.help_text)}</div>` : '';
  const placeholder = escapeHtml(field.placeholder || '');
  const draftValue = draft.custom?.[field.field_key];
  const rawValue = draftValue ?? field.default_value ?? '';
  const value = escapeHtml(rawValue || '');

  if (field.field_type === 'textarea') {
    return `
      <label class="form-block public-form-block">
        <span>${escapeHtml(field.field_label)}${requiredBadge(Boolean(field.is_required))}</span>
        <textarea name="${escapeHtml(name)}" rows="4" placeholder="${placeholder}" ${required} ${field.is_required ? 'aria-required="true"' : ''}>${value}</textarea>
        ${help}
      </label>
    `;
  }

  if (field.field_type === 'select') {
    const currentValue = String(rawValue || '');
    const options = (field.options || []).map((option) => `<option value="${escapeHtml(option)}" ${option === currentValue ? 'selected' : ''}>${escapeHtml(option)}</option>`).join('');
    return `
      <label class="form-block public-form-block">
        <span>${escapeHtml(field.field_label)}${requiredBadge(Boolean(field.is_required))}</span>
        <select name="${escapeHtml(name)}" ${required} ${field.is_required ? 'aria-required="true"' : ''}>
          <option value="">選択してください</option>
          ${options}
        </select>
        ${help}
      </label>
    `;
  }

  if (field.field_type === 'checkbox') {
    const checked = String(rawValue) === '1' ? 'checked' : '';
    return `
      <div class="public-checkbox-wrap">
        <label class="switch-card public-check-card">
          <input type="checkbox" name="${escapeHtml(name)}" value="1" ${checked} ${field.is_required ? 'aria-required="true"' : ''}>
          <span>${escapeHtml(field.field_label)}${requiredBadge(Boolean(field.is_required))}</span>
        </label>
        ${help}
      </div>
    `;
  }

  const type = field.field_type === 'number' ? 'number' : (field.field_type === 'date' ? 'date' : 'text');
  return `
    <label class="form-block public-form-block">
      <span>${escapeHtml(field.field_label)}${requiredBadge(Boolean(field.is_required))}</span>
      <input type="${type}" name="${escapeHtml(name)}" value="${value}" placeholder="${placeholder}" ${required} ${field.is_required ? 'aria-required="true"' : ''}>
      ${help}
    </label>
  `;
}

function attachDraftPersistence(formElement, form) {
  if (!formElement || !form) return;
  const persist = () => {
    const data = new FormData(formElement);
    const draft = {
      email: data.get('email') || '',
      organization_name: data.get('organization_name') || '',
      submitted_date: data.get('submitted_date') || '',
      custom: {},
    };
    for (const [key, value] of data.entries()) {
      const match = /^custom\[(.+)\]$/.exec(key);
      if (match) {
        draft.custom[match[1]] = value;
      }
    }
    saveDraft(form.id, draft);
  };

  formElement.addEventListener('input', persist);
  formElement.addEventListener('change', persist);
}

function renderDistributionSection(form) {
  const settings = form?.settings || {};
  const enabled = Boolean(settings.distribution_enabled);
  const hasFile = Boolean(settings.distribution_file_relative_path);
  const title = settings.distribution_title || '配布資料';
  const body = String(settings.distribution_body || '').trim();
  if (!enabled || (!hasFile && !body && !settings.distribution_title)) {
    return '';
  }
  const bodyHtml = body ? `<div class="distribution-body allow-select">${escapeHtml(body).replaceAll('\n', '<br>')}</div>` : '';
  const downloadButton = hasFile ? `
    <a class="btn primary" href="api/download_form_asset.php?form_id=${encodeURIComponent(String(form.id))}">
      ${escapeHtml(settings.distribution_download_label || '資料をダウンロード')}
    </a>
    <span class="small-note">${escapeHtml(settings.distribution_file_original_name || '')}</span>
  ` : '';

  return `
    <section class="public-form-section distribution-public-section">
      <div class="section-title-row compact-row">
        <div>
          <h3>${escapeHtml(title)}</h3>
          ${bodyHtml}
        </div>
      </div>
      ${downloadButton ? `<div class="distribution-download-row">${downloadButton}</div>` : ''}
    </section>
  `;
}

function renderActiveForm() {
  const root = document.getElementById('public-form-host');
  const message = document.getElementById('public-message');
  if (!root) return;

  const form = getActiveForm();
  if (!form) {
    root.innerHTML = '<div class="empty-state">現在、公開中のフォームはありません。左側の一覧もあわせて確認してください。</div>';
    return;
  }

  clearMessage(message);
  const settings = form.settings || {};
  const draft = loadDraft(form.id);
  const hasDraft = draftHasContent(draft);
  const distributionSection = renderDistributionSection(form);
  const customFields = (form.fields || []).map((field) => renderCustomField(field, draft)).join('');
  const dateField = settings.enable_date_field ? `
    <label class="form-block public-form-block">
      <span>${escapeHtml(settings.date_label || '希望日')}${requiredBadge(Boolean(settings.date_required))}</span>
      <input type="date" name="submitted_date" value="${escapeHtml(draft.submitted_date || '')}" ${settings.date_required ? 'required aria-required="true"' : ''}>
    </label>
  ` : '';
  const fileField = settings.allow_file_upload ? `
    <label class="form-block public-form-block">
      <span>${escapeHtml(settings.file_label || '添付ファイル')}${requiredBadge(Boolean(settings.file_required))}</span>
      <input type="file" name="uploaded_file" ${settings.file_required ? 'required aria-required="true"' : ''}>
      <div class="small-note allow-select">許可拡張子: ${escapeHtml(settings.allowed_extensions || '')} / 上限 ${escapeHtml(String(settings.max_upload_size_mb || 5))}MB</div>
    </label>
  ` : '';

  root.innerHTML = `
    <form id="managed-public-form" class="stack-form public-stack-form">
      <input type="hidden" name="form_id" value="${form.id}">
      ${hasDraft ? `
      <div class="draft-restore-bar" role="status" aria-live="polite">
        <div class="draft-restore-bar__text">
          <strong>前回の入力途中を復元しました</strong>
          <span class="small-note">内容を確認してから送信してください。</span>
        </div>
        <button type="button" class="btn ghost btn-compact" id="draft-discard-button">破棄する</button>
      </div>
      ` : ''}
      ${distributionSection}
      <section class="public-form-section">
        <div class="section-title-row compact-row">
          <div>
            <h3>基本情報</h3>
            <p class="small-note">送信先を特定するための情報です。</p>
          </div>
          <span class="pill subtle-pill">必須 2 項目</span>
        </div>
        <div class="public-form-grid two-col">
          <label class="form-block public-form-block">
            <span>メールアドレス <em class="required-badge" aria-hidden="true">必須</em></span>
            <input type="email" name="email" value="${escapeHtml(draft.email || '')}" required aria-required="true" autocomplete="email">
          </label>
          <label class="form-block public-form-block">
            <span>団体名 <em class="required-badge" aria-hidden="true">必須</em></span>
            <input type="text" name="organization_name" value="${escapeHtml(draft.organization_name || '')}" required aria-required="true" autocomplete="organization">
          </label>
        </div>
      </section>

      ${(dateField || customFields) ? `
      <section class="public-form-section">
        <div class="section-title-row compact-row">
          <div>
            <h3>追加情報</h3>
            <p class="small-note">フォームごとに必要な入力項目です。</p>
          </div>
          <span class="pill subtle-pill">カスタム入力</span>
        </div>
        <div class="stack-list">${dateField}${customFields}</div>
      </section>
      ` : ''}

      ${fileField ? `
      <section class="public-form-section">
        <div class="section-title-row compact-row">
          <div>
            <h3>添付ファイル</h3>
            <p class="small-note">必要な場合のみ添付してください。</p>
          </div>
        </div>
        ${fileField}
      </section>
      ` : ''}

      <div class="public-submit-bar">
        <div class="public-submit-status">
          <div class="public-submit-status__progress">
            <span class="status-dot" id="public-required-dot" data-state="pending" aria-hidden="true"></span>
            <span id="public-required-progress">必須項目を確認中...</span>
          </div>
          <div class="public-submit-status__save" id="public-draft-save-status">入力内容は自動で一時保存されます。</div>
        </div>
        <div class="inline-actions">
          <button type="button" class="btn ghost" id="draft-clear-button">入力内容をクリア</button>
          <button type="submit" class="btn primary btn-large">${escapeHtml(settings.submit_button_label || '送信する')}</button>
        </div>
      </div>
    </form>
  `;

  const submitForm = document.getElementById('managed-public-form');
  attachDraftPersistence(submitForm, form);

  // P1: 必須項目進捗の集計対象キー
  const requiredKeys = ['email', 'organization_name'];
  if (settings.enable_date_field && settings.date_required) requiredKeys.push('submitted_date');
  if (settings.allow_file_upload && settings.file_required) requiredKeys.push('uploaded_file');
  const requiredCustomKeys = (form.fields || [])
    .filter((f) => f.is_required)
    .map((f) => f.field_key);

  function updateRequiredProgress() {
    if (!submitForm) return;
    const data = new FormData(submitForm);
    let filled = 0;
    for (const key of requiredKeys) {
      if (key === 'uploaded_file') {
        const fileInput = submitForm.querySelector('input[type="file"][name="uploaded_file"]');
        if (fileInput && fileInput.files && fileInput.files.length > 0) filled += 1;
      } else {
        const v = data.get(key);
        if (v && String(v).trim() !== '') filled += 1;
      }
    }
    for (const key of requiredCustomKeys) {
      const v = data.get(`custom[${key}]`);
      if (v && String(v).trim() !== '') filled += 1;
    }
    const total = requiredKeys.length + requiredCustomKeys.length;
    const text = document.getElementById('public-required-progress');
    const dot = document.getElementById('public-required-dot');
    if (text) {
      text.textContent = total === 0
        ? '必須項目はありません'
        : `必須項目 ${filled} / ${total} 入力済み`;
    }
    if (dot) {
      dot.dataset.state = (total > 0 && filled === total) ? 'ok' : 'pending';
    }
  }

  // P1: 自動保存インジケータの更新
  function indicateDraftSaved() {
    const el = document.getElementById('public-draft-save-status');
    if (!el) return;
    const now = new Date();
    const hh = String(now.getHours()).padStart(2, '0');
    const mm = String(now.getMinutes()).padStart(2, '0');
    el.textContent = `下書きを保存しました ${hh}:${mm}`;
    el.dataset.state = 'saved';
  }

  submitForm?.addEventListener('input', updateRequiredProgress);
  submitForm?.addEventListener('change', updateRequiredProgress);
  submitForm?.addEventListener('input', indicateDraftSaved);
  submitForm?.addEventListener('change', indicateDraftSaved);
  updateRequiredProgress();

  document.getElementById('draft-clear-button')?.addEventListener('click', () => {
    clearDraft(form.id);
    renderActiveForm();
    clearMessage(message);
    showFlashMessage('入力途中の内容をクリアしました。', 'info', { title: '入力を初期化しました' });
  });

  // P1: ドラフト復元バーの「破棄する」ボタン
  document.getElementById('draft-discard-button')?.addEventListener('click', () => {
    clearDraft(form.id);
    renderActiveForm();
    clearMessage(message);
    showFlashMessage('復元した入力内容を破棄しました。', 'info', { title: '入力を初期化しました' });
  });

  submitForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(submitForm);
    const submitButton = submitForm.querySelector('button[type="submit"]');
    const formHost = document.getElementById('public-form-host');
    submitButton.disabled = true;
    submitButton.classList.add('is-loading');
    submitButton.setAttribute('aria-busy', 'true');
    formHost?.setAttribute('aria-busy', 'true');
    clearMessage(message);
    try {
      const result = await apiPostForm('api/submit.php', formData);
      if (!result.ok) {
        showFlashMessage(result.message || '送信に失敗しました。', 'error', {
          title: '送信できませんでした',
          duration: 6200,
        });
        return;
      }
      clearDraft(form.id);
      submitForm.reset();
      showFlashMessage(result.message || '送信しました。', 'success', {
        title: result.status === 'updated' ? '更新を受け付けました' : '送信を受け付けました',
        duration: 5200,
      });
    } catch (error) {
      showFlashMessage('通信に失敗しました。時間をおいて再度お試しください。', 'error', {
        title: '通信エラー',
        duration: 6200,
      });
    } finally {
      submitButton.disabled = false;
      submitButton.classList.remove('is-loading');
      submitButton.removeAttribute('aria-busy');
      formHost?.removeAttribute('aria-busy');
    }
  });
}

async function loadPublicForms() {
  const host = document.getElementById('public-form-host');
  const result = await apiGet('api/public_forms.php');
  if (!host) return;
  if (!result.ok) {
    host.innerHTML = `<div class="empty-state">${escapeHtml(result.message || 'フォーム一覧の取得に失敗しました。')}</div>`;
    return;
  }
  publicState.forms = result.forms || [];
  publicState.activeFormId = publicState.forms[0]?.id || null;
  filterForms();
  renderSidebar();
  renderHero();
  renderActiveForm();
}

document.addEventListener('click', (event) => {
  const button = event.target.closest('[data-form-tab]');
  if (button) {
    publicState.activeFormId = Number(button.dataset.formTab);
    renderSidebar();
    renderHero();
    renderActiveForm();
    return;
  }
});

document.getElementById('public-form-search')?.addEventListener('input', (event) => {
  publicState.searchQuery = event.target.value || '';
  filterForms();
  renderSidebar();
  renderHero();
  renderActiveForm();
});

loadPublicForms();

// P0: モバイル用ドロワー初期化（フォーム一覧のスライドイン）
(function initPublicDrawer() {
  const sidebar = document.getElementById('public-sidebar');
  const backdrop = document.getElementById('public-drawer-backdrop');
  const openBtn = document.getElementById('public-drawer-open');
  const closeBtn = document.getElementById('public-drawer-close');
  if (!sidebar || !backdrop || !openBtn) return;

  const mq = window.matchMedia('(max-width: 920px)');

  function setDrawerOpen(open) {
    sidebar.classList.toggle('is-open', open);
    backdrop.classList.toggle('is-visible', open);
    backdrop.hidden = !open;
    openBtn.setAttribute('aria-expanded', String(open));
    document.body.classList.toggle('public-drawer-open', open);
    if (open) {
      const focusable = sidebar.querySelector(
        'input, button, a[href], [tabindex]:not([tabindex="-1"])'
      );
      focusable?.focus();
    } else if (mq.matches) {
      openBtn.focus();
    }
  }

  openBtn.addEventListener('click', () => setDrawerOpen(true));
  closeBtn?.addEventListener('click', () => setDrawerOpen(false));
  backdrop.addEventListener('click', () => setDrawerOpen(false));

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
      setDrawerOpen(false);
    }
  });

  // フォーム選択時はモバイル時のみ閉じる
  document.addEventListener('click', (event) => {
    const tab = event.target.closest('[data-form-tab]');
    if (tab && mq.matches) {
      setDrawerOpen(false);
    }
  });

  // ビューポートが広がったら閉じてサイドバーを通常状態に戻す
  const handleMediaChange = (event) => {
    if (!event.matches) {
      setDrawerOpen(false);
    }
  };
  if (typeof mq.addEventListener === 'function') {
    mq.addEventListener('change', handleMediaChange);
  } else if (typeof mq.addListener === 'function') {
    mq.addListener(handleMediaChange);
  }
})();