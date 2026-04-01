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

  function getMonthlyWindow(date) {
    const targetYear = date.getFullYear();
    const targetMonth = date.getMonth();
    const previousMonthLastDate = new Date(targetYear, targetMonth, 0);
    const previousMonthLastDay = previousMonthLastDate.getDate();

    const start = new Date(
      previousMonthLastDate.getFullYear(),
      previousMonthLastDate.getMonth(),
      Math.max(1, previousMonthLastDay - 1),
      0, 0, 0, 0
    );
    const end = new Date(
      previousMonthLastDate.getFullYear(),
      previousMonthLastDate.getMonth(),
      previousMonthLastDay,
      23, 59, 59, 999
    );

    return { start, end };
  }

  function getTemporaryWindow(date) {
    const start = new Date(date.getFullYear(), date.getMonth(), date.getDate() - 7, 0, 0, 0, 0);
    const end = new Date(date.getFullYear(), date.getMonth(), date.getDate() - 1, 17, 0, 0, 0);
    return { start, end };
  }

  function buildWindowSummary(date) {
    const monthlyWindow = getMonthlyWindow(date);
    const temporaryWindow = getTemporaryWindow(date);

    return {
      monthly: `${padMonthDay(monthlyWindow.start)}〜${padMonthDay(monthlyWindow.end)}`,
      temporary: `${formatDateTimeLabel(temporaryWindow.start)}〜${formatDateTimeLabel(temporaryWindow.end)}`,
    };
  }

  function isApplicationDayBlocked(now) {
    const holidayName = getJapaneseHolidayName(now);
    return {
      blocked: isWeekend(now) || Boolean(holidayName),
      holidayName,
    };
  }

  function getApplicationStatus(date, dayData, now = new Date()) {
    const applicationDay = startOfDay(now);
    const applicationDayStatus = isApplicationDayBlocked(applicationDay);
    const monthlyWindow = getMonthlyWindow(date);
    const temporaryWindow = getTemporaryWindow(date);
    const inMonthlyWindow = isWithinRange(now, monthlyWindow.start, monthlyWindow.end);
    const inTemporaryWindow = isWithinRange(now, temporaryWindow.start, temporaryWindow.end);
    const canApplyByRule = !applicationDayStatus.blocked && (inMonthlyWindow || inTemporaryWindow);

    const roomApplications = {
      tamoku: canApplyByRule && !dayData.tamoku,
      orange: canApplyByRule && !dayData.orange,
    };

    const anyApplicableRoom = roomApplications.tamoku || roomApplications.orange;
    const allRoomsBooked = Boolean(dayData.tamoku && dayData.orange);
    const isPastDeadline = now.getTime() > temporaryWindow.end.getTime();
    const isBeforeTemporaryWindow = now.getTime() < temporaryWindow.start.getTime();

    let summaryText = "申請期間外です。";
    let detailText = "現在は申請できません。";
    let badgeClass = "closed";
    let badgeLabel = "申請不可";

    if (applicationDayStatus.blocked) {
      badgeLabel = applicationDayStatus.holidayName ? "本日祝日" : "本日土日";
      summaryText = applicationDayStatus.holidayName
        ? `本日が ${applicationDayStatus.holidayName} のため申請できません。`
        : "本日が土日のため申請できません。";
      detailText = "借用日が土日でも、申請日が平日かつ申請期間内であれば申請できます。逆に、本日が土日祝の場合は申請操作ができません。";
    } else if (anyApplicableRoom) {
      badgeClass = "open";
      badgeLabel = "申請可";
      summaryText = inMonthlyWindow
        ? "月次申請期間中のため申請できます。"
        : "臨時申請期間中のため申請できます。";
      detailText = inMonthlyWindow
        ? "本日が申請可能日であり、前月の最終日とその前日の月次申請期間に入っています。空いている部屋のみ申請できます。"
        : "本日が申請可能日であり、利用日の7日前から前日17:00までの臨時申請期間です。空いている部屋のみ申請できます。";
    } else if (canApplyByRule && allRoomsBooked) {
      badgeLabel = "満室";
      summaryText = "申請期間内ですが、両部屋とも使用中です。";
      detailText = "本日は申請可能日ですが、表示上は空き部屋がありません。";
    } else if (isPastDeadline) {
      badgeLabel = "締切後";
      summaryText = "この日の申請期限は終了しています。";
      detailText = "臨時申請は前日17:00までです。借用日が土日でも、期限内かつ申請日が平日なら申請できます。";
    } else if (isBeforeTemporaryWindow) {
      badgeLabel = "期間前";
      summaryText = "まだ申請開始前です。";
      detailText = "臨時申請は利用日の7日前から、月次申請は前月の最終日とその前日の2日間のみ受け付けます。申請可否は借用日ではなく、申請する日が土日祝かどうかで判定します。";
    }

    return {
      applicationDayLabel: formatDateLabel(applicationDay),
      applicationDayBlocked: applicationDayStatus.blocked,
      applicationDayHolidayName: applicationDayStatus.holidayName,
      inMonthlyWindow,
      inTemporaryWindow,
      canApplyByRule,
      roomApplications,
      anyApplicableRoom,
      allRoomsBooked,
      badgeClass,
      badgeLabel,
      summaryText,
      detailText,
      windowSummary: buildWindowSummary(date),
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
            <li>月次申請日：${applicationStatus.windowSummary.monthly}（前月の最終日とその前日）</li>
            <li>臨時申請期間：${applicationStatus.windowSummary.temporary}</li>
            <li>借用日が土日でも、申請日が平日かつ期間内なら申請できます。</li>
            <li>本日が土日祝の場合は、借用日が平日でも申請できません。</li>
            <li>テスト期間はカレンダーに未反映です。</li>
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
      if (applicationStatus.applicationDayBlocked) {
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
