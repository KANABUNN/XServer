/* =====================================================================
   提出フォーム（利用者画面）
   - 左: フォーム一覧（受付中を上に、受付期間外は折りたたみ）
   - 右: 選択したフォームの入力欄（モバイルでは一覧 → 入力へスライド遷移）
   旧 public_period_guard.js / distribution_multi_public.js / kintone_public_hints.js を統合。
   ===================================================================== */
(function () {
  'use strict';

  const PUBLIC_FORMS_API = 'api/public_forms_with_closed.php';
  const CONTACT_EMAIL = 'sogokanri@bene.fit.ac.jp';
  const MOBILE_MEDIA = window.matchMedia('(max-width: 900px)');
  const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

  const state = {
    forms: [],
    activeFormId: null,
    query: '',
    moreOpen: false,
    loaded: false,
    successFor: null,
  };

  const bridge = window.FITSC_KINTONE_BRIDGE || {};
  const orgSuggestions = Array.isArray(bridge.organization_suggestions) ? bridge.organization_suggestions : [];
  const orgNameSet = new Set(orgSuggestions.map((item) => String(item.name || '').trim()).filter(Boolean));

  const el = {
    layout: document.getElementById('fp-layout'),
    nav: document.getElementById('public-form-nav'),
    host: document.getElementById('public-form-host'),
    openCount: document.getElementById('public-open-count'),
    search: document.getElementById('public-form-search'),
    back: document.getElementById('public-back'),
  };

  const icon = (name, cls) => (window.UI ? UI.icon(name, cls) : '');
  const esc = (value) => escapeHtml(value == null ? '' : String(value));

  /* ---------------- 受付状態 ---------------- */
  function availabilityStatus(form) {
    const availability = form?.availability || {};
    const status = String(availability.status || '').toLowerCase();
    if (availability.is_open === true) return 'open';
    if (availability.is_open === false) return status === 'scheduled' ? 'scheduled' : status === 'closed' ? 'closed' : 'closed';
    if (!status || ['open', 'available', 'active', 'always_open'].includes(status)) return 'open';
    if (status === 'scheduled') return 'scheduled';
    return 'closed';
  }

  function isOpen(form) {
    return availabilityStatus(form) === 'open';
  }

  function rank(form) {
    const status = availabilityStatus(form);
    return status === 'open' ? 0 : status === 'scheduled' ? 1 : 2;
  }

  function formatDateTime(date, time) {
    if (!date) return '';
    const parts = String(date).split('-').map(Number);
    if (parts.length !== 3 || parts.some((n) => !Number.isFinite(n))) return `${date}${time ? ` ${time}` : ''}`;
    const d = new Date(parts[0], parts[1] - 1, parts[2]);
    const now = new Date();
    const yearPrefix = d.getFullYear() !== now.getFullYear() ? `${d.getFullYear()}/` : '';
    return `${yearPrefix}${d.getMonth() + 1}/${d.getDate()}(${WEEKDAYS[d.getDay()]})${time ? ` ${time}` : ''}`;
  }

  function periodLabel(form) {
    const availability = form?.availability || {};
    const settings = form?.settings || {};
    const status = availabilityStatus(form);
    const endDate = availability.end_date || settings.public_end_date || '';
    const endTime = availability.end_time || settings.public_end_time || '';
    const startDate = availability.start_date || settings.public_start_date || '';
    const startTime = availability.start_time || settings.public_start_time || '';
    if (status === 'scheduled') return startDate ? `${formatDateTime(startDate, startTime)} から受付` : '受付開始前';
    if (status === 'closed') return endDate ? `${formatDateTime(endDate, endTime)} で終了` : '受付終了';
    return endDate ? `${formatDateTime(endDate, endTime || '23:59')} まで` : '期限なし';
  }

  function statusBadge(form, size = '') {
    const status = availabilityStatus(form);
    const map = {
      open: ['success', '受付中'],
      scheduled: ['info', '受付前'],
      closed: ['neutral', '受付終了'],
    };
    const [tone, label] = map[status];
    return `<span class="badge badge--${tone} badge--dot${size ? ` badge--${size}` : ''}">${label}</span>`;
  }

  /* ---------------- 下書き ---------------- */
  function draftKey(formId) { return `forms-public-draft-${formId}`; }
  function saveDraft(formId, values) {
    try { localStorage.setItem(draftKey(formId), JSON.stringify(values)); } catch (error) { /* noop */ }
  }
  function loadDraft(formId) {
    try {
      const raw = localStorage.getItem(draftKey(formId));
      return raw ? JSON.parse(raw) : {};
    } catch (error) {
      return {};
    }
  }
  function clearDraft(formId) {
    try { localStorage.removeItem(draftKey(formId)); } catch (error) { /* noop */ }
  }
  function draftHasContent(draft) {
    if (!draft || typeof draft !== 'object') return false;
    if (draft.email || draft.organization_name || draft.submitted_date) return true;
    return Object.values(draft.custom || {}).some((v) => String(v ?? '').trim() !== '');
  }

  function collectDraft(formElement) {
    const data = new FormData(formElement);
    const draft = {
      email: data.get('email') || '',
      organization_name: data.get('organization_name') || '',
      submitted_date: data.get('submitted_date') || '',
      custom: {},
    };
    for (const [key, value] of data.entries()) {
      const match = /^custom\[(.+)\]$/.exec(key);
      if (match && typeof value === 'string') draft.custom[match[1]] = value;
    }
    return draft;
  }

  /* ---------------- 入力検証 ---------------- */
  function fieldNameToId(name) {
    return String(name || '').replace(/[^a-zA-Z0-9_-]/g, '_');
  }

  function clearAllFieldErrors(formElement) {
    if (!formElement) return;
    formElement.querySelectorAll('[data-field-error]').forEach((node) => {
      node.textContent = '';
      node.hidden = true;
    });
    formElement.querySelectorAll('[aria-invalid="true"]').forEach((node) => node.removeAttribute('aria-invalid'));
  }

  function applyFieldErrors(formElement, errors) {
    if (!formElement || !errors) return;
    clearAllFieldErrors(formElement);
    let firstInvalid = null;
    Object.entries(errors).forEach(([name, message]) => {
      if (!message) return;
      const errorNode = formElement.querySelector(`[data-field-error="${CSS.escape(name)}"]`);
      const input = formElement.querySelector(`[name="${CSS.escape(name)}"]`)
        || formElement.querySelector(`[name="${CSS.escape(name)}[]"]`);
      if (errorNode) {
        errorNode.textContent = message;
        errorNode.hidden = false;
      }
      if (input) {
        input.setAttribute('aria-invalid', 'true');
        if (!firstInvalid) firstInvalid = input;
      }
    });
    if (firstInvalid) {
      (firstInvalid.closest('.field') || firstInvalid).scrollIntoView({ behavior: 'smooth', block: 'center' });
      window.setTimeout(() => firstInvalid.focus({ preventScroll: true }), 260);
    }
  }

  function collectClientValidationErrors(formElement) {
    if (!formElement || formElement.checkValidity()) return null;
    const errors = {};
    formElement.querySelectorAll(':invalid').forEach((field) => {
      let name = field.name;
      if (!name) return;
      if (name.endsWith('[]')) name = name.slice(0, -2);
      const v = field.validity;
      if (v.valueMissing) errors[name] = field.type === 'file' ? 'ファイルを選択してください。' : field.type === 'checkbox' ? 'チェックを入れてください。' : 'この項目を入力してください。';
      else if (v.typeMismatch && field.type === 'email') errors[name] = 'メールアドレスの形式が正しくありません。';
      else if (v.typeMismatch || v.patternMismatch) errors[name] = '入力形式が正しくありません。';
      else if (v.tooShort) errors[name] = `${field.minLength} 文字以上で入力してください。`;
      else if (v.tooLong) errors[name] = `${field.maxLength} 文字以内で入力してください。`;
      else if (v.rangeUnderflow || v.rangeOverflow || v.stepMismatch) errors[name] = field.validationMessage || '入力値の範囲が正しくありません。';
      else errors[name] = field.validationMessage || '入力内容を確認してください。';
    });
    return Object.keys(errors).length ? errors : null;
  }

  /* ---------------- 一覧 ---------------- */
  function getActiveForm() {
    return state.forms.find((form) => Number(form.id) === Number(state.activeFormId)) || null;
  }

  function matchesQuery(form) {
    const q = state.query.trim().toLowerCase();
    if (!q) return true;
    return [form.name, form.description, form.slug].some((value) => String(value || '').toLowerCase().includes(q));
  }

  function navItemHtml(form) {
    const selected = Number(form.id) === Number(state.activeFormId);
    const status = availabilityStatus(form);
    const settings = form.settings || {};
    const tone = status === 'open' ? 'success' : status === 'scheduled' ? 'info' : '';
    const extras = [];
    if (settings.allow_file_upload) extras.push(`${icon('paperclip', 'ico--sm')}添付`);
    const hasDistribution = Boolean(settings.distribution_enabled && (settings.distribution_file_relative_path || (settings.distribution_files || []).length));
    if (hasDistribution) extras.push(`${icon('download', 'ico--sm')}資料`);
    return `
      <button type="button" class="fp-item${selected ? ' is-selected' : ''} is-${status}" role="tab"
        id="tab-${esc(form.id)}" aria-selected="${selected ? 'true' : 'false'}" aria-controls="public-form-host"
        tabindex="${selected ? '0' : '-1'}" data-form-tab="${esc(form.id)}">
        <span class="dot${tone ? ` dot--${tone}` : ''} fp-item__dot" aria-hidden="true"></span>
        <span class="fp-item__body">
          <span class="fp-item__title">${esc(form.name || '名称未設定')}</span>
          <span class="fp-item__meta">
            <span>${icon(status === 'open' ? 'clock' : 'calendar', 'ico--sm')}${esc(periodLabel(form))}</span>
            ${extras.map((x) => `<span>${x}</span>`).join('')}
          </span>
        </span>
        ${icon('chevron-right', 'fp-item__chevron')}
      </button>
    `;
  }

  function renderNav() {
    if (!el.nav) return;
    const visible = state.forms.filter(matchesQuery);
    const open = visible.filter((form) => rank(form) === 0);
    const others = visible.filter((form) => rank(form) !== 0);
    const openTotal = state.forms.filter(isOpen).length;
    if (el.openCount) el.openCount.textContent = String(openTotal);

    if (!state.forms.length) {
      el.nav.innerHTML = `
        <div class="empty">
          <span class="empty__icon">${icon('inbox')}</span>
          <span class="empty__title">公開中のフォームはありません</span>
        </div>`;
      return;
    }
    if (!visible.length) {
      el.nav.innerHTML = `
        <div class="empty">
          <span class="empty__icon">${icon('search')}</span>
          <span>「${esc(state.query)}」に一致するフォームはありません。</span>
        </div>`;
      return;
    }

    const activeInOthers = others.some((form) => Number(form.id) === Number(state.activeFormId));
    const moreOpen = state.moreOpen || activeInOthers || (state.query.trim() !== '' && !open.length);
    el.nav.innerHTML = `
      ${open.length ? `<div class="fp-nav__group">${open.map(navItemHtml).join('')}</div>` : `
        <p class="fp-nav__none">${state.query ? '受付中のフォームに一致するものはありません。' : '現在受付中のフォームはありません。'}</p>`}
      ${others.length ? `
        <details class="fp-nav__more" id="fp-nav-more"${moreOpen ? ' open' : ''}>
          <summary>${icon('clock', 'ico--sm')}受付期間外のフォーム<span class="badge">${others.length}</span></summary>
          <div class="fp-nav__group">${others.map(navItemHtml).join('')}</div>
        </details>` : ''}
    `;
    const more = document.getElementById('fp-nav-more');
    more?.addEventListener('toggle', () => { state.moreOpen = more.open; });
  }

  /* ---------------- 詳細 ---------------- */
  function distributionFiles(settings = {}) {
    const files = [];
    if (Array.isArray(settings.distribution_files)) {
      settings.distribution_files.forEach((file) => {
        if (!file || !file.relative_path) return;
        files.push({
          id: String(file.id || ''),
          name: String(file.original_name || file.stored_name || '配布ファイル'),
          size: Number(file.size_bytes || 0),
        });
      });
    }
    if (!files.length && settings.distribution_file_relative_path) {
      files.push({
        id: String(settings.distribution_file_id || ''),
        name: String(settings.distribution_file_original_name || '配布ファイル'),
        size: Number(settings.distribution_file_size_bytes || 0),
      });
    }
    return files;
  }

  function fileSize(bytes) {
    const size = Number(bytes || 0);
    if (!Number.isFinite(size) || size <= 0) return '';
    if (size >= 1024 * 1024) return `${(size / 1024 / 1024).toFixed(1)} MB`;
    if (size >= 1024) return `${Math.ceil(size / 1024)} KB`;
    return `${size} B`;
  }

  function distributionHtml(form) {
    const settings = form.settings || {};
    const files = distributionFiles(settings);
    const body = String(settings.distribution_body || '').trim();
    if (!settings.distribution_enabled || (!files.length && !body && !settings.distribution_title)) return '';
    const label = settings.distribution_download_label || '資料をダウンロード';
    return `
      <section class="fp-dist" aria-label="配布資料">
        <div class="fp-dist__head">${icon('download')}<strong>${esc(settings.distribution_title || '配布資料')}</strong></div>
        ${body ? `<p class="fp-dist__body allow-select">${esc(body).replaceAll('\n', '<br>')}</p>` : ''}
        ${files.length ? `<div class="fp-dist__files">${files.map((file) => {
          const params = new URLSearchParams({ form_id: String(form.id) });
          if (file.id) params.set('file_id', file.id);
          return `
            <a class="fp-file" href="api/download_form_asset.php?${params.toString()}" download>
              <span class="fp-file__icon">${icon('file-text')}</span>
              <span class="fp-file__body"><strong>${esc(file.name)}</strong>${file.size ? `<small>${esc(fileSize(file.size))}</small>` : ''}</span>
              <span class="fp-file__action">${icon('download')}<span>${esc(files.length === 1 ? label : 'ダウンロード')}</span></span>
            </a>`;
        }).join('')}</div>` : ''}
      </section>
    `;
  }

  function requiredBadge(required) {
    return required ? ' <span class="req" aria-hidden="true">必須</span>' : '';
  }

  function errorNode(name) {
    return `<p class="field__error" data-field-error="${esc(name)}" id="err-${fieldNameToId(name)}" role="alert" hidden></p>`;
  }

  function customFieldHtml(field, draft) {
    const name = `custom[${field.field_key}]`;
    const id = `input-${fieldNameToId(name)}`;
    const required = Boolean(field.is_required);
    const reqAttr = required ? 'required aria-required="true"' : '';
    const helpId = `help-${fieldNameToId(name)}`;
    const help = field.help_text ? `<span class="field__hint allow-select" id="${helpId}">${esc(field.help_text)}</span>` : '';
    const describedBy = `aria-describedby="${field.help_text ? `${helpId} ` : ''}err-${fieldNameToId(name)}"`;
    const draftValue = draft.custom?.[field.field_key];
    const raw = draftValue ?? field.default_value ?? '';
    const value = esc(raw || '');
    const placeholder = esc(field.placeholder || '');

    if (field.field_type === 'checkbox') {
      return `
        <div class="field">
          <label class="check-card" for="${id}">
            <input id="${id}" type="checkbox" name="${esc(name)}" value="1" ${String(raw) === '1' ? 'checked' : ''} ${reqAttr} ${describedBy}>
            <span><strong>${esc(field.field_label)}${requiredBadge(required)}</strong>${field.help_text ? `<small class="allow-select" id="${helpId}">${esc(field.help_text)}</small>` : ''}</span>
          </label>
          ${errorNode(name)}
        </div>`;
    }

    let control;
    if (field.field_type === 'textarea') {
      control = `<textarea id="${id}" name="${esc(name)}" rows="4" placeholder="${placeholder}" ${reqAttr} ${describedBy}>${value}</textarea>`;
    } else if (field.field_type === 'select') {
      const current = String(raw || '');
      const options = (field.options || []).map((option) => `<option value="${esc(option)}" ${option === current ? 'selected' : ''}>${esc(option)}</option>`).join('');
      control = `<select id="${id}" name="${esc(name)}" ${reqAttr} ${describedBy}><option value="">選択してください</option>${options}</select>`;
    } else {
      const type = field.field_type === 'number' ? 'number' : field.field_type === 'date' ? 'date' : 'text';
      control = `<input id="${id}" type="${type}" name="${esc(name)}" value="${value}" placeholder="${placeholder}" ${reqAttr} ${describedBy}>`;
    }
    return `
      <div class="field${field.field_type === 'textarea' ? ' span-full' : ''}">
        <label class="field__label" for="${id}">${esc(field.field_label)}${requiredBadge(required)}</label>
        ${control}
        ${help}
        ${errorNode(name)}
      </div>`;
  }

  function fileFieldHtml(settings) {
    if (!settings.allow_file_upload) return '';
    const required = Boolean(settings.file_required);
    const maxFiles = Number(settings.max_upload_files || 1);
    const exts = String(settings.allowed_extensions || '').split(',').map((s) => s.trim()).filter(Boolean);
    const accept = exts.length ? exts.map((x) => `.${x.replace(/^\./, '')}`).join(',') : '';
    const extText = exts.length ? exts.map((x) => x.toUpperCase()).join(' / ') : '指定なし';
    return `
      <div class="field span-full">
        <span class="field__label">${esc(settings.file_label || '添付ファイル')}${requiredBadge(required)}</span>
        <label class="fp-drop" for="input-uploaded_file">
          <input id="input-uploaded_file" type="file" name="uploaded_file[]" ${maxFiles > 1 ? 'multiple' : ''} ${accept ? `accept="${esc(accept)}"` : ''}
            ${required ? 'required aria-required="true"' : ''} aria-describedby="hint-uploaded_file err-uploaded_file">
          <span class="fp-drop__icon">${icon('upload', 'ico--lg')}</span>
          <span class="fp-drop__text"><strong>ファイルを選択</strong><span class="hide-mobile">、またはここへドラッグ</span></span>
          <span class="fp-drop__files" data-file-list></span>
        </label>
        <span class="field__hint" id="hint-uploaded_file">${esc(extText)} ・ 1ファイル ${esc(String(settings.max_upload_size_mb || 5))}MB まで ・ 最大 ${esc(String(maxFiles))} 個</span>
        ${errorNode('uploaded_file')}
      </div>`;
  }

  function headerHtml(form) {
    const status = availabilityStatus(form);
    return `
      <header class="fp-card__head">
        <div class="cluster">
          ${statusBadge(form)}
          <span class="chip">${icon(status === 'open' ? 'clock' : 'calendar')}${esc(periodLabel(form))}</span>
        </div>
        <h1 class="fp-card__title">${esc(form.name || '名称未設定')}</h1>
        ${form.description ? `<p class="fp-card__desc allow-select">${esc(form.description).replaceAll('\n', '<br>')}</p>` : ''}
      </header>`;
  }

  function unavailableHtml(form) {
    const status = availabilityStatus(form);
    const scheduled = status === 'scheduled';
    const availability = form.availability || {};
    const windowText = availability.window_text || '';
    return `
      <div class="fp-state fp-state--${scheduled ? 'info' : 'muted'}" role="status">
        <span class="fp-state__icon">${icon(scheduled ? 'calendar' : 'lock', 'ico--xl')}</span>
        <h2>${scheduled ? 'まだ受付が始まっていません' : 'このフォームの受付は終了しました'}</h2>
        ${windowText ? `<p>受付期間：${esc(windowText)}</p>` : ''}
        ${scheduled
          ? '<p>受付開始後に、このページから入力できるようになります。</p>'
          : `<p>提出が必要な場合は、メールでご連絡ください。</p>
             <a class="btn btn--sm" href="mailto:${CONTACT_EMAIL}">${icon('mail')}${CONTACT_EMAIL}</a>`}
      </div>`;
  }

  function successHtml(form, message, updated) {
    return `
      <div class="fp-state fp-state--success" role="status" tabindex="-1" id="fp-success">
        <span class="fp-state__icon fp-state__icon--check">
          <svg viewBox="0 0 52 52" class="fp-check" aria-hidden="true"><circle cx="26" cy="26" r="24"/><path d="m15 27 7.5 7.5L37 19"/></svg>
        </span>
        <h2>${updated ? '更新を受け付けました' : '送信を受け付けました'}</h2>
        <p class="allow-select">${esc(message || form.settings?.completion_message || '送信を受け付けました。')}</p>
        <div class="cluster" style="justify-content:center">
          <button type="button" class="btn" data-fp-again>${icon('rotate-ccw')}もう一度回答する</button>
          <button type="button" class="btn btn--ghost fp-only-mobile" data-fp-list>${icon('list')}フォーム一覧へ</button>
        </div>
      </div>`;
  }

  function renderDetail() {
    if (!el.host) return;
    const form = getActiveForm();
    if (form) el.host.setAttribute('aria-labelledby', `tab-${form.id}`);
    else el.host.removeAttribute('aria-labelledby');

    if (!form) {
      el.host.innerHTML = `
        <div class="card fp-card">
          <div class="empty">
            <span class="empty__icon">${icon('file-text')}</span>
            <span class="empty__title">${state.forms.length ? 'フォームを選択してください' : '現在、公開中のフォームはありません'}</span>
          </div>
        </div>`;
      return;
    }

    if (state.successFor && state.successFor.id === form.id) {
      el.host.innerHTML = `<article class="card fp-card">${headerHtml(form)}${successHtml(form, state.successFor.message, state.successFor.updated)}</article>`;
      document.getElementById('fp-success')?.focus({ preventScroll: true });
      return;
    }

    if (!isOpen(form)) {
      el.host.innerHTML = `<article class="card fp-card">${headerHtml(form)}${distributionHtml(form)}${unavailableHtml(form)}</article>`;
      return;
    }

    const settings = form.settings || {};
    const draft = loadDraft(form.id);
    const hasDraft = draftHasContent(draft);
    const dateField = settings.enable_date_field ? `
      <div class="field">
        <label class="field__label" for="input-submitted_date">${esc(settings.date_label || '希望日')}${requiredBadge(Boolean(settings.date_required))}</label>
        <input id="input-submitted_date" type="date" name="submitted_date" value="${esc(draft.submitted_date || '')}" ${settings.date_required ? 'required aria-required="true"' : ''} aria-describedby="err-submitted_date">
        ${errorNode('submitted_date')}
      </div>` : '';
    const customFields = (form.fields || []).map((field) => customFieldHtml(field, draft)).join('');
    const orgListAttr = orgSuggestions.length ? 'list="kintone-org-suggestions"' : '';

    el.host.innerHTML = `
      <article class="card fp-card">
        ${headerHtml(form)}
        ${distributionHtml(form)}
        <form id="managed-public-form" class="fp-form" novalidate>
          <input type="hidden" name="form_id" value="${esc(form.id)}">
          ${hasDraft ? `
            <div class="alert alert--info fp-draft" role="status">
              ${icon('history')}
              <div class="alert__body">前回の入力途中の内容を復元しました。</div>
              <button type="button" class="btn btn--ghost btn--sm" id="draft-discard-button">破棄</button>
            </div>` : ''}
          <div class="form-grid fp-grid">
            <div class="field">
              <label class="field__label" for="input-email">メールアドレス${requiredBadge(true)}</label>
              <input id="input-email" type="email" name="email" value="${esc(draft.email || '')}" required aria-required="true" autocomplete="email" inputmode="email" placeholder="example@bene.fit.ac.jp" aria-describedby="err-email">
              ${errorNode('email')}
            </div>
            <div class="field">
              <label class="field__label" for="input-organization_name">団体名${requiredBadge(true)}</label>
              <input id="input-organization_name" type="text" name="organization_name" value="${esc(draft.organization_name || '')}" required aria-required="true" autocomplete="organization" ${orgListAttr} placeholder="${orgSuggestions.length ? '入力すると候補が表示されます' : '正式名称を入力'}" aria-describedby="org-hint err-organization_name">
              <span class="field__hint fp-org-warn" id="org-hint" hidden>${icon('alert-triangle', 'ico--sm')}団体一覧にない名称です。正式名称かご確認ください（このまま送信できます）。</span>
              ${errorNode('organization_name')}
            </div>
            ${dateField}
            ${customFields}
            ${fileFieldHtml(settings)}
          </div>

          <p class="fp-note">${icon('info', 'ico--sm')}同じメールアドレスと団体名で再送信すると、内容が最新のものに更新されます。</p>

          <div class="fp-submit">
            <div class="fp-submit__status">
              <div class="fp-progress" aria-live="polite">
                <span class="progress"><span id="public-required-bar" style="width:0%"></span></span>
                <span id="public-required-progress">必須項目を確認中…</span>
              </div>
              <span class="fp-saved" id="public-draft-save-status">${icon('save', 'ico--sm')}入力内容は自動で一時保存されます</span>
            </div>
            <div class="fp-submit__actions">
              <button type="button" class="btn btn--ghost" id="draft-clear-button">${icon('rotate-ccw')}<span>クリア</span></button>
              <button type="submit" class="btn btn--primary btn--lg">${icon('send')}<span>${esc(settings.submit_button_label || '送信する')}</span></button>
            </div>
          </div>
        </form>
      </article>`;

    bindForm(form, settings);
  }

  function ensureOrgDatalist() {
    if (!orgSuggestions.length || document.getElementById('kintone-org-suggestions')) return;
    const datalist = document.createElement('datalist');
    datalist.id = 'kintone-org-suggestions';
    datalist.innerHTML = orgSuggestions.map((item) => {
      const label = [item.code, item.category].filter(Boolean).join(' / ');
      return `<option value="${esc(item.name || item.code || '')}" label="${esc(label)}"></option>`;
    }).join('');
    document.body.appendChild(datalist);
  }

  function bindForm(form, settings) {
    const formEl = document.getElementById('managed-public-form');
    if (!formEl) return;

    const requiredKeys = ['email', 'organization_name'];
    if (settings.enable_date_field && settings.date_required) requiredKeys.push('submitted_date');
    if (settings.allow_file_upload && settings.file_required) requiredKeys.push('uploaded_file');
    const requiredCustom = (form.fields || []).filter((f) => f.is_required).map((f) => f.field_key);

    const updateProgress = () => {
      const data = new FormData(formEl);
      let filled = 0;
      requiredKeys.forEach((key) => {
        if (key === 'uploaded_file') {
          const fileInput = formEl.querySelector('input[type="file"][name="uploaded_file[]"]');
          if (fileInput?.files?.length) filled += 1;
          return;
        }
        const v = data.get(key);
        if (v && String(v).trim() !== '') filled += 1;
      });
      requiredCustom.forEach((key) => {
        const v = data.get(`custom[${key}]`);
        if (v && String(v).trim() !== '') filled += 1;
      });
      const total = requiredKeys.length + requiredCustom.length;
      const text = document.getElementById('public-required-progress');
      const bar = document.getElementById('public-required-bar');
      const done = total > 0 && filled === total;
      if (text) text.textContent = total === 0 ? '必須項目はありません' : done ? '必須項目はすべて入力済みです' : `必須項目 ${filled} / ${total}`;
      if (bar) {
        bar.style.width = `${total ? Math.round((filled / total) * 100) : 100}%`;
        bar.parentElement.classList.toggle('progress--success', done);
      }
    };

    const indicateSaved = () => {
      const node = document.getElementById('public-draft-save-status');
      if (!node) return;
      const now = new Date();
      node.innerHTML = `${icon('check', 'ico--sm')}一時保存しました ${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
      node.classList.add('is-saved');
    };

    const persist = () => saveDraft(form.id, collectDraft(formEl));

    const clearFieldError = (target) => {
      if (!target?.getAttribute || target.getAttribute('aria-invalid') !== 'true') return;
      target.removeAttribute('aria-invalid');
      let name = target.name || '';
      if (name.endsWith('[]')) name = name.slice(0, -2);
      const errNode = formEl.querySelector(`[data-field-error="${CSS.escape(name)}"]`);
      if (errNode) { errNode.textContent = ''; errNode.hidden = true; }
    };

    formEl.addEventListener('input', (event) => {
      persist();
      updateProgress();
      indicateSaved();
      clearFieldError(event.target);
    });
    formEl.addEventListener('change', (event) => {
      persist();
      updateProgress();
      clearFieldError(event.target);
    });
    formEl.addEventListener('invalid', (event) => event.preventDefault(), true);

    // 団体名候補
    ensureOrgDatalist();
    const orgInput = document.getElementById('input-organization_name');
    const orgWarn = document.getElementById('org-hint');
    const checkOrg = () => {
      const value = String(orgInput?.value || '').trim();
      if (orgWarn) orgWarn.hidden = value === '' || !orgSuggestions.length || orgNameSet.has(value);
    };
    orgInput?.addEventListener('input', checkOrg);
    orgInput?.addEventListener('blur', checkOrg);

    // 添付ファイル
    const fileInput = formEl.querySelector('input[type="file"]');
    const fileList = formEl.querySelector('[data-file-list]');
    const drop = formEl.querySelector('.fp-drop');
    if (fileInput && fileList) {
      const showFiles = () => {
        const files = Array.from(fileInput.files || []);
        fileList.innerHTML = files.map((file) => `<span class="chip">${icon('file', 'ico--sm')}${esc(file.name)}<small>${esc(fileSize(file.size))}</small></span>`).join('');
        drop?.classList.toggle('has-files', files.length > 0);
      };
      fileInput.addEventListener('change', showFiles);
      ['dragenter', 'dragover'].forEach((type) => drop?.addEventListener(type, () => drop.classList.add('is-dragover')));
      ['dragleave', 'drop'].forEach((type) => drop?.addEventListener(type, () => drop.classList.remove('is-dragover')));
    }

    document.getElementById('draft-clear-button')?.addEventListener('click', async () => {
      const ok = await UI.confirm({
        title: '入力内容をクリアしますか？',
        message: 'このフォームに入力した内容と一時保存がすべて消えます。',
        okText: 'クリアする',
        tone: 'danger',
      });
      if (!ok) return;
      clearDraft(form.id);
      renderDetail();
      UI.toast('', { type: 'info', title: '入力内容をクリアしました', duration: 2600 });
    });

    document.getElementById('draft-discard-button')?.addEventListener('click', () => {
      clearDraft(form.id);
      renderDetail();
      UI.toast('', { type: 'info', title: '復元した内容を破棄しました', duration: 2600 });
    });

    formEl.addEventListener('submit', async (event) => {
      event.preventDefault();
      clearAllFieldErrors(formEl);
      const clientErrors = collectClientValidationErrors(formEl);
      if (clientErrors) {
        applyFieldErrors(formEl, clientErrors);
        UI.toast('赤字のメッセージがある項目をご確認ください。', { type: 'error', title: '入力内容を確認してください', duration: 5200 });
        return;
      }

      const formData = new FormData(formEl);
      persist();
      const submitButton = formEl.querySelector('button[type="submit"]');
      submitButton.disabled = true;
      submitButton.classList.add('is-loading');
      submitButton.setAttribute('aria-busy', 'true');
      el.host?.setAttribute('aria-busy', 'true');
      try {
        const result = await apiPostForm('api/submit.php', formData, {
          retries: 1,
          onRetry: () => UI.toast('接続が不安定なため、もう一度送信しています…', { type: 'info', duration: 2500 }),
        });
        if (!result.ok) {
          if (result.csrf_expired || result.reload_required) {
            persist();
            const reload = await UI.confirm({
              title: 'ページの再読み込みが必要です',
              message: `${result.message || 'セッションが切れた可能性があります。'}\n入力内容は一時保存されています。再読み込みしますか？`,
              okText: '再読み込み',
              tone: 'warning',
            });
            if (reload) window.location.reload();
            return;
          }
          if (result.errors && typeof result.errors === 'object') applyFieldErrors(formEl, result.errors);
          UI.toast(result.message || '送信に失敗しました。', { type: 'error', title: '送信できませんでした', duration: 6200 });
          return;
        }
        clearDraft(form.id);
        state.successFor = { id: form.id, message: result.message || '', updated: result.status === 'updated' };
        renderDetail();
        el.host?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } catch (error) {
        persist();
        const info = describeApiError(error);
        UI.toast(info.message, { type: 'error', title: '送信できませんでした', detail: info.detail, duration: 8000 });
      } finally {
        if (document.contains(submitButton)) {
          submitButton.disabled = false;
          submitButton.classList.remove('is-loading');
          submitButton.removeAttribute('aria-busy');
        }
        el.host?.removeAttribute('aria-busy');
      }
    });

    updateProgress();
  }

  /* ---------------- 画面遷移 ---------------- */
  function isMobile() { return MOBILE_MEDIA.matches; }

  function setView(view) {
    if (el.layout) el.layout.dataset.view = view;
  }

  function hashFor(form) {
    return form ? `#${encodeURIComponent(form.slug || `form-${form.id}`)}` : '';
  }

  function formFromHash() {
    const raw = decodeURIComponent(window.location.hash.replace(/^#/, ''));
    if (!raw) return null;
    return state.forms.find((form) => form.slug === raw || `form-${form.id}` === raw) || null;
  }

  function selectForm(formId, { push = true, focus = false } = {}) {
    const form = state.forms.find((item) => Number(item.id) === Number(formId));
    if (!form) return;
    const changed = Number(state.activeFormId) !== Number(form.id);
    state.activeFormId = form.id;
    if (changed || (state.successFor && state.successFor.id !== form.id)) state.successFor = null;
    renderNav();
    renderDetail();
    if (isMobile()) {
      setView('detail');
      if (push) window.history.pushState({ fpForm: form.id }, '', hashFor(form));
      window.scrollTo({ top: 0, behavior: 'auto' });
    } else if (push) {
      window.history.replaceState({ fpForm: form.id }, '', hashFor(form));
    }
    if (focus) el.host?.focus({ preventScroll: true });
  }

  function showList({ push = false } = {}) {
    setView('list');
    if (push) window.history.pushState({}, '', window.location.pathname + window.location.search);
    window.setTimeout(() => document.querySelector('.fp-item.is-selected, .fp-item')?.focus({ preventScroll: true }), 60);
  }

  async function loadForms() {
    try {
      const result = await apiGet(PUBLIC_FORMS_API);
      if (!result.ok) throw new Error(result.message || 'フォーム一覧の取得に失敗しました。');
      state.forms = (result.forms || []).slice().sort((a, b) => (
        rank(a) - rank(b)
        || Number(a.sort_order || 0) - Number(b.sort_order || 0)
        || Number(a.id || 0) - Number(b.id || 0)
      ));
      state.loaded = true;
      const fromHash = formFromHash();
      const firstOpen = state.forms.find(isOpen);
      state.activeFormId = fromHash ? fromHash.id : (firstOpen ? firstOpen.id : (state.forms[0]?.id ?? null));
      renderNav();
      renderDetail();
      if (fromHash && isMobile()) {
        window.history.replaceState({}, '', window.location.pathname + window.location.search);
        window.history.pushState({ fpForm: fromHash.id }, '', hashFor(fromHash));
        setView('detail');
      }
    } catch (error) {
      const message = error instanceof ApiError ? describeApiError(error).message : (error?.message || 'フォーム一覧の取得に失敗しました。');
      if (el.nav) {
        el.nav.innerHTML = `
          <div class="empty">
            <span class="empty__icon">${icon('alert-triangle')}</span>
            <span>${esc(message)}</span>
            <button type="button" class="btn btn--sm" data-fp-reload>${icon('refresh')}再読み込み</button>
          </div>`;
      }
      if (el.host) el.host.innerHTML = '';
    }
  }

  /* ---------------- イベント ---------------- */
  document.addEventListener('click', (event) => {
    const tab = event.target.closest('[data-form-tab]');
    if (tab) {
      selectForm(Number(tab.dataset.formTab));
      return;
    }
    if (event.target.closest('[data-fp-again]')) {
      state.successFor = null;
      renderDetail();
      document.getElementById('input-email')?.focus();
      return;
    }
    if (event.target.closest('[data-fp-list]')) {
      if (window.history.state?.fpForm) window.history.back();
      else showList();
      return;
    }
    if (event.target.closest('[data-fp-reload]')) {
      window.location.reload();
    }
  });

  el.back?.addEventListener('click', () => {
    if (window.history.state?.fpForm) window.history.back();
    else showList();
  });

  window.addEventListener('popstate', (event) => {
    if (!isMobile()) return;
    const id = event.state?.fpForm;
    if (id) {
      state.activeFormId = id;
      renderNav();
      renderDetail();
      setView('detail');
    } else {
      showList();
    }
  });

  MOBILE_MEDIA.addEventListener?.('change', (event) => {
    if (!event.matches) setView('list');
  });

  el.nav?.addEventListener('keydown', (event) => {
    const current = event.target.closest('[role="tab"]');
    if (!current) return;
    const tabs = Array.from(el.nav.querySelectorAll('[role="tab"]')).filter((t) => t.offsetParent !== null);
    const index = tabs.indexOf(current);
    let next = index;
    if (event.key === 'ArrowDown' || event.key === 'ArrowRight') next = (index + 1) % tabs.length;
    else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = tabs.length - 1;
    else return;
    event.preventDefault();
    tabs.forEach((t, i) => t.setAttribute('tabindex', i === next ? '0' : '-1'));
    tabs[next].focus();
  });

  el.search?.addEventListener('input', () => {
    state.query = el.search.value || '';
    renderNav();
    if (isMobile() && el.layout?.dataset.view === 'detail' && state.query) showList();
  });

  loadForms();
})();
