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

  // 改善: 入力欄・エラーサマリ・ステップ進捗の参照
  const emailInput = document.getElementById('email');
  const orgInput = document.getElementById('organization_name');
  const formErrorSummary = document.getElementById('formErrorSummary');
  const stepEls = Array.from(document.querySelectorAll('[data-step]'));

  // 改善 (E1): 削除確認ダイアログ要素
  const confirmDialog = document.getElementById('confirmDialog');
  const confirmDialogTitle = document.getElementById('confirmDialogTitle');
  const confirmDialogMessage = document.getElementById('confirmDialogMessage');
  const confirmDialogOkBtn = document.getElementById('confirmDialogOk');
  const confirmDialogCancelBtn = document.getElementById('confirmDialogCancel');

  // 改善 (E3): カードヒントの自動消去タイマー管理
  const cardHintTimers = {};

  // 改善 (F3): マニュアル PDF の元 <object> マークアップを記憶しておき、
  // モバイル ↔ デスクトップで切り替える際に復元できるようにする
  const manualEmbedOriginalHtml = '<object data="manuals/booking_user_manual.pdf#view=FitH" type="application/pdf" class="manual-pdf-frame"><p>PDF を表示できない場合は、<a href="manuals/booking_user_manual.pdf" target="_blank" rel="noopener">こちらから開いてください</a>。</p></object>';

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

  // 改善: 詳細パネルが直前まで非表示だったかを追跡 (自動スクロール用)
  let detailPanelWasHidden = true;

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

  // ===========================================================
  // 改善 (B): インラインエラー表示
  // ===========================================================

  function showCardError(dateKey, msg) {
    const card = dateConfigList.querySelector(`[data-date-card="${CSS.escape(dateKey)}"]`);
    if (!card) return null;
    card.classList.add('has-error');
    let err = card.querySelector('[data-card-error]');
    if (!err) {
      err = document.createElement('p');
      err.className = 'field-error';
      err.setAttribute('data-card-error', '');
      err.setAttribute('role', 'alert');
      card.appendChild(err);
    }
    err.textContent = msg;
    err.hidden = false;
    return card;
  }

  function clearCardError(dateKey) {
    const card = dateConfigList.querySelector(`[data-date-card="${CSS.escape(dateKey)}"]`);
    if (!card) return;
    card.classList.remove('has-error');
    const err = card.querySelector('[data-card-error]');
    if (err) {
      err.textContent = '';
      err.hidden = true;
    }
  }

  function clearAllCardErrors() {
    dateConfigList.querySelectorAll('.date-config-card').forEach((card) => {
      card.classList.remove('has-error');
      const err = card.querySelector('[data-card-error]');
      if (err) {
        err.textContent = '';
        err.hidden = true;
      }
    });
  }

  function showFormErrorSummary(msg) {
    if (!formErrorSummary) return;
    formErrorSummary.innerHTML = `<strong>入力内容を確認してください</strong>${escapeHtml(msg)}`;
    formErrorSummary.hidden = false;
    formErrorSummary.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function clearFormErrorSummary() {
    if (!formErrorSummary) return;
    formErrorSummary.textContent = '';
    formErrorSummary.hidden = true;
  }

  // ===========================================================
  // 改善 (E3): 一時的ヒントメッセージ (3秒で自動消去)
  // ===========================================================

  function showCardHint(dateKey, msg) {
    const card = dateConfigList.querySelector(`[data-date-card="${CSS.escape(dateKey)}"]`);
    if (!card) return;
    let hint = card.querySelector('[data-card-hint]');
    if (!hint) {
      hint = document.createElement('p');
      hint.className = 'field-hint';
      hint.setAttribute('data-card-hint', '');
      hint.setAttribute('role', 'status');
      card.appendChild(hint);
    }
    hint.textContent = msg;
    hint.hidden = false;
    if (cardHintTimers[dateKey]) clearTimeout(cardHintTimers[dateKey]);
    cardHintTimers[dateKey] = setTimeout(() => {
      if (hint && hint.isConnected) {
        hint.hidden = true;
        hint.textContent = '';
      }
      delete cardHintTimers[dateKey];
    }, 3000);
  }

  // ===========================================================
  // 改善 (E1): <dialog> ベースの確認ダイアログ (alert/confirm 廃止)
  // ===========================================================

  function showConfirm({ title = '確認', message = '', okText = '削除する', cancelText = 'キャンセル' } = {}) {
    return new Promise((resolve) => {
      // <dialog> 非対応環境では window.confirm にフォールバック
      if (!confirmDialog || typeof confirmDialog.showModal !== 'function'
          || !confirmDialogOkBtn || !confirmDialogCancelBtn
          || !confirmDialogTitle || !confirmDialogMessage) {
        resolve(window.confirm(message));
        return;
      }

      confirmDialogTitle.textContent = title;
      confirmDialogMessage.textContent = message;
      confirmDialogOkBtn.textContent = okText;
      confirmDialogCancelBtn.textContent = cancelText;

      let resolved = false;
      const safeResolve = (value) => {
        if (resolved) return;
        resolved = true;
        confirmDialogOkBtn.removeEventListener('click', handleOk);
        confirmDialogCancelBtn.removeEventListener('click', handleCancel);
        confirmDialog.removeEventListener('close', handleCloseEvent);
        resolve(value);
      };
      const handleOk = () => { safeResolve(true); confirmDialog.close(); };
      const handleCancel = () => { safeResolve(false); confirmDialog.close(); };
      const handleCloseEvent = () => { safeResolve(false); };

      confirmDialogOkBtn.addEventListener('click', handleOk);
      confirmDialogCancelBtn.addEventListener('click', handleCancel);
      confirmDialog.addEventListener('close', handleCloseEvent);
      confirmDialog.showModal();
    });
  }

  // ===========================================================
  // 改善 (A): ステップ進捗バー
  // ===========================================================

  function isStep1Done() {
    if (!emailInput || !orgInput) return false;
    const emailOk = emailInput.value.trim() !== '' && emailInput.checkValidity();
    const orgOk = orgInput.value.trim() !== '';
    return emailOk && orgOk;
  }

  function isStep2Done() {
    return selectedDates.length > 0;
  }

  function isDateConfigComplete(dateKey) {
    const detail = detailMap[dateKey];
    if (!detail) return false;
    if (!detail.room_code || !detail.usage_start_time || !detail.usage_end_time) return false;
    const s = timeToMinutes(detail.usage_start_time);
    const e = timeToMinutes(detail.usage_end_time);
    if (e <= s) return false;
    const allowedS = timeToMinutes(bookingTimeStart);
    const allowedE = timeToMinutes(bookingTimeEnd);
    if (s < allowedS || e > allowedE) return false;
    if (!getAvailableRooms(dateKey).some((r) => r.code === detail.room_code)) return false;
    return true;
  }

  function isStep3Done() {
    if (selectedDates.length === 0) return false;
    return selectedDates.every((dk) => isDateConfigComplete(dk));
  }

  function isStep4Done() {
    return Boolean(agreeTerms?.checked);
  }

  function updateStepProgress() {
    const states = [isStep1Done(), isStep2Done(), isStep3Done(), isStep4Done()];
    let foundActive = false;
    for (let i = 0; i < 4; i++) {
      const stepEl = stepEls.find((el) => Number(el.dataset.step) === i + 1);
      if (!stepEl) continue;
      const done = states[i];
      let active = false;
      if (!done && !foundActive) {
        active = true;
        foundActive = true;
      }
      stepEl.classList.toggle('is-done', done);
      stepEl.classList.toggle('is-active', active);
    }
  }

  // ===========================================================
  // 既存ロジック (一部更新)
  // ===========================================================

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
      detailPanelWasHidden = true;
      syncHiddenInput();
      updateStepProgress();
      return;
    }

    const wasHidden = detailPanelWasHidden;
    detailPanel.classList.remove('is-hidden');
    detailPanelWasHidden = false;

    dateConfigList.innerHTML = dates.map((dateKey, index) => {
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
              <h3><span class="date-card-num" aria-hidden="true">${index + 1}</span><span class="date-card-text">${escapeHtml(formatDateLabel(dateKey))}</span></h3>
              ${roomBadge}
            </div>
            <button type="button" class="remove-date-btn" data-remove-date="${dateKey}" aria-label="${escapeHtml(formatDateLabel(dateKey))} の選択を外す">この日を外す</button>
          </div>

          <div class="date-config-grid">
            <label class="form-group">
              <span>部屋<span class="required-badge" aria-hidden="true">必須</span></span>
              <select class="detail-room-select" data-date="${dateKey}">${roomOptions}</select>
            </label>

            <label class="form-group">
              <span>利用開始時刻<span class="required-badge" aria-hidden="true">必須</span></span>
              <select class="detail-start-select" data-date="${dateKey}">${startOptions}</select>
            </label>

            <label class="form-group">
              <span>利用終了時刻<span class="required-badge" aria-hidden="true">必須</span></span>
              <select class="detail-end-select" data-date="${dateKey}">${endOptions}</select>
            </label>
          </div>

          <p class="field-error" data-card-error role="alert" hidden></p>
          <p class="field-hint" data-card-hint role="status" hidden></p>
        </article>
      `;
    }).join('');

    dateConfigList.querySelectorAll('.detail-room-select').forEach((select) => {
      select.addEventListener('change', () => {
        const dateKey = select.dataset.date;
        if (!dateKey) return;
        ensureDetail(dateKey).room_code = select.value;
        clearCardError(dateKey);
        clearFormErrorSummary();
        syncHiddenInput();
        updateStepProgress();
      });
    });

    dateConfigList.querySelectorAll('.detail-start-select').forEach((select) => {
      select.addEventListener('change', () => {
        const dateKey = select.dataset.date;
        if (!dateKey) return;
        const detail = ensureDetail(dateKey);
        // 改善 (E3): 終了時刻が自動調整された場合に通知するため、変更前の値を控える
        const oldEnd = detail.usage_end_time;
        detail.usage_start_time = select.value;
        normalizeEndTime(detail);
        const newEnd = detail.usage_end_time;
        clearCardError(dateKey);
        clearFormErrorSummary();
        renderDetailCards();
        if (oldEnd !== newEnd && newEnd) {
          showCardHint(dateKey, `終了時刻を ${newEnd} に自動調整しました。`);
        }
      });
    });

    dateConfigList.querySelectorAll('.detail-end-select').forEach((select) => {
      select.addEventListener('change', () => {
        const dateKey = select.dataset.date;
        if (!dateKey) return;
        ensureDetail(dateKey).usage_end_time = select.value;
        clearCardError(dateKey);
        clearFormErrorSummary();
        syncHiddenInput();
        updateStepProgress();
      });
    });

    dateConfigList.querySelectorAll('.remove-date-btn').forEach((button) => {
      button.addEventListener('click', async () => {
        const dateKey = button.dataset.removeDate;
        if (!dateKey) return;
        // 改善 (E1): 誤クリック防止のため <dialog> ベースの確認を表示
        const ok = await showConfirm({
          title: '日付の選択を外しますか?',
          message: `${formatDateLabel(dateKey)} の予約設定がリセットされます。本当に外してよろしいですか?`,
          okText: '外す',
          cancelText: 'キャンセル',
        });
        if (!ok) return;
        const index = selectedDates.indexOf(dateKey);
        if (index >= 0) selectedDates.splice(index, 1);
        delete detailMap[dateKey];
        renderCalendar();
        renderCalendarDetail();
        renderDetailCards();
      });
    });

    syncHiddenInput();
    updateStepProgress();

    // 改善 (A): 詳細パネルが新たに表示されたときだけスクロール誘導
    if (wasHidden) {
      requestAnimationFrame(() => {
        detailPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    }
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

      // 改善 (D1): 「多:○ / 橙:○」記号表記をバッジに置き換え
      // 改善 (F4): スクリーンリーダー向けに aria-label / aria-pressed を付与
      const noteText = !inRange ? '対象外' : (fullBooked ? '満室' : (selected ? '選択中' : (partial ? '一部空き' : '選択可')));
      const tamokuOpen = !dayStatus.tamoku;
      const orangeOpen = !dayStatus.orange;
      const tamokuStateLabel = tamokuOpen ? '空き' : '予約あり';
      const orangeStateLabel = orangeOpen ? '空き' : '予約あり';

      button.innerHTML = `
        <span class="calendar-day-number">${day}</span>
        <span class="calendar-day-note">${noteText}</span>
        <span class="calendar-room-chips" aria-hidden="true">
          <span class="room-chip ${tamokuOpen ? 'is-open' : 'is-taken'}">多目的</span>
          <span class="room-chip ${orangeOpen ? 'is-open' : 'is-taken'}">オレンジ</span>
        </span>
      `;

      const ariaLabel = inRange
        ? `${formatDateLabel(dateKey)} ${noteText}。多目的室${tamokuStateLabel}、オレンジの部屋${orangeStateLabel}。`
        : `${formatDateLabel(dateKey)} ${noteText}`;
      button.setAttribute('aria-label', ariaLabel);
      if (selectable) {
        button.setAttribute('aria-pressed', selected ? 'true' : 'false');
      }

      button.addEventListener('click', () => {
        const idx = selectedDates.indexOf(dateKey);
        if (idx >= 0) {
          selectedDates.splice(idx, 1);
          delete detailMap[dateKey];
        } else {
          selectedDates.push(dateKey);
          ensureDetail(dateKey);
        }
        clearFormErrorSummary();
        renderCalendar();
        renderCalendarDetail();
        renderDetailCards();
      });
      calendarGrid.appendChild(button);
    }
  }

  // 改善 (D2): 月遷移中のシマー付きスケルトン表示
  function renderSkeleton() {
    calendarGrid.innerHTML = '';
    renderWeekdayHeader();
    // 6週分(42セル)を生成すれば、どの月でも収まる
    for (let i = 0; i < 42; i++) {
      const el = document.createElement('div');
      el.className = 'calendar-day is-skeleton';
      el.setAttribute('aria-hidden', 'true');
      calendarGrid.appendChild(el);
    }
  }

  async function loadMonth(date) {
    calendarStatusText.textContent = '予約状況を取得しています。';
    renderSkeleton(); // 改善 (D2): fetch 前にスケルトンを表示
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

  // ===========================================================
  // 改善 (B): validateBeforeSubmit から alert を完全排除
  // ===========================================================
  function validateBeforeSubmit() {
    clearAllCardErrors();
    clearFormErrorSummary();

    const dates = selectedSortedDates();
    if (!dates.length) {
      showFormErrorSummary('利用日を1日以上選択してください。');
      return false;
    }

    const allowedStartMinutes = timeToMinutes(bookingTimeStart);
    const allowedEndMinutes = timeToMinutes(bookingTimeEnd);
    let firstErrorCard = null;
    let firstErrorMsg = '';

    for (const dateKey of dates) {
      const detail = ensureDetail(dateKey);
      let cardMsg = '';

      if (!detail.room_code || !detail.usage_start_time || !detail.usage_end_time) {
        cardMsg = '部屋と利用時間を設定してください。';
      } else {
        const startMinutes = timeToMinutes(detail.usage_start_time);
        const endMinutes = timeToMinutes(detail.usage_end_time);
        if (endMinutes <= startMinutes) {
          cardMsg = '利用終了時刻は開始時刻より後にしてください。';
        } else if (startMinutes < allowedStartMinutes || endMinutes > allowedEndMinutes) {
          cardMsg = `利用時間は ${bookingTimeStart}〜${bookingTimeEnd} の範囲で指定してください。`;
        } else if (!getAvailableRooms(dateKey).some((room) => room.code === detail.room_code)) {
          cardMsg = '選択中の部屋は現在予約できません。別の部屋を選び直してください。';
        }
      }

      if (cardMsg) {
        const card = showCardError(dateKey, cardMsg);
        if (!firstErrorCard) {
          firstErrorCard = card;
          firstErrorMsg = `${formatDateLabel(dateKey)} に問題があります。詳細はカード内のメッセージを確認してください。`;
        }
      }
    }

    if (firstErrorCard) {
      showFormErrorSummary(firstErrorMsg);
      requestAnimationFrame(() => {
        firstErrorCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
      return false;
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
    if (typeof manualDialog?.showModal !== 'function') return;
    // 改善 (F3): iOS Safari など <object type="application/pdf"> を表示できない
    // モバイル端末では、プレビューをやめて「新しいタブで開く」CTA に置き換える
    const wrap = manualDialog.querySelector('.manual-embed-wrap');
    if (wrap) {
      const isMobile = window.matchMedia('(max-width: 640px)').matches;
      if (isMobile) {
        wrap.innerHTML = `
          <div class="manual-mobile-fallback">
            <p>マニュアル(PDF)は新しいタブで開きます。</p>
            <a href="manuals/booking_user_manual.pdf" target="_blank" rel="noopener" class="manual-mobile-open-btn">マニュアルを開く</a>
          </div>
        `;
      } else if (!wrap.querySelector('object')) {
        wrap.innerHTML = manualEmbedOriginalHtml;
      }
    }
    manualDialog.showModal();
  });

  agreeFromDialogBtn?.addEventListener('click', () => {
    agreeTerms.checked = true;
    if (typeof termsDialog?.close === 'function') {
      termsDialog.close();
    }
    updateStepProgress();
  });

  // 改善 (A): 入力欄イベントとステップ進捗連動
  emailInput?.addEventListener('input', updateStepProgress);
  emailInput?.addEventListener('blur', updateStepProgress);
  orgInput?.addEventListener('input', updateStepProgress);
  orgInput?.addEventListener('blur', updateStepProgress);
  agreeTerms?.addEventListener('change', updateStepProgress);

  renderCalendarDetail();
  renderDetailCards();
  updateStepProgress();
  const initial = new Date();
  initial.setDate(1);
  currentMonth = initial;
  loadMonth(currentMonth);
})();
