/* =====================================================================
   FIT-SC UI v7 — 共通スクリプト
   <head> 内で defer なしに読み込む（テーマを描画前に確定させるため）。
   残りの初期化は DOMContentLoaded 後に行う。
   公開 API: window.UI
     UI.icon(name, cls)              … SVG アイコン文字列
     UI.toast(message, opts)         … トースト通知
     UI.confirm(opts) → Promise<bool>… 確認ダイアログ（window.confirm の置き換え）
     UI.alert(opts)   → Promise       … お知らせダイアログ
     UI.openModal(elOrId) / UI.closeModal(elOrId)
     UI.setTheme('dark'|'light') / UI.toggleTheme(event)
     UI.escapeHtml(str)
     UI.stampTables(root)            … モバイル用に td[data-label] を付与
   ===================================================================== */
(function () {
  'use strict';

  var THEME_KEY = 'fitsc-theme';
  var root = document.documentElement;

  /* ---------- テーマを描画前に確定（既定はダーク） ---------- */
  function readTheme() {
    try {
      var stored = window.localStorage.getItem(THEME_KEY);
      if (stored === 'light' || stored === 'dark') return stored;
    } catch (e) { /* storage 不可でも既定値で続行 */ }
    return 'dark';
  }
  var initialTheme = readTheme();
  root.setAttribute('data-theme', initialTheme);

  var currentScript = document.currentScript;
  var scriptSrc = currentScript ? currentScript.src : '';
  var spriteUrl = 'assets/ui/icons.svg';
  try {
    if (scriptSrc) {
      var u = new URL(scriptSrc, window.location.href);
      var version = u.searchParams.get('v');
      var sprite = new URL('icons.svg', u);
      if (version) sprite.searchParams.set('v', version);
      spriteUrl = sprite.pathname + sprite.search;
    }
  } catch (e) { /* noop */ }

  var reduceMotion = false;
  try { reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) { /* noop */ }

  var UI = window.UI || {};
  window.UI = UI;
  UI.spriteUrl = spriteUrl;

  /* ---------- ユーティリティ ---------- */
  UI.escapeHtml = function (value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  };
  var esc = UI.escapeHtml;

  UI.icon = function (name, cls) {
    return '<svg class="ico' + (cls ? ' ' + cls : '') + '" aria-hidden="true" focusable="false"><use href="' + spriteUrl + '#i-' + name + '"></use></svg>';
  };

  function nextFrame(fn) {
    window.requestAnimationFrame(function () { window.requestAnimationFrame(fn); });
  }

  function waitTransition(el, fallbackMs) {
    return new Promise(function (resolve) {
      if (reduceMotion || !el) { resolve(); return; }
      var done = false;
      var finish = function () { if (done) return; done = true; el.removeEventListener('transitionend', onEnd); resolve(); };
      var onEnd = function (event) { if (event.target === el) finish(); };
      el.addEventListener('transitionend', onEnd);
      window.setTimeout(finish, fallbackMs || 420);
    });
  }

  /* ---------- テーマ ---------- */
  function updateThemeMeta(theme) {
    var meta = document.querySelector('meta[name="theme-color"]');
    if (!meta) {
      meta = document.createElement('meta');
      meta.name = 'theme-color';
      document.head && document.head.appendChild(meta);
    }
    meta.setAttribute('content', theme === 'light' ? '#f4f5f9' : '#0b0d13');
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      var label = theme === 'light' ? 'ダークテーマに切り替え' : 'ライトテーマに切り替え';
      btn.setAttribute('aria-label', label);
      btn.setAttribute('title', label);
      btn.setAttribute('aria-pressed', theme === 'light' ? 'false' : 'true');
    });
    try {
      document.querySelectorAll('iframe[data-theme-sync]').forEach(function (frame) {
        var doc = frame.contentDocument;
        if (doc && doc.documentElement) doc.documentElement.setAttribute('data-theme', theme);
      });
    } catch (e) { /* cross-origin */ }
  }

  function applyTheme(theme) {
    root.setAttribute('data-theme', theme);
    try { window.localStorage.setItem(THEME_KEY, theme); } catch (e) { /* noop */ }
    updateThemeMeta(theme);
    try { window.dispatchEvent(new CustomEvent('ui:themechange', { detail: { theme: theme } })); } catch (e) { /* noop */ }
  }

  UI.getTheme = function () { return root.getAttribute('data-theme') === 'light' ? 'light' : 'dark'; };

  UI.setTheme = function (theme, origin) {
    var next = theme === 'light' ? 'light' : 'dark';
    if (next === UI.getTheme()) return;
    if (!reduceMotion && typeof document.startViewTransition === 'function') {
      var x = origin && typeof origin.x === 'number' ? origin.x : window.innerWidth - 40;
      var y = origin && typeof origin.y === 'number' ? origin.y : 32;
      var radius = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y));
      var transition = document.startViewTransition(function () { applyTheme(next); });
      transition.ready.then(function () {
        root.animate(
          { clipPath: ['circle(0px at ' + x + 'px ' + y + 'px)', 'circle(' + radius + 'px at ' + x + 'px ' + y + 'px)'] },
          { duration: 560, easing: 'cubic-bezier(.65,0,.35,1)', pseudoElement: '::view-transition-new(root)' }
        );
      }).catch(function () { /* noop */ });
      return;
    }
    root.classList.add('is-theme-switching');
    applyTheme(next);
    window.setTimeout(function () { root.classList.remove('is-theme-switching'); }, 420);
  };

  UI.toggleTheme = function (event) {
    var origin = null;
    if (event && event.currentTarget && event.currentTarget.getBoundingClientRect) {
      var rect = event.currentTarget.getBoundingClientRect();
      origin = { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 };
    }
    UI.setTheme(UI.getTheme() === 'light' ? 'dark' : 'light', origin);
  };

  // 別タブでの変更に追従
  window.addEventListener('storage', function (event) {
    if (event.key === THEME_KEY && (event.newValue === 'light' || event.newValue === 'dark')) {
      root.setAttribute('data-theme', event.newValue);
      updateThemeMeta(event.newValue);
    }
  });

  /* ---------- トースト ---------- */
  var TOAST_ICONS = { success: 'check-circle', error: 'x-circle', danger: 'x-circle', warning: 'alert-triangle', info: 'info' };
  var TOAST_TITLES = { success: '完了しました', error: 'エラー', danger: 'エラー', warning: 'ご確認ください', info: 'お知らせ' };

  function toastRegion() {
    var region = document.getElementById('ui-toast-region');
    if (region) return region;
    region = document.createElement('div');
    region.id = 'ui-toast-region';
    region.className = 'toast-region';
    region.setAttribute('aria-live', 'polite');
    region.setAttribute('aria-atomic', 'false');
    document.body.appendChild(region);
    return region;
  }

  function dismissToast(item) {
    if (!item || item.classList.contains('is-leaving')) return;
    item.classList.remove('is-visible');
    item.classList.add('is-leaving');
    var height = item.offsetHeight;
    item.style.marginBottom = '-' + (height + 10) + 'px';
    window.setTimeout(function () { item.remove(); }, reduceMotion ? 0 : 260);
  }

  UI.toast = function (message, opts) {
    opts = opts || {};
    if (!message && !opts.title) return null;
    var type = opts.type || 'info';
    if (type === 'danger') type = 'error';
    var duration = typeof opts.duration === 'number' ? opts.duration : (type === 'error' ? 7000 : 4200);
    var item = document.createElement('div');
    item.className = 'toast toast--' + type;
    item.setAttribute('role', type === 'error' ? 'alert' : 'status');
    item.innerHTML =
      '<span class="toast__icon">' + UI.icon(TOAST_ICONS[type] || 'info') + '</span>' +
      '<div class="toast__body">' +
        '<strong class="toast__title">' + esc(opts.title || TOAST_TITLES[type] || 'お知らせ') + '</strong>' +
        (message ? '<div class="toast__text">' + esc(message) + '</div>' : '') +
        (opts.detail ? '<small class="toast__detail">' + esc(opts.detail) + '</small>' : '') +
      '</div>' +
      '<button type="button" class="btn btn--ghost btn--icon btn--sm toast__close" aria-label="閉じる">' + UI.icon('x') + '</button>' +
      (duration > 0 ? '<span class="toast__timer"></span>' : '');
    item.querySelector('.toast__close').addEventListener('click', function () { dismissToast(item); });
    toastRegion().appendChild(item);
    nextFrame(function () { item.classList.add('is-visible'); });
    if (duration > 0) {
      var timer = item.querySelector('.toast__timer');
      var remaining = duration;
      var started = Date.now();
      var handle = null;
      var anim = null;
      var start = function () {
        started = Date.now();
        handle = window.setTimeout(function () { dismissToast(item); }, remaining);
        if (timer && timer.animate && !reduceMotion) {
          if (anim) { anim.play(); } else {
            anim = timer.animate([{ transform: 'scaleX(1)' }, { transform: 'scaleX(0)' }], { duration: duration, fill: 'forwards', easing: 'linear' });
          }
        }
      };
      var pause = function () {
        window.clearTimeout(handle);
        remaining = Math.max(800, remaining - (Date.now() - started));
        if (anim) anim.pause();
      };
      item.addEventListener('mouseenter', pause);
      item.addEventListener('mouseleave', start);
      start();
    }
    item.close = function () { dismissToast(item); };
    return item;
  };

  /* ---------- モーダル ---------- */
  var modalStack = [];

  function resolveEl(target) {
    if (!target) return null;
    if (typeof target === 'string') return document.getElementById(target);
    return target;
  }

  function lockScroll() { root.classList.add('has-modal'); }
  function unlockScroll() { if (!modalStack.length) root.classList.remove('has-modal'); }

  UI.openModal = function (target, opts) {
    var dialog = resolveEl(target);
    if (!dialog) return Promise.resolve();
    opts = opts || {};
    if (dialog.open && dialog.classList.contains('is-open')) return Promise.resolve();
    dialog.classList.remove('is-closing');
    dialog.__returnFocus = document.activeElement;
    if (typeof dialog.showModal === 'function') {
      if (!dialog.open) dialog.showModal();
    } else {
      dialog.setAttribute('open', '');
    }
    modalStack.push(dialog);
    lockScroll();
    nextFrame(function () { dialog.classList.add('is-open'); });
    var focusTarget = opts.focus
      ? (typeof opts.focus === 'string' ? dialog.querySelector(opts.focus) : opts.focus)
      : dialog.querySelector('[autofocus], [data-autofocus]');
    window.setTimeout(function () {
      if (focusTarget) { try { focusTarget.focus({ preventScroll: true }); } catch (e) { focusTarget.focus(); } }
    }, 30);
    try { dialog.dispatchEvent(new CustomEvent('ui:open')); } catch (e) { /* noop */ }
    return Promise.resolve();
  };

  UI.closeModal = function (target, returnValue) {
    var dialog = resolveEl(target);
    if (!dialog || !dialog.open || dialog.classList.contains('is-closing')) return Promise.resolve();
    dialog.classList.remove('is-open');
    dialog.classList.add('is-closing');
    var panel = dialog.querySelector('.modal__panel') || dialog;
    return waitTransition(panel, 360).then(function () {
      dialog.classList.remove('is-closing');
      if (typeof dialog.close === 'function') {
        try { dialog.close(returnValue == null ? '' : String(returnValue)); } catch (e) { dialog.removeAttribute('open'); }
      } else {
        dialog.removeAttribute('open');
      }
      modalStack = modalStack.filter(function (d) { return d !== dialog; });
      unlockScroll();
      var back = dialog.__returnFocus;
      if (back && typeof back.focus === 'function' && document.contains(back)) {
        try { back.focus({ preventScroll: true }); } catch (e) { /* noop */ }
      }
      try { dialog.dispatchEvent(new CustomEvent('ui:close', { detail: { returnValue: returnValue } })); } catch (e) { /* noop */ }
    });
  };

  function bindModalBehaviour(dialog) {
    if (dialog.__uiBound) return;
    dialog.__uiBound = true;
    // Esc は アニメーション付きで閉じる
    dialog.addEventListener('cancel', function (event) {
      event.preventDefault();
      if (dialog.getAttribute('data-static') === 'true') return;
      UI.closeModal(dialog);
    });
    // 背景クリックで閉じる
    dialog.addEventListener('mousedown', function (event) { dialog.__downOnBackdrop = event.target === dialog; });
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog && dialog.__downOnBackdrop && dialog.getAttribute('data-static') !== 'true') {
        UI.closeModal(dialog);
      }
    });
    // method="dialog" のフォーム送信もアニメーションさせる
    dialog.addEventListener('submit', function (event) {
      var form = event.target;
      if (form && form.getAttribute('method') === 'dialog') {
        event.preventDefault();
        var submitter = event.submitter;
        UI.closeModal(dialog, submitter ? submitter.value : '');
      }
    });
  }

  function buildDialog(opts) {
    var tone = opts.tone || 'default';
    var iconName = opts.icon || (tone === 'danger' ? 'alert-triangle' : tone === 'warning' ? 'alert-circle' : tone === 'success' ? 'check-circle' : 'info');
    var dialog = document.createElement('dialog');
    dialog.className = 'modal modal--sm';
    dialog.setAttribute('aria-labelledby', 'ui-dialog-title');
    dialog.innerHTML =
      '<div class="modal__panel" role="document">' +
        '<div class="modal__header">' +
          '<span class="modal__icon' + (tone !== 'default' ? ' modal__icon--' + tone : '') + '">' + UI.icon(iconName, 'ico--lg') + '</span>' +
          '<div><h2 class="modal__title" id="ui-dialog-title">' + esc(opts.title || '確認') + '</h2>' +
          (opts.message ? '<p class="modal__desc">' + esc(opts.message) + '</p>' : '') +
          (opts.html ? '<div class="modal__desc">' + opts.html + '</div>' : '') +
          '</div>' +
        '</div>' +
        '<div class="modal__footer">' +
          (opts.cancelText === false ? '' : '<button type="button" class="btn btn--ghost" data-ui-dialog="cancel">' + esc(opts.cancelText || 'キャンセル') + '</button>') +
          '<button type="button" class="btn ' + (tone === 'danger' ? 'btn--danger' : 'btn--primary') + '" data-ui-dialog="ok">' + esc(opts.okText || 'OK') + '</button>' +
        '</div>' +
      '</div>';
    return dialog;
  }

  UI.confirm = function (opts) {
    if (typeof opts === 'string') opts = { message: opts };
    opts = opts || {};
    return new Promise(function (resolve) {
      if (!document.body) { resolve(window.confirm(opts.message || opts.title || '')); return; }
      var dialog = buildDialog(opts);
      document.body.appendChild(dialog);
      bindModalBehaviour(dialog);
      var result = false;
      dialog.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-ui-dialog]');
        if (!btn) return;
        result = btn.getAttribute('data-ui-dialog') === 'ok';
        UI.closeModal(dialog);
      });
      dialog.addEventListener('ui:close', function () {
        dialog.remove();
        resolve(result);
      });
      UI.openModal(dialog, { focus: opts.tone === 'danger' ? '[data-ui-dialog="cancel"]' : '[data-ui-dialog="ok"]' });
    });
  };

  UI.alert = function (opts) {
    if (typeof opts === 'string') opts = { message: opts };
    opts = Object.assign({ okText: '閉じる', cancelText: false }, opts || {});
    return UI.confirm(opts).then(function () { return undefined; });
  };

  /* ---------- メニュー（ドロップダウン） ---------- */
  var openMenus = [];
  function closeMenus(except) {
    openMenus.slice().forEach(function (menu) {
      if (menu === except) return;
      menu.classList.remove('is-open');
      var trigger = menu.__trigger;
      if (trigger) trigger.setAttribute('aria-expanded', 'false');
      openMenus = openMenus.filter(function (m) { return m !== menu; });
    });
  }
  function toggleMenu(trigger) {
    var id = trigger.getAttribute('data-menu-toggle');
    var menu = id ? document.getElementById(id) : trigger.parentElement.querySelector('.menu');
    if (!menu) return;
    var willOpen = !menu.classList.contains('is-open');
    closeMenus(menu);
    menu.__trigger = trigger;
    menu.classList.toggle('is-open', willOpen);
    trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    if (willOpen) {
      openMenus.push(menu);
      var first = menu.querySelector('.menu__item:not([disabled])');
      if (first && trigger.__viaKeyboard) first.focus();
    } else {
      openMenus = openMenus.filter(function (m) { return m !== menu; });
    }
  }

  /* ---------- 検索トグル ---------- */
  function initSearchToggle(wrap) {
    if (wrap.__uiBound) return;
    wrap.__uiBound = true;
    var button = wrap.querySelector('.search-toggle__button');
    var input = wrap.querySelector('input');
    var clear = wrap.querySelector('.search-toggle__clear');
    if (!input) return;
    var open = function (focus) {
      wrap.classList.add('is-open');
      if (button) button.setAttribute('aria-expanded', 'true');
      if (focus) window.setTimeout(function () { input.focus(); }, 40);
    };
    var close = function () {
      wrap.classList.remove('is-open');
      if (button) { button.setAttribute('aria-expanded', 'false'); button.focus(); }
    };
    if (input.value) open(false);
    if (wrap.hasAttribute('data-open')) open(false);
    if (button) button.addEventListener('click', function () { open(true); });
    if (clear) clear.addEventListener('click', function () {
      if (input.value) {
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.focus();
        if (wrap.hasAttribute('data-submit-on-clear') && input.form) input.form.requestSubmit ? input.form.requestSubmit() : input.form.submit();
        return;
      }
      if (!wrap.hasAttribute('data-open')) close();
    });
    input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.stopPropagation();
        if (input.value) {
          input.value = '';
          input.dispatchEvent(new Event('input', { bubbles: true }));
        } else if (!wrap.hasAttribute('data-open')) {
          close();
        }
      }
    });
    input.addEventListener('blur', function () {
      window.setTimeout(function () {
        if (!input.value && !wrap.contains(document.activeElement) && !wrap.hasAttribute('data-open')) {
          wrap.classList.remove('is-open');
          if (button) button.setAttribute('aria-expanded', 'false');
        }
      }, 120);
    });
    wrap.__open = open;
    wrap.__close = close;
  }

  UI.searchToggle = initSearchToggle;

  /* ---------- テーブル: モバイル用ラベル付与 ---------- */
  UI.stampTables = function (scope) {
    (scope || document).querySelectorAll('.table-wrap.is-stacked table').forEach(function (table) {
      var heads = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) {
        return (th.getAttribute('data-label') || th.textContent || '').trim();
      });
      if (!heads.length) return;
      table.querySelectorAll('tbody tr').forEach(function (tr) {
        var cells = tr.children;
        if (cells.length !== heads.length) return;
        for (var i = 0; i < cells.length; i += 1) {
          if (!cells[i].hasAttribute('data-label')) cells[i].setAttribute('data-label', heads[i]);
        }
      });
    });
  };

  /* ---------- サイドバー ---------- */
  var SIDEBAR_KEY = 'fitsc-sidebar-collapsed';
  function initShell() {
    var shell = document.querySelector('.app-shell');
    if (!shell) return;
    try {
      if (window.localStorage.getItem(SIDEBAR_KEY) === '1') shell.setAttribute('data-collapsed', 'true');
    } catch (e) { /* noop */ }
    var setNavOpen = function (open) {
      shell.classList.toggle('is-nav-open', open);
      document.querySelectorAll('[data-sidebar-toggle]').forEach(function (b) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); });
      if (open) {
        var first = shell.querySelector('.sidebar .nav-item');
        if (first) window.setTimeout(function () { first.focus(); }, 60);
      }
    };
    document.addEventListener('click', function (event) {
      if (event.target.closest('[data-sidebar-toggle]')) { setNavOpen(!shell.classList.contains('is-nav-open')); return; }
      if (event.target.closest('[data-sidebar-close]')) { setNavOpen(false); return; }
      if (event.target.closest('[data-sidebar-collapse]')) {
        var collapsed = shell.getAttribute('data-collapsed') === 'true';
        shell.setAttribute('data-collapsed', collapsed ? 'false' : 'true');
        try { window.localStorage.setItem(SIDEBAR_KEY, collapsed ? '0' : '1'); } catch (e) { /* noop */ }
        return;
      }
      if (shell.classList.contains('is-nav-open') && event.target.closest('.sidebar .nav-item[href], .sidebar .nav-item[data-nav-href]')) {
        setNavOpen(false);
      }
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && shell.classList.contains('is-nav-open')) setNavOpen(false);
    });
  }

  /* ---------- フォームの確認ダイアログ ---------- */
  function initConfirmForms() {
    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!(form instanceof HTMLFormElement)) return;
      var message = form.getAttribute('data-confirm');
      var submitter = event.submitter || null;
      if (submitter && submitter.hasAttribute('data-confirm')) message = submitter.getAttribute('data-confirm');
      if (!message) return;
      if (form.__uiConfirmed) { form.__uiConfirmed = false; return; }
      event.preventDefault();
      event.stopImmediatePropagation();
      var tone = (submitter && submitter.getAttribute('data-confirm-tone')) || form.getAttribute('data-confirm-tone') || 'danger';
      UI.confirm({
        title: (submitter && submitter.getAttribute('data-confirm-title')) || form.getAttribute('data-confirm-title') || '実行してよろしいですか？',
        message: message,
        okText: (submitter && submitter.getAttribute('data-confirm-ok')) || form.getAttribute('data-confirm-ok') || '実行する',
        tone: tone
      }).then(function (ok) {
        if (!ok) return;
        form.__uiConfirmed = true;
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
        } else {
          form.submit();
        }
      });
    }, true);
  }

  /* ---------- 送信中表示（多重送信防止） ---------- */
  function initBusyForms() {
    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
      if (!form.hasAttribute('data-busy')) return;
      var submitter = event.submitter || form.querySelector('[type="submit"]');
      window.setTimeout(function () {
        if (event.defaultPrevented) return;
        if (submitter) { submitter.classList.add('is-loading'); submitter.setAttribute('aria-busy', 'true'); }
        form.querySelectorAll('[type="submit"]').forEach(function (b) { if (b !== submitter) b.disabled = true; });
      }, 0);
    });
    window.addEventListener('pageshow', function () {
      document.querySelectorAll('form[data-busy] [type="submit"]').forEach(function (b) {
        b.classList.remove('is-loading');
        b.removeAttribute('aria-busy');
        b.disabled = b.hasAttribute('data-disabled');
      });
    });
  }

  /* ---------- 宣言的な挙動 ---------- */
  function initDeclarative() {
    document.addEventListener('click', function (event) {
      var t = event.target;
      if (!(t instanceof Element)) return;

      var themeBtn = t.closest('[data-theme-toggle]');
      if (themeBtn) { event.preventDefault(); UI.toggleTheme({ currentTarget: themeBtn }); return; }

      var opener = t.closest('[data-modal-open]');
      if (opener) { event.preventDefault(); UI.openModal(opener.getAttribute('data-modal-open')); return; }

      var closer = t.closest('[data-modal-close]');
      if (closer) {
        var dlg = closer.getAttribute('data-modal-close') ? document.getElementById(closer.getAttribute('data-modal-close')) : closer.closest('dialog');
        if (dlg) { event.preventDefault(); UI.closeModal(dlg); }
        return;
      }

      var menuTrigger = t.closest('[data-menu-toggle]');
      if (menuTrigger) { event.preventDefault(); toggleMenu(menuTrigger); return; }
      if (!t.closest('.menu')) closeMenus();
      else if (t.closest('.menu__item')) window.setTimeout(function () { closeMenus(); }, 60);

      var helpBtn = t.closest('.help-pop__btn');
      document.querySelectorAll('.help-pop.is-open').forEach(function (pop) { if (!helpBtn || !pop.contains(helpBtn)) pop.classList.remove('is-open'); });
      if (helpBtn) { event.preventDefault(); helpBtn.closest('.help-pop').classList.toggle('is-open'); return; }

      var copyBtn = t.closest('[data-copy]');
      if (copyBtn) {
        event.preventDefault();
        var text = copyBtn.getAttribute('data-copy');
        var src = copyBtn.getAttribute('data-copy-target');
        if (src) { var node = document.querySelector(src); text = node ? (node.value || node.textContent || '') : text; }
        var done = function () { UI.toast('', { type: 'success', title: 'コピーしました', duration: 1800 }); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text || '').then(done, function () { UI.toast('コピーできませんでした。', { type: 'error' }); });
        }
        return;
      }

      var navBtn = t.closest('[data-nav-href]');
      if (navBtn && !navBtn.hasAttribute('data-nav-managed')) {
        var href = navBtn.getAttribute('data-nav-href');
        if (href) {
          event.preventDefault();
          if (navBtn.getAttribute('data-nav-target') === '_blank') window.open(href, '_blank', 'noopener');
          else window.location.href = href;
        }
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        closeMenus();
        document.querySelectorAll('.help-pop.is-open').forEach(function (pop) { pop.classList.remove('is-open'); });
      }
      var trigger = event.target && event.target.closest ? event.target.closest('[data-menu-toggle]') : null;
      if (trigger && (event.key === 'Enter' || event.key === ' ' || event.key === 'ArrowDown')) trigger.__viaKeyboard = true;
    });

    // select 変更で自動送信（インライン onchange の置き換え。CSP 下でも動作）
    document.addEventListener('change', function (event) {
      var el = event.target;
      if (!(el instanceof Element)) return;
      if (el.hasAttribute('data-autosubmit') && el.form) {
        if (typeof el.form.requestSubmit === 'function') el.form.requestSubmit(); else el.form.submit();
      }
      var navSelect = el.closest('[data-autonav]');
      if (navSelect && navSelect.value) {
        var tpl = navSelect.getAttribute('data-autonav');
        window.location.href = tpl.replace('{value}', encodeURIComponent(navSelect.value));
      }
    });

    // 戻るボタン
    document.addEventListener('click', function (event) {
      var back = event.target instanceof Element ? event.target.closest('[data-history-back]') : null;
      if (!back) return;
      event.preventDefault();
      if (window.history.length > 1) window.history.back();
      else window.location.href = back.getAttribute('href') || '/';
    });
  }

  /* ---------- サーバー側フラッシュをトーストへ ---------- */
  function convertFlashes() {
    document.querySelectorAll('[data-toast]').forEach(function (el) {
      var type = el.getAttribute('data-toast') || 'info';
      var text = (el.getAttribute('data-toast-text') || el.textContent || '').trim();
      var title = el.getAttribute('data-toast-title') || '';
      if (!text) { el.remove(); return; }
      var sticky = type === 'error' || type === 'danger' || type === 'warning';
      UI.toast(text, { type: type, title: title || undefined, duration: sticky ? 0 : 4800 });
      el.remove();
    });
  }

  /* ---------- タブ ----------
     <div class="tabs" data-tabs [data-tabs-hash]> <button class="tab" data-tab="a">…</button> </div>
     <section data-tab-panel="a">…</section>（同じ [data-tabs-root] 内、なければ document） */
  function initTabs(bar) {
    if (bar.__tabsReady) return;
    bar.__tabsReady = true;
    var scope = bar.closest('[data-tabs-root]') || document;
    var tabs = Array.prototype.slice.call(bar.querySelectorAll('[data-tab]'));
    if (!tabs.length) return;
    bar.setAttribute('role', 'tablist');
    var select = function (name, focus) {
      var found = tabs.some(function (t) { return t.dataset.tab === name; });
      if (!found) name = tabs[0].dataset.tab;
      tabs.forEach(function (t) {
        var on = t.dataset.tab === name;
        t.setAttribute('role', 'tab');
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
        t.classList.toggle('is-active', on);
        if (on && focus) t.focus();
      });
      scope.querySelectorAll('[data-tab-panel]').forEach(function (panel) {
        if (!tabs.some(function (t) { return t.dataset.tab === panel.dataset.tabPanel; })) return;
        var on = panel.dataset.tabPanel === name;
        panel.hidden = !on;
        panel.setAttribute('role', 'tabpanel');
        if (on) { panel.classList.remove('tab-panel'); void panel.offsetWidth; panel.classList.add('tab-panel'); }
      });
      if (bar.hasAttribute('data-tabs-hash') && window.history.replaceState) {
        window.history.replaceState(null, '', '#' + name);
      }
      bar.dispatchEvent(new CustomEvent('ui:tab', { bubbles: true, detail: { tab: name } }));
    };
    bar.addEventListener('click', function (event) {
      var tab = event.target.closest('[data-tab]');
      if (tab && bar.contains(tab)) { event.preventDefault(); select(tab.dataset.tab); }
    });
    bar.addEventListener('keydown', function (event) {
      if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
      var i = tabs.indexOf(document.activeElement);
      if (i < 0) return;
      event.preventDefault();
      var next = tabs[(i + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
      select(next.dataset.tab, true);
    });
    var initial = bar.hasAttribute('data-tabs-hash') ? decodeURIComponent(window.location.hash.slice(1)) : '';
    var preset = tabs.filter(function (t) { return t.classList.contains('is-active') || t.getAttribute('aria-selected') === 'true'; })[0];
    select(initial || (preset ? preset.dataset.tab : tabs[0].dataset.tab));
  }

  /* ---------- 起動 ---------- */
  function init() {
    updateThemeMeta(UI.getTheme());
    document.querySelectorAll('dialog.modal').forEach(bindModalBehaviour);
    // 後から追加される dialog にも対応
    if ('MutationObserver' in window) {
      new MutationObserver(function (records) {
        records.forEach(function (r) {
          r.addedNodes.forEach(function (n) {
            if (n.nodeType !== 1) return;
            if (n.matches && n.matches('dialog.modal')) bindModalBehaviour(n);
            if (n.querySelectorAll) n.querySelectorAll('dialog.modal').forEach(bindModalBehaviour);
            if (n.matches && n.matches('[data-search-toggle]')) initSearchToggle(n);
            if (n.querySelectorAll) n.querySelectorAll('[data-search-toggle]').forEach(initSearchToggle);
          });
        });
      }).observe(document.body, { childList: true, subtree: true });
    }
    document.querySelectorAll('[data-search-toggle]').forEach(initSearchToggle);
    document.querySelectorAll('[data-tabs]').forEach(initTabs);
    initShell();
    initConfirmForms();
    initBusyForms();
    initDeclarative();
    UI.stampTables(document);
    if ('MutationObserver' in window) {
      document.querySelectorAll('.table-wrap.is-stacked tbody').forEach(function (tbody) {
        new MutationObserver(function () { UI.stampTables(tbody.closest('.table-wrap')); }).observe(tbody, { childList: true });
      });
    }
    convertFlashes();
    // サーバー側で開いた状態にしたいダイアログ（編集画面など）
    document.querySelectorAll('dialog.modal[data-open-on-load]').forEach(function (d) { UI.openModal(d); });
    root.classList.add('ui-ready');
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
