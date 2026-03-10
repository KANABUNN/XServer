/* admin-sidebar.js
 * - 左サイドバーの表示切り替え
 */
(function () {
  'use strict';

  const Admin = (window.Admin = window.Admin || {});
  const { setStatus } = Admin.utils || {};

  function switchView(viewId) {
    const { contentViews, sidebarLinks } = Admin.el || {};
    if (!viewId || !contentViews || !contentViews.length) return;

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
  }

  function init() {
    const { sidebarLinks, contentViews } = Admin.el || {};
    if (!sidebarLinks || !sidebarLinks.length || !contentViews || !contentViews.length) return;

    sidebarLinks.forEach((btn) => {
      btn.addEventListener('click', () => switchView(btn.dataset.viewTarget));
    });
  }

  Admin.sidebar = { switchView, init };
})();
