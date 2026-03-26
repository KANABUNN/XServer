/* admin-core.js
 * 予約管理画面 JS（分割版）
 * - 共有設定/状態/定数
 */
(function () {
  'use strict';

  const Admin = (window.Admin = window.Admin || {});
  Admin.bootstrap = window.AdminBootstrap || {};
  Admin.currentUser = (Admin.bootstrap && Admin.bootstrap.currentUser) || {};
  const grantedPermissions = new Set(Array.isArray(Admin.currentUser.permissions) ? Admin.currentUser.permissions.map(String) : []);

  Admin.permissions = Array.from(grantedPermissions);
  Admin.hasPermission = function hasPermission(permission) {
    if (!permission) return true;
    return grantedPermissions.has(String(permission));
  };
  Admin.hasAnyPermission = function hasAnyPermission(permissions) {
    return Array.isArray(permissions) && permissions.some((permission) => Admin.hasPermission(permission));
  };

  // API（js/ は index.php と同階層）
  Admin.apiPath = 'manage_reservations.php';

  Admin.handleUnauthorized = function handleUnauthorized(payload) {
    const loginUrl = (payload && payload.login_url) || 'login.php';
    const separator = loginUrl.includes('?') ? '&' : '?';
    const returnTo = encodeURIComponent(window.location.pathname + window.location.search + window.location.hash);
    window.location.href = `${loginUrl}${separator}return_to=${returnTo}`;
  };

  if (!window.__adminFetchWrapped) {
    const rawFetch = window.fetch.bind(window);
    window.fetch = async function adminFetch(input, init = {}) {
      const options = { ...init };
      options.credentials = options.credentials || 'same-origin';

      const headers = new Headers(options.headers || {});
      if (!headers.has('X-Requested-With')) {
        headers.set('X-Requested-With', 'fetch');
      }
      options.headers = headers;

      const response = await rawFetch(input, options);
      if (response.status === 401) {
        let payload = null;
        try {
          payload = await response.clone().json();
        } catch (_) {
          payload = null;
        }
        Admin.handleUnauthorized(payload);
        throw new Error((payload && payload.message) || 'ログインが必要です。');
      }
      return response;
    };
    window.__adminFetchWrapped = true;
  }

  // 一覧（申請）側の状態
  Admin.state = {
    page: 1,
    totalPages: 1,
    currentRows: [],
    lastDetailId: null,
    detailRow: null,
  };

  // カレンダー（確定予約）側の状態
  Admin.calendar = Admin.calendar || {};
  Admin.calendar.state = {
    loaded: false,
    month: '',
    reservations: [],
    selectedDate: '',
    editingReservationId: null,
  };

  Admin.calendar.targetReservationId = null;

  // 予約通知メール側の状態
  Admin.mail = Admin.mail || {};
  Admin.mail.state = {
    loaded: false,
    initialized: false,
    reservations: [],
    passcodes: [],
    historyRows: [],
  };

  // 借用部屋管理（SwitchBot）側の状態
  Admin.switchbot = Admin.switchbot || {};
  Admin.switchbot.state = {
    loaded: false,
    status: null,
    busy: false,
  };

  // 表示用定数
  Admin.constants = {
    roomLabelMap: {
      tamoku: '多目的室',
      orange: 'オレンジの部屋',
    },
    applicationStatusMap: {
      pending: '未確認',
      reviewing: '確認中',
      confirmed: '確定',
      rejected: '却下',
    },
    weekdayLabels: ['日', '月', '火', '水', '木', '金', '土'],
  };
})();
