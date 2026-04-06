(() => {
  const form = document.getElementById('reservationForm');
  const roomSelect = document.getElementById('room_code');
  const hiddenInput = document.getElementById('use_dates');
  const displayInput = document.getElementById('use_dates_display');
  const usageStartSelect = document.getElementById('usage_start_time');
  const usageEndSelect = document.getElementById('usage_end_time');
  const calendarGrid = document.getElementById('calendarGrid');
  const calendarTitle = document.getElementById('calendarTitle');
  const calendarStatusText = document.getElementById('calendarStatusText');
  const calendarDetail = document.getElementById('calendarDetail');
  const prevMonthBtn = document.getElementById('prevMonth');
  const nextMonthBtn = document.getElementById('nextMonth');
  const goAvailableStartBtn = document.getElementById('goAvailableStart');
  const openTermsDialogBtn = document.getElementById('openTermsDialog');
  const termsDialog = document.getElementById('termsDialog');
  const agreeFromDialogBtn = document.getElementById('agreeFromDialog');
  const agreeTerms = document.getElementById('agreeTerms');

  if (!form || !roomSelect || !hiddenInput || !displayInput || !usageStartSelect || !usageEndSelect || !calendarGrid || !calendarTitle || !calendarStatusText || !calendarDetail) {
    return;
  }

  const weekdayLabels = ['日', '月', '火', '水', '木', '金', '土'];
  let monthData = {};
  let selectedDates = [];
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
    if (minDate && target < minDate) return false;
    if (maxDate && target > maxDate) return false;
    return true;
  }

  function isBookedOnSelectedRoom(dateKey) {
    const roomCode = roomSelect.value;
    if (!roomCode) return false;
    return Boolean(monthData[dateKey]?.[roomCode]);
  }

  function isSelectable(dateKey) {
    if (!roomSelect.value) return false;
    const date = parseDateKey(dateKey);
    return isWithinRange(date) && !isBookedOnSelectedRoom(dateKey);
  }

  function updateSelectedFields() {
    selectedDates.sort();
    hiddenInput.value = JSON.stringify(selectedDates);
    displayInput.value = selectedDates.length ? selectedDates.map(formatDateLabel).join(' / ') : '';
  }

  function renderDetail() {
    if (!selectedDates.length) {
      calendarDetail.innerHTML = '<p class="calendar-detail-placeholder">部屋を選び、利用日をクリックしてください。</p>';
      return;
    }

    const roomName = roomSelect.options[roomSelect.selectedIndex]?.textContent || '未選択';
    const selectedHtml = selectedDates.map((dateKey) => `<li>${formatDateLabel(dateKey)}</li>`).join('');
    calendarDetail.innerHTML = `
      <div class="calendar-detail-card">
        <h3>選択中の予約内容</h3>
        <ul class="calendar-detail-list">
          <li><strong>選択部屋</strong><span>${roomName}</span></li>
          <li><strong>選択日数</strong><span>${selectedDates.length}日</span></li>
          <li><strong>利用時間</strong><span>${usageStartSelect.value || '--:--'}~${usageEndSelect.value || '--:--'}</span></li>
        </ul>
        <div class="selected-date-list-wrap">
          <strong>選択中の日付</strong>
          <ul class="selected-date-list">${selectedHtml}</ul>
        </div>
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

  function toggleDate(dateKey) {
    if (!isSelectable(dateKey)) return;
    const idx = selectedDates.indexOf(dateKey);
    if (idx >= 0) {
      selectedDates.splice(idx, 1);
    } else {
      selectedDates.push(dateKey);
    }
    updateSelectedFields();
    renderCalendar();
    renderDetail();
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
      const selected = selectedDates.includes(dateKey);

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
        <span class="calendar-day-note">${booked ? '予約済み' : (selected ? '選択中' : (selectable ? '選択可' : '対象外'))}</span>
        <span class="calendar-room-state">多:${data.tamoku ? '×' : '○'} / 橙:${data.orange ? '×' : '○'}</span>
      `;
      button.addEventListener('click', () => toggleDate(dateKey));
      calendarGrid.appendChild(button);
    }

    renderDetail();
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
      if (json.min_date) minDate = normalizeDate(parseDateKey(json.min_date));
      if (json.max_date) maxDate = normalizeDate(parseDateKey(json.max_date));

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
    if (minDate && target < new Date(minDate.getFullYear(), minDate.getMonth(), 1)) return;
    if (maxDate && target > new Date(maxDate.getFullYear(), maxDate.getMonth(), 1)) return;
    currentMonth = target;
    loadMonth(currentMonth);
  }

  function buildTimeOptions() {
    const options = [];
    for (let h = 0; h < 24; h++) {
      for (let m = 0; m < 60; m += 15) {
        options.push(`${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`);
      }
    }
    options.push('24:00');
    return options;
  }

  function fillTimeSelects() {
    const options = buildTimeOptions();
    usageStartSelect.innerHTML = '<option value="">開始時刻を選択してください</option>' + options.filter((v) => v !== '24:00').map((v) => `<option value="${v}">${v}</option>`).join('');
    usageEndSelect.innerHTML = '<option value="">終了時刻を選択してください</option>' + options.map((v) => `<option value="${v}">${v}</option>`).join('');
    usageStartSelect.value = '09:00';
    usageEndSelect.value = '18:00';
  }

  roomSelect.addEventListener('change', () => {
    selectedDates = [];
    updateSelectedFields();
    renderCalendar();
    renderDetail();
  });

  usageStartSelect.addEventListener('change', renderDetail);
  usageEndSelect.addEventListener('change', renderDetail);

  form.addEventListener('submit', (event) => {
    if (!roomSelect.value) {
      event.preventDefault();
      alert('部屋を選択してください。');
      roomSelect.focus();
      return;
    }
    if (!selectedDates.length) {
      event.preventDefault();
      alert('利用日を1日以上選択してください。');
      return;
    }
    if (!usageStartSelect.value || !usageEndSelect.value) {
      event.preventDefault();
      alert('利用時間を指定してください。');
      return;
    }
    if (usageStartSelect.value >= usageEndSelect.value) {
      event.preventDefault();
      alert('利用終了時刻は開始時刻より後にしてください。');
      return;
    }
  });

  prevMonthBtn?.addEventListener('click', () => moveMonth(-1));
  nextMonthBtn?.addEventListener('click', () => moveMonth(1));
  goAvailableStartBtn?.addEventListener('click', () => {
    if (minDate) {
      currentMonth = new Date(minDate.getFullYear(), minDate.getMonth(), 1);
    } else {
      currentMonth = new Date();
      currentMonth.setDate(1);
    }
    loadMonth(currentMonth);
  });

  openTermsDialogBtn?.addEventListener('click', () => {
    if (typeof termsDialog.showModal === 'function') {
      termsDialog.showModal();
    }
  });

  agreeFromDialogBtn?.addEventListener('click', () => {
    agreeTerms.checked = true;
    termsDialog.close();
  });

  fillTimeSelects();
  updateSelectedFields();
  const initial = new Date();
  initial.setDate(1);
  currentMonth = initial;
  loadMonth(currentMonth);
})();
