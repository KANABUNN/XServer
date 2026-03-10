/* admin-core.js
 * 予約管理画面 JS（分割版）
 * - 共有設定/状態/定数
 */
(function () {
  'use strict';

  const Admin = (window.Admin = window.Admin || {});

  // API（js/ は index.html の1つ下なので ../ で同階層へ）
  Admin.apiPath = "../manage_reservations.php";

  // 一覧（申請）側の状態
  Admin.state = {
    page: 1,
    totalPages: 1,
    currentRows: [],
    lastDetailId: null,
  };

  // カレンダー（確定予約）側の状態
  Admin.calendar = Admin.calendar || {};
  Admin.calendar.state = {
    loaded: false,
    month: "",
    reservations: [],
    selectedDate: "",
  };

  Admin.calendar.targetReservationId = null;

  // 表示用定数
  Admin.constants = {
    roomLabelMap: {
      tamoku: "多目的室",
      orange: "オレンジの部屋",
    },
    weekdayLabels: ["日", "月", "火", "水", "木", "金", "土"],
  };
})();
