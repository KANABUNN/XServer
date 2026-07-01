(function () {
  'use strict';

  const bridge = window.FITSC_KINTONE_BRIDGE || {};
  const suggestions = Array.isArray(bridge.organization_suggestions) ? bridge.organization_suggestions : [];
  const nameSet = new Set(suggestions.map((item) => String(item.name || '').trim()).filter(Boolean));

  function ensureDatalist() {
    if (!suggestions.length || document.getElementById('kintone-org-suggestions')) return;
    const datalist = document.createElement('datalist');
    datalist.id = 'kintone-org-suggestions';
    datalist.innerHTML = suggestions.map((item) => {
      const label = [item.code, item.category].filter(Boolean).join(' / ');
      return `<option value="${escapeHtml(item.name || item.code || '')}" label="${escapeHtml(label)}"></option>`;
    }).join('');
    document.body.appendChild(datalist);
  }

  function buildCacheNotice() {
    const categories = bridge.apps && bridge.apps.categories ? bridge.apps.categories.status : null;
    if (!categories || !categories.available) return '';
    const staleClass = categories.is_stale ? ' warning' : ' info';
    const synced = categories.last_synced_at ? ` 最終同期: ${escapeHtml(categories.last_synced_at)}` : '';
    return `<div class="alert${staleClass} kintone-cache-notice" role="status">${escapeHtml(categories.message || categories.label || 'kintoneキャッシュを参照中です。')}${synced}</div>`;
  }

  function enhanceOrganizationField() {
    ensureDatalist();
    const input = document.getElementById('input-organization_name');
    if (!input || input.dataset.kintoneEnhanced === '1') return;
    input.dataset.kintoneEnhanced = '1';
    if (suggestions.length) {
      input.setAttribute('list', 'kintone-org-suggestions');
      input.setAttribute('autocomplete', 'organization');
    }

    const wrapper = input.closest('.public-form-block') || input.parentElement;
    if (!wrapper) return;

    const hint = document.createElement('p');
    hint.className = 'small-note allow-select kintone-org-hint';
    hint.textContent = suggestions.length
      ? '団体マスタの候補を表示しています。候補に無い場合も送信できます。'
      : '団体マスタ候補は未取得です。通常どおり入力できます。';
    wrapper.appendChild(hint);

    const warn = document.createElement('p');
    warn.className = 'small-note allow-select kintone-org-warning';
    warn.hidden = true;
    warn.textContent = '団体マスタ候補に一致しません。表記ゆれの可能性があるため、正式名称を確認してください。送信自体は可能です。';
    wrapper.appendChild(warn);

    input.addEventListener('input', () => {
      const value = String(input.value || '').trim();
      warn.hidden = value === '' || !suggestions.length || nameSet.has(value);
    });
  }

  function injectCacheNotice() {
    const host = document.getElementById('public-form-host');
    if (!host || host.querySelector('.kintone-cache-notice')) return;
    const notice = buildCacheNotice();
    if (!notice) return;
    host.insertAdjacentHTML('afterbegin', notice);
  }

  function enhanceCurrentForm() {
    enhanceOrganizationField();
    injectCacheNotice();
  }

  const originalRenderActiveForm = window.renderActiveForm;
  if (typeof originalRenderActiveForm === 'function') {
    window.renderActiveForm = function (...args) {
      const result = originalRenderActiveForm.apply(this, args);
      enhanceCurrentForm();
      return result;
    };
  }

  document.addEventListener('DOMContentLoaded', () => {
    enhanceCurrentForm();
    const host = document.getElementById('public-form-host');
    if (host && 'MutationObserver' in window) {
      const observer = new MutationObserver(() => enhanceCurrentForm());
      observer.observe(host, { childList: true, subtree: true });
    }
  });
})();
