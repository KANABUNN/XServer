const STATUS_DEFINITIONS = {
  all: { label: 'すべて', className: 'status-all' },
  new: { label: '未確認', className: 'status-new' },
  reviewing: { label: '確認中', className: 'status-reviewing' },
  on_hold: { label: '保留', className: 'status-on-hold' },
  resolved: { label: '対応済', className: 'status-resolved' },
  rejected: { label: '差戻し', className: 'status-rejected' },
};

const RECENT_FORMS_LIMIT = 3;
const RECENT_FORMS_STORAGE_KEY = 'forms.admin.recentFormIds.v2';
const EXPANDED_FOLDERS_STORAGE_KEY = 'forms.admin.expandedFolderIds.v2';
const WORKSPACE_TAB_STORAGE_KEY = 'forms.admin.activeWorkspaceTab.v2';
const WORKSPACE_TABS = new Set(['overview', 'settings', 'fields', 'responses']);

const adminState = {
  forms: [],
  folders: [],
  activeFormId: 0,
  activeWorkspaceTab: readSessionValue(WORKSPACE_TAB_STORAGE_KEY, 'overview'),
  formSearch: '',
  recentFormIds: readNumberList(RECENT_FORMS_STORAGE_KEY),
  expandedFolderIds: new Set(readNumberList(EXPANDED_FOLDERS_STORAGE_KEY)),
  folderExpansionInitialized: false,
  entriesResult: null,
  activeEntryId: 0,
  activeEntryHistory: null,
};

const formContextMenuState = {
  formId: 0,
  anchor: null,
  view: 'main',
  clientX: 0,
  clientY: 0,
  busy: false,
  error: '',
  renameValue: '',
  openedByKeyboard: false,
};

function readSessionValue(key, fallback) {
  try {
    return window.sessionStorage.getItem(key) || fallback;
  } catch (error) {
    return fallback;
  }
}

function readNumberList(key) {
  try {
    const parsed = JSON.parse(window.localStorage.getItem(key) || '[]');
    if (!Array.isArray(parsed)) return [];
    return parsed.map(Number).filter((value) => Number.isInteger(value) && value > 0);
  } catch (error) {
    return [];
  }
}

function writeNumberList(key, values) {
  try {
    window.localStorage.setItem(key, JSON.stringify(Array.from(values)));
  } catch (error) {
    // プライベートブラウズ等で保存できない場合も、現在の画面内では継続する。
  }
}

function getFolder(folderId) {
  return adminState.folders.find((folder) => folder.id === Number(folderId)) || null;
}

function getFolderPath(folderId) {
  const names = [];
  const visited = new Set();
  let current = getFolder(folderId);
  while (current && !visited.has(current.id)) {
    visited.add(current.id);
    names.unshift(current.name);
    current = current.parent_id ? getFolder(current.parent_id) : null;
  }
  return names.join(' / ') || '未分類';
}

function getFolderDescendantIds(folderId) {
  const result = new Set([Number(folderId)]);
  let changed = true;
  while (changed) {
    changed = false;
    adminState.folders.forEach((folder) => {
      if (folder.parent_id && result.has(folder.parent_id) && !result.has(folder.id)) {
        result.add(folder.id);
        changed = true;
      }
    });
  }
  return result;
}

function buildFolderOptions(selectedId = null, rootLabel = '未分類', excludedIds = new Set()) {
  const childrenByParent = new Map();
  adminState.folders.forEach((folder) => {
    const parentKey = folder.parent_id || 0;
    if (!childrenByParent.has(parentKey)) childrenByParent.set(parentKey, []);
    childrenByParent.get(parentKey).push(folder);
  });
  childrenByParent.forEach((folders) => folders.sort((a, b) => (
    Number(a.sort_order || 0) - Number(b.sort_order || 0)
    || String(a.name).localeCompare(String(b.name), 'ja')
    || a.id - b.id
  )));

  const options = [`<option value="">${escapeHtml(rootLabel)}</option>`];
  const visited = new Set();
  const appendChildren = (parentId, depth) => {
    (childrenByParent.get(parentId) || []).forEach((folder) => {
      if (visited.has(folder.id) || excludedIds.has(folder.id)) return;
      visited.add(folder.id);
      const prefix = depth ? `${'　'.repeat(depth)}└ ` : '';
      const selected = Number(selectedId) === folder.id ? ' selected' : '';
      options.push(`<option value="${folder.id}"${selected}>${escapeHtml(prefix + folder.name)}</option>`);
      appendChildren(folder.id, depth + 1);
    });
  };
  appendChildren(0, 0);

  // 壊れた親参照が存在しても管理画面から救済できるよう、孤立フォルダーも末尾へ出す。
  adminState.folders.forEach((folder) => {
    if (visited.has(folder.id) || excludedIds.has(folder.id)) return;
    const selected = Number(selectedId) === folder.id ? ' selected' : '';
    options.push(`<option value="${folder.id}"${selected}>${escapeHtml(folder.name)}</option>`);
  });
  return options.join('');
}

function refreshFolderSelects() {
  const currentFormFolderId = getActiveForm()?.folder_id || null;
  const formSelect = document.getElementById('form-folder-select');
  if (formSelect) formSelect.innerHTML = buildFolderOptions(currentFormFolderId);
  const newFormSelect = document.getElementById('new-form-folder-select');
  if (newFormSelect) newFormSelect.innerHTML = buildFolderOptions(newFormSelect.value || null);
}

function rememberFormUsage(formId) {
  const id = Number(formId);
  if (!id) return;
  adminState.recentFormIds = [id, ...adminState.recentFormIds.filter((item) => item !== id)]
    .slice(0, RECENT_FORMS_LIMIT);
  writeNumberList(RECENT_FORMS_STORAGE_KEY, adminState.recentFormIds);
}

function persistExpandedFolders() {
  writeNumberList(EXPANDED_FOLDERS_STORAGE_KEY, adminState.expandedFolderIds);
}

function expandFolderPath(folderId) {
  const visited = new Set();
  let currentId = Number(folderId || 0);
  adminState.expandedFolderIds.add(currentId);
  while (currentId > 0 && !visited.has(currentId)) {
    visited.add(currentId);
    const folder = getFolder(currentId);
    if (!folder?.parent_id) break;
    currentId = folder.parent_id;
    adminState.expandedFolderIds.add(currentId);
  }
  persistExpandedFolders();
}

function getStatusMeta(status) {
  return STATUS_DEFINITIONS[status] || STATUS_DEFINITIONS.new;
}

