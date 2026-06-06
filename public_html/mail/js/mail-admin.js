(function () {
  'use strict';

  const links = Array.from(document.querySelectorAll('[data-view-target]'));
  const views = Array.from(document.querySelectorAll('.content-view'));

  function activateView(viewId) {
    for (const view of views) {
      view.classList.toggle('is-active', view.id === viewId);
    }
    for (const link of links) {
      link.classList.toggle('is-active', link.dataset.viewTarget === viewId);
    }
    if (history.replaceState) {
      history.replaceState(null, '', '#' + viewId);
    }
  }

  for (const link of links) {
    link.addEventListener('click', function () {
      activateView(link.dataset.viewTarget);
    });
  }

  const initialHash = decodeURIComponent(location.hash.replace(/^#/, ''));
  if (initialHash && document.getElementById(initialHash)) {
    activateView(initialHash);
  }


  let lastVariableTarget = null;
  const variableTargets = Array.from(document.querySelectorAll('[data-variable-insert-target]'));
  variableTargets.forEach(target => {
    target.addEventListener('focus', function () {
      lastVariableTarget = target;
    });
    target.addEventListener('click', function () {
      lastVariableTarget = target;
    });
    target.addEventListener('keyup', function () {
      lastVariableTarget = target;
    });
  });

  function insertTextAtCursor(target, text) {
    if (!target) return;
    const value = target.value || '';
    const start = typeof target.selectionStart === 'number' ? target.selectionStart : value.length;
    const end = typeof target.selectionEnd === 'number' ? target.selectionEnd : value.length;
    target.value = value.slice(0, start) + text + value.slice(end);
    const next = start + text.length;
    target.focus();
    if (typeof target.setSelectionRange === 'function') {
      target.setSelectionRange(next, next);
    }
    target.dispatchEvent(new Event('input', { bubbles: true }));
    target.dispatchEvent(new Event('change', { bubbles: true }));
  }

  document.querySelectorAll('[data-insert-variable]').forEach(button => {
    button.addEventListener('click', function () {
      const text = button.dataset.insertVariable || button.textContent || '';
      const targetIds = (button.dataset.insertTargets || '')
        .split(',')
        .map(v => v.trim())
        .filter(Boolean);
      const candidates = targetIds
        .map(id => document.getElementById(id))
        .filter(Boolean);
      const active = document.activeElement;
      const target = candidates.includes(active)
        ? active
        : (candidates.includes(lastVariableTarget) ? lastVariableTarget : candidates[0]);
      insertTextAtCursor(target, text);
    });
  });
})();
