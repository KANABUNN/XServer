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

// P2: 受付状態が「開いている」とみなせるかを安全に判定する。
// availability.status は通常 'open' / 'closed' などが入るが、
// データ仕様の揺れ（is_open フラグだけ来る等）にも防御的に対応する。
function isAvailabilityOpen(availability) {
  if (!availability || typeof availability !== 'object') return true;
  if (availability.is_open === false) return false;
  if (availability.is_open === true) return true;
  const status = String(availability.status || '').toLowerCase();
  if (!status) return true;
  return status === 'open' || status === 'available' || status === 'active';
}

// P3: フィールド名 (例: "custom[memo]") を id 属性に使える形へサニタイズする。
function fieldNameToId(name) {
  return String(name || '').replace(/[^a-zA-Z0-9_-]/g, '_');
}

// P3: フォームDOMからすべての field-error 表示と aria-invalid をクリアする。
function clearAllFieldErrors(formElement) {
  if (!formElement) return;
  formElement.querySelectorAll('[data-field-error]').forEach((el) => {
    el.textContent = '';
    el.hidden = true;
  });
  formElement.querySelectorAll('[aria-invalid="true"]').forEach((el) => {
    el.removeAttribute('aria-invalid');
  });
}

// P3: errors オブジェクト ({ "email": "...", "custom[memo]": "..." } 形式) を
// フォーム上の各フィールドに反映する。最初のエラー要素にスクロール&フォーカスを当てる。
function applyFieldErrors(formElement, errors) {
  if (!formElement || !errors) return;
  clearAllFieldErrors(formElement);
  let firstInvalid = null;
  for (const [name, message] of Object.entries(errors)) {
    if (!message) continue;
    const errEl = formElement.querySelector(`[data-field-error="${CSS.escape(name)}"]`);
    const input = formElement.querySelector(`[name="${CSS.escape(name)}"]`);
    if (errEl) {
      errEl.textContent = message;
      errEl.hidden = false;
    }
    if (input) {
      input.setAttribute('aria-invalid', 'true');
      if (!firstInvalid) firstInvalid = input;
    }
  }
  if (firstInvalid) {
    firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => firstInvalid.focus({ preventScroll: true }), 240);
  }
}

