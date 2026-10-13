/* =====================================================================
   貸し部屋予約（利用者画面）
   左: 月カレンダー（部屋ごとの空き状況）／右: 利用者情報・日付ごとの部屋と時間・規約同意
   ===================================================================== */
(() => {
  const $ = (id) => document.getElementById(id);
  const form = $('reservationForm');
  const calendarGrid = $('calendarGrid');
  const calendarTitle = $('calendarTitle');
  const calendarStatusText = $('calendarStatusText');
  const calendarDetail = $('calendarDetail');
  const detailPanel = $('detailPanel');
  const dateConfigList = $('dateConfigList');
  const reservationDetailsInput = $('reservationDetails');
  const prevMonthBtn = $('prevMonth');
  const nextMonthBtn = $('nextMonth');
  const goAvailableStartBtn = $('goAvailableStart');
  const termsDialog = $('termsDialog');
  const agreeFromDialogBtn = $('agreeFromDialog');
  const agreeTerms = $('agreeTerms');
  const manualDialog = $('manualDialog');
  const submitBtn = form ? form.querySelector('.submit-btn') : null;
  const submitLabel = submitBtn ? submitBtn.querySelector('.submit-label') : null;
  const emailInput = $('email');
  const orgInput = $('organization_name');
  const formErrorSummary = $('formErrorSummary');
  const selectedCount = $('selectedCount');
  const jump = $('bkJump');
  const jumpText = $('bkJumpText');
  const stepEls = Array.from(document.querySelectorAll('[data-step]'));

  if (!form || !calendarGrid || !calendarTitle || !calendarStatusText || !calendarDetail || !detailPanel || !dateConfigList || !reservationDetailsInput) {
    return;
  }

  const icon = (name, cls) => (window.UI ? UI.icon(name, cls) : '');
  const MANUAL_URL = 'manuals/booking_user_manual.pdf';
  const ROOMS = [
    { code: 'tamoku', label: '多目的室', short: '多目的' },
    { code: 'orange', label: 'オレンジの部屋', short: 'オレンジ' },
  ];
  const weekdayLabels = ['日', '月', '火', '水', '木', '金', '土'];
  const statusByDate = {};
  const selectedDates = [];
  const detailMap = {};
  const cardHintTimers = {};
  let currentMonth = new Date();
  currentMonth.setDate(1);
  let minDate = null;
  let maxDate = null;
  let todayKey = '';
  let bookingTimeStart = '09:00';
  let bookingTimeEnd = '20:00';
  let bookingStepMinutes = 15;
  let detailPanelWasHidden = true;

  /* ---------------- ユーティリティ ---------------- */
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
    return `${date.getFullYear()}年${date.getMonth() + 1}月${date.getDate()}日(${weekdayLabels[date.getDay()]})`;
  }
  function formatShortDate(value) {
    const date = parseDateKey(value);
    return { md: `${date.getMonth() + 1}/${date.getDate()}`, wd: weekdayLabels[date.getDay()], dow: date.getDay() };
  }
  function timeToMinutes(value) {
    const [hours, minutes] = value.split(':').map(Number);
    return (hours * 60) + minutes;
  }
  function minutesToTime(total) {
    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
  }
  function buildTimeOptions(startValue, endValue, { includeEnd = false } = {}) {
    const options = [];
    const limit = includeEnd ? timeToMinutes(endValue) : timeToMinutes(endValue) - bookingStepMinutes;
    for (let m = timeToMinutes(startValue); m <= limit; m += bookingStepMinutes) options.push(minutesToTime(m));
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
    return ROOMS.filter((room) => !day[room.code]);
  }
  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function selectedSortedDates() {
    return [...selectedDates].sort();
  }
  function ensureDetail(dateKey) {
    if (!detailMap[dateKey]) {
      const rooms = getAvailableRooms(dateKey);
      detailMap[dateKey] = {
        use_date: dateKey,
        room_code: rooms.length === 1 ? rooms[0].code : '',
        usage_start_time: bookingTimeStart,
        usage_end_time: minutesToTime(Math.min(timeToMinutes(bookingTimeStart) + 60, timeToMinutes(bookingTimeEnd))),
      };
    }
    return detailMap[dateKey];
  }
  function syncHiddenInput() {
    reservationDetailsInput.value = JSON.stringify(selectedSortedDates().map((dateKey) => {
      const detail = ensureDetail(dateKey);
      return {
        use_date: dateKey,
        room_code: detail.room_code || '',
        usage_start_time: detail.usage_start_time || '',
        usage_end_time: detail.usage_end_time || '',
      };
    }));
  }

  /* ---------------- エラー表示 ---------------- */
  function cardFor(dateKey) {
    return dateConfigList.querySelector(`[data-date-card="${CSS.escape(dateKey)}"]`);
  }
  function showCardError(dateKey, msg) {
    const card = cardFor(dateKey);
    if (!card) return null;
    card.classList.add('has-error');
    const err = card.querySelector('[data-card-error]');
    if (err) { err.textContent = msg; err.hidden = false; }
    return card;
  }
  function clearCardError(dateKey) {
    const card = cardFor(dateKey);
    if (!card) return;
    card.classList.remove('has-error');
    const err = card.querySelector('[data-card-error]');
    if (err) { err.textContent = ''; err.hidden = true; }
  }
  function clearAllCardErrors() {
    dateConfigList.querySelectorAll('[data-date-card]').forEach((card) => clearCardError(card.dataset.dateCard));
  }
  function showFormErrorSummary(msg) {
    if (!formErrorSummary) return;
    formErrorSummary.innerHTML = `${icon('alert-circle')}<div class="alert__body"><strong class="alert__title">入力内容を確認してください</strong>${escapeHtml(msg)}</div>`;
    formErrorSummary.hidden = false;
  }
  function clearFormErrorSummary() {
    if (!formErrorSummary) return;
    formErrorSummary.textContent = '';
    formErrorSummary.hidden = true;
  }
  function setFieldError(input, msg) {
    if (!input) return;
    const err = form.querySelector(`[data-field-error="${input.name}"]`);
    if (msg) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
    if (err) { err.textContent = msg || ''; err.hidden = !msg; }
  }
  function showCardHint(dateKey, msg) {
    const card = cardFor(dateKey);
    const hint = card?.querySelector('[data-card-hint]');
    if (!hint) return;
    hint.textContent = msg;
    hint.hidden = false;
    if (cardHintTimers[dateKey]) clearTimeout(cardHintTimers[dateKey]);
    cardHintTimers[dateKey] = setTimeout(() => {
      if (hint.isConnected) { hint.hidden = true; hint.textContent = ''; }
      delete cardHintTimers[dateKey];
    }, 3200);
  }

  function setSubmitButtonState(isSubmitting) {
    if (!submitBtn) return;
    submitBtn.disabled = isSubmitting;
    submitBtn.classList.toggle('is-loading', isSubmitting);
    submitBtn.setAttribute('aria-busy', isSubmitting ? 'true' : 'false');
    if (submitLabel) submitLabel.textContent = isSubmitting ? '送信中…' : '予約する';
  }

  /* ---------------- ステップ表示 ---------------- */
  function emailError() {
    if (!emailInput) return '';
    const value = emailInput.value.trim();
    if (!value) return 'メールアドレスを入力してください。';
    if (!emailInput.checkValidity()) return '@bene.fit.ac.jp または @fit.ac.jp のメールアドレスを入力してください。';
    return '';
  }
  function isDateConfigComplete(dateKey) {
    const detail = detailMap[dateKey];
    if (!detail || !detail.room_code || !detail.usage_start_time || !detail.usage_end_time) return false;
    const s = timeToMinutes(detail.usage_start_time);
    const e = timeToMinutes(detail.usage_end_time);
    if (e <= s || s < timeToMinutes(bookingTimeStart) || e > timeToMinutes(bookingTimeEnd)) return false;
    return getAvailableRooms(dateKey).some((r) => r.code === detail.room_code);
  }
  function updateStepProgress() {
    const states = [
      !emailError() && Boolean(orgInput?.value.trim()),
      selectedDates.length > 0,
      selectedDates.length > 0 && selectedDates.every(isDateConfigComplete),
      Boolean(agreeTerms?.checked),
    ];
    let foundActive = false;
    stepEls.forEach((stepEl) => {
      const done = states[Number(stepEl.dataset.step) - 1];
      const active = !done && !foundActive;
      if (active) foundActive = true;
      stepEl.classList.toggle('is-done', Boolean(done));
      stepEl.classList.toggle('is-active', active);
    });
    const n = selectedDates.length;
    if (selectedCount) selectedCount.textContent = `${n}日`;
    if (jumpText) jumpText.textContent = `${n}日選択中 ・ 時間を設定`;
    updateJump();
  }

  /* ---------------- 選択日リスト ---------------- */
  function renderCalendarDetail() {
    calendarDetail.hidden = selectedDates.length > 0;
  }

  function buildRoomOptions(detail, rooms) {
    if (detail.room_code && !rooms.some((room) => room.code === detail.room_code)) detail.room_code = '';
    return ['<option value="">部屋を選択</option>']
      .concat(rooms.map((room) => `<option value="${room.code}" ${detail.room_code === room.code ? 'selected' : ''}>${room.label}</option>`))
      .join('');
  }
  function buildStartOptions(detail) {
    const options = buildTimeOptions(bookingTimeStart, bookingTimeEnd, { includeEnd: false });
    if (!options.includes(detail.usage_start_time)) detail.usage_start_time = options[0] || '';
    return options.map((value) => `<option value="${value}" ${detail.usage_start_time === value ? 'selected' : ''}>${value}</option>`).join('');
  }
  function normalizeEndTime(detail) {
    const start = timeToMinutes(detail.usage_start_time || bookingTimeStart);
    const minEnd = start + bookingStepMinutes;
    const maxEnd = timeToMinutes(bookingTimeEnd);
    let end = detail.usage_end_time ? timeToMinutes(detail.usage_end_time) : (minEnd + 45);
    if (end <= start) end = minEnd;
    if (end > maxEnd) end = maxEnd;
    if (end < minEnd) end = minEnd;
    detail.usage_end_time = minutesToTime(end);
  }
  function buildEndOptions(detail) {
    normalizeEndTime(detail);
    const options = buildTimeOptions(minutesToTime(timeToMinutes(detail.usage_start_time) + bookingStepMinutes), bookingTimeEnd, { includeEnd: true });
    if (!options.includes(detail.usage_end_time)) detail.usage_end_time = options[0] || '';
    return options.map((value) => `<option value="${value}" ${detail.usage_end_time === value ? 'selected' : ''}>${value}</option>`).join('');
  }

  function durationLabel(detail) {
    if (!detail.usage_start_time || !detail.usage_end_time) return '';
    const mins = timeToMinutes(detail.usage_end_time) - timeToMinutes(detail.usage_start_time);
    if (mins <= 0) return '';
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return `${h ? `${h}時間` : ''}${m ? `${m}分` : ''}`;
  }

  function renderDetailCards(focusDateKey = '') {
    const dates = selectedSortedDates();
    if (!dates.length) {
      detailPanel.classList.add('is-hidden');
      dateConfigList.innerHTML = '';
      detailPanelWasHidden = true;
      syncHiddenInput();
      updateStepProgress();
      return;
    }
    detailPanel.classList.remove('is-hidden');
    detailPanelWasHidden = false;

    dateConfigList.innerHTML = dates.map((dateKey) => {
      const detail = ensureDetail(dateKey);
      const rooms = getAvailableRooms(dateKey);
      const d = formatShortDate(dateKey);
      const startOptions = buildStartOptions(detail);
      const endOptions = buildEndOptions(detail);
      return `
        <article class="bk-date${dateKey === focusDateKey ? ' is-new' : ''}" data-date-card="${dateKey}">
          <header class="bk-date__head">
            <span class="bk-date__label${d.dow === 0 ? ' is-sun' : d.dow === 6 ? ' is-sat' : ''}"><strong>${d.md}</strong><span>(${d.wd})</span></span>
            <span class="bk-date__dur" data-duration>${escapeHtml(durationLabel(detail))}</span>
            <button type="button" class="btn btn--ghost btn--icon btn--sm remove-date-btn" data-remove-date="${dateKey}" aria-label="${escapeHtml(formatDateLabel(dateKey))} の選択を外す">${icon('x')}</button>
          </header>
          ${rooms.length === 0 ? `<p class="field__error">この日は満室です。日付を選び直してください。</p>` : ''}
          <div class="bk-date__grid">
            <label class="field bk-date__room"><span class="sr-only">部屋</span>
              <select class="detail-room-select" data-date="${dateKey}" aria-label="${escapeHtml(formatDateLabel(dateKey))} の部屋">${buildRoomOptions(detail, rooms)}</select>
            </label>
            <label class="field"><span class="sr-only">開始</span>
              <select class="detail-start-select" data-date="${dateKey}" aria-label="開始時刻">${startOptions}</select>
            </label>
            <span class="bk-date__tilde" aria-hidden="true">〜</span>
            <label class="field"><span class="sr-only">終了</span>
              <select class="detail-end-select" data-date="${dateKey}" aria-label="終了時刻">${endOptions}</select>
            </label>
          </div>
          <p class="field__error" data-card-error role="alert" hidden></p>
          <p class="field__hint bk-date__hint" data-card-hint role="status" hidden></p>
        </article>
      `;
    }).join('');

    syncHiddenInput();
    updateStepProgress();
  }

  dateConfigList.addEventListener('change', (event) => {
    const select = event.target.closest('select[data-date]');
    if (!select) return;
    const dateKey = select.dataset.date;
    const detail = ensureDetail(dateKey);
    clearCardError(dateKey);
    clearFormErrorSummary();
    if (select.classList.contains('detail-room-select')) {
      detail.room_code = select.value;
    } else if (select.classList.contains('detail-start-select')) {
      const oldEnd = detail.usage_end_time;
      detail.usage_start_time = select.value;
      normalizeEndTime(detail);
      const card = cardFor(dateKey);
      const endSelect = card?.querySelector('.detail-end-select');
      if (endSelect) endSelect.innerHTML = buildEndOptions(detail);
      if (oldEnd !== detail.usage_end_time) showCardHint(dateKey, `終了時刻を ${detail.usage_end_time} に調整しました。`);
    } else if (select.classList.contains('detail-end-select')) {
      detail.usage_end_time = select.value;
    }
    const dur = cardFor(dateKey)?.querySelector('[data-duration]');
    if (dur) dur.textContent = durationLabel(detail);
    syncHiddenInput();
    updateStepProgress();
  });

  dateConfigList.addEventListener('click', (event) => {
    const button = event.target.closest('[data-remove-date]');
    if (!button) return;
    const dateKey = button.dataset.removeDate;
    const card = cardFor(dateKey);
    const remove = () => {
      const index = selectedDates.indexOf(dateKey);
      if (index >= 0) selectedDates.splice(index, 1);
      delete detailMap[dateKey];
      renderCalendar();
      renderCalendarDetail();
      renderDetailCards();
    };
    if (card) {
      card.classList.add('is-leaving');
      setTimeout(remove, 220);
    } else {
      remove();
    }
  });

  /* ---------------- カレンダー ---------------- */
  function renderWeekdayHeader() {
    weekdayLabels.forEach((label, i) => {
      const el = document.createElement('div');
      el.className = `bk-weekday${i === 0 ? ' is-sun' : i === 6 ? ' is-sat' : ''}`;
      el.textContent = label;
      calendarGrid.appendChild(el);
    });
  }

  function updateMonthNav() {
    const first = new Date(currentMonth.getFullYear(), currentMonth.getMonth(), 1);
    if (prevMonthBtn) prevMonthBtn.disabled = Boolean(minDate && first <= new Date(minDate.getFullYear(), minDate.getMonth(), 1));
    if (nextMonthBtn) nextMonthBtn.disabled = Boolean(maxDate && first >= new Date(maxDate.getFullYear(), maxDate.getMonth(), 1));
  }

  function renderCalendar() {
    calendarGrid.innerHTML = '';
    renderWeekdayHeader();
    calendarTitle.textContent = `${currentMonth.getFullYear()}年${currentMonth.getMonth() + 1}月`;
    const startDay = new Date(currentMonth.getFullYear(), currentMonth.getMonth(), 1).getDay();
    const lastDate = new Date(currentMonth.getFullYear(), currentMonth.getMonth() + 1, 0).getDate();
    const today = todayKey || formatDateKey(new Date());

    for (let i = 0; i < startDay; i += 1) {
      const empty = document.createElement('div');
      empty.className = 'bk-day is-empty';
      calendarGrid.appendChild(empty);
    }

    for (let day = 1; day <= lastDate; day += 1) {
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
      button.className = 'bk-day';
      if (!inRange) button.classList.add('is-disabled');
      if (fullBooked) button.classList.add('is-booked');
      if (partial) button.classList.add('is-partial');
      if (selected) button.classList.add('is-selected');
      if (selectable) button.classList.add('is-available');
      if (dateKey === today) button.classList.add('is-today');
      if (cellDate.getDay() === 0) button.classList.add('is-sun');
      if (cellDate.getDay() === 6) button.classList.add('is-sat');
      button.disabled = !selectable;
      button.dataset.date = dateKey;

      const roomsHtml = inRange ? ROOMS.map((room) => {
        const open = !dayStatus[room.code];
        return `<span class="bk-room bk-room--${room.code}${open ? '' : ' is-taken'}"><span class="bk-room__label">${room.short}</span></span>`;
      }).join('') : '';
      const note = !inRange ? '' : fullBooked ? '満室' : '';

      button.innerHTML = `
        <span class="bk-day__num">${day}</span>
        ${selected ? `<span class="bk-day__check">${icon('check', 'ico--sm')}</span>` : ''}
        ${note ? `<span class="bk-day__note">${note}</span>` : ''}
        <span class="bk-day__rooms" aria-hidden="true">${roomsHtml}</span>
      `;
      const roomState = ROOMS.map((room) => `${room.label}${dayStatus[room.code] ? '予約あり' : '空き'}`).join('、');
      button.setAttribute('aria-label', inRange
        ? `${formatDateLabel(dateKey)}${selected ? ' 選択中' : ''}。${fullBooked ? '満室。' : ''}${roomState}`
        : `${formatDateLabel(dateKey)} 選択できません`);
      if (selectable) button.setAttribute('aria-pressed', selected ? 'true' : 'false');
      calendarGrid.appendChild(button);
    }
    updateMonthNav();
  }

  calendarGrid.addEventListener('click', (event) => {
    const button = event.target.closest('button.bk-day[data-date]');
    if (!button || button.disabled) return;
    const dateKey = button.dataset.date;
    const idx = selectedDates.indexOf(dateKey);
    let added = '';
    if (idx >= 0) {
      selectedDates.splice(idx, 1);
      delete detailMap[dateKey];
    } else {
      selectedDates.push(dateKey);
      ensureDetail(dateKey);
      added = dateKey;
    }
    clearFormErrorSummary();
    renderCalendar();
    renderCalendarDetail();
    renderDetailCards(added);
    calendarGrid.querySelector(`[data-date="${dateKey}"]`)?.focus({ preventScroll: true });
  });

  function renderSkeleton() {
    calendarGrid.innerHTML = '';
    renderWeekdayHeader();
    for (let i = 0; i < 35; i += 1) {
      const el = document.createElement('div');
      el.className = 'bk-day is-skeleton skeleton';
      el.setAttribute('aria-hidden', 'true');
      calendarGrid.appendChild(el);
    }
  }

  async function loadMonth(date) {
    calendarStatusText.textContent = '予約状況を取得しています…';
    renderSkeleton();
    try {
      const response = await fetch(`get_calendar_status.php?year=${date.getFullYear()}&month=${date.getMonth() + 1}`, { credentials: 'same-origin' });
      const json = await response.json();
      if (!json.ok) throw new Error(json.message || '予約状況の取得に失敗しました。');
      Object.entries(json.days || {}).forEach(([dateKey, day]) => { statusByDate[dateKey] = day; });
      if (json.min_date) minDate = normalizeDate(parseDateKey(json.min_date));
      if (json.max_date) maxDate = normalizeDate(parseDateKey(json.max_date));
      if (json.today) todayKey = json.today;
      if (json.time_start) bookingTimeStart = json.time_start;
      if (json.time_end) bookingTimeEnd = json.time_end;
      if (json.time_step_minutes) bookingStepMinutes = Number(json.time_step_minutes) || 15;

      renderCalendar();
      renderCalendarDetail();
      renderDetailCards();
      calendarGrid.classList.remove('is-entering');
      void calendarGrid.offsetWidth;
      calendarGrid.classList.add('is-entering');

      calendarStatusText.textContent = minDate && maxDate
        ? `予約できる期間：${minDate.getMonth() + 1}/${minDate.getDate()} 〜 ${maxDate.getMonth() + 1}/${maxDate.getDate()} ・ 利用時間 ${bookingTimeStart}〜${bookingTimeEnd}`
        : '予約状況を表示しています。';
    } catch (error) {
      calendarGrid.innerHTML = `<div class="bk-grid__error"><div class="empty"><span class="empty__icon">${icon('alert-triangle')}</span><span>予約状況を取得できませんでした。</span><button type="button" class="btn btn--sm" data-retry>${icon('refresh')}再読み込み</button></div></div>`;
      calendarStatusText.textContent = error instanceof Error ? error.message : '取得に失敗しました。';
    }
  }
  calendarGrid.addEventListener('click', (event) => {
    if (event.target.closest('[data-retry]')) loadMonth(currentMonth);
  });

  function moveMonth(offset) {
    const target = new Date(currentMonth.getFullYear(), currentMonth.getMonth() + offset, 1);
    if (minDate && target < new Date(minDate.getFullYear(), minDate.getMonth(), 1)) return;
    if (maxDate && target > new Date(maxDate.getFullYear(), maxDate.getMonth(), 1)) return;
    currentMonth = target;
    calendarGrid.dataset.dir = offset > 0 ? 'next' : 'prev';
    loadMonth(currentMonth);
  }

  /* ---------------- 送信前チェック ---------------- */
  function validateBeforeSubmit() {
    clearAllCardErrors();
    clearFormErrorSummary();
    let firstError = null;

    const eErr = emailError();
    setFieldError(emailInput, eErr);
    if (eErr && !firstError) firstError = { el: emailInput, msg: eErr };
    const oErr = orgInput && !orgInput.value.trim() ? '団体名を入力してください。' : '';
    setFieldError(orgInput, oErr);
    if (oErr && !firstError) firstError = { el: orgInput, msg: oErr };

    const dates = selectedSortedDates();
    if (!dates.length && !firstError) {
      firstError = { el: calendarGrid, msg: '利用日を1日以上選択してください。' };
    }
    const allowedStart = timeToMinutes(bookingTimeStart);
    const allowedEnd = timeToMinutes(bookingTimeEnd);
    for (const dateKey of dates) {
      const detail = ensureDetail(dateKey);
      let msg = '';
      if (!detail.room_code || !detail.usage_start_time || !detail.usage_end_time) {
        msg = '部屋を選択してください。';
      } else {
        const s = timeToMinutes(detail.usage_start_time);
        const e = timeToMinutes(detail.usage_end_time);
        if (e <= s) msg = '終了時刻は開始時刻より後にしてください。';
        else if (s < allowedStart || e > allowedEnd) msg = `利用時間は ${bookingTimeStart}〜${bookingTimeEnd} の範囲で指定してください。`;
        else if (!getAvailableRooms(dateKey).some((room) => room.code === detail.room_code)) msg = '選択した部屋はこの日予約できません。';
      }
      if (msg) {
        const card = showCardError(dateKey, msg);
        if (!firstError) firstError = { el: card, msg: `${formatDateLabel(dateKey)}：${msg}` };
      }
    }

    if (!agreeTerms?.checked && !firstError) {
      firstError = { el: agreeTerms.closest('.check-card'), msg: '利用規約に同意してください。' };
    }
    agreeTerms?.closest('.check-card')?.classList.toggle('has-error', !agreeTerms.checked);

    if (firstError) {
      showFormErrorSummary(firstError.msg);
      requestAnimationFrame(() => {
        firstError.el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (firstError.el?.matches?.('input')) setTimeout(() => firstError.el.focus({ preventScroll: true }), 300);
      });
      return false;
    }
    syncHiddenInput();
    return true;
  }

  form.addEventListener('submit', (event) => {
    if (form.dataset.submitting === '1') {
      event.preventDefault();
      return;
    }
    if (!validateBeforeSubmit()) {
      event.preventDefault();
      setSubmitButtonState(false);
      return;
    }
    form.dataset.submitting = '1';
    setSubmitButtonState(true);
  });

  window.addEventListener('pageshow', () => {
    form.dataset.submitting = '0';
    setSubmitButtonState(false);
  });

  /* ---------------- ダイアログ ---------------- */
  function openTerms() {
    if (termsDialog && window.UI) UI.openModal(termsDialog, { focus: '#agreeFromDialog' });
    else window.open('terms.html', '_blank', 'noopener');
  }
  document.getElementById('openTermsDialog')?.addEventListener('click', openTerms);
  document.querySelectorAll('[data-open-terms]').forEach((b) => b.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    openTerms();
  }));

  agreeFromDialogBtn?.addEventListener('click', () => {
    if (agreeTerms) agreeTerms.checked = true;
    agreeTerms?.closest('.check-card')?.classList.remove('has-error');
    if (window.UI && termsDialog) UI.closeModal(termsDialog);
    updateStepProgress();
  });

  document.getElementById('openManualDialog')?.addEventListener('click', () => {
    const isMobile = window.matchMedia('(max-width: 640px)').matches;
    if (isMobile || !manualDialog || !window.UI) {
      window.open(MANUAL_URL, '_blank', 'noopener');
      return;
    }
    const wrap = manualDialog.querySelector('.manual-embed-wrap');
    if (wrap && !wrap.querySelector('object')) {
      wrap.innerHTML = `<object data="${MANUAL_URL}#view=FitH" type="application/pdf" class="bk-manual-frame"><div class="empty"><span>PDF を表示できません。</span><a class="btn" href="${MANUAL_URL}" target="_blank" rel="noopener">${icon('external')}新しいタブで開く</a></div></object>`;
    }
    UI.openModal(manualDialog);
  });

  /* ---------------- 入力イベント ---------------- */
  [emailInput, orgInput].forEach((input) => {
    input?.addEventListener('input', () => {
      if (input.getAttribute('aria-invalid') === 'true') setFieldError(input, '');
      updateStepProgress();
    });
    input?.addEventListener('blur', updateStepProgress);
  });
  agreeTerms?.addEventListener('change', () => {
    agreeTerms.closest('.check-card')?.classList.remove('has-error');
    updateStepProgress();
  });

  prevMonthBtn?.addEventListener('click', () => moveMonth(-1));
  nextMonthBtn?.addEventListener('click', () => moveMonth(1));
  goAvailableStartBtn?.addEventListener('click', () => {
    currentMonth = minDate ? new Date(minDate.getFullYear(), minDate.getMonth(), 1) : new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    loadMonth(currentMonth);
  });

  /* ---------------- モバイル: 予約内容へのジャンプ ---------------- */
  const cart = document.getElementById('bkCart');
  let cartVisible = false;
  function updateJump() {
    if (!jump) return;
    const show = selectedDates.length > 0 && !cartVisible && window.matchMedia('(max-width: 900px)').matches;
    jump.hidden = !show;
  }
  if (cart && 'IntersectionObserver' in window) {
    new IntersectionObserver((entries) => {
      cartVisible = entries.some((entry) => entry.isIntersecting);
      updateJump();
    }, { threshold: 0.15 }).observe(cart);
  }

  renderCalendarDetail();
  renderDetailCards();
  updateStepProgress();
  const initial = new Date();
  initial.setDate(1);
  currentMonth = initial;
  loadMonth(currentMonth);
})();
