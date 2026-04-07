(() => {
  const form = document.getElementById('reservationForm');
  const calendarGrid = document.getElementById('calendarGrid');
  const calendarTitle = document.getElementById('calendarTitle');
  const calendarStatusText = document.getElementById('calendarStatusText');
  const calendarDetail = document.getElementById('calendarDetail');
  const detailPanel = document.getElementById('detailPanel');
  const dateConfigList = document.getElementById('dateConfigList');
  const reservationDetailsInput = document.getElementById('reservationDetails');
  const prevMonthBtn = document.getElementById('prevMonth');
  const nextMonthBtn = document.getElementById('nextMonth');
  const goAvailableStartBtn = document.getElementById('goAvailableStart');
  const openTermsDialogBtn = document.getElementById('openTermsDialog');
  const termsDialog = document.getElementById('termsDialog');
  const agreeFromDialogBtn = document.getElementById('agreeFromDialog');
  const agreeTerms = document.getElementById('agreeTerms');
  const openManualDialogBtn = document.getElementById('openManualDialog');
  const manualDialog = document.getElementById('manualDialog');
  const submitBtn = form ? form.querySelector('.submit-btn') : null;
  const submitBtnDefaultLabel = submitBtn ? submitBtn.textContent : '';

  function setSubmitButtonState(isSubmitting) {
    if (!submitBtn) return;
    submitBtn.disabled = isSubmitting;
    submitBtn.classList.toggle('is-submitting', isSubmitting);
    submitBtn.setAttribute('aria-disabled', isSubmitting ? 'true' : 'false');
    submitBtn.textContent = isSubmitting ? '送信中...' : submitBtnDefaultLabel;
  }

  if (!form || !calendarGrid || !calendarTitle || !calendarStatusText || !calendarDetail || !detailPanel || !dateConfigList || !reservationDetailsInput) {
    return;
  }

  const weekdayLabels = ['日', '月', '火', '水', '木', '金', '土'];
  const statusByDate = {};
  const selectedDates = [];
  const detailMap = {};
  let currentMonth = new Date();
  currentMonth.setDate(1);
  let minDate = null;
  let maxDate = null;
  let bookingTimeStart = '09:00';
  let bookingTimeEnd = '20:00';
  let bookingStepMinutes = 15;

  function normalizeDate(date) {
    const copy = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    copy.setHours(0, 0, 0, 0);
    return copy;
  }

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

  function timeToMinutes(value) {
    const [hours, minutes] = value.split(':').map(Number);
    return (hours * 60) + minutes;
  }

  function minutesToTime(totalMinutes) {
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
  }

  function buildTimeOptions(startValue, endValue, { includeEnd = false } = {}) {
    const options = [];
    const startMinutes = timeToMinutes(startValue);
    const endMinutes = timeToMinutes(endValue);
    const limit = includeEnd ? endMinutes : endMinutes - bookingStepMinutes;

    for (let minutes = startMinutes; minutes <= limit; minutes += bookingStepMinutes) {
      options.push(minutesToTime(minutes));
    }
    return options;
  }

  function isWithinRange(date) {
    const target = normalizeDate(date);
    if (minDate && target < minDate) return false;
    if (maxDate && target > maxDate) return false;
    return true;
  }

  function getDayStatus(dateKey) {
    return statusByDate[dateKey] || { tamoku: false, orange: false };
  }

  function isFullyBooked(dateKey) {
    const day = getDayStatus(dateKey);
    return Boolean(day.tamoku && day.orange);
  }

  function getAvailableRooms(dateKey) {
    const day = getDayStatus(dateKey);
    const rooms = [];
    if (!day.tamoku) rooms.push({ code: 'tamoku', label: '多目的室' });
    if (!day.orange) rooms.push({ code: 'orange', label: 'オレンジの部屋' });
    return rooms;
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function selectedSortedDates() {
    return [...selectedDates].sort();
  }

  function ensureDetail(dateKey) {
    if (!detailMap[dateKey]) {
      detailMap[dateKey] = {
        use_date: dateKey,
        room_code: '',
        usage_start_time: bookingTimeStart,
        usage_end_time: minutesToTime(Math.min(timeToMinutes(bookingTimeStart) + 60, timeToMinutes(bookingTimeEnd))),
      };
    }
    return detailMap[dateKey];
  }

  function syncHiddenInput() {
    const rows = selectedSortedDates().map((dateKey) => {
      const detail = ensureDetail(dateKey);
      return {
        use_date: dateKey,
        room_code: detail.room_code || '',
        usage_start_time: detail.usage_start_time || '',
        usage_end_time: detail.usage_end_time || '',
      };
    });
    reservationDetailsInput.value = JSON.stringify(rows);
  }

  function renderCalendarDetail() {
    const dates = selectedSortedDates();
    if (!dates.length) {
      calendarDetail.innerHTML = '<p class="calendar-detail-placeholder">利用日をクリックして選択してください。選択後に日付ごとの部屋・利用時間を設定できます。</p>';
      return;
    }

    const list = dates.map((dateKey) => {
      const rooms = getAvailableRooms(dateKey);
      const roomText = rooms.length === 0
        ? '満室'
        : rooms.map((room) => room.label).join(' / ');
      return `<li>${escapeHtml(formatDateLabel(dateKey))}<span>空き部屋: ${escapeHtml(roomText)}</span></li>`;
    }).join('');

    calendarDetail.innerHTML = `
      <div class="calendar-detail-card">
        <h3>選択中の日付</h3>
        <ul class="selected-date-summary">${list}</ul>
      </div>
    `;
  }

  function buildRoomOptions(detail, rooms) {
    const currentRoomSelectable = rooms.some((room) => room.code === detail.room_code);
    if (detail.room_code && !currentRoomSelectable) {
      detail.room_code = '';
    }

    return ['<option value="">部屋を選択してください</option>']
      .concat(rooms.map((room) => `<option value="${room.code}" ${detail.room_code === room.code ? 'selected' : ''}>${room.label}</option>`))
      .join('');
  }

  function buildStartOptions(detail) {
    const options = buildTimeOptions(bookingTimeStart, bookingTimeEnd, { includeEnd: false });
    if (!options.includes(detail.usage_start_time)) {
      detail.usage_start_time = options[0] || '';
    }
    return ['<option value="">開始時刻</option>']
      .concat(options.map((value) => `<option value="${value}" ${detail.usage_start_time === value ? 'selected' : ''}>${value}</option>`))
      .join('');
  }

  function normalizeEndTime(detail) {
    const startMinutes = timeToMinutes(detail.usage_start_time || bookingTimeStart);
    const minEndMinutes = startMinutes + bookingStepMinutes;
    const maxEndMinutes = timeToMinutes(bookingTimeEnd);
    let endMinutes = detail.usage_end_time ? timeToMinutes(detail.usage_end_time) : (minEndMinutes + 45);

    if (endMinutes <= startMinutes) {
      endMinutes = minEndMinutes;
    }
    if (endMinutes > maxEndMinutes) {
      endMinutes = maxEndMinutes;
    }
    if (endMinutes < minEndMinutes) {
      endMinutes = minEndMinutes;
    }

    detail.usage_end_time = minutesToTime(endMinutes);
  }

  function buildEndOptions(detail) {
    normalizeEndTime(detail);
    const endOptions = buildTimeOptions(minutesToTime(timeToMinutes(detail.usage_start_time) + bookingStepMinutes), bookingTimeEnd, { includeEnd: true });
    if (!endOptions.includes(detail.usage_end_time)) {
      detail.usage_end_time = endOptions[0] || '';
    }
    return ['<option value="">終了時刻</option>']
      .concat(endOptions.map((value) => `<option value="${value}" ${detail.usage_end_time === value ? 'selected' : ''}>${value}</option>`))
      .join('');
  }

  function renderDetailCards() {
    const dates = selectedSortedDates();
    if (!dates.length) {
      detailPanel.classList.add('is-hidden');
      dateConfigList.innerHTML = '';
      syncHiddenInput();
      return;
    }

    detailPanel.classList.remove('is-hidden');
    dateConfigList.innerHTML = dates.map((dateKey) => {
      const detail = ensureDetail(dateKey);
      const rooms = getAvailableRooms(dateKey);
      const roomOptions = buildRoomOptions(detail, rooms);
      const startOptions = buildStartOptions(detail);
      const endOptions = buildEndOptions(detail);
      const roomBadge = rooms.length === 0 ? '<span class="inline-warning">この日は満室です</span>' : '';

      return `
        <article class="date-config-card" data-date-card="${dateKey}">
          <div class="date-config-head">
            <div>
              <h3>${escapeHtml(formatDateLabel(dateKey))}</h3>
              ${roomBadge}
            </div>
            <button type="button" class="remove-date-btn" data-remove-date="${dateKey}">この日を外す</button>
          </div>

          <div class="date-config-grid">
            <label class="form-group">
              <span>部屋 <span class="required">*</span></span>
              <select class="detail-room-select" data-date="${dateKey}">${roomOptions}</select>
            </label>

            <label class="form-group">
              <span>利用開始時刻 <span class="required">*</span></span>
              <select class="detail-start-select" data-date="${dateKey}">${startOptions}</select>
            </label>

            <label class="form-group">
              <span>利用終了時刻 <span class="required">*</span></span>
              <select class="detail-end-select" data-date="${dateKey}">${endOptions}</select>
            </label>
          </div>
        </article>
      `;
    }).join('');

    dateConfigList.querySelectorAll('.detail-room-select').forEach((select) => {
      select.addEventListener('change', () => {
        const dateKey = select.dataset.date;
        if (!dateKey) return;
        ensureDetail(dateKey).room_code = select.value;
        syncHiddenInput();
      });
    });

    dateConfigList.querySelectorAll('.detail-start-select').forEach((select) => {
      select.addEventListener('change', () => {
        const dateKey = select.dataset.date;
        if (!dateKey) return;
        const detail = ensureDetail(dateKey);
        detail.usage_start_time = select.value;
        normalizeEndTime(detail);
        renderDetailCards();
      });
    });

    dateConfigList.querySelectorAll('.detail-end-select').forEach((select) => {
      select.addEventListener('change', () => {
        const dateKey = select.dataset.date;
        if (!dateKey) return;
        ensureDetail(dateKey).usage_end_time = select.value;
        syncHiddenInput();
      });
    });

    dateConfigList.querySelectorAll('.remove-date-btn').forEach((button) => {
      button.addEventListener('click', () => {
        const dateKey = button.dataset.removeDate;
        if (!dateKey) return;
        const index = selectedDates.indexOf(dateKey);
        if (index >= 0) selectedDates.splice(index, 1);
        delete detailMap[dateKey];
        renderCalendar();
        renderCalendarDetail();
        renderDetailCards();
      });
    });

    syncHiddenInput();
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
      const dayStatus = getDayStatus(dateKey);
      const inRange = isWithinRange(cellDate);
      const fullBooked = isFullyBooked(dateKey);
      const partial = !fullBooked && (dayStatus.tamoku || dayStatus.orange);
      const selected = selectedDates.includes(dateKey);
      const selectable = inRange && !fullBooked;

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'calendar-day';
      if (!inRange) button.classList.add('is-disabled');
      if (fullBooked) button.classList.add('is-booked');
      if (partial) button.classList.add('is-partial');
      if (selected) button.classList.add('is-selected');
      if (selectable && !selected && !partial) button.classList.add('is-available');

      button.disabled = !selectable;
      button.dataset.date = dateKey;
      button.innerHTML = `
        <span class="calendar-day-number">${day}</span>
        <span class="calendar-day-note">${!inRange ? '対象外' : (fullBooked ? '満室' : (selected ? '選択中' : (partial ? '一部空き' : '選択可')))}</span>
        <span class="calendar-room-state">多:${dayStatus.tamoku ? '×' : '○'} / 橙:${dayStatus.orange ? '×' : '○'}</span>
      `;
      button.addEventListener('click', () => {
        const idx = selectedDates.indexOf(dateKey);
        if (idx >= 0) {
          selectedDates.splice(idx, 1);
          delete detailMap[dateKey];
        } else {
          selectedDates.push(dateKey);
          ensureDetail(dateKey);
        }
        renderCalendar();
        renderCalendarDetail();
        renderDetailCards();
      });
      calendarGrid.appendChild(button);
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

      Object.entries(json.days || {}).forEach(([dateKey, day]) => {
        statusByDate[dateKey] = day;
      });

      if (json.min_date) minDate = normalizeDate(parseDateKey(json.min_date));
      if (json.max_date) maxDate = normalizeDate(parseDateKey(json.max_date));
      if (json.time_start) bookingTimeStart = json.time_start;
      if (json.time_end) bookingTimeEnd = json.time_end;
      if (json.time_step_minutes) bookingStepMinutes = Number(json.time_step_minutes) || 15;

      renderCalendar();
      renderCalendarDetail();
      renderDetailCards();

      calendarStatusText.textContent = minDate && maxDate
        ? `予約可能期間: ${formatDateKey(minDate)} ～ ${formatDateKey(maxDate)} / 利用時間: ${bookingTimeStart}～${bookingTimeEnd}`
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

  function validateBeforeSubmit() {
    const dates = selectedSortedDates();
    if (!dates.length) {
      alert('利用日を1日以上選択してください。');
      return false;
    }

    const allowedStartMinutes = timeToMinutes(bookingTimeStart);
    const allowedEndMinutes = timeToMinutes(bookingTimeEnd);

    for (const dateKey of dates) {
      const detail = ensureDetail(dateKey);
      if (!detail.room_code || !detail.usage_start_time || !detail.usage_end_time) {
        alert(`${formatDateLabel(dateKey)} の部屋と利用時間を設定してください。`);
        return false;
      }
      const startMinutes = timeToMinutes(detail.usage_start_time);
      const endMinutes = timeToMinutes(detail.usage_end_time);
      if (endMinutes <= startMinutes) {
        alert(`${formatDateLabel(dateKey)} の利用終了時刻は開始時刻より後にしてください。`);
        return false;
      }
      if (startMinutes < allowedStartMinutes || endMinutes > allowedEndMinutes) {
        alert(`${formatDateLabel(dateKey)} の利用時間は ${bookingTimeStart}〜${bookingTimeEnd} の範囲で指定してください。`);
        return false;
      }
      const roomStillAvailable = getAvailableRooms(dateKey).some((room) => room.code === detail.room_code);
      if (!roomStillAvailable) {
        alert(`${formatDateLabel(dateKey)} の選択中の部屋は現在予約できません。`);
        return false;
      }
    }

    syncHiddenInput();
    return true;
  }

  form.addEventListener('submit', (event) => {
    if (!validateBeforeSubmit()) {
      event.preventDefault();
      setSubmitButtonState(false);
      form.dataset.submitting = '0';
      return;
    }

    if (form.dataset.submitting === '1') {
      event.preventDefault();
      return;
    }

    form.dataset.submitting = '1';
    setSubmitButtonState(true);
  });

  window.addEventListener('pageshow', () => {
    form.dataset.submitting = '0';
    setSubmitButtonState(false);
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
    if (typeof termsDialog?.showModal === 'function') {
      termsDialog.showModal();
    }
  });

  openManualDialogBtn?.addEventListener('click', () => {
    if (typeof manualDialog?.showModal === 'function') {
      manualDialog.showModal();
    }
  });

  agreeFromDialogBtn?.addEventListener('click', () => {
    agreeTerms.checked = true;
    if (typeof termsDialog?.close === 'function') {
      termsDialog.close();
    }
  });

  renderCalendarDetail();
  renderDetailCards();
  const initial = new Date();
  initial.setDate(1);
  currentMonth = initial;
  loadMonth(currentMonth);
})();