function switchWorkspaceTab(tab) {
  const nextTab = WORKSPACE_TABS.has(tab) ? tab : 'overview';
  adminState.activeWorkspaceTab = nextTab;
  try {
    window.sessionStorage.setItem(WORKSPACE_TAB_STORAGE_KEY, nextTab);
  } catch (error) {
    // 状態保存が使えなくてもタブ切替自体は継続する。
  }
  document.querySelectorAll('[data-workspace-tab]').forEach((button) => {
    const isActive = button.dataset.workspaceTab === nextTab;
    button.classList.toggle('active', isActive);
    button.setAttribute('aria-selected', isActive ? 'true' : 'false');
  });
  document.querySelectorAll('[data-workspace-panel]').forEach((panel) => {
    panel.classList.toggle('hidden', panel.dataset.workspacePanel !== nextTab);
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

function availabilityBadge(availability = {}, isActive = true) {
  if (!isActive) {
    return '<span class="pill period-pill period-inactive">非公開</span>';
  }
  const status = availability.status || 'always_open';
  const labelMap = {
    always_open: '常時公開',
    open: '公開期間内',
    scheduled: '受付前',
    closed: '受付終了',
    inactive: '非公開',
  };
  return `<span class="pill period-pill period-${escapeHtml(status)}">${escapeHtml(availability.label || labelMap[status] || '公開状態')}</span>`;
}

function formatAvailabilityWindow(availability = {}, settings = {}) {
  const startDate = availability.start_date || settings.public_start_date || '';
  const startTime = availability.start_time || settings.public_start_time || '';
  const endDate = availability.end_date || settings.public_end_date || '';
  const endTime = availability.end_time || settings.public_end_time || '';

  const startLabel = startDate ? `${startDate}${startTime ? ` ${startTime}` : ''}` : '';
  const endLabel = endDate ? `${endDate}${endTime ? ` ${endTime}` : ''}` : '';

  if (!startLabel && !endLabel) {
    return '常時公開';
  }
  if (startLabel && endLabel) {
    return `${startLabel} 〜 ${endLabel}`;
  }
  return startLabel ? `${startLabel} 以降` : `${endLabel} まで`;
}

function syncDeleteButtonState(form = null) {
  const button = document.getElementById('delete-form-button');
  if (!button) return;
  button.disabled = !form || !form.id;
  button.textContent = form?.id ? 'このフォームを削除' : '保存済みフォームを選択すると削除できます';
}

function formatFileSize(bytes = 0) {
  const size = Number(bytes || 0);
  if (!Number.isFinite(size) || size <= 0) return '';
  if (size >= 1024 * 1024) return `${(size / 1024 / 1024).toFixed(1)} MB`;
  if (size >= 1024) return `${Math.ceil(size / 1024)} KB`;
  return `${size} B`;
}

function renderDistributionFileStatus(form = null) {
  const root = document.getElementById('distribution-file-status');
  if (!root) return;
  const settings = form?.settings || {};
  const hasFile = Boolean(settings.distribution_file_relative_path);
  if (!form || !form.id) {
    root.className = 'distribution-file-status empty-state';
    root.innerHTML = '保存済みフォームを選択すると配布ファイルを登録できます。';
    return;
  }
  if (!hasFile) {
    root.className = 'distribution-file-status empty-state';
    root.innerHTML = '配布ファイルは未設定です。';
    return;
  }
  const sizeText = formatFileSize(settings.distribution_file_size_bytes);
  const uploadedAt = settings.distribution_file_uploaded_at ? ` / ${escapeHtml(settings.distribution_file_uploaded_at)}` : '';
  root.className = 'distribution-file-status file-ready-card';
  root.innerHTML = `
    <div class="file-ready-main">
      <strong>${escapeHtml(settings.distribution_file_original_name || '配布ファイル')}</strong>
      <div class="small-note">${escapeHtml([sizeText, uploadedAt.replace(/^ \/ /, '')].filter(Boolean).join(' / '))}</div>
    </div>
    <div class="inline-actions">
      <a class="btn btn-small" href="api/admin_download_form_asset.php?form_id=${encodeURIComponent(String(form.id))}&csrf_token=${encodeURIComponent(csrfToken)}" target="_blank" rel="noopener">ダウンロード確認</a>
      <button type="button" class="btn danger btn-small" id="delete-distribution-file-button">配布ファイルを削除</button>
    </div>
  `;
}

function mergeUpdatedForm(result = {}) {
  if (Array.isArray(result.forms)) {
    adminState.forms = result.forms;
  } else if (result.form?.id) {
    const index = adminState.forms.findIndex((form) => form.id === result.form.id);
    if (index >= 0) adminState.forms[index] = result.form;
  }
  if (result.form?.id) {
    adminState.activeFormId = result.form.id;
  }
  const activeForm = result.form || getActiveForm();
  renderOverallStats();
  renderFormList();
  renderWorkspaceHeader(activeForm);
  renderSelectedFormSidebar(activeForm);
  renderOverview(activeForm);
  renderDistributionFileStatus(activeForm);
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

function formHoverDetailsHtml(form) {
  const availability = form.availability || {};
  const settings = form.settings || {};
  const statusLabel = form.is_active ? (availability.label || '公開中') : '非公開';
  const rows = [
    ['状態', statusLabel],
    ['保存先', getFolderPath(form.folder_id)],
    ['公開期間', formatAvailabilityWindow(availability, settings)],
    ['slug', form.slug || '未設定'],
    ['追加項目', `${Array.isArray(form.fields) ? form.fields.length : 0}件`],
  ];

  return `
    <strong class="form-hover-tooltip-title">${escapeHtml(form.name)}</strong>
    <span class="form-hover-tooltip-rows">
      ${rows.map(([label, value]) => `
        <span class="form-hover-tooltip-row">
          <span class="form-hover-tooltip-label">${escapeHtml(label)}</span>
          <span class="form-hover-tooltip-value">${escapeHtml(value)}</span>
        </span>
      `).join('')}
    </span>
    ${form.description
      ? `<span class="form-hover-tooltip-description">${escapeHtml(form.description)}</span>`
      : '<span class="form-hover-tooltip-description muted-description">説明は未設定です。</span>'}
    <span class="form-hover-tooltip-context-hint">右クリックで公開設定・移動・名前変更</span>
  `;
}

function ensureFormHoverTooltip() {
  let tooltip = document.getElementById('form-hover-tooltip');
  if (tooltip) return tooltip;
  tooltip = document.createElement('div');
  tooltip.id = 'form-hover-tooltip';
  tooltip.className = 'form-hover-tooltip';
  tooltip.setAttribute('role', 'tooltip');
  tooltip.hidden = true;
  document.body.appendChild(tooltip);
  return tooltip;
}

function positionFormHoverTooltip(button, tooltip) {
  const buttonRect = button.getBoundingClientRect();
  const tooltipRect = tooltip.getBoundingClientRect();
  const viewportWidth = document.documentElement.clientWidth;
  const viewportHeight = document.documentElement.clientHeight;
  const gap = 12;
  const edge = 12;
  let placement = 'right';
  let left = buttonRect.right + gap;
  let top = buttonRect.top + ((buttonRect.height - tooltipRect.height) / 2);

  if (viewportWidth < 720) {
    placement = 'below';
    left = Math.min(Math.max(edge, buttonRect.left), Math.max(edge, viewportWidth - tooltipRect.width - edge));
    top = buttonRect.bottom + 8;
    if (top + tooltipRect.height > viewportHeight - edge) {
      placement = 'above';
      top = buttonRect.top - tooltipRect.height - 8;
    }
  } else if (left + tooltipRect.width > viewportWidth - edge) {
    placement = 'left';
    left = buttonRect.left - tooltipRect.width - gap;
  }

  left = Math.min(Math.max(edge, left), Math.max(edge, viewportWidth - tooltipRect.width - edge));
  top = Math.min(Math.max(edge, top), Math.max(edge, viewportHeight - tooltipRect.height - edge));
  tooltip.dataset.placement = placement;
  tooltip.style.left = `${Math.round(left)}px`;
  tooltip.style.top = `${Math.round(top)}px`;
}

function showFormHoverTooltip(button) {
  const form = adminState.forms.find((item) => item.id === Number(button.dataset.formTooltip));
  if (!form) return;
  const tooltip = ensureFormHoverTooltip();
  tooltip.innerHTML = formHoverDetailsHtml(form);
  tooltip.hidden = false;
  tooltip.classList.add('is-visible');
  positionFormHoverTooltip(button, tooltip);
  button.setAttribute('aria-describedby', tooltip.id);
}

function hideFormHoverTooltip() {
  const tooltip = document.getElementById('form-hover-tooltip');
  if (!tooltip) return;
  tooltip.classList.remove('is-visible');
  tooltip.hidden = true;
}

function bindFormHoverTooltips() {
  ['form-list', 'recent-form-list'].forEach((rootId) => {
    const root = document.getElementById(rootId);
    if (!root) return;

    root.addEventListener('pointerover', (event) => {
      const button = event.target.closest('[data-form-tooltip]');
      if (!button || !root.contains(button) || button.contains(event.relatedTarget)) return;
      showFormHoverTooltip(button);
    });
    root.addEventListener('pointerout', (event) => {
      const button = event.target.closest('[data-form-tooltip]');
      if (!button || button.contains(event.relatedTarget)) return;
      hideFormHoverTooltip();
    });
    root.addEventListener('focusin', (event) => {
      const button = event.target.closest('[data-form-tooltip]');
      if (button && root.contains(button)) showFormHoverTooltip(button);
    });
    root.addEventListener('focusout', (event) => {
      const button = event.target.closest('[data-form-tooltip]');
      if (!button || button.contains(event.relatedTarget)) return;
      hideFormHoverTooltip();
    });
  });

  window.addEventListener('resize', hideFormHoverTooltip);
  window.addEventListener('scroll', hideFormHoverTooltip, true);
}

function getContextMenuForm() {
  return adminState.forms.find((form) => form.id === formContextMenuState.formId) || null;
}

function ensureFormContextMenu() {
  let menu = document.getElementById('form-context-menu');
  if (menu) return menu;

  menu = document.createElement('div');
  menu.id = 'form-context-menu';
  menu.className = 'form-context-menu';
  menu.setAttribute('role', 'menu');
  menu.setAttribute('aria-label', 'フォームの簡易コマンド');
  menu.setAttribute('aria-hidden', 'true');
  menu.tabIndex = -1;
  menu.hidden = true;
  document.body.appendChild(menu);

  menu.addEventListener('click', async (event) => {
    const button = event.target.closest('button');
    if (!button || button.disabled || formContextMenuState.busy) return;

    const nextView = button.dataset.contextView;
    if (nextView) {
      formContextMenuState.view = nextView;
      formContextMenuState.error = '';
      const form = getContextMenuForm();
      if (nextView === 'rename') formContextMenuState.renameValue = form?.name || '';
      renderFormContextMenu({ focus: true });
      return;
    }

    if (button.hasAttribute('data-context-visibility')) {
      const form = getContextMenuForm();
      if (!form) return;
      const isActive = button.dataset.contextVisibility === 'public';
      if (Boolean(form.is_active) === isActive) return;
      await quickUpdateFormFromContextMenu(
        { is_active: isActive },
        `「${form.name}」を${isActive ? '公開' : '非公開に'}しました。`
      );
      return;
    }

    if (button.hasAttribute('data-context-folder-id')) {
      const form = getContextMenuForm();
      if (!form) return;
      const rawFolderId = button.dataset.contextFolderId;
      const folderId = rawFolderId === 'root' ? null : Number(rawFolderId);
      if ((form.folder_id || null) === folderId) return;
      const destination = folderId ? getFolderPath(folderId) : '未分類';
      await quickUpdateFormFromContextMenu(
        { folder_id: folderId },
        `「${form.name}」を「${destination}」へ移動しました。`
      );
    }
  });

  menu.addEventListener('submit', async (event) => {
    const formElement = event.target.closest('[data-context-rename-form]');
    if (!formElement || formContextMenuState.busy) return;
    event.preventDefault();
    const targetForm = getContextMenuForm();
    if (!targetForm) return;
    const input = formElement.elements.form_name;
    const nextName = String(input?.value || '').trim();
    formContextMenuState.renameValue = nextName;
    if (!nextName) {
      formContextMenuState.error = 'フォーム名を入力してください。';
      renderFormContextMenu({ focus: true });
      return;
    }
    if (nextName === targetForm.name) {
      formContextMenuState.view = 'main';
      renderFormContextMenu({ focus: true });
      return;
    }
    await quickUpdateFormFromContextMenu(
      { name: nextName },
      `フォーム名を「${nextName}」へ変更しました。`
    );
  });

  menu.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      if (formContextMenuState.view !== 'main' && !formContextMenuState.busy) {
        formContextMenuState.view = 'main';
        formContextMenuState.error = '';
        renderFormContextMenu({ focus: true });
      } else {
        closeFormContextMenu({ restoreFocus: true });
      }
      return;
    }

    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
    if (event.target.matches('input, textarea, select')) return;
    const focusable = Array.from(menu.querySelectorAll('button:not([disabled]), input:not([disabled])'))
      .filter((item) => item.offsetParent !== null);
    if (!focusable.length) return;
    event.preventDefault();
    const currentIndex = focusable.indexOf(document.activeElement);
    let nextIndex = 0;
    if (event.key === 'End') nextIndex = focusable.length - 1;
    if (event.key === 'ArrowDown') nextIndex = currentIndex < 0 ? 0 : (currentIndex + 1) % focusable.length;
    if (event.key === 'ArrowUp') nextIndex = currentIndex < 0 ? focusable.length - 1 : (currentIndex - 1 + focusable.length) % focusable.length;
    focusable[nextIndex].focus();
  });

  return menu;
}

function contextMenuFolderItems() {
  const byParent = new Map();
  adminState.folders.forEach((folder) => {
    const parentId = folder.parent_id || 0;
    if (!byParent.has(parentId)) byParent.set(parentId, []);
    byParent.get(parentId).push(folder);
  });
  byParent.forEach((folders) => folders.sort((a, b) => (
    Number(a.sort_order || 0) - Number(b.sort_order || 0)
    || String(a.name).localeCompare(String(b.name), 'ja')
    || a.id - b.id
  )));

  const result = [{ id: null, name: '未分類', path: '未分類', depth: 0 }];
  const visited = new Set();
  const appendChildren = (parentId, depth) => {
    (byParent.get(parentId) || []).forEach((folder) => {
      if (visited.has(folder.id)) return;
      visited.add(folder.id);
      result.push({ id: folder.id, name: folder.name, path: getFolderPath(folder.id), depth });
      appendChildren(folder.id, depth + 1);
    });
  };
  appendChildren(0, 0);

  // 親参照が壊れたフォルダーも移動先から除外せず、末尾へ表示する。
  adminState.folders.forEach((folder) => {
    if (visited.has(folder.id)) return;
    result.push({ id: folder.id, name: folder.name, path: getFolderPath(folder.id), depth: 0 });
  });
  return result;
}

function formContextMenuErrorHtml() {
  return formContextMenuState.error
    ? `<div class="form-context-menu-error" role="alert">${escapeHtml(formContextMenuState.error)}</div>`
    : '';
}

function formContextMenuMainHtml(form) {
  const busy = formContextMenuState.busy ? ' disabled' : '';
  const active = Boolean(form.is_active);
  return `
    <div class="form-context-menu-header">
      <span class="form-context-menu-kicker">簡易コマンド</span>
      <strong>${escapeHtml(form.name)}</strong>
    </div>
    ${formContextMenuErrorHtml()}
    <div class="form-context-menu-label">公開状態</div>
    <button type="button" class="form-context-menu-item ${active ? 'is-current' : ''}"
      role="menuitemradio" aria-checked="${active ? 'true' : 'false'}" data-context-visibility="public"${busy}>
      <span class="form-context-menu-status status-public" aria-hidden="true"></span>
      <span>公開</span><span class="form-context-menu-check" aria-hidden="true">${active ? '✓' : ''}</span>
    </button>
    <button type="button" class="form-context-menu-item ${!active ? 'is-current' : ''}"
      role="menuitemradio" aria-checked="${!active ? 'true' : 'false'}" data-context-visibility="private"${busy}>
      <span class="form-context-menu-status status-private" aria-hidden="true"></span>
      <span>非公開</span><span class="form-context-menu-check" aria-hidden="true">${!active ? '✓' : ''}</span>
    </button>
    <div class="form-context-menu-separator" role="separator"></div>
    <button type="button" class="form-context-menu-item form-context-menu-item-detail" role="menuitem" data-context-view="folders"${busy}>
      <span class="form-context-menu-icon" aria-hidden="true">▣</span>
      <span><strong>フォルダーへ移動</strong><small>${escapeHtml(getFolderPath(form.folder_id))}</small></span>
      <span class="form-context-menu-chevron" aria-hidden="true">›</span>
    </button>
    <button type="button" class="form-context-menu-item" role="menuitem" data-context-view="rename"${busy}>
      <span class="form-context-menu-icon" aria-hidden="true">✎</span>
      <span>名前を変更</span><span></span>
    </button>
    ${formContextMenuState.busy ? '<div class="form-context-menu-progress" role="status">保存しています…</div>' : ''}
  `;
}

function formContextMenuFoldersHtml(form) {
  const currentFolderId = form.folder_id || null;
  const busy = formContextMenuState.busy ? ' disabled' : '';
  return `
    <div class="form-context-menu-subhead">
      <button type="button" class="form-context-menu-back" data-context-view="main" aria-label="簡易コマンドへ戻る"${busy}>‹</button>
      <span><small>移動先を選択</small><strong>${escapeHtml(form.name)}</strong></span>
    </div>
    ${formContextMenuErrorHtml()}
    <div class="form-context-menu-folder-list" role="group" aria-label="移動先フォルダー">
      ${contextMenuFolderItems().map((folder) => {
        const isCurrent = currentFolderId === folder.id;
        const folderId = folder.id === null ? 'root' : String(folder.id);
        return `
          <button type="button" class="form-context-menu-item form-context-folder-item ${isCurrent ? 'is-current' : ''}"
            style="--context-folder-indent:${folder.depth * 13}px" role="menuitemradio" aria-checked="${isCurrent ? 'true' : 'false'}"
            data-context-folder-id="${folderId}" title="${escapeHtml(folder.path)}"${busy}>
            <span class="form-context-folder-glyph" aria-hidden="true"></span>
            <span>${escapeHtml(folder.name)}</span>
            <span class="form-context-menu-check" aria-hidden="true">${isCurrent ? '✓' : ''}</span>
          </button>`;
      }).join('')}
    </div>
    ${formContextMenuState.busy ? '<div class="form-context-menu-progress" role="status">移動しています…</div>' : ''}
  `;
}

function formContextMenuRenameHtml(form) {
  const busy = formContextMenuState.busy ? ' disabled' : '';
  const value = formContextMenuState.renameValue;
  return `
    <div class="form-context-menu-subhead">
      <button type="button" class="form-context-menu-back" data-context-view="main" aria-label="簡易コマンドへ戻る"${busy}>‹</button>
      <span><small>名前を変更</small><strong>${escapeHtml(form.name)}</strong></span>
    </div>
    ${formContextMenuErrorHtml()}
    <form class="form-context-rename-form" data-context-rename-form>
      <label for="context-form-name">新しいフォーム名</label>
      <input type="text" id="context-form-name" name="form_name" value="${escapeHtml(value)}" maxlength="150"
        autocomplete="off" required${busy}>
      <div class="form-context-rename-actions">
        <button type="button" class="form-context-secondary-button" data-context-view="main"${busy}>キャンセル</button>
        <button type="submit" class="form-context-primary-button"${busy}>変更</button>
      </div>
    </form>
    ${formContextMenuState.busy ? '<div class="form-context-menu-progress" role="status">変更しています…</div>' : ''}
  `;
}

function positionFormContextMenu() {
  const menu = ensureFormContextMenu();
  if (menu.hidden) return;
  const edge = 10;
  const viewportWidth = document.documentElement.clientWidth;
  const viewportHeight = document.documentElement.clientHeight;
  const rect = menu.getBoundingClientRect();
  const maxLeft = Math.max(edge, viewportWidth - rect.width - edge);
  const maxTop = Math.max(edge, viewportHeight - rect.height - edge);
  const left = Math.min(Math.max(edge, formContextMenuState.clientX), maxLeft);
  const top = Math.min(Math.max(edge, formContextMenuState.clientY), maxTop);
  menu.style.left = `${Math.round(left)}px`;
  menu.style.top = `${Math.round(top)}px`;
}

function focusFormContextMenu() {
  const menu = ensureFormContextMenu();
  let target = null;
  if (formContextMenuState.view === 'rename') {
    target = menu.querySelector('input[name="form_name"]');
  } else if (formContextMenuState.view === 'folders') {
    target = menu.querySelector('.form-context-folder-item.is-current')
      || menu.querySelector('.form-context-folder-item');
  } else {
    target = menu.querySelector('.form-context-menu-item:not([disabled])');
  }
  target?.focus();
  if (target?.matches('input[name="form_name"]')) target.select();
}

function renderFormContextMenu({ focus = false } = {}) {
  const menu = ensureFormContextMenu();
  const form = getContextMenuForm();
  if (!form) {
    closeFormContextMenu();
    return;
  }

  if (formContextMenuState.view === 'folders') {
    menu.innerHTML = formContextMenuFoldersHtml(form);
  } else if (formContextMenuState.view === 'rename') {
    menu.innerHTML = formContextMenuRenameHtml(form);
  } else {
    menu.innerHTML = formContextMenuMainHtml(form);
  }
  menu.hidden = false;
  menu.setAttribute('aria-hidden', 'false');
  menu.setAttribute('aria-busy', formContextMenuState.busy ? 'true' : 'false');
  positionFormContextMenu();
  if (focus) focusFormContextMenu();
}

function openFormContextMenu(formId, clientX, clientY, anchor, openedByKeyboard = false) {
  if (formContextMenuState.busy) return;
  const form = adminState.forms.find((item) => item.id === Number(formId));
  if (!form) return;
  hideFormHoverTooltip();
  formContextMenuState.anchor?.setAttribute('aria-expanded', 'false');
  formContextMenuState.formId = form.id;
  formContextMenuState.anchor = anchor || null;
  formContextMenuState.anchor?.setAttribute('aria-expanded', 'true');
  formContextMenuState.view = 'main';
  formContextMenuState.clientX = Number(clientX) || 0;
  formContextMenuState.clientY = Number(clientY) || 0;
  formContextMenuState.busy = false;
  formContextMenuState.error = '';
  formContextMenuState.renameValue = form.name;
  formContextMenuState.openedByKeyboard = openedByKeyboard;
  renderFormContextMenu({ focus: true });
}

function closeFormContextMenu({ restoreFocus = false, force = false } = {}) {
  if (formContextMenuState.busy && !force) return;
  const menu = document.getElementById('form-context-menu');
  const formId = formContextMenuState.formId;
  const anchor = formContextMenuState.anchor;
  anchor?.setAttribute('aria-expanded', 'false');
  if (menu) {
    menu.hidden = true;
    menu.setAttribute('aria-hidden', 'true');
    menu.removeAttribute('aria-busy');
    menu.innerHTML = '';
  }
  formContextMenuState.formId = 0;
  formContextMenuState.anchor = null;
  formContextMenuState.view = 'main';
  formContextMenuState.busy = false;
  formContextMenuState.error = '';
  formContextMenuState.renameValue = '';
  formContextMenuState.openedByKeyboard = false;
  if (restoreFocus) {
    const nextAnchor = anchor?.isConnected
      ? anchor
      : document.querySelector(`[data-select-form="${formId}"]`);
    nextAnchor?.focus();
  }
}

function syncQuickUpdatedEditor(form, changes) {
  if (!form || form.id !== adminState.activeFormId) return;
  const editor = document.getElementById('form-editor');
  if (!editor) return;
  if (Object.prototype.hasOwnProperty.call(changes, 'name')) {
    editor.elements.name.value = form.name;
  }
  if (Object.prototype.hasOwnProperty.call(changes, 'is_active')) {
    editor.elements.is_active.checked = Boolean(form.is_active);
  }
  if (Object.prototype.hasOwnProperty.call(changes, 'folder_id') && editor.elements.folder_id) {
    editor.elements.folder_id.innerHTML = buildFolderOptions(form.folder_id || null);
    editor.elements.folder_id.value = form.folder_id ? String(form.folder_id) : '';
  }
}

async function quickUpdateFormFromContextMenu(changes, successMessage) {
  const formId = formContextMenuState.formId;
  const openedByKeyboard = formContextMenuState.openedByKeyboard;
  if (!formId || formContextMenuState.busy) return;

  formContextMenuState.busy = true;
  formContextMenuState.error = '';
  renderFormContextMenu();

  try {
    const result = await apiPost('api/admin_forms.php', {
      action: 'quick_update',
      form_id: formId,
      changes,
    });
    if (!result.ok) {
      throw new Error(result.message || '簡易操作の保存に失敗しました。');
    }

    adminState.forms = Array.isArray(result.forms) ? result.forms : adminState.forms;
    adminState.folders = Array.isArray(result.folders) ? result.folders : adminState.folders;
    const updatedForm = result.form
      || adminState.forms.find((form) => form.id === formId)
      || null;
    if (updatedForm && Object.prototype.hasOwnProperty.call(changes, 'folder_id')) {
      expandFolderPath(updatedForm.folder_id);
    }

    closeFormContextMenu({ force: true });
    renderOverallStats();
    renderFormList();
    const activeForm = getActiveForm();
    if (activeForm?.id === formId) {
      renderWorkspaceHeader(activeForm);
      renderSelectedFormSidebar(activeForm);
      renderOverview(activeForm);
      syncQuickUpdatedEditor(activeForm, changes);
    }
    showFlashMessage(successMessage || result.message || 'フォームを更新しました。', 'success', {
      title: '簡易操作を保存しました',
      duration: 3200,
    });

    if (openedByKeyboard) {
      document.querySelector(`[data-select-form="${formId}"]`)?.focus();
    }
  } catch (error) {
    formContextMenuState.busy = false;
    formContextMenuState.error = error?.message || '通信に失敗しました。';
    renderFormContextMenu({ focus: true });
  }
}

function bindFormContextMenu() {
  const menu = ensureFormContextMenu();
  ['form-list', 'recent-form-list'].forEach((rootId) => {
    const root = document.getElementById(rootId);
    if (!root) return;

    root.addEventListener('contextmenu', (event) => {
      const button = event.target.closest('[data-select-form]');
      if (!button || !root.contains(button)) return;
      event.preventDefault();
      openFormContextMenu(button.dataset.selectForm, event.clientX, event.clientY, button, false);
    });

    root.addEventListener('keydown', (event) => {
      if (event.key !== 'ContextMenu' && !(event.shiftKey && event.key === 'F10')) return;
      const button = event.target.closest('[data-select-form]');
      if (!button || !root.contains(button)) return;
      event.preventDefault();
      const rect = button.getBoundingClientRect();
      openFormContextMenu(button.dataset.selectForm, rect.right - 4, rect.top + 8, button, true);
    });
  });

  document.addEventListener('pointerdown', (event) => {
    if (menu.hidden || menu.contains(event.target)) return;
    closeFormContextMenu();
  }, true);
  document.addEventListener('contextmenu', (event) => {
    if (menu.hidden || menu.contains(event.target) || event.target.closest('[data-select-form]')) return;
    closeFormContextMenu();
  });
  window.addEventListener('resize', () => closeFormContextMenu());
  window.addEventListener('blur', () => closeFormContextMenu());
  window.addEventListener('scroll', (event) => {
    if (menu.hidden || menu.contains(event.target)) return;
    closeFormContextMenu();
  }, true);
}

function renderFormList() {
  const root = document.getElementById('form-list');
  if (!root) return;
  hideFormHoverTooltip();

  const keyword = adminState.formSearch.trim().toLowerCase();
  const folderMap = new Map(adminState.folders.map((folder) => [folder.id, folder]));
  const childrenByParent = new Map();
  adminState.folders.forEach((folder) => {
    const parentId = folder.parent_id && folderMap.has(folder.parent_id) ? folder.parent_id : 0;
    if (!childrenByParent.has(parentId)) childrenByParent.set(parentId, []);
    childrenByParent.get(parentId).push(folder);
  });
  childrenByParent.forEach((folders) => folders.sort((a, b) => (
    Number(a.sort_order || 0) - Number(b.sort_order || 0)
    || String(a.name).localeCompare(String(b.name), 'ja')
    || a.id - b.id
  )));

  const formsByFolder = new Map();
  adminState.forms.forEach((form) => {
    const folderId = form.folder_id && folderMap.has(form.folder_id) ? form.folder_id : 0;
    if (!formsByFolder.has(folderId)) formsByFolder.set(folderId, []);
    formsByFolder.get(folderId).push(form);
  });
  formsByFolder.forEach((forms) => forms.sort((a, b) => (
    Number(a.sort_order || 0) - Number(b.sort_order || 0)
    || String(a.name).localeCompare(String(b.name), 'ja')
    || a.id - b.id
  )));

  const formMatches = (form) => !keyword || [form.name, form.slug, form.description, getFolderPath(form.folder_id)]
    .filter(Boolean)
    .some((value) => String(value).toLowerCase().includes(keyword));
  const folderMatches = (folder) => keyword && [folder.name, getFolderPath(folder.id)]
    .some((value) => String(value).toLowerCase().includes(keyword));

  const countFolderForms = (folderId, visited = new Set()) => {
    if (visited.has(folderId)) return 0;
    visited.add(folderId);
    let count = (formsByFolder.get(folderId) || []).length;
    (childrenByParent.get(folderId) || []).forEach((child) => {
      count += countFolderForms(child.id, new Set(visited));
    });
    return count;
  };

  const subtreeHasMatch = (folder, visited = new Set()) => {
    if (!keyword || folderMatches(folder)) return true;
    if (visited.has(folder.id)) return false;
    visited.add(folder.id);
    if ((formsByFolder.get(folder.id) || []).some(formMatches)) return true;
    return (childrenByParent.get(folder.id) || []).some((child) => subtreeHasMatch(child, new Set(visited)));
  };

  const renderFormButton = (form, depth) => {
    const isSelected = form.id === adminState.activeFormId;
    return `
      <button type="button" class="directory-form-button ${isSelected ? 'active' : ''}"
        style="--tree-indent:${depth * 14}px" data-select-form="${form.id}" data-form-tooltip="${form.id}"
        aria-label="${escapeHtml(form.name)}を選択。右クリックで簡易操作" aria-current="${isSelected ? 'true' : 'false'}"
        aria-haspopup="menu" aria-expanded="false" aria-controls="form-context-menu">
        <span class="directory-form-name">${escapeHtml(form.name)}</span>
      </button>
    `;
  };

  const renderFolder = (folder, depth, visited = new Set(), forceAll = false) => {
    if (visited.has(folder.id)) return '';
    visited.add(folder.id);
    const forceSubtree = forceAll || folderMatches(folder);
    if (!forceSubtree && !subtreeHasMatch(folder)) return '';
    const expanded = keyword || adminState.expandedFolderIds.has(folder.id);
    const directForms = (formsByFolder.get(folder.id) || []).filter((form) => forceSubtree || formMatches(form));
    const childHtml = (childrenByParent.get(folder.id) || [])
      .map((child) => renderFolder(child, depth + 1, new Set(visited), forceSubtree))
      .join('');
    const formHtml = directForms.map((form) => renderFormButton(form, depth + 1)).join('');
    return `
      <div class="directory-folder-node" data-folder-node="${folder.id}">
        <div class="directory-folder-row" style="--tree-indent:${depth * 14}px">
          <button type="button" class="directory-folder-toggle" data-toggle-folder="${folder.id}"
            aria-expanded="${expanded ? 'true' : 'false'}" aria-label="${expanded ? '折りたたむ' : '展開する'}">›</button>
          <button type="button" class="directory-folder-main" data-toggle-folder="${folder.id}" title="${escapeHtml(getFolderPath(folder.id))}">
            <span class="folder-glyph" aria-hidden="true"></span>
            <span class="directory-folder-name">${escapeHtml(folder.name)}</span>
            <span class="directory-folder-count">${countFolderForms(folder.id)}</span>
          </button>
          <button type="button" class="directory-folder-edit" data-edit-folder="${folder.id}" aria-label="${escapeHtml(folder.name)}を編集" title="フォルダー設定">•••</button>
        </div>
        <div class="directory-folder-children"${expanded ? '' : ' hidden'}>${childHtml}${formHtml}</div>
      </div>
    `;
  };

  const unfiledForms = (formsByFolder.get(0) || []).filter(formMatches);
  const unfiledMatches = !keyword || '未分類'.includes(keyword) || unfiledForms.length > 0;
  const unfiledExpanded = keyword || adminState.expandedFolderIds.has(0);
  const unfiledHtml = unfiledMatches && (unfiledForms.length || !keyword) ? `
    <div class="directory-folder-node" data-folder-node="0">
      <div class="directory-folder-row" style="--tree-indent:0px">
        <button type="button" class="directory-folder-toggle" data-toggle-folder="0" aria-expanded="${unfiledExpanded ? 'true' : 'false'}">›</button>
        <button type="button" class="directory-folder-main" data-toggle-folder="0">
          <span class="folder-glyph" aria-hidden="true"></span>
          <span class="directory-folder-name">未分類</span>
          <span class="directory-folder-count">${(formsByFolder.get(0) || []).length}</span>
        </button>
      </div>
      <div class="directory-folder-children"${unfiledExpanded ? '' : ' hidden'}>
        ${unfiledForms.map((form) => renderFormButton(form, 1)).join('')}
      </div>
    </div>
  ` : '';

  const folderHtml = (childrenByParent.get(0) || [])
    .map((folder) => renderFolder(folder, 0))
    .join('');
  root.innerHTML = (folderHtml || unfiledHtml)
    ? `${folderHtml}${unfiledHtml}`
    : '<div class="directory-empty-state">条件に一致するフォームはありません。</div>';

  renderRecentForms();
  const expandButton = document.getElementById('expand-all-folders-button');
  if (expandButton) {
    const allFolderIds = [0, ...adminState.folders.map((folder) => folder.id)];
    const allExpanded = allFolderIds.length > 0 && allFolderIds.every((id) => adminState.expandedFolderIds.has(id));
    expandButton.textContent = allExpanded ? 'すべて折りたたむ' : 'すべて展開';
  }
}

function renderRecentForms() {
  const section = document.getElementById('recent-forms-section');
  const root = document.getElementById('recent-form-list');
  if (!section || !root) return;
  const recentForms = adminState.recentFormIds
    .map((id) => adminState.forms.find((form) => form.id === id))
    .filter(Boolean)
    .slice(0, RECENT_FORMS_LIMIT);
  const visible = recentForms.length > 0 && adminState.formSearch.trim() === '';
  section.classList.toggle('hidden', !visible);
  if (!visible) {
    root.innerHTML = '';
    return;
  }
  root.innerHTML = recentForms.map((form) => `
    <button type="button" class="recent-form-button ${form.id === adminState.activeFormId ? 'active' : ''}"
      data-select-form="${form.id}" data-form-tooltip="${form.id}" aria-label="${escapeHtml(form.name)}を選択。右クリックで簡易操作"
      aria-haspopup="menu" aria-expanded="false" aria-controls="form-context-menu">
      <strong>${escapeHtml(form.name)}</strong>
    </button>
  `).join('');
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
    <span class="pill">${escapeHtml(getFolderPath(form.folder_id))}</span>
    <span class="pill">slug: ${escapeHtml(form.slug)}</span>
    ${createStatusBadge(form.is_active ? 'resolved' : 'on_hold', form.is_active ? '公開中' : '非公開')}
    ${availabilityBadge(form.availability, form.is_active)}
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
  const availability = form.availability || {};
  pill.className = `status-badge ${form.is_active ? 'status-resolved' : 'status-on-hold'}`;
  pill.textContent = form.is_active ? (availability.label || '公開中') : '非公開';

  body.innerHTML = `
    <article class="mini-info-card">
      <strong>${escapeHtml(form.name)}</strong>
      <div class="meta-line">
        <span class="pill">${escapeHtml(getFolderPath(form.folder_id))}</span>
        <span class="pill">slug: ${escapeHtml(form.slug)}</span>
        <span class="pill">順序 ${escapeHtml(String(form.sort_order ?? 0))}</span>
        ${availabilityBadge(form.availability, form.is_active)}
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
  const availability = form?.availability || {};

  document.getElementById('overview-kpi-status').textContent = form ? (form.is_active ? (availability.label || '公開中') : '非公開') : '未選択';
  document.getElementById('overview-kpi-fields').textContent = String(fieldCount);
  document.getElementById('overview-kpi-entries').textContent = String(summary.total_count || 0);
  document.getElementById('overview-kpi-new').textContent = String(newCount);

  const infoRoot = document.getElementById('overview-form-info');
  if (!form) {
    infoRoot.innerHTML = '<div class="empty-state">フォームを選択すると基本情報を表示します。</div>';
  } else {
    const infoItems = [
      ['フォーム名', form.name],
      ['保存先', getFolderPath(form.folder_id)],
      ['slug', form.slug],
      ['公開状態', form.is_active ? '公開中' : '非公開'],
      ['公開期間', formatAvailabilityWindow(availability, settings)],
      ['現在の受付状態', availability.label || (form.is_active ? '公開中' : '非公開')],
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
      settings.distribution_enabled ? '配布資料表示あり' : '配布資料表示なし',
      settings.distribution_file_relative_path ? '配布ファイル登録済み' : '配布ファイル未設定',
      (settings.public_start_date || settings.public_end_date || settings.public_start_time || settings.public_end_time)
        ? `公開期間: ${formatAvailabilityWindow(availability, settings)}`
        : '公開期間制限なし',
      `送信ボタン: ${settings.submit_button_label || '送信する'}`,
    ];
    settingPills.innerHTML = pills.map((item) => `<span class="pill">${escapeHtml(item)}</span>`).join('');
  }

  const periodNote = document.getElementById('overview-period-note');
  if (periodNote) {
    periodNote.textContent = form ? (availability.note || '') : '';
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
  if (editor.elements.folder_id) {
    editor.elements.folder_id.innerHTML = buildFolderOptions(form?.folder_id || null);
    editor.elements.folder_id.value = form?.folder_id ? String(form.folder_id) : '';
  }
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
  if (editor.elements.max_upload_files) editor.elements.max_upload_files.value = form?.settings?.max_upload_files ?? 1;
  if (editor.elements.distribution_enabled) {
    editor.elements.distribution_enabled.checked = form?.settings?.distribution_enabled ?? false;
    editor.elements.distribution_title.value = form?.settings?.distribution_title || '';
    editor.elements.distribution_body.value = form?.settings?.distribution_body || '';
    editor.elements.distribution_download_label.value = form?.settings?.distribution_download_label || '資料をダウンロード';
  }
  renderDistributionFileStatus(form);
  const distributionFileInput = document.getElementById('distribution-file-input');
  if (distributionFileInput) distributionFileInput.value = '';
  editor.elements.public_start_date.value = form?.settings?.public_start_date || '';
  editor.elements.public_start_time.value = form?.settings?.public_start_time || '';
  editor.elements.public_end_date.value = form?.settings?.public_end_date || '';
  editor.elements.public_end_time.value = form?.settings?.public_end_time || '';
  editor.elements.submit_button_label.value = form?.settings?.submit_button_label || '送信する';
  editor.elements.completion_message.value = form?.settings?.completion_message || '送信を受け付けました。';

  const fieldRoot = document.getElementById('field-builder-list');
  fieldRoot.innerHTML = '';
  (form?.fields || []).forEach((field) => fieldRoot.appendChild(fieldRowDataToHtml(field)));
  syncDeleteButtonState(form);
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
            ${(entry.uploaded_file_count || 0) > 0 ? `<span class="pill">添付 ${escapeHtml(String(entry.uploaded_file_count))}件</span>` : (entry.uploaded_original_name ? `<span class="pill">添付あり</span>` : '')}
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
      ${item.uploaded_original_name ? `<div class="inline-actions"><a class="btn" href="api/download_revision.php?revision_id=${item.id}&csrf_token=${encodeURIComponent(csrfToken)}">添付を取得</a></div>` : ''}
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
        ${((entry.uploaded_file_count || 0) > 0 || entry.uploaded_original_name) && entry.latest_revision_id ? `<a class="btn" href="api/download_revision.php?revision_id=${entry.latest_revision_id}&csrf_token=${encodeURIComponent(csrfToken)}">最新添付を取得</a>` : ''}
        ${((entry.uploaded_file_count || 0) > 0 || entry.uploaded_original_name) ? `<button type="button" class="btn danger" id="delete-entry-file-button" data-entry-id="${entry.id}">提出ファイルを削除</button>` : ''}
        <button type="button" class="btn danger" id="delete-entry-button" data-entry-id="${entry.id}">回答を削除</button>
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
  adminState.folders = result.folders || [];
  const validFolderIds = new Set(adminState.folders.map((folder) => folder.id));
  adminState.expandedFolderIds = new Set(
    Array.from(adminState.expandedFolderIds).filter((id) => id === 0 || validFolderIds.has(id))
  );
  if (!adminState.folderExpansionInitialized) {
    if (adminState.expandedFolderIds.size === 0) {
      adminState.expandedFolderIds.add(0);
      adminState.folders.filter((folder) => !folder.parent_id).forEach((folder) => {
        adminState.expandedFolderIds.add(folder.id);
      });
    }
    adminState.folderExpansionInitialized = true;
  }
  renderOverallStats();

  if (selectId !== null) {
    adminState.activeFormId = selectId;
  } else if (!adminState.forms.some((form) => form.id === adminState.activeFormId)) {
    adminState.activeFormId = adminState.forms[0]?.id || 0;
  }

  const activeForm = getActiveForm();
  if (activeForm) expandFolderPath(activeForm.folder_id);
  refreshFolderSelects();
  renderFormList();

  const current = activeForm;
  renderWorkspaceHeader(current);
  renderSelectedFormSidebar(current);
  renderOverview(current);
  fillEditor(current);

  if (current) {
    setWorkspaceButtonsDisabled(false);
    switchWorkspaceTab(adminState.activeWorkspaceTab);
    await loadEntries(current.id, null, true);
  } else {
    adminState.entriesResult = null;
    adminState.activeEntryId = 0;
    setWorkspaceButtonsDisabled(false);
    renderEntrySummaryBlock({ total_count: 0, filtered_count: 0, status_counts: [] });
    renderEntryList([]);
  }
}

async function selectFormById(formId) {
  const nextId = Number(formId);
  const form = adminState.forms.find((item) => item.id === nextId) || null;
  if (!form) return;

  adminState.activeFormId = nextId;
  adminState.entriesResult = null;
  adminState.activeEntryId = 0;
  adminState.activeEntryHistory = null;
  rememberFormUsage(nextId);
  expandFolderPath(form.folder_id);
  renderFormList();
  renderWorkspaceHeader(form);
  renderSelectedFormSidebar(form);
  renderOverview(form);
  fillEditor(form);
  // activeWorkspaceTabは変更せず、フォームだけを差し替える。
  switchWorkspaceTab(adminState.activeWorkspaceTab);
  await loadEntries(form.id, null, false);
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
    showFlashMessage(result.message || '状態更新に失敗しました。', 'error', { title: '保存できませんでした', duration: 5200 });
    return;
  }

  await loadEntries(adminState.activeFormId, getEntryFilterValues(), true);
  await selectEntry(Number(entryId), false);
}

async function deleteCurrentEntryFile(entryId) {
  const entries = adminState.entriesResult?.entries || [];
  const entry = entries.find((item) => item.id === Number(entryId)) || null;

  if (!entry) {
    showFlashMessage('削除対象の回答が見つかりません。', 'error', { title: '削除できませんでした' });
    return;
  }

  if (!entry.uploaded_original_name) {
    showFlashMessage('この回答には提出ファイルがありません。', 'info', { title: '削除対象なし' });
    return;
  }

  const confirmed = window.confirm(`提出ファイル「${entry.uploaded_original_name}」を削除します。\n回答内容は残りますが、ファイルは元に戻せません。`);
  if (!confirmed) return;

  const result = await apiPost('api/admin_submission_delete.php', {
    action: 'delete_file',
    submission_id: Number(entryId),
  });

  if (!result.ok) {
    showFlashMessage(result.message || '提出ファイルの削除に失敗しました。', 'error', {
      title: '削除できませんでした',
      duration: 5200,
    });
    return;
  }

  showFlashMessage(result.message || '提出ファイルを削除しました。', 'success', {
    title: '提出ファイルを削除しました',
    duration: 3600,
  });

  await loadEntries(adminState.activeFormId, getEntryFilterValues(), true);
  await selectEntry(Number(entryId), false);
}

async function deleteCurrentEntry(entryId) {
  const entries = adminState.entriesResult?.entries || [];
  const entry = entries.find((item) => item.id === Number(entryId)) || null;

  if (!entry) {
    showFlashMessage('削除対象の回答が見つかりません。', 'error', { title: '削除できませんでした' });
    return;
  }

  const confirmed = window.confirm(
    `回答を削除します。\n\n団体名: ${entry.organization_name}\nメール: ${entry.submitter_email}\n\n回答内容、更新履歴、状態ログ、紐づく提出ファイルを削除します。この操作は元に戻せません。`
  );
  if (!confirmed) return;

  const result = await apiPost('api/admin_submission_delete.php', {
    action: 'delete_submission',
    submission_id: Number(entryId),
  });

  if (!result.ok) {
    showFlashMessage(result.message || '回答の削除に失敗しました。', 'error', {
      title: '削除できませんでした',
      duration: 5200,
    });
    return;
  }

  showFlashMessage(result.message || '回答を削除しました。', 'success', {
    title: '回答を削除しました',
    duration: 3600,
  });

  adminState.activeEntryId = 0;
  adminState.activeEntryHistory = null;
  await loadEntries(adminState.activeFormId, getEntryFilterValues(), false);
}

function startNewFormMode(seed = {}) {
  adminState.activeFormId = 0;
  adminState.entriesResult = null;
  adminState.activeEntryId = 0;
  adminState.activeEntryHistory = null;
  renderFormList();
  renderWorkspaceHeader(null);
  renderSelectedFormSidebar(null);
  renderOverview(null);
  fillEditor(null);
  const editor = document.getElementById('form-editor');
  if (editor) {
    editor.elements.name.value = seed.name || '';
    if (editor.elements.folder_id) editor.elements.folder_id.value = seed.folderId ? String(seed.folderId) : '';
  }
  renderEntrySummaryBlock({ total_count: 0, filtered_count: 0, status_counts: [] });
  renderEntryList([]);
  switchWorkspaceTab('settings');
  editor?.elements?.name?.focus();
}

function openAdminDialog(dialogId) {
  const dialog = document.getElementById(dialogId);
  if (!dialog) return;
  if (typeof dialog.showModal === 'function') {
    if (!dialog.open) dialog.showModal();
  } else {
    dialog.setAttribute('open', '');
  }
}

function closeAdminDialog(dialogId) {
  const dialog = document.getElementById(dialogId);
  if (!dialog) return;
  if (typeof dialog.close === 'function' && dialog.open) {
    dialog.close();
  } else {
    dialog.removeAttribute('open');
  }
}

function syncNewFormCreationMode() {
  const form = document.getElementById('new-form-dialog-form');
  const copyField = document.getElementById('copy-source-field');
  const submitButton = document.getElementById('create-form-confirm-button');
  if (!form || !copyField || !submitButton) return;
  const mode = form.elements.creation_mode.value;
  copyField.classList.toggle('hidden', mode !== 'copy');
  submitButton.textContent = mode === 'copy' ? '非公開でコピー' : '作成を開始';
}

function openNewFormDialog() {
  const form = document.getElementById('new-form-dialog-form');
  if (!form) return;
  form.reset();
  clearMessage(document.getElementById('new-form-dialog-message'));

  const activeForm = getActiveForm();
  const folderSelect = form.elements.folder_id;
  folderSelect.innerHTML = buildFolderOptions(activeForm?.folder_id || null);
  folderSelect.value = activeForm?.folder_id ? String(activeForm.folder_id) : '';

  const sourceSelect = form.elements.source_form_id;
  sourceSelect.innerHTML = adminState.forms.map((item) => (
    `<option value="${item.id}">${escapeHtml(getFolderPath(item.folder_id) + ' / ' + item.name)}</option>`
  )).join('');
  if (activeForm) sourceSelect.value = String(activeForm.id);

  const copyModeInput = form.querySelector('input[name="creation_mode"][value="copy"]');
  if (copyModeInput) copyModeInput.disabled = adminState.forms.length === 0;
  syncNewFormCreationMode();
  openAdminDialog('new-form-dialog');
  window.setTimeout(() => form.elements.name.focus(), 0);
}

async function submitNewFormDialog(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const message = document.getElementById('new-form-dialog-message');
  const name = form.elements.name.value.trim();
  const folderId = Number(form.elements.folder_id.value || 0) || null;
  if (!name) {
    setMessage(message, 'フォーム名を入力してください。', 'error');
    return;
  }

  if (form.elements.creation_mode.value === 'blank') {
    closeAdminDialog('new-form-dialog');
    startNewFormMode({ name, folderId });
    showFlashMessage('基本設定を確認し、「設定を保存」で作成を完了してください。', 'info', {
      title: '新しいフォームを編集中',
      duration: 4200,
    });
    return;
  }

  const sourceFormId = Number(form.elements.source_form_id.value || 0);
  if (!sourceFormId) {
    setMessage(message, 'コピー元のフォームを選択してください。', 'error');
    return;
  }

  const submitButton = document.getElementById('create-form-confirm-button');
  submitButton.disabled = true;
  setMessage(message, 'フォームをコピーしています...', 'info');
  try {
    const result = await apiPost('api/admin_forms.php', {
      action: 'copy',
      source_form_id: sourceFormId,
      name,
      folder_id: folderId,
    });
    if (!result.ok) {
      setMessage(message, result.message || 'フォームのコピーに失敗しました。', 'error');
      return;
    }
    adminState.forms = result.forms || [];
    adminState.folders = result.folders || adminState.folders;
    refreshFolderSelects();
    closeAdminDialog('new-form-dialog');
    adminState.activeWorkspaceTab = 'settings';
    rememberFormUsage(result.form.id);
    await selectFormById(result.form.id);
    renderOverallStats();
    showFlashMessage(result.message || 'フォームをコピーしました。', 'success', {
      title: 'コピーを作成しました',
      duration: 4800,
    });
  } catch (error) {
    setMessage(message, error?.message || '通信に失敗しました。時間をおいて再度お試しください。', 'error');
  } finally {
    submitButton.disabled = false;
  }
}

function openFolderDialog(folder = null) {
  const form = document.getElementById('folder-editor');
  if (!form) return;
  form.reset();
  clearMessage(document.getElementById('folder-dialog-message'));
  form.elements.id.value = folder?.id || 0;
  form.elements.name.value = folder?.name || '';
  form.elements.sort_order.value = folder?.sort_order ?? 0;

  const excludedIds = folder ? getFolderDescendantIds(folder.id) : new Set();
  const parentSelect = form.elements.parent_id;
  parentSelect.innerHTML = buildFolderOptions(folder?.parent_id || null, '最上位', excludedIds);
  parentSelect.value = folder?.parent_id ? String(folder.parent_id) : '';

  document.getElementById('folder-dialog-title').textContent = folder ? 'フォルダーを編集' : 'フォルダーを作成';
  document.getElementById('delete-folder-button').classList.toggle('hidden', !folder);
  openAdminDialog('folder-dialog');
  window.setTimeout(() => form.elements.name.focus(), 0);
}

async function submitFolderDialog(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const message = document.getElementById('folder-dialog-message');
  const folder = {
    id: Number(form.elements.id.value || 0),
    name: form.elements.name.value.trim(),
    parent_id: Number(form.elements.parent_id.value || 0) || null,
    sort_order: Number(form.elements.sort_order.value || 0),
  };
  setMessage(message, '保存しています...', 'info');
  try {
    const result = await apiPost('api/admin_form_folders.php', { action: 'save', folder });
    if (!result.ok) {
      setMessage(message, result.message || 'フォルダーを保存できませんでした。', 'error');
      return;
    }
    adminState.folders = result.folders || [];
    adminState.expandedFolderIds.add(result.folder.id);
    if (result.folder.parent_id) adminState.expandedFolderIds.add(result.folder.parent_id);
    persistExpandedFolders();
    refreshFolderSelects();
    renderFormList();
    closeAdminDialog('folder-dialog');
    showFlashMessage(result.message || 'フォルダーを保存しました。', 'success', { title: 'フォルダーを更新しました' });
  } catch (error) {
    setMessage(message, error?.message || '通信に失敗しました。時間をおいて再度お試しください。', 'error');
  }
}

async function deleteFolderFromDialog() {
  const form = document.getElementById('folder-editor');
  const folderId = Number(form?.elements?.id?.value || 0);
  const folder = getFolder(folderId);
  if (!folder) return;
  if (!window.confirm(`空のフォルダー「${folder.name}」を削除します。よろしいですか。`)) return;

  const message = document.getElementById('folder-dialog-message');
  setMessage(message, '削除しています...', 'info');
  try {
    const result = await apiPost('api/admin_form_folders.php', { action: 'delete', folder_id: folderId });
    if (!result.ok) {
      setMessage(message, result.message || 'フォルダーを削除できませんでした。', 'error');
      return;
    }
    adminState.folders = result.folders || [];
    adminState.expandedFolderIds.delete(folderId);
    persistExpandedFolders();
    refreshFolderSelects();
    renderFormList();
    closeAdminDialog('folder-dialog');
    showFlashMessage(result.message || 'フォルダーを削除しました。', 'success', { title: 'フォルダーを削除しました' });
  } catch (error) {
    setMessage(message, error?.message || '通信に失敗しました。時間をおいて再度お試しください。', 'error');
  }
}

function buildCsvUrl() {
  if (!adminState.activeFormId) return '';
  const query = buildEntriesQuery(adminState.activeFormId, getEntryFilterValues());
  const params = new URLSearchParams(query);
  params.set('csrf_token', csrfToken);
  return `api/export_csv.php?${params.toString()}`;
}

function buildLatestAttachmentsUrl() {
  if (!adminState.activeFormId) return '';
  const filters = getEntryFilterValues();
  const query = buildEntriesQuery(adminState.activeFormId, {
    query: filters.query,
    status: filters.status,
    date_from: filters.date_from,
    date_to: filters.date_to,
  });
  const params = new URLSearchParams(query);
  params.set('csrf_token', csrfToken);
  return `api/download_latest_attachments.php?${params.toString()}`;
}

async function uploadDistributionFile() {
  const activeForm = getActiveForm();
  const message = document.getElementById('admin-message');
  const fileInput = document.getElementById('distribution-file-input');
  if (!activeForm?.id) {
    showFlashMessage('先にフォームを保存してから配布ファイルを登録してください。', 'info', { title: 'フォーム未保存' });
    return;
  }
  if (!fileInput?.files?.length) {
    showFlashMessage('アップロードする配布ファイルを選択してください。', 'info', { title: 'ファイル未選択' });
    return;
  }

  const button = document.getElementById('upload-distribution-file-button');
  const editor = document.getElementById('form-editor');
  const distributionEnabled = editor?.elements?.distribution_enabled;

  // 配布ファイル保存時は、表示設定・案内文・ボタン文言も同時に保存する。
  // 未チェックのままアップロードした場合は、利用者画面で見えるように自動で有効化する。
  if (distributionEnabled && !distributionEnabled.checked) {
    distributionEnabled.checked = true;
  }

  const formData = new FormData();
  formData.set('action', 'upload');
  formData.set('form_id', String(activeForm.id));
  formData.set('distribution_enabled', distributionEnabled?.checked ? '1' : '0');
  formData.set('distribution_title', editor?.elements?.distribution_title?.value || '');
  formData.set('distribution_body', editor?.elements?.distribution_body?.value || '');
  formData.set('distribution_download_label', editor?.elements?.distribution_download_label?.value || '資料をダウンロード');
  formData.set('distribution_file', fileInput.files[0]);
  button && (button.disabled = true);
  setMessage(message, '配布ファイルを保存中です...', 'info');
  try {
    const result = await apiPostForm('api/admin_form_asset.php', formData);
    if (!result.ok) {
      setMessage(message, result.message || '配布ファイルの保存に失敗しました。', 'error');
      showFlashMessage(result.message || '配布ファイルの保存に失敗しました。', 'error', { title: '保存できませんでした' });
      return;
    }
    fileInput.value = '';
    setMessage(message, result.message || '配布ファイルを保存しました。', 'success');
    showFlashMessage(result.message || '配布ファイルを保存しました。', 'success', { title: '配布ファイルを更新しました' });
    mergeUpdatedForm(result);
  } catch (error) {
    const errorMessage = error?.message || '通信に失敗しました。';
    setMessage(message, errorMessage, 'error');
    showFlashMessage(errorMessage, 'error', { title: '通信エラー' });
  } finally {
    button && (button.disabled = false);
  }
}

async function deleteDistributionFile() {
  const activeForm = getActiveForm();
  const message = document.getElementById('admin-message');
  if (!activeForm?.id) return;
  if (!window.confirm('このフォームの配布ファイルを削除します。よろしいですか。')) {
    return;
  }

  const button = document.getElementById('delete-distribution-file-button');
  const formData = new FormData();
  formData.set('action', 'delete');
  formData.set('form_id', String(activeForm.id));
  button && (button.disabled = true);
  setMessage(message, '配布ファイルを削除中です...', 'info');
  try {
    const result = await apiPostForm('api/admin_form_asset.php', formData);
    if (!result.ok) {
      setMessage(message, result.message || '配布ファイルの削除に失敗しました。', 'error');
      showFlashMessage(result.message || '配布ファイルの削除に失敗しました。', 'error', { title: '削除できませんでした' });
      return;
    }
    setMessage(message, result.message || '配布ファイルを削除しました。', 'success');
    showFlashMessage(result.message || '配布ファイルを削除しました。', 'success', { title: '配布ファイルを削除しました' });
    mergeUpdatedForm(result);
  } catch (error) {
    const errorMessage = error?.message || '通信に失敗しました。';
    setMessage(message, errorMessage, 'error');
    showFlashMessage(errorMessage, 'error', { title: '通信エラー' });
  } finally {
    button && (button.disabled = false);
  }
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

  document.getElementById('new-form-button')?.addEventListener('click', openNewFormDialog);
  document.getElementById('new-folder-button')?.addEventListener('click', () => openFolderDialog());
  document.getElementById('expand-all-folders-button')?.addEventListener('click', () => {
    const allFolderIds = [0, ...adminState.folders.map((folder) => folder.id)];
    const allExpanded = allFolderIds.every((id) => adminState.expandedFolderIds.has(id));
    adminState.expandedFolderIds = allExpanded ? new Set() : new Set(allFolderIds);
    persistExpandedFolders();
    renderFormList();
  });

  document.querySelectorAll('[data-close-dialog]').forEach((button) => {
    button.addEventListener('click', () => closeAdminDialog(button.dataset.closeDialog));
  });
  document.querySelectorAll('.admin-dialog').forEach((dialog) => {
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) closeAdminDialog(dialog.id);
    });
  });
  document.getElementById('new-form-dialog-form')?.addEventListener('change', (event) => {
    if (event.target.name === 'creation_mode') syncNewFormCreationMode();
  });
  document.getElementById('new-form-dialog-form')?.addEventListener('submit', submitNewFormDialog);
  document.getElementById('folder-editor')?.addEventListener('submit', submitFolderDialog);
  document.getElementById('delete-folder-button')?.addEventListener('click', deleteFolderFromDialog);

  document.getElementById('delete-form-button')?.addEventListener('click', async () => {
    const form = getActiveForm();
    const message = document.getElementById('admin-message');
    if (!form || !form.id) {
      showFlashMessage('削除するフォームを選択してください。', 'info', { title: 'フォーム未選択' });
      return;
    }
    const confirmed = window.confirm(`フォーム「${form.name}」を削除します。回答一覧、履歴、状態ログも削除されます。
この操作は元に戻せません。`);
    if (!confirmed) return;

    setMessage(message, '削除中です...', 'info');
    const result = await apiPost('api/admin_forms.php', {
      action: 'delete',
      form_id: form.id,
    });
    if (!result.ok) {
      setMessage(message, result.message || '削除に失敗しました。', 'error');
      return;
    }

    adminState.forms = result.forms || [];
    adminState.folders = result.folders || adminState.folders;
    adminState.recentFormIds = adminState.recentFormIds.filter((id) => id !== form.id);
    writeNumberList(RECENT_FORMS_STORAGE_KEY, adminState.recentFormIds);
    adminState.activeFormId = adminState.forms[0]?.id || 0;
    renderOverallStats();
    refreshFolderSelects();

    if (adminState.activeFormId > 0) {
      await selectFormById(adminState.activeFormId);
    } else {
      startNewFormMode();
    }

    setMessage(message, result.message || 'フォームを削除しました。', 'success');
    showFlashMessage(result.message || 'フォームを削除しました。', 'success', { title: 'フォームを削除しました', duration: 3600 });
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
    const editButton = event.target.closest('[data-edit-folder]');
    if (editButton) {
      openFolderDialog(getFolder(Number(editButton.dataset.editFolder)));
      return;
    }
    const toggleButton = event.target.closest('[data-toggle-folder]');
    if (toggleButton) {
      const folderId = Number(toggleButton.dataset.toggleFolder);
      if (adminState.expandedFolderIds.has(folderId)) {
        adminState.expandedFolderIds.delete(folderId);
      } else {
        adminState.expandedFolderIds.add(folderId);
      }
      persistExpandedFolders();
      renderFormList();
      return;
    }
    const button = event.target.closest('[data-select-form]');
    if (!button) return;
    await selectFormById(button.dataset.selectForm);
  });

  document.getElementById('recent-form-list')?.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-select-form]');
    if (!button) return;
    await selectFormById(button.dataset.selectForm);
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
      showFlashMessage('フォームを選択してください。', 'info', { title: 'フォーム未選択' });
      return;
    }
    window.location.href = url;
  });

  document.getElementById('download-latest-attachments-button')?.addEventListener('click', async (event) => {
    const url = buildLatestAttachmentsUrl();
    if (!url) {
      showFlashMessage('フォームを選択してください。', 'info', { title: 'フォーム未選択' });
      return;
    }
    const button = event.currentTarget;
    button.disabled = true;
    try {
      const filename = await downloadBinaryFile(url, 'latest_attachments.zip');
      showFlashMessage(`${filename} のダウンロードを開始しました。`, 'success', {
        title: '最新添付をまとめて取得',
        duration: 3800,
      });
    } catch (error) {
      showFlashMessage(error.message || '一括ダウンロードに失敗しました。', 'error', {
        title: 'ZIPを作成できませんでした',
        duration: 6200,
      });
    } finally {
      button.disabled = false;
    }
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
    const deleteFileButton = event.target.closest('#delete-entry-file-button');
    if (deleteFileButton) {
      await deleteCurrentEntryFile(Number(deleteFileButton.dataset.entryId));
      return;
    }

    const deleteEntryButton = event.target.closest('#delete-entry-button');
    if (deleteEntryButton) {
      await deleteCurrentEntry(Number(deleteEntryButton.dataset.entryId));
      return;
    }
  });

  document.getElementById('upload-distribution-file-button')?.addEventListener('click', uploadDistributionFile);
  document.getElementById('distribution-file-status')?.addEventListener('click', async (event) => {
    if (event.target.closest('#delete-distribution-file-button')) {
      await deleteDistributionFile();
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
        folder_id: Number(form.elements.folder_id?.value || 0) || null,
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
        max_upload_files: Number(form.elements.max_upload_files?.value || 1),
        distribution_enabled: form.elements.distribution_enabled?.checked || false,
        distribution_title: form.elements.distribution_title?.value || '',
        distribution_body: form.elements.distribution_body?.value || '',
        distribution_download_label: form.elements.distribution_download_label?.value || '資料をダウンロード',
        public_start_date: form.elements.public_start_date.value,
        public_start_time: form.elements.public_start_time.value,
        public_end_date: form.elements.public_end_date.value,
        public_end_time: form.elements.public_end_time.value,
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
    showFlashMessage(result.message || '保存しました。', 'success', { title: 'フォーム設定を更新しました', duration: 3200 });
    adminState.forms = result.forms || [];
    adminState.folders = result.folders || adminState.folders;
    adminState.activeFormId = result.form?.id || adminState.activeFormId;
    rememberFormUsage(adminState.activeFormId);
    expandFolderPath(result.form?.folder_id);
    refreshFolderSelects();
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
  bindFormHoverTooltips();
  bindFormContextMenu();
  switchWorkspaceTab(adminState.activeWorkspaceTab);
  loadForms();
}

initAdminPage();
