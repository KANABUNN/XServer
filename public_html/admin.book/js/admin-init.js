/* admin-init.js
 * - 初期化（イベントバインド & 初回ロード）
 */
(function () {
  'use strict';

  const Admin = (window.Admin = window.Admin || {});
  const u = Admin.utils;

  function initDialogsBackdrop() {
    const el = Admin.el;
    u.bindDialogBackdropClose(el.calendarAddDialog);
    u.bindDialogBackdropClose(el.detailDialog);
    u.bindDialogBackdropClose(el.calendarManageDialog);
    u.bindDialogBackdropClose(el.switchbotCommandDialog);
  }

  async function init() {
    if (Admin.sidebar && typeof Admin.sidebar.init === 'function') {
      Admin.sidebar.init();
    }

    if (Admin.application && typeof Admin.application.bindEvents === 'function') {
      Admin.application.bindEvents();
    }

    if (Admin.calendar && typeof Admin.calendar.bindAddDialog === 'function') {
      Admin.calendar.bindAddDialog();
    }

    if (Admin.switchbot && typeof Admin.switchbot.bindEvents === 'function') {
      Admin.switchbot.bindEvents();
    }

    initDialogsBackdrop();

    // 初回ロード（申請一覧）
    if (Admin.application && typeof Admin.application.loadRooms === 'function') {
      await Admin.application.loadRooms();
    }
    if (Admin.application && typeof Admin.application.loadRows === 'function') {
      await Admin.application.loadRows();
    }
  }

  // defer + ここで即実行（元JSの IIFE 相当）
  (async function () {
    await init();
  })();
})();
