(() => {
  const form = document.getElementById('reservationForm');
  const roomSelect = document.getElementById('room_code');
  const hiddenInput = document.getElementById('use_date');
  const displayInput = document.getElementById('use_date_display');
  const calendarGrid = document.getElementById('calendarGrid');
  const calendarTitle = document.getElementById('calendarTitle');
  const calendarStatusText = document.getElementById('calendarStatusText');
  const calendarDetail = document.getElementById('calendarDetail');
  const prevMonthBtn = document.getElementById('prevMonth');
  const nextMonthBtn = document.getElementById('nextMonth');
  const goTodayBtn = document.getElementById('goToday');

  if (!form || !roomSelect || !hiddenInput || !displayInput || !calendarGrid || !calendarTitle || !calendarStatusText || !calendarDetail) {
    return;
  }

  const weekdayLabels = ['日', '月', '火', '水', '木', '金', '土'];
  let monthData = {};
  let selectedDate = '';
  let currentMonth = new Date();
  currentMonth.setDate(1);
  let minDate = null;
  let maxDate = null;

  function formatDateKey(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  }

  function parseDateKey(value) {
    const [y, m, d] = value.split('-').map(Number);
    return new Date(y, m - 1, d);
  }

  function formatDateLabel(value) {
    const date = parseDateKey(value);
    return `${date.getFullYear()}年${date.getMonth() + 1}月${date.getDate()}日（${weekdayLabels[date.getDay()]}）`;
  }

  function normalizeDate(date) {
    const copy = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    copy.setHours(0, 0, 0, 0);
    return copy;
  }

  function isWithinRange(date) {
    const target = normalizeDate(date);
    if (minDate && target < minDate) {
      return false;
    }
    if (maxDate && target > maxDate) {
      return false;
    }
    return true;
  }

  function isBookedOnSelectedRoom(dateKey) {
    const roomCode = roomSelect.value;
    if (!roomCode) {
      return false;
    }
    return Boolean(monthData[dateKey]?.[roomCode]);
  }

  function isSelectable(dateKey) {
    if (!roomSelect.value) {
      return false;
    }
    const date = parseDateKey(dateKey);
    return isWithinRange(date) && !isBookedOnSelectedRoom(dateKey);
  }

  function setSelectedDate(dateKey) {
    selectedDate = dateKey;
    hiddenInput.value = dateKey;
    displayInput.value = formatDateLabel(dateKey);
    renderCalendar();
    renderDetail(dateKey);
  }

  function renderDetail(dateKey = '') {
    if (!dateKey) {
      calendarDetail.innerHTML = '<p class="calendar-detail-placeholder">部屋を選び、利用日をクリックしてください。</p>';
      return;
    }

    const day = monthData[dateKey] || { tamoku: false, orange: false };
    const roomName = roomSelect.options[roomSelect.selectedIndex]?.textContent || '未選択';
    const selectedBooked = roomSelect.value ? Boolean(day[roomSelect.value]) : false;
    const selectable = isSelectable(dateKey);

    calendarDetail.innerHTML = `
      <div class="calendar-detail-card">
        <h3>${formatDateLabel(dateKey)}</h3>
        <ul class="calendar-detail-list">
          <li><strong>選択部屋</strong><span>${roomName}</span></li>
          <li><strong>多目的室</strong><span>${day.tamoku ? '予約済み' : '空き'}</span></li>
          <li><strong>オレンジの部屋</strong><span>${day.orange ? '予約済み' : '空き'}</span></li>
          <li><strong>選択可否</strong><span>${selectable ? '選択可能' : (selectedBooked ? 'この部屋は予約済み' : '選択不可')}</span></li>
        </ul>
      </div>
    `;
  }

  function renderWeekdayHeader() {
    weekdayLabels.forEach((label) => {
      const el = document.createElement('div');
      el.className = 'calendar-weekday';
      el.textContent = label;
      calendarGrid.appendChild(el);
    });
  }

  function renderCalendar() {
    calendarGrid.innerHTML = '';
    renderWeekdayHeader();

    calendarTitle.textContent = `${currentMonth.getFullYear()}年${currentMonth.getMonth() + 1}月`;
    const firstDay = new Date(currentMonth.getFullYear(), currentMonth.getMonth(), 1);
    const startDay = firstDay.getDay();
    const lastDate = new Date(currentMonth.getFullYear(), currentMonth.getMonth() + 1, 0).getDate();

    for (let i = 0; i < startDay; i++) {
      const empty = document.createElement('div');
      empty.className = 'calendar-day is-empty';
      calendarGrid.appendChild(empty);
    }

    for (let day = 1; day <= lastDate; day++) {
      const cellDate = new Date(currentMonth.getFullYear(), currentMonth.getMonth(), day);
      const dateKey = formatDateKey(cellDate);
      const data = monthData[dateKey] || { tamoku: false, orange: false };
      const isOutOfRange = !isWithinRange(cellDate);
      const booked = isBookedOnSelectedRoom(dateKey);
      const selectable = !isOutOfRange && roomSelect.value && !booked;
      const selected = selectedDate === dateKey;

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'calendar-day';
      if (isOutOfRange || !roomSelect.value) button.classList.add('is-disabled');
      if (booked) button.classList.add('is-booked');
      if (selected) button.classList.add('is-selected');
      if (selectable) button.classList.add('is-available');

      button.disabled = !selectable;
      button.dataset.date = dateKey;
      button.innerHTML = `
        <span class="calendar-day-number">${day}</span>
        <span class="calendar-day-note">${booked ? '予約済み' : (selectable ? '選択可' : '対象外')}</span>
        <span class="calendar-room-state">多:${data.tamoku ? '×' : '○'} / 橙:${data.orange ? '×' : '○'}</span>
      `;
      button.addEventListener('click', () => setSelectedDate(dateKey));
      calendarGrid.appendChild(button);
    }

    if (selectedDate) {
      renderDetail(selectedDate);
    } else {
      renderDetail('');
    }
  }

  async function loadMonth(date) {
    calendarStatusText.textContent = '予約状況を取得しています。';
    try {
      const response = await fetch(`get_calendar_status.php?year=${date.getFullYear()}&month=${date.getMonth() + 1}`, {
        credentials: 'same-origin',
      });
      const json = await response.json();
      if (!json.ok) {
        throw new Error(json.message || '予約状況の取得に失敗しました。');
      }

      monthData = json.days || {};
      if (json.min_date) {
        minDate = normalizeDate(parseDateKey(json.min_date));
      }
      if (json.max_date) {
        maxDate = normalizeDate(parseDateKey(json.max_date));
      }

      const desiredMonth = minDate ? new Date(minDate.getFullYear(), minDate.getMonth(), 1) : new Date();
      if (!selectedDate && currentMonth.getTime() === date.getTime()) {
        // noop
      }
      if (!selectedDate && currentMonth.getMonth() !== desiredMonth.getMonth() && currentMonth.getFullYear() !== desiredMonth.getFullYear()) {
        // noop
      }

      renderCalendar();
      calendarStatusText.textContent = minDate && maxDate
        ? `予約可能期間: ${formatDateKey(minDate)} ～ ${formatDateKey(maxDate)}`
        : '予約状況を表示しています。';
    } catch (error) {
      calendarGrid.innerHTML = '<p class="calendar-error">予約状況の取得に失敗しました。</p>';
      calendarStatusText.textContent = error instanceof Error ? error.message : '取得に失敗しました。';
    }
  }

  function moveMonth(offset) {
    const target = new Date(currentMonth.getFullYear(), currentMonth.getMonth() + offset, 1);
    if (minDate && target < new Date(minDate.getFullYear(), minDate.getMonth(), 1)) {
      return;
    }
    if (maxDate && target > new Date(maxDate.getFullYear(), maxDate.getMonth(), 1)) {
      return;
    }
    currentMonth = target;
    loadMonth(currentMonth);
  }

  roomSelect.addEventListener('change', () => {
    if (selectedDate && !isSelectable(selectedDate)) {
      selectedDate = '';
      hiddenInput.value = '';
      displayInput.value = '';
    }
    renderCalendar();
    renderDetail(selectedDate);
  });

  form.addEventListener('submit', (event) => {
    if (!roomSelect.value) {
      event.preventDefault();
      alert('部屋を選択してください。');
      roomSelect.focus();
      return;
    }
    if (!hiddenInput.value) {
      event.preventDefault();
      alert('利用日を選択してください。');
    }
  });

  prevMonthBtn?.addEventListener('click', () => moveMonth(-1));
  nextMonthBtn?.addEventListener('click', () => moveMonth(1));
  goTodayBtn?.addEventListener('click', () => {
    if (minDate) {
      currentMonth = new Date(minDate.getFullYear(), minDate.getMonth(), 1);
    } else {
      currentMonth = new Date();
      currentMonth.setDate(1);
    }
    loadMonth(currentMonth);
  });

  const initial = new Date();
  initial.setDate(1);
  currentMonth = initial;
  loadMonth(currentMonth);
})();
