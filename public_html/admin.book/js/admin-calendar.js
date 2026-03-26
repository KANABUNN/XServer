/* admin-calendar.js
 * - 予約状況管理（確定予約カレンダー）
 */
(function () {
  'use strict';

  const Admin = (window.Admin = window.Admin || {});
  const u = Admin.utils;

  function guessRoomCode(value) {
    const v = String(value || '').trim().toLowerCase();
    if (v === 'tamoku' || v === 'orange') return v;
    if (v.includes('多目的')) return 'tamoku';
    if (v.includes('オレンジ')) return 'orange';
    return '';
  }

  function guessOrgNameFromEmail(email) {
    const e = String(email || '').trim();
    const at = e.indexOf('@');
    if (at <= 0) return '';
    return e.slice(0, at);
  }

  // 利用時間（開始/終了）プルダウン生成（0〜23時・15分刻み）
  function buildTimeValues() {
    const values = [];
    for (let h = 0; h <= 23; h += 1) {
      for (let m = 0; m <= 45; m += 15) {
        const hh = String(h).padStart(2, '0');
        const mm = String(m).padStart(2, '0');
        values.push(`${hh}:${mm}`);
      }
    }
    return values;
  }

  function populateTimeSelect(selectEl, placeholder) {
    if (!selectEl) return;
    const current = String(selectEl.value || '').trim();
    const options = [`<option value="">${u.escapeHtml(placeholder || '選択')}</option>`];
    buildTimeValues().forEach((t) => {
      options.push(`<option value="${u.escapeHtml(t)}">${u.escapeHtml(t)}</option>`);
    });
    selectEl.innerHTML = options.join('');
    if (current) selectEl.value = current;
  }

  function timeValueToMinutes(timeValue) {
    const v = String(timeValue || '').trim();
    if (!/^[0-2]\d:[0-5]\d$/.test(v)) return null;
    const [hh, mm] = v.split(':').map(Number);
    if (!Number.isFinite(hh) || !Number.isFinite(mm)) return null;
    if (hh < 0 || hh > 23) return null;
    if (![0, 15, 30, 45].includes(mm)) return null;
    return hh * 60 + mm;
  }

  // 両方未選択なら ''、片方のみはエラー、終了<=開始もエラー
  function buildUsageTimeString(startValue, endValue) {
    const start = String(startValue || '').trim();
    const end = String(endValue || '').trim();

    if (!start && !end) return '';

    if (!start || !end) {
      throw new Error('利用時間は開始と終了を両方選択してください。');
    }

    const startMin = timeValueToMinutes(start);
    const endMin = timeValueToMinutes(end);
    if (startMin === null || endMin === null) {
      throw new Error('利用時間の形式が不正です。');
    }
    if (endMin <= startMin) {
      throw new Error('利用終了時刻は利用開始時刻より後を選択してください。');
    }

    return `${start}~${end}`;
  }

  function initTimeSelects() {
    const el = Admin.el;
    populateTimeSelect(el.calendarUsageStart, '開始');
    populateTimeSelect(el.calendarUsageEnd, '終了');
    populateTimeSelect(el.calendarManageUsageStart, '開始');
    populateTimeSelect(el.calendarManageUsageEnd, '終了');
  }

  function ensureLoaded() {
    const el = Admin.el;
    const cs = Admin.calendar.state;
    if (cs.loaded) return;
    if (!el.calendarMonthInput || !el.calendarGrid || !el.calendarListBody) return;

    cs.loaded = true;
    initView();
    loadReservations();
  }

  function initView() {
    const el = Admin.el;
    const cs = Admin.calendar.state;

    cs.month = el.calendarMonthInput.value || u.formatMonthValue(new Date());
    el.calendarMonthInput.value = cs.month;

    el.calendarMonthInput.addEventListener('change', () => {
      if (!el.calendarMonthInput.value) return;
      cs.month = el.calendarMonthInput.value;
      loadReservations();
    });

    el.calendarRoomFilter?.addEventListener('change', () => {
      renderGrid();
      renderList();
    });

    el.calendarReloadBtn?.addEventListener('click', () => loadReservations());
    el.calendarPrevMonthBtn?.addEventListener('click', () => moveMonth(-1));
    el.calendarNextMonthBtn?.addEventListener('click', () => moveMonth(1));
    el.calendarTodayBtn?.addEventListener('click', () => {
      cs.month = u.formatMonthValue(new Date());
      el.calendarMonthInput.value = cs.month;
      loadReservations();
    });

    // calendar grid/list interaction
    el.calendarGrid?.addEventListener('click', (event) => {
      const manageButton = event.target.closest('[data-action="calendar-manage"]');
      if (manageButton) {
        openManage(manageButton.dataset.date || '');
        return;
      }

      const day = event.target.closest('.calendar-day[data-date]');
      if (day) {
        openManage(day.dataset.date || '');
      }
    });

    el.calendarListBody?.addEventListener('click', (event) => {
      const button = event.target.closest('[data-action="calendar-manage"]');
      if (!button) return;
      openManage(button.dataset.date || '');
    });

    el.calendarCardList?.addEventListener('click', (event) => {
      const button = event.target.closest('[data-action="calendar-manage"]');
      if (!button) return;
      openManage(button.dataset.date || '');
    });

    el.calendarManageList?.addEventListener('click', (event) => {
      const button = event.target.closest('[data-action="calendar-delete"]');
      if (!button) return;

      deleteReservation({
        id: Number(button.dataset.id || 0) || null,
        useDate: button.dataset.useDate || '',
        roomCode: button.dataset.roomCode || '',
        orgName: button.dataset.orgName || '',
      });
    });

    el.calendarManageAddBtn?.addEventListener('click', () => submitManageAdd());
    el.calendarManageCloseBtn?.addEventListener('click', () => el.calendarManageDialog.close());
  }

  function moveMonth(diff) {
    const el = Admin.el;
    const cs = Admin.calendar.state;

    const [year, month] = (cs.month || u.formatMonthValue(new Date())).split('-').map(Number);
    const next = new Date(year, month - 1 + diff, 1);
    cs.month = u.formatMonthValue(next);
    if (el.calendarMonthInput) el.calendarMonthInput.value = cs.month;
    loadReservations();
  }

  function buildUrl() {
    const cs = Admin.calendar.state;
    const url = new URL(Admin.apiPath, window.location.href);
    url.searchParams.set('action', 'calendar_list');
    url.searchParams.set('month', cs.month || u.formatMonthValue(new Date()));
    return url;
  }

  function normalizeRows(data) {
    if (!data) return [];
    const rows = Array.isArray(data.rows) ? data.rows : Array.isArray(data.data) ? data.data : Array.isArray(data.items) ? data.items : [];
    return rows.map((row) => ({
      id: row.id ?? '',
      use_date: String(row.use_date || row.date || '').trim(),
      room_code: String(row.room_code || row.room || '').trim(),
      organization_name: String(row.organization_name || row.org_name || row.organization || '').trim(),
      people_count: row.people_count === null || row.people_count === undefined ? '' : String(row.people_count).trim(),
      usage_time: String(row.usage_time ?? '').trim(),
    }));
  }

  function getVisibleRows() {
    const el = Admin.el;
    const cs = Admin.calendar.state;
    const room = (el.calendarRoomFilter?.value || '').trim();
    if (!room) return cs.reservations.slice();
    return cs.reservations.filter((entry) => String(entry.room_code || '') === room);
  }

  function getRowsForDate(dateKey, respectCurrentFilter = false) {
    const cs = Admin.calendar.state;
    const source = respectCurrentFilter ? getVisibleRows() : cs.reservations;

    return source
      .filter((entry) => String(entry.use_date || '') === String(dateKey))
      .sort((a, b) => {
        const roomCompare = String(a.room_code || '').localeCompare(String(b.room_code || ''));
        if (roomCompare !== 0) return roomCompare;
        return String(a.organization_name || '').localeCompare(String(b.organization_name || ''));
      });
  }

  function buildDayReservationsMap() {
    const map = new Map();
    getVisibleRows().forEach((item) => {
      const useDate = String(item.use_date || '').trim();
      if (!useDate) return;
      if (!map.has(useDate)) map.set(useDate, []);
      map.get(useDate).push(item);
    });
    return map;
  }

  function renderGrid() {
    const el = Admin.el;
    const cs = Admin.calendar.state;
    if (!el.calendarGrid) return;

    const monthValue = cs.month || u.formatMonthValue(new Date());
    const [year, month] = monthValue.split('-').map(Number);
    const firstDay = new Date(year, month - 1, 1);
    const lastDay = new Date(year, month, 0);
    const startWeekday = firstDay.getDay();
    const totalDays = lastDay.getDate();
    const todayKey = u.formatDateValue(new Date());
    const reservationMap = buildDayReservationsMap();

    const parts = [];
    const weekdayLabels = (Admin.constants && Admin.constants.weekdayLabels) || [];
    weekdayLabels.forEach((label) => parts.push(`<div class="calendar-weekday">${label}</div>`));

    for (let i = 0; i < startWeekday; i += 1) {
      parts.push('<div class="calendar-day is-muted"></div>');
    }

    for (let day = 1; day <= totalDays; day += 1) {
      const date = new Date(year, month - 1, day);
      const dateKey = u.formatDateValue(date);
      const entries = reservationMap.get(dateKey) || [];

      const bodyHtml = entries.length
        ? entries.map((entry) => {
          const extraMeta = u.buildCalendarExtraMeta(entry);
          return `
              <div class="calendar-entry">
                <div class="calendar-entry-head">
                  <span class="calendar-entry-room">${u.escapeHtml(u.roomLabel(entry.room_code))}</span>
                  ${entry.id ? `<span class="calendar-entry-id">#${u.escapeHtml(String(entry.id))}</span>` : ''}
                </div>
                <span>${u.escapeHtml(entry.organization_name || '—')}</span>
                ${extraMeta ? `<small>${u.escapeHtml(extraMeta)}</small>` : ''}
              </div>
            `;
        }).join('')
        : '<div class="calendar-empty">予約なし</div>';

      parts.push(`
        <div class="calendar-day ${dateKey === todayKey ? 'is-today' : ''}" data-date="${dateKey}">
          <div class="calendar-day-head">
            <span class="calendar-day-number">${day}</span>
          </div>
          <div class="calendar-day-body">
            ${bodyHtml}
          </div>
          <div class="calendar-day-tools">
            <button type="button" class="secondary calendar-day-manage" data-action="calendar-manage" data-date="${dateKey}">管理</button>
          </div>
        </div>
      `);
    }

    el.calendarGrid.innerHTML = parts.join('');

    if (el.calendarMetaText) {
      const room = (el.calendarRoomFilter?.value || '').trim();
      const roomText = room ? `（${u.roomLabel(room)}）` : '';
      el.calendarMetaText.textContent = `${monthValue} の確定予約 ${roomText}`;
    }
  }

  function renderList() {
    const el = Admin.el;
    const cardList = el.calendarCardList;

    const rows = getVisibleRows().sort((a, b) => {
      const dateCompare = String(a.use_date || '').localeCompare(String(b.use_date || ''));
      if (dateCompare !== 0) return dateCompare;
      const roomCompare = String(a.room_code || '').localeCompare(String(b.room_code || ''));
      if (roomCompare !== 0) return roomCompare;
      return String(a.organization_name || '').localeCompare(String(b.organization_name || ''));
    });

    if (!rows.length) {
      el.calendarListBody.innerHTML = '<tr><td colspan="6" class="empty">この条件の確定予約はありません。</td></tr>';
      if (cardList) {
        cardList.innerHTML = '<div class="card-empty">この条件の確定予約はありません。</div>';
      }
      return;
    }

    el.calendarListBody.innerHTML = rows.map((entry) => `
      <tr>
        <td>${u.escapeHtml(entry.use_date || '—')}</td>
        <td>${u.escapeHtml(u.roomLabel(entry.room_code))}</td>
        <td>${u.escapeHtml(entry.organization_name || '—')}</td>
        <td>${u.escapeHtml(u.peopleCountLabel(entry.people_count))}</td>
        <td>${u.escapeHtml(u.usageTimeLabel(entry.usage_time))}</td>
        <td>
          <div class="calendar-list-actions">
            <button type="button" class="secondary" data-action="calendar-manage" data-date="${u.escapeHtml(entry.use_date || '')}">管理</button>
          </div>
        </td>
      </tr>
    `).join('');
    if (cardList) {
      cardList.innerHTML = rows.map((entry) => {
        const useDate = u.escapeHtml(entry.use_date || '—');
        const room = u.escapeHtml(u.roomLabel(entry.room_code));
        const org = u.escapeHtml(entry.organization_name || '—');
        const people = u.escapeHtml(u.peopleCountLabel(entry.people_count));
        const usage = u.escapeHtml(u.usageTimeLabel(entry.usage_time));
        const dateRaw = u.escapeHtml(entry.use_date || '');

        return `
          <article class="data-card">
            <header class="card-head">
              <div class="card-head-left">
                <div class="card-title">${useDate}</div>
                <div class="card-sub">${room}</div>
              </div>
              <div class="card-head-right">
                <button type="button" class="secondary" data-action="calendar-manage" data-date="${dateRaw}">管理</button>
              </div>
            </header>

            <dl class="card-kv">
              <dt>団体名</dt><dd>${org}</dd>
              <dt>人数</dt><dd>${people}</dd>
              <dt>利用時間</dt><dd>${usage}</dd>
            </dl>
          </article>
        `;
      }).join('');
    }
  }

  async function loadReservations() {
    const el = Admin.el;
    const cs = Admin.calendar.state;

    if (!el.calendarMonthInput || !el.calendarGrid || !el.calendarListBody) return;

    u.setElementStatus(el.calendarStatusText, '確定予約を読み込んでいます…');

    try {
      const res = await fetch(buildUrl().toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        cache: 'no-store',
      });

      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.ok === false) {
        throw new Error(data.message || `HTTP ${res.status}`);
      }

      cs.reservations = normalizeRows(data);
      u.setElementStatus(el.calendarStatusText, '');
    } catch (err) {
      console.error('[calendar_list] load failed:', err);
      cs.reservations = [];
      u.setElementStatus(el.calendarStatusText, err.message || '確定予約を取得できませんでした。', 'error');
    }

    renderGrid();
    renderList();
    if (el.calendarManageDialog?.open && cs.selectedDate) {
      renderManageList();
    }
  }

  function openAdd(reservationId) {
    const el = Admin.el;
    const state = Admin.state;

    if (!el.calendarAddDialog || !el.calendarUseDate || !el.calendarRoomCode || !el.calendarOrgName || !el.calendarAddSubmitBtn) {
      u.setStatus('カレンダー登録UIがページに配置されていません。', true);
      return;
    }

    Admin.calendar.targetReservationId = reservationId;
    if (el.calendarAddSubText) el.calendarAddSubText.textContent = `申請ID ${reservationId}`;

    u.setElementStatus(el.calendarAddStatus, '');
    const row = state.currentRows.find((r) => Number(r.id) === Number(reservationId));
    const guessedRoom = row ? guessRoomCode(row.room) : '';
    const guessedOrg = row ? guessOrgNameFromEmail(row.email) : '';

    if (guessedRoom) el.calendarRoomCode.value = guessedRoom;
    if (!el.calendarOrgName.value && guessedOrg) el.calendarOrgName.value = guessedOrg;

    if (el.calendarPeopleCount) el.calendarPeopleCount.value = '';
    if (el.calendarUsageStart) el.calendarUsageStart.value = '';
    if (el.calendarUsageEnd) el.calendarUsageEnd.value = '';

    if (!el.calendarAddDialog.open) el.calendarAddDialog.showModal();
  }

  async function submitAdd() {
    const el = Admin.el;
    const cs = Admin.calendar;

    if (!cs.targetReservationId) return;

    const useDate = (el.calendarUseDate?.value || '').trim();
    const roomCode = (el.calendarRoomCode?.value || '').trim();
    const orgName = (el.calendarOrgName?.value || '').trim();

    let usageTime = '';
    try {
      usageTime = buildUsageTimeString(el.calendarUsageStart?.value, el.calendarUsageEnd?.value);
    } catch (err) {
      u.setElementStatus(el.calendarAddStatus, err.message || '利用時間が不正です。', 'error');
      return;
    }

    let peopleCount = null;
    try {
      peopleCount = u.normalizePeopleCountForSend(el.calendarPeopleCount?.value);
    } catch (err) {
      u.setElementStatus(el.calendarAddStatus, err.message || '人数が不正です。', 'error');
      return;
    }

    if (!useDate || !roomCode || !orgName) {
      u.setElementStatus(el.calendarAddStatus, '使用日・部屋・団体名は必須です。', 'error');
      return;
    }

    u.setElementStatus(el.calendarAddStatus, '登録しています…');

    try {
      const res = await fetch(`${Admin.apiPath}?action=calendar_add`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          use_date: useDate,
          room_code: roomCode,
          organization_name: orgName,
          people_count: peopleCount,
          usage_time: usageTime,
          reservation_id: cs.targetReservationId,
        }),
      });

      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        throw new Error(data.message || '確定予約の登録に失敗しました。');
      }

      u.setElementStatus(el.calendarAddStatus, data.message || '登録しました。', 'ok');
      u.setStatus(data.message || '確定予約として登録しました。');

      if (Admin.calendar.state.loaded) {
        await loadReservations();
      }
    } catch (err) {
      u.setElementStatus(el.calendarAddStatus, err.message || '登録に失敗しました。', 'error');
    }
  }

  function openManage(dateKey, presetRoom = '') {
    const el = Admin.el;
    const cs = Admin.calendar.state;

    if (!el.calendarManageDialog || !el.calendarManageList || !el.calendarManageUseDate || !el.calendarManageRoomCode || !el.calendarManageOrgName) {
      u.setElementStatus(el.calendarStatusText, 'カレンダー管理UIが不足しています。', 'error');
      return;
    }

    cs.selectedDate = String(dateKey || '').trim();
    if (!cs.selectedDate) return;

    el.calendarManageUseDate.value = cs.selectedDate;
    if (el.calendarManageSubText) el.calendarManageSubText.textContent = `日付 ${cs.selectedDate}`;
    if (presetRoom) {
      el.calendarManageRoomCode.value = presetRoom;
    } else if (el.calendarRoomFilter?.value) {
      el.calendarManageRoomCode.value = el.calendarRoomFilter.value;
    }

    el.calendarManageOrgName.value = '';
    if (el.calendarManagePeopleCount) el.calendarManagePeopleCount.value = '';
    if (el.calendarManageUsageStart) el.calendarManageUsageStart.value = '';
    if (el.calendarManageUsageEnd) el.calendarManageUsageEnd.value = '';

    if (!el.calendarManageDialog.open) {
      u.setElementStatus(el.calendarManageStatus, '');
    }
    renderManageList();

    if (!el.calendarManageDialog.open) el.calendarManageDialog.showModal();
  }

  function renderManageList() {
    const el = Admin.el;
    const cs = Admin.calendar.state;
    if (!el.calendarManageList) return;

    const rows = getRowsForDate(cs.selectedDate, false);
    if (!rows.length) {
      el.calendarManageList.innerHTML = '<div class="detail-empty">この日の確定予約はまだありません。</div>';
      return;
    }

    el.calendarManageList.innerHTML = rows.map((entry) => {
      const attrs = [
        entry.id ? `data-id="${u.escapeHtml(String(entry.id))}"` : '',
        `data-use-date="${u.escapeHtml(entry.use_date || '')}"`,
        `data-room-code="${u.escapeHtml(entry.room_code || '')}"`,
        `data-org-name="${u.escapeHtml(entry.organization_name || '')}"`,
      ].filter(Boolean).join(' ');

      const metaParts = [entry.organization_name || '—'];
      const extraMeta = u.buildCalendarExtraMeta(entry);
      if (extraMeta) metaParts.push(extraMeta);
      if (entry.id) metaParts.push(`ID ${entry.id}`);

      return `
        <div class="calendar-manage-item">
          <div class="calendar-manage-item-main">
            <div class="calendar-manage-item-title">${u.escapeHtml(u.roomLabel(entry.room_code))}</div>
            <div class="calendar-manage-item-meta">${u.escapeHtml(metaParts.join(' / '))}</div>
          </div>
          <div class="calendar-manage-item-actions">
            <button type="button" class="danger" data-action="calendar-delete" ${attrs}>削除</button>
          </div>
        </div>
      `;
    }).join('');
  }

  async function submitManageAdd() {
    const el = Admin.el;

    const useDate = (el.calendarManageUseDate?.value || '').trim();
    const roomCode = (el.calendarManageRoomCode?.value || '').trim();
    const orgName = (el.calendarManageOrgName?.value || '').trim();

    let usageTime = '';
    try {
      usageTime = buildUsageTimeString(el.calendarManageUsageStart?.value, el.calendarManageUsageEnd?.value);
    } catch (err) {
      u.setElementStatus(el.calendarManageStatus, err.message || '利用時間が不正です。', 'error');
      return;
    }

    let peopleCount = null;
    try {
      peopleCount = u.normalizePeopleCountForSend(el.calendarManagePeopleCount?.value);
    } catch (err) {
      u.setElementStatus(el.calendarManageStatus, err.message || '人数が不正です。', 'error');
      return;
    }

    if (!useDate || !roomCode || !orgName) {
      u.setElementStatus(el.calendarManageStatus, '使用日・部屋・団体名は必須です。', 'error');
      return;
    }

    u.setElementStatus(el.calendarManageStatus, '追加しています…');

    try {
      const res = await fetch(`${Admin.apiPath}?action=calendar_add`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          use_date: useDate,
          room_code: roomCode,
          organization_name: orgName,
          people_count: peopleCount,
          usage_time: usageTime,
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        throw new Error(data.message || 'カレンダー追加に失敗しました。');
      }

      u.setElementStatus(el.calendarManageStatus, data.message || '追加しました。', 'ok');
      el.calendarManageOrgName.value = '';
      if (el.calendarManagePeopleCount) el.calendarManagePeopleCount.value = '';
      if (el.calendarManageUsageStart) el.calendarManageUsageStart.value = '';
      if (el.calendarManageUsageEnd) el.calendarManageUsageEnd.value = '';

      await loadReservations();
      renderManageList();
    } catch (err) {
      u.setElementStatus(el.calendarManageStatus, err.message || 'カレンダー追加に失敗しました。', 'error');
    }
  }

  async function deleteReservation(payload) {
    const el = Admin.el;

    const roomText = u.roomLabel(payload.roomCode);
    const orgText = payload.orgName || '（団体名なし）';
    const ok = window.confirm(`${payload.useDate} / ${roomText} / ${orgText} を削除します。\nこの操作は取り消せません。`);
    if (!ok) return;

    u.setElementStatus(el.calendarManageStatus, '削除しています…');

    try {
      const res = await fetch(`${Admin.apiPath}?action=calendar_delete`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id: payload.id || null,
          use_date: payload.useDate,
          room_code: payload.roomCode,
          organization_name: payload.orgName,
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        throw new Error(data.message || 'カレンダー削除に失敗しました。');
      }

      u.setElementStatus(el.calendarManageStatus, data.message || '削除しました。', 'ok');
      await loadReservations();
      renderManageList();
    } catch (err) {
      u.setElementStatus(el.calendarManageStatus, err.message || 'カレンダー削除に失敗しました。', 'error');
    }
  }

  function bindAddDialog() {
    const el = Admin.el;

    // 申請一覧から「確定」ダイアログを開くケースがあるため、初期化はここで行う
    initTimeSelects();

    el.calendarFillTodayBtn?.addEventListener('click', () => {
      if (el.calendarUseDate) el.calendarUseDate.value = u.formatDateValue(new Date());
    });

    el.calendarAddSubmitBtn?.addEventListener('click', () => submitAdd());
    el.calendarAddCloseBtn?.addEventListener('click', () => el.calendarAddDialog.close());
  }

  Admin.calendar.ensureLoaded = ensureLoaded;
  Admin.calendar.openAdd = openAdd;
  Admin.calendar.submitAdd = submitAdd;
  Admin.calendar.openManage = openManage;
  Admin.calendar.loadReservations = loadReservations;
  Admin.calendar.renderGrid = renderGrid;
  Admin.calendar.renderList = renderList;
  Admin.calendar.bindAddDialog = bindAddDialog;
})();
