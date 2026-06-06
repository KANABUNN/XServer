(function () {
  'use strict';

  const sidebar = document.querySelector('.sidebar');
  const sidebarButtons = Array.from(document.querySelectorAll('[data-sidebar-href]'));


  function navigateFromButton(button) {
    const href = button.dataset.navHref || button.dataset.sidebarHref || '';
    if (!href) return;
    const target = button.dataset.navTarget || '';
    if (target === '_blank') {
      window.open(href, '_blank', 'noopener');
      return;
    }
    window.location.href = href;
  }

  document.querySelectorAll('[data-nav-href]').forEach(button => {
    button.setAttribute('draggable', 'false');
    button.addEventListener('click', function () {
      navigateFromButton(button);
    });
    button.addEventListener('dragstart', function (event) {
      event.preventDefault();
    });
  });

  document.querySelectorAll('[data-download-href]').forEach(button => {
    button.setAttribute('draggable', 'false');
    button.addEventListener('click', function () {
      const href = button.dataset.downloadHref || '';
      if (!href) return;
      const link = document.createElement('a');
      link.href = href;
      link.download = button.dataset.downloadName || '';
      link.rel = 'noopener';
      link.style.display = 'none';
      document.body.appendChild(link);
      link.click();
      link.remove();
    });
    button.addEventListener('dragstart', function (event) {
      event.preventDefault();
    });
  });

  sidebarButtons.forEach(button => {
    button.setAttribute('draggable', 'false');
    button.addEventListener('click', function () {
      navigateFromButton(button);
    });
    button.addEventListener('dragstart', function (event) {
      event.preventDefault();
    });
  });

  if (sidebar) {
    ['dragstart', 'dragover', 'drop'].forEach(type => {
      sidebar.addEventListener(type, function (event) {
        event.preventDefault();
      });
    });
  }

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

  function normalizeEditorHtml(html) {
    return String(html || '')
      .replace(/<script[\s\S]*?>[\s\S]*?<\/script>/gi, '')
      .replace(/<style[\s\S]*?>[\s\S]*?<\/style>/gi, '')
      .replace(/\s+on[a-z]+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '')
      .replace(/javascript:/gi, '');
  }

  function htmlToPlain(html) {
    const div = document.createElement('div');
    div.innerHTML = normalizeEditorHtml(html);
    return (div.textContent || '').replace(/\u00a0/g, ' ').trim();
  }

  function syncEditorToInput(editor) {
    const inputId = editor.dataset.htmlEditor;
    const input = inputId ? document.getElementById(inputId) : null;
    if (!input) return;
    input.value = normalizeEditorHtml(editor.innerHTML);
  }

  function syncInputToEditor(input) {
    const editor = Array.from(document.querySelectorAll('[data-html-editor]'))
      .find(item => item.dataset.htmlEditor === input.id);
    if (!editor) return;
    editor.innerHTML = normalizeEditorHtml(input.value || '');
  }

  document.querySelectorAll('[data-html-editor]').forEach(editor => {
    syncEditorToInput(editor);
    editor.addEventListener('input', function () {
      syncEditorToInput(editor);
    });
    editor.addEventListener('paste', function (event) {
      const html = event.clipboardData?.getData('text/html') || '';
      const text = event.clipboardData?.getData('text/plain') || '';
      if (html) {
        event.preventDefault();
        document.execCommand('insertHTML', false, normalizeEditorHtml(html));
      } else if (text) {
        event.preventDefault();
        document.execCommand('insertText', false, text);
      }
    });
    const form = editor.closest('form');
    form?.addEventListener('submit', function (event) {
      syncEditorToInput(editor);
      const plain = htmlToPlain(editor.innerHTML);
      if (editor.hasAttribute('data-required') || document.getElementById(editor.dataset.htmlEditor || '')?.hasAttribute('required')) {
        if (!plain) {
          event.preventDefault();
          editor.focus();
          alert('本文を入力してください。');
        }
      }
    });
  });

  document.querySelectorAll('[data-html-editor-input]').forEach(input => {
    syncInputToEditor(input);
  });

  function editorByToolbar(toolbar) {
    const editorId = toolbar.dataset.editorToolbar;
    return editorId ? document.getElementById(editorId) : null;
  }

  function runEditorCommand(editor, command, value) {
    if (!editor) return;
    editor.focus();
    document.execCommand(command, false, value ?? null);
    syncEditorToInput(editor);
  }

  document.querySelectorAll('.html-editor-toolbar').forEach(toolbar => {
    toolbar.addEventListener('click', function (event) {
      const button = event.target.closest('[data-editor-command]');
      if (!button) return;
      event.preventDefault();
      runEditorCommand(editorByToolbar(toolbar), button.dataset.editorCommand || '', null);
    });

    toolbar.querySelectorAll('[data-editor-size]').forEach(select => {
      select.addEventListener('change', function () {
        runEditorCommand(editorByToolbar(toolbar), 'fontSize', select.value || '3');
        select.value = select.querySelector('option[value="3"]') ? '3' : select.value;
      });
    });

    toolbar.querySelectorAll('[data-editor-color]').forEach(input => {
      input.addEventListener('input', function () {
        runEditorCommand(editorByToolbar(toolbar), 'foreColor', input.value || '#1b2430');
      });
    });
  });

  let lastVariableTarget = null;
  const variableTargets = Array.from(document.querySelectorAll('[data-variable-insert-target]'));
  variableTargets.forEach(target => {
    ['focus', 'click', 'keyup', 'mouseup'].forEach(type => {
      target.addEventListener(type, function () {
        lastVariableTarget = target;
      });
    });
  });

  function insertTextIntoEditable(target, text) {
    target.focus();
    const selection = window.getSelection();
    if (!selection) return;
    let range;
    if (selection.rangeCount > 0 && target.contains(selection.anchorNode)) {
      range = selection.getRangeAt(0);
    } else {
      range = document.createRange();
      range.selectNodeContents(target);
      range.collapse(false);
    }
    range.deleteContents();
    const node = document.createTextNode(text);
    range.insertNode(node);
    range.setStartAfter(node);
    range.setEndAfter(node);
    selection.removeAllRanges();
    selection.addRange(range);
    target.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function insertTextAtCursor(target, text) {
    if (!target) return;
    if (target.isContentEditable) {
      insertTextIntoEditable(target, text);
      return;
    }
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
