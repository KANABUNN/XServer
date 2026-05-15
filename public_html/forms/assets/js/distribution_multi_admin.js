(() => {
  function esc(value = '') {
    if (typeof escapeHtml === 'function') return escapeHtml(value);
    return String(value ?? '').replace(/[&<>'"]/g, (ch) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;',
    }[ch]));
  }

  function fileSize(bytes = 0) {
    const size = Number(bytes || 0);
    if (!Number.isFinite(size) || size <= 0) return '';
    if (typeof formatFileSize === 'function') return formatFileSize(size);
    if (size >= 1024 * 1024) return `${(size / 1024 / 1024).toFixed(1)} MB`;
    if (size >= 1024) return `${Math.ceil(size / 1024)} KB`;
    return `${size} B`;
  }

  function csrfToken() {
    if (window.App && window.App.csrfToken) return String(window.App.csrfToken);
    if (window.FORMS_CSRF_TOKEN) return String(window.FORMS_CSRF_TOKEN);
    if (window.CSRF_TOKEN) return String(window.CSRF_TOKEN);
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta?.content) return meta.content;
    const input = document.querySelector('input[name="csrf_token"]');
    if (input?.value) return input.value;
    return '';
  }

  function notify(message, type = 'info') {
    if (typeof showFlashMessage === 'function') {
      showFlashMessage(message, type, { title: type === 'error' ? 'エラー' : '完了' });
      return;
    }
    const box = document.getElementById('admin-message');
    if (box) {
      box.className = `alert ${type === 'error' ? 'danger' : type}`;
      box.textContent = message;
      box.classList.remove('hidden');
      return;
    }
    alert(message);
  }

  function distributionFiles(settings = {}) {
    const files = [];
    if (Array.isArray(settings.distribution_files)) {
      settings.distribution_files.forEach((file) => {
        if (!file || !file.relative_path) return;
        files.push({
          id: String(file.id || ''),
          original_name: String(file.original_name || file.stored_name || '配布ファイル'),
          size_bytes: Number(file.size_bytes || 0),
          uploaded_at: String(file.uploaded_at || ''),
        });
      });
    }

    if (!files.length && settings.distribution_file_relative_path) {
      files.push({
        id: String(settings.distribution_file_id || ''),
        original_name: String(settings.distribution_file_original_name || '配布ファイル'),
        size_bytes: Number(settings.distribution_file_size_bytes || 0),
        uploaded_at: String(settings.distribution_file_uploaded_at || ''),
      });
    }
    return files;
  }

  function activeForm() {
    if (typeof getActiveForm === 'function') return getActiveForm();
    return adminState?.forms?.find((form) => form.id === adminState.activeFormId) || null;
  }

  function renderDistributionFileStatusMulti(form = null) {
    const root = document.getElementById('distribution-file-status');
    if (!root) return;
    const settings = form?.settings || {};
    const files = distributionFiles(settings);

    if (!form || !form.id) {
      root.className = 'distribution-file-status empty-state';
      root.innerHTML = '保存済みフォームを選択すると配布ファイルを登録できます。';
      return;
    }
    if (!files.length) {
      root.className = 'distribution-file-status empty-state';
      root.innerHTML = '配布ファイルは未設定です。';
      return;
    }

    root.className = 'distribution-file-status file-ready-card';
    root.innerHTML = `
      <div class="stack-list distribution-admin-file-list">
        ${files.map((file, index) => {
          const params = new URLSearchParams({ form_id: String(form.id) });
          if (file.id) params.set('file_id', file.id);
          const meta = [fileSize(file.size_bytes), file.uploaded_at].filter(Boolean).join(' / ');
          return `
            <div class="list-item-header compact-row distribution-admin-file-item">
              <div class="file-ready-main">
                <strong>${esc(file.original_name || `配布ファイル${index + 1}`)}</strong>
                ${meta ? `<div class="small-note">${esc(meta)}</div>` : ''}
              </div>
              <div class="inline-actions">
                <a class="btn btn-small" href="api/admin_download_form_asset.php?${params.toString()}" target="_blank" rel="noopener">ダウンロード確認</a>
                <button type="button" class="btn danger btn-small" data-delete-distribution-file="${esc(file.id)}">削除</button>
              </div>
            </div>
          `;
        }).join('')}
      </div>
    `;
  }

  function applyUpdatedForm(result = {}) {
    if (typeof mergeUpdatedForm === 'function') {
      mergeUpdatedForm(result);
      return;
    }
    if (result.form?.id && window.adminState?.forms) {
      const index = adminState.forms.findIndex((form) => form.id === result.form.id);
      if (index >= 0) adminState.forms[index] = result.form;
      else adminState.forms.push(result.form);
      adminState.activeFormId = result.form.id;
    }
    renderDistributionFileStatusMulti(result.form || activeForm());
  }

  async function postForm(url, formData) {
    const response = await fetch(url, { method: 'POST', body: formData });
    const result = await response.json().catch(() => ({ ok: false, message: '応答の解析に失敗しました。' }));
    if (!response.ok || !result.ok) {
      throw new Error(result.message || '処理に失敗しました。');
    }
    return result;
  }

  async function uploadDistributionFiles() {
    const form = activeForm();
    const input = document.getElementById('distribution-file-input');
    if (!form?.id) {
      notify('保存済みフォームを選択してからアップロードしてください。', 'error');
      return;
    }
    if (!input?.files?.length) {
      notify('アップロードする配布ファイルを選択してください。', 'error');
      return;
    }

    const button = document.getElementById('upload-distribution-files-button') || document.getElementById('upload-distribution-file-button');
    const data = new FormData();
    data.append('csrf_token', csrfToken());
    data.append('form_id', String(form.id));
    Array.from(input.files).forEach((file) => data.append('distribution_files[]', file));

    try {
      if (button) button.disabled = true;
      const result = await postForm('api/admin_upload_distribution_files.php', data);
      input.value = '';
      applyUpdatedForm(result);
      notify(result.message || '配布ファイルを保存しました。', 'success');
    } catch (error) {
      notify(error.message || '配布ファイルの保存に失敗しました。', 'error');
    } finally {
      if (button) button.disabled = false;
    }
  }

  async function deleteDistributionFile(fileId) {
    const form = activeForm();
    if (!form?.id || !fileId) return;
    if (!confirm('この配布ファイルを削除します。よろしいですか？')) return;

    const data = new FormData();
    data.append('csrf_token', csrfToken());
    data.append('form_id', String(form.id));
    data.append('file_id', String(fileId));

    try {
      const result = await postForm('api/admin_delete_distribution_file.php', data);
      applyUpdatedForm(result);
      notify(result.message || '配布ファイルを削除しました。', 'success');
    } catch (error) {
      notify(error.message || '配布ファイルの削除に失敗しました。', 'error');
    }
  }

  try {
    renderDistributionFileStatus = renderDistributionFileStatusMulti;
  } catch (error) {
    window.renderDistributionFileStatus = renderDistributionFileStatusMulti;
  }
  window.renderDistributionFileStatus = renderDistributionFileStatusMulti;

  function bind() {
    const input = document.getElementById('distribution-file-input');
    if (input) input.setAttribute('multiple', 'multiple');
    const uploadButton = document.getElementById('upload-distribution-files-button') || document.getElementById('upload-distribution-file-button');
    uploadButton?.setAttribute('type', 'button');
    uploadButton?.addEventListener('click', (event) => {
      event.preventDefault();
      uploadDistributionFiles();
    });
    document.addEventListener('click', (event) => {
      const button = event.target.closest('[data-delete-distribution-file]');
      if (!button) return;
      event.preventDefault();
      deleteDistributionFile(button.dataset.deleteDistributionFile || '');
    });
    renderDistributionFileStatusMulti(activeForm());
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();


/* ---- Form finder enhancement: modal selector, status filters, recent forms ---- */
(() => {
  const STORAGE_KEY = 'formsAdmin.recentFormIds.v1';
  const SIDEBAR_LIMIT = 8;
  const RECENT_LIMIT = 10;
  const state = {
    sidebarFilter: 'all',
    modalFilter: 'all',
    modalQuery: '',
    modalSort: 'recent',
    modalOpen: false,
  };

  const FILTERS = [
    ['all', 'すべて'],
    ['active', '公開中'],
    ['inactive', '非公開'],
    ['open', '受付中'],
    ['scheduled', '受付前'],
    ['closed', '受付終了'],
    ['distribution', '配布資料あり'],
  ];

  function esc(value = '') {
    if (typeof escapeHtml === 'function') return escapeHtml(String(value ?? ''));
    return String(value ?? '').replace(/[&<>'"]/g, (ch) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      "'": '&#039;',
      '"': '&quot;',
    }[ch]));
  }

  function forms() {
    try {
      return (typeof adminState !== 'undefined' && Array.isArray(adminState.forms)) ? adminState.forms : [];
    } catch (error) {
      return [];
    }
  }

  function activeFormId() {
    try {
      return Number(adminState.activeFormId || 0);
    } catch (error) {
      return 0;
    }
  }

  function recentIds() {
    try {
      const parsed = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
      return Array.isArray(parsed) ? parsed.map((id) => Number(id)).filter(Boolean) : [];
    } catch (error) {
      return [];
    }
  }

  function saveRecentIds(ids) {
    const normalized = Array.from(new Set(ids.map((id) => Number(id)).filter(Boolean))).slice(0, RECENT_LIMIT);
    localStorage.setItem(STORAGE_KEY, JSON.stringify(normalized));
  }

  function rememberRecentForm(formId) {
    const id = Number(formId || 0);
    if (!id) return;
    saveRecentIds([id, ...recentIds().filter((item) => item !== id)]);
  }

  function formText(form) {
    return [form.name, form.slug, form.description]
      .filter(Boolean)
      .join(' ')
      .toLowerCase();
  }

  function availabilityStatus(form) {
    if (!form || !form.is_active) return 'inactive';
    return form.availability?.status || 'always_open';
  }

  function hasDistribution(form) {
    const settings = form?.settings || {};
    return Boolean(
      settings.distribution_enabled ||
      settings.distribution_file_relative_path ||
      (Array.isArray(settings.distribution_files) && settings.distribution_files.length > 0)
    );
  }

  function matchKeyword(form, keyword) {
    const normalized = String(keyword || '').trim().toLowerCase();
    if (!normalized) return true;
    return formText(form).includes(normalized);
  }

  function matchFilter(form, filter) {
    switch (filter) {
      case 'active':
        return Boolean(form.is_active);
      case 'inactive':
        return !form.is_active;
      case 'open':
        return Boolean(form.is_active) && ['open', 'always_open'].includes(availabilityStatus(form));
      case 'scheduled':
        return Boolean(form.is_active) && availabilityStatus(form) === 'scheduled';
      case 'closed':
        return Boolean(form.is_active) && availabilityStatus(form) === 'closed';
      case 'distribution':
        return hasDistribution(form);
      case 'all':
      default:
        return true;
    }
  }

  function filterCount(filter) {
    return forms().filter((form) => matchFilter(form, filter)).length;
  }

  function compareText(a, b) {
    return String(a || '').localeCompare(String(b || ''), 'ja');
  }

  function timestamp(value) {
    const parsed = Date.parse(String(value || '').replace(' ', 'T'));
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function sortedForms(list, sortKey = 'recent') {
    const recent = recentIds();
    const recentIndex = new Map(recent.map((id, index) => [Number(id), index]));
    const base = [...list];
    base.sort((a, b) => {
      if (sortKey === 'recent') {
        const aRecent = recentIndex.has(Number(a.id)) ? recentIndex.get(Number(a.id)) : 9999;
        const bRecent = recentIndex.has(Number(b.id)) ? recentIndex.get(Number(b.id)) : 9999;
        if (aRecent !== bRecent) return aRecent - bRecent;
      }
      if (sortKey === 'updated') {
        const diff = timestamp(b.updated_at) - timestamp(a.updated_at);
        if (diff !== 0) return diff;
      }
      if (sortKey === 'created') {
        const diff = timestamp(b.created_at) - timestamp(a.created_at);
        if (diff !== 0) return diff;
      }
      if (sortKey === 'name') {
        const diff = compareText(a.name, b.name);
        if (diff !== 0) return diff;
      }
      const sortDiff = Number(a.sort_order || 0) - Number(b.sort_order || 0);
      if (sortDiff !== 0) return sortDiff;
      return Number(a.id || 0) - Number(b.id || 0);
    });
    return base;
  }

  function statusPills(form) {
    const publicLabel = form.is_active ? '公開中' : '非公開';
    const periodLabel = form.is_active
      ? (form.availability?.label || ({ always_open: '常時公開', open: '受付中', scheduled: '受付前', closed: '受付終了' }[availabilityStatus(form)] || '公開状態'))
      : '非公開';
    const parts = [
      `<span class="status-badge ${form.is_active ? 'status-resolved' : 'status-on-hold'}">${esc(publicLabel)}</span>`,
      `<span class="pill period-pill period-${esc(availabilityStatus(form))}">${esc(periodLabel)}</span>`,
      `<span class="pill">slug: ${esc(form.slug)}</span>`,
    ];
    if (hasDistribution(form)) parts.push('<span class="pill">配布資料あり</span>');
    return parts.join(' ');
  }

  function formCardHtml(form, mode = 'sidebar') {
    const isSelected = Number(form.id) === activeFormId();
    const fieldCount = Array.isArray(form.fields) ? form.fields.length : 0;
    const updated = form.updated_at ? `<span class="small-note">更新 ${esc(form.updated_at)}</span>` : '';
    const actionAttr = mode === 'modal' ? 'data-finder-select-form' : 'data-select-form';
    return `
      <article class="form-nav-item finder-form-card ${isSelected ? 'selected' : ''}">
        <button type="button" class="form-nav-button" ${actionAttr}="${esc(form.id)}">
          <div class="form-nav-main">
            <div>
              <strong>${esc(form.name)}</strong>
              <div class="meta-line">${statusPills(form)}</div>
            </div>
            <div class="finder-card-side">
              <span class="small-note">項目 ${esc(fieldCount)}件</span>
              ${updated}
            </div>
          </div>
          ${form.description ? `<div class="small-note finder-card-description">${esc(form.description)}</div>` : ''}
        </button>
      </article>
    `;
  }

  function injectStyle() {
    if (document.getElementById('form-finder-enhancement-style')) return;
    const style = document.createElement('style');
    style.id = 'form-finder-enhancement-style';
    style.textContent = `
      .form-finder-toolbar { display: grid; gap: .65rem; margin: .85rem 0; }
      .form-finder-filter-row { display: flex; flex-wrap: wrap; gap: .4rem; }
      .form-finder-chip { border: 1px solid rgba(37, 99, 235, .2); background: rgba(37, 99, 235, .06); color: inherit; border-radius: 999px; padding: .38rem .62rem; font-size: .82rem; cursor: pointer; }
      .form-finder-chip.active { background: #2563eb; color: #fff; border-color: #2563eb; box-shadow: 0 8px 20px rgba(37, 99, 235, .18); }
      .form-finder-open-button { width: 100%; justify-content: center; }
      .form-nav-list.finder-condensed { max-height: none; overflow: visible; }
      .finder-sidebar-caption { display: flex; justify-content: space-between; align-items: center; gap: .5rem; margin: .45rem 0 .55rem; }
      .finder-card-side { display: grid; justify-items: end; gap: .15rem; min-width: 5.8rem; }
      .finder-card-description { margin-top: .35rem; text-align: left; }
      .form-finder-backdrop { position: fixed; inset: 0; z-index: 1000; display: grid; place-items: center; padding: 1.25rem; background: rgba(15, 23, 42, .48); backdrop-filter: blur(5px); }
      .form-finder-backdrop.hidden { display: none; }
      .form-finder-dialog { width: min(1080px, 96vw); max-height: min(86vh, 920px); display: grid; grid-template-rows: auto auto 1fr; gap: 1rem; overflow: hidden; background: #fff; border-radius: 24px; box-shadow: 0 24px 70px rgba(15, 23, 42, .25); padding: 1.1rem; }
      .form-finder-dialog-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; }
      .form-finder-dialog-header h2 { margin: 0; }
      .form-finder-dialog-controls { display: grid; grid-template-columns: minmax(220px, 1fr) 180px; gap: .75rem; align-items: end; }
      .form-finder-dialog-controls label { display: grid; gap: .3rem; }
      .form-finder-dialog-controls input, .form-finder-dialog-controls select { width: 100%; }
      .form-finder-result-summary { display: flex; justify-content: space-between; align-items: center; gap: .8rem; flex-wrap: wrap; }
      .form-finder-result-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: .75rem; overflow: auto; padding: .1rem .25rem .4rem; }
      .form-finder-result-list .form-nav-item { height: 100%; }
      .form-finder-result-list .form-nav-button { height: 100%; }
      .form-finder-empty { grid-column: 1 / -1; }
      body.form-finder-modal-open { overflow: hidden; }
      @media (max-width: 720px) {
        .form-finder-backdrop { padding: .55rem; align-items: stretch; }
        .form-finder-dialog { width: 100%; max-height: 96vh; border-radius: 18px; }
        .form-finder-dialog-controls { grid-template-columns: 1fr; }
        .form-finder-result-list { grid-template-columns: 1fr; }
      }
    `;
    document.head.appendChild(style);
  }

  function ensureToolbar() {
    const sidebarCard = document.querySelector('.admin-sidebar .sidebar-card');
    const stats = document.getElementById('forms-overall-stats');
    if (!sidebarCard || !stats || document.getElementById('form-finder-toolbar')) return;

    const toolbar = document.createElement('div');
    toolbar.id = 'form-finder-toolbar';
    toolbar.className = 'form-finder-toolbar';
    toolbar.innerHTML = `
      <button type="button" class="btn primary form-finder-open-button" id="open-form-finder-button">すべてのフォームを探す</button>
      <div class="form-finder-filter-row" id="sidebar-form-filter-row" aria-label="フォーム状態フィルタ"></div>
      <div class="small-note">左側は最近使ったフォームと条件一致分だけを表示します。全件検索は上のボタンから開きます。</div>
    `;
    stats.insertAdjacentElement('afterend', toolbar);
  }

  function ensureModal() {
    if (document.getElementById('form-finder-modal')) return;
    const modal = document.createElement('div');
    modal.id = 'form-finder-modal';
    modal.className = 'form-finder-backdrop hidden';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'form-finder-title');
    modal.innerHTML = `
      <section class="form-finder-dialog" role="document">
        <header class="form-finder-dialog-header">
          <div>
            <p class="eyebrow">Form Finder</p>
            <h2 id="form-finder-title">フォームを探す</h2>
            <p class="small-note">名前・slug・説明で検索し、公開状態や受付状態で絞り込めます。</p>
          </div>
          <button type="button" class="btn" data-close-form-finder>閉じる</button>
        </header>
        <div class="form-finder-dialog-controls">
          <label>
            <span>検索</span>
            <input type="search" id="form-finder-modal-query" placeholder="フォーム名・slug・説明で検索">
          </label>
          <label>
            <span>並び替え</span>
            <select id="form-finder-sort">
              <option value="recent">最近使った順</option>
              <option value="sort_order">表示順</option>
              <option value="updated">更新が新しい順</option>
              <option value="created">作成が新しい順</option>
              <option value="name">名前順</option>
            </select>
          </label>
        </div>
        <div class="form-finder-result-summary">
          <div class="form-finder-filter-row" id="modal-form-filter-row" aria-label="フォーム状態フィルタ"></div>
          <div id="form-finder-result-count" class="small-note"></div>
        </div>
        <div id="form-finder-result-list" class="form-finder-result-list"></div>
      </section>
    `;
    document.body.appendChild(modal);
  }

  function renderFilterRow(rootId, activeFilter, dataName) {
    const root = document.getElementById(rootId);
    if (!root) return;
    root.innerHTML = FILTERS.map(([key, label]) => `
      <button type="button" class="form-finder-chip ${key === activeFilter ? 'active' : ''}" ${dataName}="${esc(key)}">
        ${esc(label)} <span>${esc(filterCount(key))}</span>
      </button>
    `).join('');
  }

  function sidebarList() {
    const all = forms();
    const keyword = (typeof adminState !== 'undefined' ? adminState.formSearch : '').trim();
    let list = all.filter((form) => matchKeyword(form, keyword) && matchFilter(form, state.sidebarFilter));

    if (!keyword && state.sidebarFilter === 'all') {
      const active = activeFormId();
      const recent = recentIds();
      const preferred = [active, ...recent, ...all.map((form) => Number(form.id))]
        .filter(Boolean);
      const seen = new Set();
      list = preferred
        .map((id) => all.find((form) => Number(form.id) === Number(id)))
        .filter(Boolean)
        .filter((form) => {
          if (seen.has(Number(form.id))) return false;
          seen.add(Number(form.id));
          return true;
        })
        .slice(0, SIDEBAR_LIMIT);
    } else {
      list = sortedForms(list, 'sort_order').slice(0, SIDEBAR_LIMIT);
    }
    return list;
  }

  function renderEnhancedFormList() {
    ensureToolbar();
    ensureModal();
    renderFilterRow('sidebar-form-filter-row', state.sidebarFilter, 'data-sidebar-form-filter');

    const root = document.getElementById('form-list');
    if (!root) return;
    root.classList.add('finder-condensed');

    const keyword = (typeof adminState !== 'undefined' ? adminState.formSearch : '').trim();
    const list = sidebarList();
    const label = keyword || state.sidebarFilter !== 'all' ? '条件に一致するフォーム' : '最近使うフォーム';
    const totalMatch = forms().filter((form) => matchKeyword(form, keyword) && matchFilter(form, state.sidebarFilter)).length;

    if (!list.length) {
      root.innerHTML = '<div class="empty-state">条件に一致するフォームはありません。「すべてのフォームを探す」から条件を変えてください。</div>';
      renderModalResults();
      return;
    }

    root.innerHTML = `
      <div class="finder-sidebar-caption">
        <strong>${esc(label)}</strong>
        <span class="small-note">${esc(list.length)} / ${esc(totalMatch)}件</span>
      </div>
      ${list.map((form) => formCardHtml(form, 'sidebar')).join('')}
      ${totalMatch > list.length ? '<button type="button" class="btn form-finder-open-button" id="open-form-finder-button-bottom">残りのフォームも探す</button>' : ''}
    `;
    renderModalResults();
  }

  function modalFilteredForms() {
    const list = forms().filter((form) => matchKeyword(form, state.modalQuery) && matchFilter(form, state.modalFilter));
    return sortedForms(list, state.modalSort);
  }

  function renderModalResults() {
    renderFilterRow('modal-form-filter-row', state.modalFilter, 'data-modal-form-filter');
    const root = document.getElementById('form-finder-result-list');
    const count = document.getElementById('form-finder-result-count');
    if (!root || !count) return;

    const list = modalFilteredForms();
    count.textContent = `${list.length}件 / 全${forms().length}件`;
    root.innerHTML = list.length
      ? list.map((form) => formCardHtml(form, 'modal')).join('')
      : '<div class="empty-state form-finder-empty">条件に一致するフォームはありません。</div>';
  }

  function openModal() {
    ensureModal();
    state.modalOpen = true;
    const modal = document.getElementById('form-finder-modal');
    modal?.classList.remove('hidden');
    document.body.classList.add('form-finder-modal-open');
    const sidebarSearch = document.getElementById('form-search')?.value || '';
    if (!state.modalQuery && sidebarSearch) state.modalQuery = sidebarSearch;
    const queryInput = document.getElementById('form-finder-modal-query');
    const sortInput = document.getElementById('form-finder-sort');
    if (queryInput) queryInput.value = state.modalQuery;
    if (sortInput) sortInput.value = state.modalSort;
    renderModalResults();
    setTimeout(() => queryInput?.focus(), 0);
  }

  function closeModal() {
    state.modalOpen = false;
    document.getElementById('form-finder-modal')?.classList.add('hidden');
    document.body.classList.remove('form-finder-modal-open');
  }

  async function selectFormFromFinder(formId) {
    const id = Number(formId || 0);
    if (!id || typeof adminState === 'undefined') return;
    rememberRecentForm(id);
    adminState.activeFormId = id;
    adminState.activeEntryId = 0;
    adminState.activeEntryHistory = null;

    const form = (typeof getActiveForm === 'function') ? getActiveForm() : forms().find((item) => Number(item.id) === id);
    if (typeof renderFormList === 'function') renderFormList();
    if (typeof renderWorkspaceHeader === 'function') renderWorkspaceHeader(form);
    if (typeof renderSelectedFormSidebar === 'function') renderSelectedFormSidebar(form);
    if (typeof renderOverview === 'function') renderOverview(form);
    if (typeof fillEditor === 'function') fillEditor(form);
    if (form && typeof switchWorkspaceTab === 'function') switchWorkspaceTab('overview');
    closeModal();
    if (form && typeof loadEntries === 'function') {
      try {
        await loadEntries(form.id, null, false);
      } catch (error) {
        console.error(error);
      }
    }
  }

  function bindFinderEvents() {
    document.addEventListener('click', (event) => {
      if (event.target.closest('#open-form-finder-button') || event.target.closest('#open-form-finder-button-bottom')) {
        event.preventDefault();
        openModal();
        return;
      }

      const sidebarFilterButton = event.target.closest('[data-sidebar-form-filter]');
      if (sidebarFilterButton) {
        event.preventDefault();
        state.sidebarFilter = sidebarFilterButton.getAttribute('data-sidebar-form-filter') || 'all';
        renderEnhancedFormList();
        return;
      }

      const modalFilterButton = event.target.closest('[data-modal-form-filter]');
      if (modalFilterButton) {
        event.preventDefault();
        state.modalFilter = modalFilterButton.getAttribute('data-modal-form-filter') || 'all';
        renderModalResults();
        return;
      }

      const modalSelectButton = event.target.closest('[data-finder-select-form]');
      if (modalSelectButton) {
        event.preventDefault();
        selectFormFromFinder(modalSelectButton.getAttribute('data-finder-select-form'));
        return;
      }

      const sidebarSelectButton = event.target.closest('[data-select-form]');
      if (sidebarSelectButton) {
        rememberRecentForm(sidebarSelectButton.getAttribute('data-select-form'));
        setTimeout(() => {
          renderEnhancedFormList();
          renderModalResults();
        }, 0);
        return;
      }

      if (event.target.closest('[data-close-form-finder]')) {
        event.preventDefault();
        closeModal();
        return;
      }

      const modal = document.getElementById('form-finder-modal');
      if (modal && event.target === modal) {
        closeModal();
      }
    });

    document.addEventListener('input', (event) => {
      if (event.target?.id === 'form-finder-modal-query') {
        state.modalQuery = event.target.value || '';
        renderModalResults();
      }
    });

    document.addEventListener('change', (event) => {
      if (event.target?.id === 'form-finder-sort') {
        state.modalSort = event.target.value || 'recent';
        renderModalResults();
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && state.modalOpen) {
        closeModal();
        return;
      }
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        const active = document.activeElement;
        const isTyping = active && ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName);
        if (!isTyping) {
          event.preventDefault();
          openModal();
        }
      }
    });
  }

  function patchRenderFormList() {
    if (typeof renderFormList !== 'function') return;
    if (renderFormList.__formFinderEnhanced) return;
    const enhanced = function enhancedRenderFormList() {
      renderEnhancedFormList();
    };
    enhanced.__formFinderEnhanced = true;
    try {
      renderFormList = enhanced;
      window.renderFormList = enhanced;
    } catch (error) {
      window.renderFormList = enhanced;
    }
  }

  function init() {
    injectStyle();
    ensureToolbar();
    ensureModal();
    patchRenderFormList();
    bindFinderEvents();
    renderEnhancedFormList();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
/* ---- /Form finder enhancement ---- */
