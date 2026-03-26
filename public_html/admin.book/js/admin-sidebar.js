/* admin-sidebar.js
 * - 左サイドバーの表示切り替え
 */
(function () {
  'use strict';

  const Admin = (window.Admin = window.Admin || {});
  const { setStatus } = Admin.utils || {};

  const viewPermissionMap = {
    applicationView: 'application.view',
    calendarView: 'calendar.view',
    mailView: 'mail.view',
    switchbotView: 'access.view',
  };

  function isViewAllowed(viewId) {
    const permission = viewPermissionMap[viewId] || '';
    return !permission || Admin.hasPermission(permission);
  }

  function switchView(viewId) {
    const { contentViews, sidebarLinks } = Admin.el || {};
    if (!viewId || !contentViews || !contentViews.length || !isViewAllowed(viewId)) return;

    contentViews.forEach((view) => {
      view.classList.toggle('is-active', view.id === viewId);
    });

    (sidebarLinks || []).forEach((btn) => {
      btn.classList.toggle('is-active', btn.dataset.viewTarget === viewId);
    });

    if (viewId === 'calendarView') {
      if (Admin.calendar && typeof Admin.calendar.ensureLoaded === 'function') {
        Admin.calendar.ensureLoaded();
      } else {
        setStatus && setStatus('カレンダー機能が初期化されていません。', true);
      }
    }

    if (viewId === 'mailView') {
      if (Admin.mail && typeof Admin.mail.ensureLoaded === 'function') {
        Admin.mail.ensureLoaded();
      } else {
        setStatus && setStatus('予約通知メール機能が初期化されていません。', true);
      }
    }

    if (viewId === 'switchbotView') {
      if (Admin.switchbot && typeof Admin.switchbot.ensureLoaded === 'function') {
        Admin.switchbot.ensureLoaded();
      } else {
        setStatus && setStatus('借用部屋管理機能が初期化されていません。', true);
      }
    }
  }

  function init() {
    const { sidebarLinks, contentViews } = Admin.el || {};
    if (!sidebarLinks || !sidebarLinks.length || !contentViews || !contentViews.length) return;

    sidebarLinks.forEach((btn) => {
      const allowed = isViewAllowed(btn.dataset.viewTarget || '');
      btn.hidden = !allowed;
      btn.addEventListener('click', () => switchView(btn.dataset.viewTarget));
    });

    contentViews.forEach((view) => {
      view.hidden = !isViewAllowed(view.id || '');
      if (view.hidden) {
        view.classList.remove('is-active');
      }
    });

    const firstAllowed = sidebarLinks.find((btn) => !btn.hidden)?.dataset.viewTarget || '';
    const activeAllowed = sidebarLinks.find((btn) => btn.classList.contains('is-active') && !btn.hidden)?.dataset.viewTarget || '';
    switchView(activeAllowed || firstAllowed);
  }

  Admin.sidebar = { switchView, init, isViewAllowed };
})();
