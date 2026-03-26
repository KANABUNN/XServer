/* admin-dom.js
 * - DOM参照をまとめる
 */
(function () {
  'use strict';

  const Admin = (window.Admin = window.Admin || {});

  Admin.el = {
    // Application list filters
    searchInput: document.getElementById('searchInput'),
    roomFilter: document.getElementById('roomFilter'),
    statusFilter: document.getElementById('statusFilter'),
    dateFromInput: document.getElementById('dateFromInput'),
    dateToInput: document.getElementById('dateToInput'),
    sortFieldSelect: document.getElementById('sortFieldSelect'),
    sortDirSelect: document.getElementById('sortDirSelect'),
    perPageSelect: document.getElementById('perPageSelect'),
    searchBtn: document.getElementById('searchBtn'),
    clearBtn: document.getElementById('clearBtn'),
    reloadBtn: document.getElementById('reloadBtn'),
    applicationCsvExportBtn: document.getElementById('applicationCsvExportBtn'),
    prevPageBtn: document.getElementById('prevPageBtn'),
    nextPageBtn: document.getElementById('nextPageBtn'),
    pageInfo: document.getElementById('pageInfo'),
    metaText: document.getElementById('metaText'),
    statusText: document.getElementById('statusText'),
    activeFilters: document.getElementById('activeFilters'),
    tableBody: document.getElementById('tableBody'),
    applicationTableWrap: document.getElementById('applicationTableWrap'),
    applicationCardList: document.getElementById('applicationCardList'),

    // Detail dialog
    detailDialog: document.getElementById('detailDialog'),
    detailGrid: document.getElementById('detailGrid'),
    detailSubText: document.getElementById('detailSubText'),
    detailStatus: document.getElementById('detailStatus'),
    detailStatusEditor: document.querySelector('.detail-status-editor'),
    detailApplicationStatusSelect: document.getElementById('detailApplicationStatusSelect'),
    detailApplicationStatusSaveBtn: document.getElementById('detailApplicationStatusSaveBtn'),
    detailDownloadBtn: document.getElementById('detailDownloadBtn'),
    detailDeleteBtn: document.getElementById('detailDeleteBtn'),

    // Calendar add dialog (from application)
    calendarAddDialog: document.getElementById('calendarAddDialog'),
    calendarAddSubText: document.getElementById('calendarAddSubText'),
    calendarUseDate: document.getElementById('calendarUseDate'),
    calendarRoomCode: document.getElementById('calendarRoomCode'),
    calendarOrgName: document.getElementById('calendarOrgName'),
    calendarPeopleCount: document.getElementById('calendarPeopleCount'),
    calendarUsageStart: document.getElementById('calendarUsageStart'),
    calendarUsageEnd: document.getElementById('calendarUsageEnd'),
    calendarAddStatus: document.getElementById('calendarAddStatus'),
    calendarAddSubmitBtn: document.getElementById('calendarAddSubmitBtn'),
    calendarAddCloseBtn: document.getElementById('calendarAddCloseBtn'),
    calendarFillTodayBtn: document.getElementById('calendarFillTodayBtn'),

    // Sidebar
    sidebarLinks: Array.from(document.querySelectorAll('.sidebar-link')),
    contentViews: Array.from(document.querySelectorAll('.content-view')),

    // Calendar view
    calendarReloadBtn: document.getElementById('calendarReloadBtn'),
    calendarCsvExportBtn: document.getElementById('calendarCsvExportBtn'),
    calendarMonthInput: document.getElementById('calendarMonthInput'),
    calendarRoomFilter: document.getElementById('calendarRoomFilter'),
    calendarPrevMonthBtn: document.getElementById('calendarPrevMonthBtn'),
    calendarTodayBtn: document.getElementById('calendarTodayBtn'),
    calendarNextMonthBtn: document.getElementById('calendarNextMonthBtn'),
    calendarGrid: document.getElementById('calendarGrid'),
    calendarListBody: document.getElementById('calendarListBody'),
    calendarTableWrap: document.getElementById('calendarTableWrap'),
    calendarCardList: document.getElementById('calendarCardList'),
    calendarMetaText: document.getElementById('calendarMetaText'),
    calendarStatusText: document.getElementById('calendarStatusText'),

    // Reservation mail view
    reservationMailReloadBtn: document.getElementById('reservationMailReloadBtn'),
    mailComposeArea: document.getElementById('mailComposeArea'),
    mailViewerNotice: document.getElementById('mailViewerNotice'),
    reservationMailCsvExportBtn: document.getElementById('reservationMailCsvExportBtn'),
    reservationMailTo: document.getElementById('reservationMailTo'),
    reservationMailAppendBeneBtn: document.getElementById('reservationMailAppendBeneBtn'),
    reservationMailReservationSelect: document.getElementById('reservationMailReservationSelect'),
    reservationMailPasscodeSelect: document.getElementById('reservationMailPasscodeSelect'),
    reservationMailMetaText: document.getElementById('reservationMailMetaText'),
    reservationMailStatusText: document.getElementById('reservationMailStatusText'),
    reservationMailReservationSummary: document.getElementById('reservationMailReservationSummary'),
    reservationMailPasscodeSummary: document.getElementById('reservationMailPasscodeSummary'),
    reservationMailSendBtn: document.getElementById('reservationMailSendBtn'),
    reservationMailHistoryMetaText: document.getElementById('reservationMailHistoryMetaText'),
    reservationMailHistoryStatusText: document.getElementById('reservationMailHistoryStatusText'),
    reservationMailHistoryBody: document.getElementById('reservationMailHistoryBody'),


    // Calendar manage dialog
    calendarManageDialog: document.getElementById('calendarManageDialog'),
    calendarManageSubText: document.getElementById('calendarManageSubText'),
    calendarManageList: document.getElementById('calendarManageList'),
    calendarManageUseDate: document.getElementById('calendarManageUseDate'),
    calendarManageRoomCode: document.getElementById('calendarManageRoomCode'),
    calendarManageOrgName: document.getElementById('calendarManageOrgName'),
    calendarManagePeopleCount: document.getElementById('calendarManagePeopleCount'),
    calendarManageUsageStart: document.getElementById('calendarManageUsageStart'),
    calendarManageUsageEnd: document.getElementById('calendarManageUsageEnd'),
    calendarManageStatus: document.getElementById('calendarManageStatus'),
    calendarManageAddBtn: document.getElementById('calendarManageAddBtn'),
    calendarManageCancelEditBtn: document.getElementById('calendarManageCancelEditBtn'),
    calendarManageCloseBtn: document.getElementById('calendarManageCloseBtn'),

    // SwitchBot room access view
    switchbotReloadBtn: document.getElementById('switchbotReloadBtn'),
    switchbotMetaText: document.getElementById('switchbotMetaText'),
    switchbotStatusText: document.getElementById('switchbotStatusText'),
    switchbotRoomGrid: document.getElementById('switchbotRoomGrid'),
    switchbotWebhookSummary: document.getElementById('switchbotWebhookSummary'),
    switchbotSuggestedUrl: document.getElementById('switchbotSuggestedUrl'),
    switchbotWebhookList: document.getElementById('switchbotWebhookList'),
    switchbotWebhookStatusText: document.getElementById('switchbotWebhookStatusText'),
    switchbotWebhookSyncBtn: document.getElementById('switchbotWebhookSyncBtn'),
    switchbotWebhookToggleBtn: document.getElementById('switchbotWebhookToggleBtn'),
    switchbotCommandList: document.getElementById('switchbotCommandList'),
    switchbotCommandDialog: document.getElementById('switchbotCommandDialog'),
    switchbotCommandSubText: document.getElementById('switchbotCommandSubText'),
    switchbotCommandDetailStatus: document.getElementById('switchbotCommandDetailStatus'),
    switchbotCommandDetailGrid: document.getElementById('switchbotCommandDetailGrid'),
    switchbotCommandDetailJson: document.getElementById('switchbotCommandDetailJson'),
    switchbotCommandCloseBtn: document.getElementById('switchbotCommandCloseBtn'),

    // Admin management view
    adminReloadBtn: document.getElementById('adminReloadBtn'),
    adminUserId: document.getElementById('adminUserId'),
    adminLoginId: document.getElementById('adminLoginId'),
    adminDisplayName: document.getElementById('adminDisplayName'),
    adminEmail: document.getElementById('adminEmail'),
    adminRoleKey: document.getElementById('adminRoleKey'),
    adminIsActive: document.getElementById('adminIsActive'),
    adminPassword: document.getElementById('adminPassword'),
    adminUserCreateBtn: document.getElementById('adminUserCreateBtn'),
    adminUserUpdateBtn: document.getElementById('adminUserUpdateBtn'),
    adminUserResetBtn: document.getElementById('adminUserResetBtn'),
    adminUsersMetaText: document.getElementById('adminUsersMetaText'),
    adminUsersStatusText: document.getElementById('adminUsersStatusText'),
    adminUsersBody: document.getElementById('adminUsersBody'),
    adminAuditMetaText: document.getElementById('adminAuditMetaText'),
    adminAuditStatusText: document.getElementById('adminAuditStatusText'),
    adminAuditBody: document.getElementById('adminAuditBody'),
  };
})();