// P3: クライアント側で HTML5 制約検証を実行し、日本語のカスタムメッセージに変換する。
// 戻り値: 全項目妥当なら null、エラーがあれば { name: message } のオブジェクト。
function collectClientValidationErrors(formElement) {
  if (!formElement || formElement.checkValidity()) return null;
  const errors = {};
  formElement.querySelectorAll(':invalid').forEach((field) => {
    const name = field.name;
    if (!name) return;
    const v = field.validity;
    if (v.valueMissing) {
      errors[name] = 'この項目を入力してください。';
    } else if (v.typeMismatch && field.type === 'email') {
      errors[name] = 'メールアドレスの形式が正しくありません。';
    } else if (v.typeMismatch) {
      errors[name] = '入力形式が正しくありません。';
    } else if (v.tooShort) {
      errors[name] = `${field.minLength} 文字以上で入力してください。`;
    } else if (v.tooLong) {
      errors[name] = `${field.maxLength} 文字以内で入力してください。`;
    } else if (v.rangeUnderflow || v.rangeOverflow || v.stepMismatch) {
      errors[name] = field.validationMessage || '入力値の範囲が正しくありません。';
    } else if (v.patternMismatch) {
      errors[name] = '入力形式が正しくありません。';
    } else {
      errors[name] = field.validationMessage || '入力内容を確認してください。';
    }
  });
  return Object.keys(errors).length ? errors : null;
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
    // P2: 初期表示・絞り込み変更時は、受付中のフォームを優先的にアクティブ化
    const firstOpen = publicState.filteredForms.find((form) => isAvailabilityOpen(form.availability));
    publicState.activeFormId = firstOpen ? firstOpen.id : (publicState.filteredForms[0]?.id || null);
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
    const isOpen = isAvailabilityOpen(availability);

    // P2: 必須数 = 基本2 + 日付必須 + 添付必須 + カスタム必須
    const customRequiredCount = (form.fields || []).filter((f) => f.is_required).length;
    const requiredTotal = 2
      + (settings.enable_date_field && settings.date_required ? 1 : 0)
      + (settings.allow_file_upload && settings.file_required ? 1 : 0)
      + customRequiredCount;
    const visibleFieldCount = 2
      + (settings.enable_date_field ? 1 : 0)
      + fieldCount
      + (settings.allow_file_upload ? 1 : 0);

    // P2: チップを意味別に分類（色分けはCSS側）
    const chips = [];
    chips.push(`<span class="pill nav-chip nav-chip--required">必須 ${requiredTotal} <span class="nav-chip__sub">/ 全 ${visibleFieldCount}</span></span>`);
    if (settings.enable_date_field) {
      chips.push('<span class="pill nav-chip nav-chip--date">日付</span>');
    }
    if (settings.allow_file_upload) {
      chips.push('<span class="pill nav-chip nav-chip--file">添付</span>');
    }

    // P2: 受付停止中は aria-disabled で非活性化（キーボード到達は維持）
    // P4: tablist パターンに沿って role="tab"、選択中のみ tabindex="0"
    const isDisabled = !isOpen;
    const tabIndex = isSelected && !isDisabled ? '0' : '-1';
    const ariaDisabledAttr = isDisabled ? 'aria-disabled="true"' : '';
    const itemClass = [
      'public-form-nav-item',
      isSelected ? 'selected' : '',
      isOpen ? '' : 'is-closed',
    ].filter(Boolean).join(' ');

    return `
      <button type="button" class="${itemClass}"
              role="tab"
              id="tab-${form.id}"
              aria-selected="${isSelected ? 'true' : 'false'}"
              aria-controls="public-form-host"
              tabindex="${tabIndex}"
              ${ariaDisabledAttr}
              data-form-tab="${form.id}">
        <div class="public-form-nav-item__top">
          <div>
            <strong>${escapeHtml(form.name)}</strong>
            <div class="small-note">${escapeHtml(form.slug || '')}</div>
          </div>
          <span class="${statusPillClass(availability.status || 'open')}">${escapeHtml(availability.label || '受付中')}</span>
        </div>
        ${form.description ? `<p class="public-form-nav-item__description">${escapeHtml(form.description)}</p>` : ''}
        <div class="public-form-nav-item__chips">
          ${chips.join('')}
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
  const help = field.help_text ? `<div class="small-note allow-select" id="help-${fieldNameToId(name)}">${escapeHtml(field.help_text)}</div>` : '';
  const placeholder = escapeHtml(field.placeholder || '');
  const draftValue = draft.custom?.[field.field_key];
  const rawValue = draftValue ?? field.default_value ?? '';
  const value = escapeHtml(rawValue || '');
  // P3: 各フィールド共通の field-error 要素（aria-describedby で関連付け）
  const errorEl = `<p class="field-error" data-field-error="${escapeHtml(name)}" id="err-${fieldNameToId(name)}" role="alert" hidden></p>`;
  const describedBy = field.help_text
    ? `aria-describedby="help-${fieldNameToId(name)} err-${fieldNameToId(name)}"`
    : `aria-describedby="err-${fieldNameToId(name)}"`;
  // P4: ラベル/入力の明示的な for/id 紐付け
  const inputId = `input-${fieldNameToId(name)}`;

  if (field.field_type === 'textarea') {
    return `
      <label class="form-block public-form-block" for="${inputId}">
        <span>${escapeHtml(field.field_label)}${requiredBadge(Boolean(field.is_required))}</span>
        <textarea id="${inputId}" name="${escapeHtml(name)}" rows="4" placeholder="${placeholder}" ${required} ${field.is_required ? 'aria-required="true"' : ''} ${describedBy}>${value}</textarea>
        ${help}
        ${errorEl}
      </label>
    `;
  }

  if (field.field_type === 'select') {
    const currentValue = String(rawValue || '');
    const options = (field.options || []).map((option) => `<option value="${escapeHtml(option)}" ${option === currentValue ? 'selected' : ''}>${escapeHtml(option)}</option>`).join('');
    return `
      <label class="form-block public-form-block" for="${inputId}">
        <span>${escapeHtml(field.field_label)}${requiredBadge(Boolean(field.is_required))}</span>
        <select id="${inputId}" name="${escapeHtml(name)}" ${required} ${field.is_required ? 'aria-required="true"' : ''} ${describedBy}>
          <option value="">選択してください</option>
          ${options}
        </select>
        ${help}
        ${errorEl}
      </label>
    `;
  }

  if (field.field_type === 'checkbox') {
    const checked = String(rawValue) === '1' ? 'checked' : '';
    return `
      <div class="public-checkbox-wrap">
        <label class="switch-card public-check-card" for="${inputId}">
          <input id="${inputId}" type="checkbox" name="${escapeHtml(name)}" value="1" ${checked} ${field.is_required ? 'aria-required="true"' : ''}>
          <span>${escapeHtml(field.field_label)}${requiredBadge(Boolean(field.is_required))}</span>
        </label>
        ${help}
        ${errorEl}
      </div>
    `;
  }

  const type = field.field_type === 'number' ? 'number' : (field.field_type === 'date' ? 'date' : 'text');
  return `
    <label class="form-block public-form-block" for="${inputId}">
      <span>${escapeHtml(field.field_label)}${requiredBadge(Boolean(field.is_required))}</span>
      <input id="${inputId}" type="${type}" name="${escapeHtml(name)}" value="${value}" placeholder="${placeholder}" ${required} ${field.is_required ? 'aria-required="true"' : ''} ${describedBy}>
      ${help}
      ${errorEl}
    </label>
  `;
}

function persistCurrentPublicDraft(formElement, form) {
  if (!formElement || !form) return;
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
}

function attachDraftPersistence(formElement, form) {
  if (!formElement || !form) return;
  const persist = () => persistCurrentPublicDraft(formElement, form);

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
  // P4: tabpanel の aria-labelledby を現在のタブIDに紐付ける
  if (form) {
    root.setAttribute('aria-labelledby', `tab-${form.id}`);
  } else {
    root.removeAttribute('aria-labelledby');
  }

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
    <label class="form-block public-form-block" for="input-submitted_date">
      <span>${escapeHtml(settings.date_label || '希望日')}${requiredBadge(Boolean(settings.date_required))}</span>
      <input id="input-submitted_date" type="date" name="submitted_date" value="${escapeHtml(draft.submitted_date || '')}" ${settings.date_required ? 'required aria-required="true"' : ''} aria-describedby="err-submitted_date">
      <p class="field-error" data-field-error="submitted_date" id="err-submitted_date" role="alert" hidden></p>
    </label>
  ` : '';
  // P3: 添付制約を small-note → hint-bar に格上げし、aria-describedby で input と関連付ける
  const fileField = settings.allow_file_upload ? `
    <label class="form-block public-form-block" for="input-uploaded_file">
      <span>${escapeHtml(settings.file_label || '添付ファイル')}${requiredBadge(Boolean(settings.file_required))}</span>
      <div class="hint-bar" id="hint-uploaded_file">
        <span class="hint-bar__label">添付ルール</span>
        <span class="hint-bar__item">許可拡張子: <strong>${escapeHtml(settings.allowed_extensions || '指定なし')}</strong></span>
        <span class="hint-bar__sep" aria-hidden="true">/</span>
        <span class="hint-bar__item">上限 <strong>${escapeHtml(String(settings.max_upload_size_mb || 5))} MB</strong></span>
      </div>
      <input id="input-uploaded_file" type="file" name="uploaded_file" ${settings.file_required ? 'required aria-required="true"' : ''} aria-describedby="hint-uploaded_file err-uploaded_file">
      <p class="field-error" data-field-error="uploaded_file" id="err-uploaded_file" role="alert" hidden></p>
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
          <label class="form-block public-form-block" for="input-email">
            <span>メールアドレス <em class="required-badge" aria-hidden="true">必須</em></span>
            <input id="input-email" type="email" name="email" value="${escapeHtml(draft.email || '')}" required aria-required="true" autocomplete="email" aria-describedby="hint-resubmit err-email">
            <p class="field-error" data-field-error="email" id="err-email" role="alert" hidden></p>
          </label>
          <label class="form-block public-form-block" for="input-organization_name">
            <span>団体名 <em class="required-badge" aria-hidden="true">必須</em></span>
            <input id="input-organization_name" type="text" name="organization_name" value="${escapeHtml(draft.organization_name || '')}" required aria-required="true" autocomplete="organization" aria-describedby="hint-resubmit err-organization_name">
            <p class="field-error" data-field-error="organization_name" id="err-organization_name" role="alert" hidden></p>
          </label>
        </div>
        <!-- P3: 上書き仕様の inline help（メアド+団体名の重複時挙動） -->
        <p class="inline-help" id="hint-resubmit">
          <span class="inline-help__icon" aria-hidden="true">i</span>
          <span><strong>再送信時の挙動：</strong>同じメールアドレスと団体名の組み合わせで過去に申請がある場合、<strong>最新の内容で上書き</strong>されます。過去の申請内容は管理者の履歴として保持されます。</span>
        </p>
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
            <p class="small-note">指定されたファイルを添付してください。</p>
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

  // P3: フィールド入力時に該当フィールドのエラー表示を自動クリアする
  submitForm?.addEventListener('input', (event) => {
    const target = event.target;
    if (!target || !target.matches('input, textarea, select')) return;
    if (target.getAttribute('aria-invalid') === 'true') {
      target.removeAttribute('aria-invalid');
      const errEl = submitForm.querySelector(`[data-field-error="${CSS.escape(target.name || '')}"]`);
      if (errEl) {
        errEl.textContent = '';
        errEl.hidden = true;
      }
    }
  });

  // P3: ブラウザ標準のバリデーションバルーンを抑制する
  // （checkValidity → applyFieldErrors の独自フローに統一する）
  submitForm?.addEventListener('invalid', (event) => {
    event.preventDefault();
  }, true);

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

    // P3: 送信前にクライアント検証を実行し、エラーがあれば inline 表示
    clearAllFieldErrors(submitForm);
    const clientErrors = collectClientValidationErrors(submitForm);
    if (clientErrors) {
      applyFieldErrors(submitForm, clientErrors);
      showFlashMessage('入力内容に不備があります。赤いメッセージをご確認ください。', 'error', {
        title: '入力内容を確認してください',
        duration: 5200,
      });
      return;
    }

    const formData = new FormData(submitForm);
    persistCurrentPublicDraft(submitForm, form);
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
        if (result.csrf_expired || result.reload_required) {
          persistCurrentPublicDraft(submitForm, form);
          showFlashMessage(result.message || 'セッションが切れました。ページを再読み込みしてください。', 'error', {
            title: '再読み込みが必要です',
            duration: 0,
          });
          const shouldReload = window.confirm('セッションが切れた可能性があります。入力内容は下書きに保存されています。ページを再読み込みしますか？');
          if (shouldReload) {
            window.location.reload();
          }
          return;
        }
        // P3: サーバが errors オブジェクトを返した場合は各フィールドに反映
        if (result.errors && typeof result.errors === 'object') {
          applyFieldErrors(submitForm, result.errors);
        }
        showFlashMessage(result.message || '送信に失敗しました。', 'error', {
          title: '送信できませんでした',
          duration: 6200,
        });
        return;
      }
      clearDraft(form.id);
      submitForm.reset();
      clearAllFieldErrors(submitForm);
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
  // P2: 初期は受付中フォームを優先的にアクティブ化（全件停止中の場合のみ先頭にフォールバック）
  const firstOpen = publicState.forms.find((form) => isAvailabilityOpen(form.availability));
  publicState.activeFormId = firstOpen ? firstOpen.id : (publicState.forms[0]?.id || null);
  filterForms();
  renderSidebar();
  renderHero();
  renderActiveForm();
}

document.addEventListener('click', (event) => {
  const button = event.target.closest('[data-form-tab]');
  if (button) {
    // P2: 受付停止中フォームは選択不可
    if (button.getAttribute('aria-disabled') === 'true') {
      event.preventDefault();
      return;
    }
    publicState.activeFormId = Number(button.dataset.formTab);
    renderSidebar();
    renderHero();
    renderActiveForm();
    return;
  }
});

// P4: tablist の矢印キーナビゲーション (manual activation pattern)
// 矢印キーでフォーカスのみ移動、Enter/Space で選択を確定する。
document.addEventListener('keydown', (event) => {
  const nav = document.getElementById('public-form-nav');
  if (!nav) return;
  const activeTab = event.target.closest('[role="tab"]');
  if (!activeTab || !nav.contains(activeTab)) return;

  const tabs = Array.from(nav.querySelectorAll('[role="tab"]'));
  if (!tabs.length) return;
  const currentIndex = tabs.indexOf(activeTab);
  let nextIndex = currentIndex;

  switch (event.key) {
    case 'ArrowDown':
    case 'ArrowRight':
      nextIndex = (currentIndex + 1) % tabs.length;
      break;
    case 'ArrowUp':
    case 'ArrowLeft':
      nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
      break;
    case 'Home':
      nextIndex = 0;
      break;
    case 'End':
      nextIndex = tabs.length - 1;
      break;
    default:
      return;
  }
  event.preventDefault();
  // 移動先タブをフォーカス可能にしてからフォーカスを移す（roving tabindex）
  tabs.forEach((t, i) => t.setAttribute('tabindex', i === nextIndex ? '0' : '-1'));
  tabs[nextIndex].focus();
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
    // P2: 停止中フォーム（aria-disabled）はクリックしてもドロワーを閉じない
    if (tab && tab.getAttribute('aria-disabled') !== 'true' && mq.matches) {
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