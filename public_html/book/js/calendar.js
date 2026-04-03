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
  const holidayCache = new Map();

  function pad(num) {
    return String(num).padStart(2, "0");
  }

  function padMonthDay(date) {
    return `${date.getMonth() + 1}/${date.getDate()}`;
  }

  function formatDateKey(date) {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  }

  function formatDateLabel(date) {
    return `${date.getFullYear()}年${date.getMonth() + 1}月${date.getDate()}日`;
  }

  function formatDateTimeLabel(date) {
    return `${date.getMonth() + 1}/${date.getDate()} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
  }

  function startOfDay(date) {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate(), 0, 0, 0, 0);
  }

  function endOfDay(date) {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate(), 23, 59, 59, 999);
  }

  function isSameDate(a, b) {
    return (
      a.getFullYear() === b.getFullYear() &&
      a.getMonth() === b.getMonth() &&
      a.getDate() === b.getDate()
    );
  }

  function isWeekend(date) {
    const day = date.getDay();
    return day === 0 || day === 6;
  }

  function isWithinRange(target, start, end) {
    return target.getTime() >= start.getTime() && target.getTime() <= end.getTime();
  }

  function nthWeekdayOfMonth(year, monthIndex, weekday, nth) {
    const firstDay = new Date(year, monthIndex, 1);
    const offset = (weekday - firstDay.getDay() + 7) % 7;
    return new Date(year, monthIndex, 1 + offset + (nth - 1) * 7);
  }

  function getSpringEquinoxDay(year) {
    if (year <= 2099) {
      return Math.floor(20.8431 + 0.242194 * (year - 1980) - Math.floor((year - 1980) / 4));
    }
    return 20;
  }

  function getAutumnEquinoxDay(year) {
    if (year <= 2099) {
      return Math.floor(23.2488 + 0.242194 * (year - 1980) - Math.floor((year - 1980) / 4));
    }
    return 23;
  }

  function addHoliday(map, date, name) {
    map.set(formatDateKey(date), name);
  }

  function getJapaneseHolidayMap(year) {
    if (holidayCache.has(year)) {
      return holidayCache.get(year);
    }

    const map = new Map();

    addHoliday(map, new Date(year, 0, 1), "元日");
    addHoliday(map, nthWeekdayOfMonth(year, 0, 1, 2), "成人の日");
    addHoliday(map, new Date(year, 1, 11), "建国記念の日");
    addHoliday(map, new Date(year, 1, 23), "天皇誕生日");
    addHoliday(map, new Date(year, 2, getSpringEquinoxDay(year)), "春分の日");
    addHoliday(map, new Date(year, 3, 29), "昭和の日");
    addHoliday(map, new Date(year, 4, 3), "憲法記念日");
    addHoliday(map, new Date(year, 4, 4), "みどりの日");
    addHoliday(map, new Date(year, 4, 5), "こどもの日");
    addHoliday(map, nthWeekdayOfMonth(year, 6, 1, 3), "海の日");
    addHoliday(map, new Date(year, 7, 11), "山の日");
    addHoliday(map, nthWeekdayOfMonth(year, 8, 1, 3), "敬老の日");
    addHoliday(map, new Date(year, 8, getAutumnEquinoxDay(year)), "秋分の日");
    addHoliday(map, nthWeekdayOfMonth(year, 9, 1, 2), "スポーツの日");
    addHoliday(map, new Date(year, 10, 3), "文化の日");
    addHoliday(map, new Date(year, 10, 23), "勤労感謝の日");

    const currentKeys = Array.from(map.keys()).sort();
    currentKeys.forEach((key) => {
      const date = new Date(key);
      if (date.getDay() !== 0) {
        return;
      }

      const substitute = new Date(date.getFullYear(), date.getMonth(), date.getDate() + 1);
      while (map.has(formatDateKey(substitute))) {
        substitute.setDate(substitute.getDate() + 1);
      }
      addHoliday(map, substitute, "振替休日");
    });

    for (let month = 0; month < 12; month++) {
      const daysInMonth = new Date(year, month + 1, 0).getDate();
      for (let day = 2; day < daysInMonth; day++) {
        const current = new Date(year, month, day);
        const currentKey = formatDateKey(current);
        if (map.has(currentKey) || isWeekend(current)) {
          continue;
        }

        const prev = new Date(year, month, day - 1);
        const next = new Date(year, month, day + 1);
        if (map.has(formatDateKey(prev)) && map.has(formatDateKey(next))) {
          addHoliday(map, current, "国民の休日");
        }
      }
    }

    holidayCache.set(year, map);
    return map;
  }

  function getJapaneseHolidayName(date) {
    const map = getJapaneseHolidayMap(date.getFullYear());
    return map.get(formatDateKey(date)) || "";
  }

  function getDayData(date) {
    const key = formatDateKey(date);
    return reservationData[key] || {
      tamoku: false,
      orange: false,
    };
  }

  function hasBooking(dayData) {
    return Boolean(dayData.tamoku || dayData.orange);
  }


  function isSameMonth(a, b) {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth();
  }

  function monthSerial(date) {
    return date.getFullYear() * 12 + date.getMonth();
  }

  function isNextMonth(target, base) {
    return monthSerial(target) === monthSerial(base) + 1;
  }

  function formatMonthLabel(date) {
    return `${date.getFullYear()}年${date.getMonth() + 1}月`;
  }

  function getTargetBulkWindow(date) {
    const start = new Date(date.getFullYear(), date.getMonth() - 1, 1, 0, 0, 0, 0);
    const end = new Date(date.getFullYear(), date.getMonth() - 1, 2, 23, 59, 59, 999);
    return { start, end };
  }

  function buildWindowSummary(date, now = new Date()) {
    const applicationDay = startOfDay(now);
    const targetBulkWindow = getTargetBulkWindow(date);

    return {
      currentMonth: `${formatMonthLabel(applicationDay)} 分は当月中に申請可`,
      nextMonthBulk: `${formatMonthLabel(date)} 分の一括申請日：${formatDateLabel(targetBulkWindow.start)}〜${formatDateLabel(targetBulkWindow.end)}`,
      sameDay: "同日利用分は使用開始したい時刻まで提出可（時刻は申請書で確認）",
    };
  }

  function getApplicationStatus(date, dayData, now = new Date()) {
    const applicationDay = startOfDay(now);
    const targetDay = startOfDay(date);
    const isPastDate = targetDay.getTime() < applicationDay.getTime();
    const isTodayTarget = isSameDate(targetDay, applicationDay);
    const isBulkWindow = applicationDay.getDate() === 1 || applicationDay.getDate() === 2;
    const targetIsCurrentMonth = isSameMonth(targetDay, applicationDay);
    const targetIsNextMonth = isNextMonth(targetDay, applicationDay);
    const targetBulkWindow = getTargetBulkWindow(targetDay);
    const canApplyByRule = !isPastDate && (targetIsCurrentMonth || (targetIsNextMonth && isBulkWindow));

    const roomApplications = {
      tamoku: canApplyByRule && !dayData.tamoku,
      orange: canApplyByRule && !dayData.orange,
    };

    const anyApplicableRoom = roomApplications.tamoku || roomApplications.orange;
    const allRoomsBooked = Boolean(dayData.tamoku && dayData.orange);
    const isRuleBlocked = !canApplyByRule;

    let summaryText = "現在は申請対象外です。";
    let detailText = "この日付は現在の受付対象外です。";
    let badgeClass = "closed";
    let badgeLabel = "申請不可";

    if (isPastDate) {
      badgeLabel = "終了日";
      summaryText = "この日はすでに経過しています。";
      detailText = "過去の日付については申請できません。";
    } else if (anyApplicableRoom) {
      badgeClass = "open";
      badgeLabel = isTodayTarget ? "本日可" : "申請可";

      if (targetIsNextMonth) {
        summaryText = "翌月分の一括申請期間中のため申請できます。";
        detailText = "本日が前月1日または2日のため、翌月分の空いている部屋を申請できます。";
      } else if (isTodayTarget) {
        summaryText = "本日分も申請できます。";
        detailText = "規約上、借用申請書類は使用開始したい時刻まで提出できます。システムは日付単位で表示しているため、最終可否は申請書に記載した利用開始時刻で確認してください。";
      } else {
        summaryText = "当月分のため申請できます。";
        detailText = "規約上、当月分は当月中に申請できます。空いている部屋のみ申請できます。";
      }
    } else if (!anyApplicableRoom && canApplyByRule && allRoomsBooked) {
      badgeLabel = "満室";
      summaryText = "申請可能期間ですが、両部屋とも使用中です。";
      detailText = "現在の表示上は空き部屋がありません。";
    } else if (targetIsNextMonth) {
      badgeLabel = "翌月待ち";
      summaryText = "翌月分は前月1日・2日のみ申請できます。";
      detailText = `この日付の申請受付は ${formatDateLabel(targetBulkWindow.start)}〜${formatDateLabel(targetBulkWindow.end)} です。`;
    } else {
      badgeLabel = "受付前";
      summaryText = "まだこの月の申請受付前です。";
      detailText = "この日付の申請は、原則として前月1日・2日の一括申請期間から受け付けます。";
    }

    return {
      applicationDayLabel: formatDateLabel(applicationDay),
      applicationDayBlocked: false,
      applicationDayHolidayName: "",
      canApplyByRule,
      roomApplications,
      anyApplicableRoom,
      allRoomsBooked,
      badgeClass,
      badgeLabel,
      summaryText,
      detailText,
      windowSummary: buildWindowSummary(date, now),
      isBulkWindow,
      isTodayTarget,
      isPastDate,
      targetIsCurrentMonth,
      targetIsNextMonth,
      isRuleBlocked,
    };
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

  function getRoomStatusLabel(isBooked) {
    return isBooked ? "使用中" : "空き";
  }


  function showDetail(date) {
    const dayData = getDayData(date);
    const applicationStatus = getApplicationStatus(date, dayData);

    calendarDetail.innerHTML = `
      <div class="calendar-detail-card">
        <h3>${formatDateLabel(date)} の予約状況・申請可否</h3>

        <div class="calendar-apply-summary ${applicationStatus.badgeClass}">
          <div class="calendar-apply-summary-head">
            <span class="calendar-apply-chip ${applicationStatus.badgeClass}">${applicationStatus.badgeLabel}</span>
            <strong>${applicationStatus.summaryText}</strong>
          </div>
          <p>${applicationStatus.detailText}</p>
          <ul class="calendar-rule-list">
            <li>判定基準日：${applicationStatus.applicationDayLabel}</li>
            <li>当月分の扱い：${applicationStatus.windowSummary.currentMonth}</li>
            <li>翌月分の扱い：${applicationStatus.windowSummary.nextMonthBulk}</li>
            <li>同日利用分：${applicationStatus.windowSummary.sameDay}</li>
            <li>本日が一括申請日か：${applicationStatus.isBulkWindow ? "はい（1日または2日）" : "いいえ"}</li>
          </ul>
        </div>

        <ul class="calendar-detail-list">
          <li>
            <div class="calendar-detail-room">
              <strong>多目的室</strong>
              <div class="calendar-detail-badges">
                <span class="calendar-day-status status-${dayData.tamoku ? "full" : "available"}">
                  ${getRoomStatusLabel(dayData.tamoku)}
                </span>
                <span class="calendar-day-status status-${applicationStatus.roomApplications.tamoku ? "apply-open" : "apply-closed"}">
                  ${applicationStatus.roomApplications.tamoku ? "申請可" : "申請不可"}
                </span>
              </div>
            </div>
          </li>
          <li>
            <div class="calendar-detail-room">
              <strong>オレンジの部屋</strong>
              <div class="calendar-detail-badges">
                <span class="calendar-day-status status-${dayData.orange ? "full" : "available"}">
                  ${getRoomStatusLabel(dayData.orange)}
                </span>
                <span class="calendar-day-status status-${applicationStatus.roomApplications.orange ? "apply-open" : "apply-closed"}">
                  ${applicationStatus.roomApplications.orange ? "申請可" : "申請不可"}
                </span>
              </div>
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
      const applicationStatus = getApplicationStatus(cellDate, dayData, today);

      if (hasBooking(dayData)) {
        cell.classList.add("has-booking");
      }
      if (applicationStatus.anyApplicableRoom) {
        cell.classList.add("can-apply");
      } else {
        cell.classList.add("cannot-apply");
      }
      if (applicationStatus.isRuleBlocked) {
        cell.classList.add("is-rule-blocked");
      }
      if (applicationStatus.allRoomsBooked) {
        cell.classList.add("is-fully-booked");
      }

      cell.title = `${formatDateLabel(cellDate)}\n${applicationStatus.summaryText}`;
      cell.innerHTML = `
        <div class="calendar-day-top">
          <span class="calendar-day-number">${cellDate.getDate()}</span>
          <span class="calendar-apply-chip ${applicationStatus.badgeClass}">${applicationStatus.badgeLabel}</span>
        </div>
        <div class="calendar-day-rooms">
          <div class="room-chip ${dayData.tamoku ? "full" : "available"}">
            多目的室：${getRoomStatusLabel(dayData.tamoku)}
          </div>
          <div class="room-chip ${dayData.orange ? "full" : "available"}">
            オレンジ：${getRoomStatusLabel(dayData.orange)}
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

    calendarDetail.innerHTML = '<p class="calendar-detail-placeholder">日付を選択すると、予約状況と現在の申請可否が表示されます。</p>';
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
