(function () {
  'use strict';

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function normalizeLines(text) {
    return String(text || '')
      .split(/\r?\n+/)
      .map((line) => line.replace(/\s+/g, ' ').trim())
      .filter(Boolean);
  }

  function detectTitle(root) {
    const titleNode = root.querySelector('strong, h3, [data-form-title], .form-nav-title');
    if (titleNode) {
      const text = titleNode.textContent.replace(/\s+/g, ' ').trim();
      if (text) return text;
    }

    const lines = normalizeLines(root.innerText || root.textContent || '');
    return lines[0] || 'フォーム';
  }

  function detectDetailLines(root, title) {
    const textLines = normalizeLines(root.innerText || root.textContent || '');
    const details = [];

    textLines.forEach((line) => {
      if (!line || line === title) return;
      if (details.includes(line)) return;
      details.push(line);
    });

    return details;
  }

  function buildTooltipHtml(title, detailLines) {
    const titleHtml = `<span class="form-list-tooltip-title">${escapeHtml(title)}</span>`;
    const rowsHtml = detailLines.length
      ? detailLines.map((line) => `<span class="form-list-tooltip-row">${escapeHtml(line)}</span>`).join('')
      : '<span class="form-list-tooltip-row">詳細情報はありません。</span>';

    return `${titleHtml}${rowsHtml}`;
  }

  function enhanceFormButton(button) {
    if (!(button instanceof HTMLElement)) return;
    if (button.dataset.titleOnlyEnhanced === '1') return;
    if (button.classList.contains('empty-state')) return;

    const title = detectTitle(button);
    const detailLines = detectDetailLines(button, title);
    const tooltipText = [title].concat(detailLines).join(' / ');

    button.dataset.titleOnlyEnhanced = '1';
    button.classList.add('form-list-item-title-only');
    button.setAttribute('title', tooltipText);
    button.setAttribute('aria-label', tooltipText);

    button.innerHTML = `
      <span class="form-list-item-title">${escapeHtml(title)}</span>
      <span class="form-list-item-hover-hint" aria-hidden="true">詳細</span>
      <span class="form-list-item-tooltip" role="tooltip" aria-hidden="true">${buildTooltipHtml(title, detailLines)}</span>
    `;
  }

  function enhanceFormList() {
    const root = document.getElementById('form-list');
    if (!root) return;

    Array.from(root.children).forEach((child) => {
      if (!(child instanceof HTMLElement)) return;
      if (child.classList.contains('empty-state')) return;

      if (child.matches('button, a, [data-form-id]')) {
        enhanceFormButton(child);
        return;
      }

      const candidate = child.querySelector('button, a, [data-form-id]');
      if (candidate instanceof HTMLElement) {
        enhanceFormButton(candidate);
      }
    });
  }

  function initObserver() {
    const root = document.getElementById('form-list');
    if (!root) return;

    const observer = new MutationObserver(() => {
      window.requestAnimationFrame(enhanceFormList);
    });

    observer.observe(root, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      enhanceFormList();
      initObserver();
    });
  } else {
    enhanceFormList();
    initObserver();
  }
})();
