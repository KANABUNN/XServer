document.addEventListener("DOMContentLoaded", () => {
  const calendarDialog = document.getElementById("calendarDialog");
  const openCalendarBtn = document.getElementById("openCalendar");
  const closeCalendarBtn = document.getElementById("closeCalendar");
  const prevMonthBtn = document.getElementById("prevMonth");
  const goTodayBtn = document.getElementById("goToday");
  const nextMonthBtn = document.getElementById("nextMonth");
  const calendarGrid = document.getElementById("calendarGrid");
  const calendarTitle = document.getElementById("calendarTitle");
  const calendarDetail = document.getElementById("calendarDetail");

  if (
    !calendarDialog ||
    !openCalendarBtn ||
    !closeCalendarBtn ||
    !prevMonthBtn ||
    !goTodayBtn ||
    !nextMonthBtn ||
    !calendarGrid ||
    !calendarTitle ||
    !calendarDetail
  ) {
    return;
  }

  let currentDate = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
  let selectedCell = null;
  let reservationData = {};

  const weekdayLabels = ["日", "月", "火", "水", "木", "金", "土"];

  function pad(num) {
    return String(num).padStart(2, "0");
  }

  function isSameDate(a, b) {
    return (
      a.getFullYear() === b.getFullYear() &&
      a.getMonth() === b.getMonth() &&
      a.getDate() === b.getDate()
    );
  }

  function formatDateKey(date) {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  }

  function getDayData(date) {
    const key = formatDateKey(date);
    return reservationData[key] || {
      tamoku: false,
      orange: false
    };
  }

  function hasBooking(dayData) {
    return Boolean(dayData.tamoku || dayData.orange);
  }

  async function loadReservationData() {
  const year = currentDate.getFullYear();
  const month = currentDate.getMonth() + 1;

  const response = await fetch(
    `get_calendar_status.php?year=${encodeURIComponent(year)}&month=${encodeURIComponent(month)}`,
    { cache: "no-store" }
  );

  if (!response.ok) {
    throw new Error("予約状況の取得に失敗しました。");
  }

  const json = await response.json();
  if (json && typeof json === "object" && !Array.isArray(json)) {
    reservationData = json;
    return;
  }

  throw new Error("予約状況の形式が不正です。");
  }

  function showDetail(date) {
    const dayData = getDayData(date);

    calendarDetail.innerHTML = `
      <div class="calendar-detail-card">
        <h3>${date.getFullYear()}年${date.getMonth() + 1}月${date.getDate()}日 の予約状況</h3>
        <ul class="calendar-detail-list">
          <li>
            <div class="calendar-detail-room">
              <strong>多目的室</strong>
              <span class="calendar-day-status status-${dayData.tamoku ? "full" : "available"}">
                ${dayData.tamoku ? "使用中" : "空き"}
              </span>
            </div>
          </li>
          <li>
            <div class="calendar-detail-room">
              <strong>オレンジの部屋</strong>
              <span class="calendar-day-status status-${dayData.orange ? "full" : "available"}">
                ${dayData.orange ? "使用中" : "空き"}
              </span>
            </div>
          </li>
        </ul>
      </div>
    `;
  }

  function createWeekdayHeaders() {
    weekdayLabels.forEach((label) => {
      const el = document.createElement("div");
      el.className = "calendar-weekday";
      el.textContent = label;
      calendarGrid.appendChild(el);
    });
  }

  function renderCalendar() {
    calendarGrid.innerHTML = "";
    selectedCell = null;

    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();
    calendarTitle.textContent = `${year}年${month + 1}月`;

    createWeekdayHeaders();

    const firstDay = new Date(year, month, 1);
    const startDay = firstDay.getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const daysInPrevMonth = new Date(year, month, 0).getDate();
    const totalCells = 42;
    const today = new Date();

    for (let i = 0; i < totalCells; i++) {
      const cell = document.createElement("button");
      cell.type = "button";
      cell.className = "calendar-day";

      let cellDate;
      let isOtherMonth = false;

      if (i < startDay) {
        const day = daysInPrevMonth - startDay + i + 1;
        cellDate = new Date(year, month - 1, day);
        isOtherMonth = true;
      } else if (i >= startDay + daysInMonth) {
        const day = i - (startDay + daysInMonth) + 1;
        cellDate = new Date(year, month + 1, day);
        isOtherMonth = true;
      } else {
        const day = i - startDay + 1;
        cellDate = new Date(year, month, day);
      }

      if (isOtherMonth) {
        cell.classList.add("is-other-month");
      }

      if (isSameDate(cellDate, today)) {
        cell.classList.add("is-today");
      }

      const dayData = getDayData(cellDate);
      if (hasBooking(dayData)) {
        cell.classList.add("has-booking");
      }

      cell.innerHTML = `
        <div class="calendar-day-top">
          <span class="calendar-day-number">${cellDate.getDate()}</span>
        </div>
        <div class="calendar-day-rooms">
          <div class="room-chip ${dayData.tamoku ? "full" : "available"}">
            多目的室：${dayData.tamoku ? "使用中" : "空き"}
          </div>
          <div class="room-chip ${dayData.orange ? "full" : "available"}">
            オレンジ：${dayData.orange ? "使用中" : "空き"}
          </div>
        </div>
      `;

      cell.addEventListener("click", () => {
        if (selectedCell) {
          selectedCell.classList.remove("is-selected");
        }
        cell.classList.add("is-selected");
        selectedCell = cell;
        showDetail(cellDate);
      });

      calendarGrid.appendChild(cell);
    }

    calendarDetail.innerHTML = '<p class="calendar-detail-placeholder">日付を選択すると部屋ごとの状況が表示されます。</p>';
  }

  async function reloadAndRender() {
    calendarDetail.innerHTML = '<p class="calendar-loading">予約状況を読み込んでいます...</p>';
    await loadReservationData();
    renderCalendar();
  }

  openCalendarBtn.addEventListener("click", async () => {
    calendarDialog.showModal();
    try {
      await reloadAndRender();
    } catch (error) {
      console.error(error);
      calendarTitle.textContent = "取得エラー";
      calendarGrid.innerHTML = "";
      calendarDetail.innerHTML = '<p class="calendar-error">予約状況の取得に失敗しました。</p>';
    }
  });

  closeCalendarBtn.addEventListener("click", () => {
    calendarDialog.close();
  });

  prevMonthBtn.addEventListener("click", async () => {
    currentDate = new Date(currentDate.getFullYear(), currentDate.getMonth() - 1, 1);
    try {
      await reloadAndRender();
    } catch (error) {
      console.error(error);
      calendarDetail.innerHTML = '<p class="calendar-error">予約状況の取得に失敗しました。</p>';
    }
  });

  goTodayBtn.addEventListener("click", async () => {
    const today = new Date();
    currentDate = new Date(today.getFullYear(), today.getMonth(), 1);
    try {
      await reloadAndRender();
    } catch (error) {
      console.error(error);
      calendarDetail.innerHTML = '<p class="calendar-error">予約状況の取得に失敗しました。</p>';
    }
  });

  nextMonthBtn.addEventListener("click", async () => {
    currentDate = new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 1);
    try {
      await reloadAndRender();
    } catch (error) {
      console.error(error);
      calendarDetail.innerHTML = '<p class="calendar-error">予約状況の取得に失敗しました。</p>';
    }
  });

  calendarDialog.addEventListener("click", (event) => {
    const rect = calendarDialog.getBoundingClientRect();
    const isInDialog =
      rect.top <= event.clientY &&
      event.clientY <= rect.top + rect.height &&
      rect.left <= event.clientX &&
      event.clientX <= rect.left + rect.width;

    if (!isInDialog) {
      calendarDialog.close();
    }
  });
});
