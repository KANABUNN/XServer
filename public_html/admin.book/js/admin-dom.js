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
    dateFromInput: document.getElementById('dateFromInput'),
    dateToInput: document.getElementById('dateToInput'),
    sortFieldSelect: document.getElementById('sortFieldSelect'),
    sortDirSelect: document.getElementById('sortDirSelect'),
    perPageSelect: document.getElementById('perPageSelect'),
    searchBtn: document.getElementById('searchBtn'),
    clearBtn: document.getElementById('clearBtn'),
    reloadBtn: document.getElementById('reloadBtn'),
    prevPageBtn: document.getElementById('prevPageBtn'),
    nextPageBtn: document.getElementById('nextPageBtn'),
    pageInfo: document.getElementById('pageInfo'),
    metaText: document.getElementById('metaText'),
    statusText: document.getElementById('statusText'),
    activeFilters: document.getElementById('activeFilters'),
    tableBody: document.getElementById('tableBody'),

    // Detail dialog
    detailDialog: document.getElementById('detailDialog'),
    detailGrid: document.getElementById('detailGrid'),
    detailSubText: document.getElementById('detailSubText'),
    detailStatus: document.getElementById('detailStatus'),
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
    calendarMonthInput: document.getElementById('calendarMonthInput'),
    calendarRoomFilter: document.getElementById('calendarRoomFilter'),
    calendarPrevMonthBtn: document.getElementById('calendarPrevMonthBtn'),
    calendarTodayBtn: document.getElementById('calendarTodayBtn'),
    calendarNextMonthBtn: document.getElementById('calendarNextMonthBtn'),
    calendarGrid: document.getElementById('calendarGrid'),
    calendarListBody: document.getElementById('calendarListBody'),
    calendarMetaText: document.getElementById('calendarMetaText'),
    calendarStatusText: document.getElementById('calendarStatusText'),

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
    calendarManageCloseBtn: document.getElementById('calendarManageCloseBtn'),
  };
})();
