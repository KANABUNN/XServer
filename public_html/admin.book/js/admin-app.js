/* =====================================================================
   貸し部屋予約 管理画面
   ===================================================================== */
(() => {
  const bootstrap = window.AdminBootstrap || {};
  const csrfToken = bootstrap.csrfToken || '';
  const permissions = Array.isArray(bootstrap.currentUser?.permissions) ? bootstrap.currentUser.permissions : [];

  const q = (selector) => document.querySelector(selector);
  const hasPermission = (permission) => permissions.includes(permission);
  const icon = (name, cls) => (window.UI ? UI.icon(name, cls) : '');
  const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

  const STATUS_META = {
    confirmed: ['確定', 'success'],
    rejected: ['却下', 'danger'],
    error: ['要確認', 'warning'],
    pending: ['保留', 'info'],
    replaced: ['上書き済み', ''],
    deleted: ['削除済み', ''],
    queued: ['待機中', 'info'],
    requested: ['発行済み', 'success'],
    success: ['成功', 'success'],
    synced: ['同期済み', 'success'],
    sent: ['送信済み', 'success'],
    failed: ['失敗', 'danger'],
    skipped: ['対象外', ''],
    disabled: ['無効', ''],
  };

  const calendarState = {
    month: '',
    rows: [],
    dayMap: {},
    selectedDate: '',
    today: (() => {
      const now = new Date();
      return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    })(),
  };
  let passcodeRows = [];
  let passcodeFilter = 'all';
  let adminUserRows = [];

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function setText(selector, value) {
    const el = q(selector);
    if (el) el.textContent = value;
  }

  function statusBadge(status, prefix = '') {
    const key = String(status || '').toLowerCase();
    if (!key) return '<span class="text-subtle">—</span>';
    const [label, tone] = STATUS_META[key] || [status, ''];
    return `<span class="badge${tone ? ` badge--${tone}` : ''} badge--dot" title="${escapeHtml(status)}">${prefix ? `${escapeHtml(prefix)} ` : ''}${escapeHtml(label)}</span>`;
  }

  function roomBadge(row) {
    const code = String(row.room_code || '');
    const label = row.room_label || code || '—';
    const tone = code === 'tamoku' ? 'teal' : code === 'orange' ? 'orange' : '';
    return `<span class="badge${tone ? ` badge--${tone}` : ''}">${escapeHtml(label)}</span>`;
  }

  function formatDay(dateKey) {
    if (!dateKey) return '';
    const date = new Date(`${dateKey}T00:00:00`);
    if (Number.isNaN(date.getTime())) return dateKey;
    return `${date.getMonth() + 1}/${date.getDate()}(${WEEKDAYS[date.getDay()]})`;
  }

  function formatCalendarDateLabel(dateKey) {
    if (!dateKey) return '';
    const date = new Date(`${dateKey}T00:00:00`);
    if (Number.isNaN(date.getTime())) return dateKey;
    return `${date.getFullYear()}年${date.getMonth() + 1}月${date.getDate()}日(${WEEKDAYS[date.getDay()]})`;
  }

  function rangeLabel(row) {
    const first = row.use_date || '';
    const last = row.use_date_end || row.use_date || '';
    const count = Number(row.selected_dates_count || 0);
    if (!first) return '';
    if (count <= 1 || first === last) return `<span class="cell-main">${escapeHtml(formatDay(first))}</span>`;
    return `<span class="cell-main">${escapeHtml(formatDay(first))} 〜 ${escapeHtml(formatDay(last))}</span><span class="cell-sub">${escapeHtml(String(count))}日分</span>`;
  }

  function orgCell(row) {
    return `<span class="cell-main">${escapeHtml(row.organization_name || '')}</span>${row.email ? `<span class="cell-sub allow-select">${escapeHtml(row.email)}</span>` : ''}`;
  }

  function passcodeCell(code) {
    return code ? `<span class="ab-code allow-select">${escapeHtml(code)}</span>` : '<span class="text-subtle">—</span>';
  }

  function emptyRow(colspan, message, iconName = 'inbox') {
    return `<tr><td colspan="${colspan}" class="empty"><div class="empty"><span class="empty__icon">${icon(iconName)}</span><span>${escapeHtml(message)}</span></div></td></tr>`;
  }

  function errorRow(colspan, message) {
    return emptyRow(colspan, message, 'alert-triangle');
  }

  function api(action, payload = {}) {
    return fetch('./manage_reservations.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken,
      },
      body: JSON.stringify({ action, ...payload }),
    }).then(async (response) => {
      const json = await response.json().catch(() => ({ ok: false, message: '応答を解釈できませんでした。' }));
      if (!response.ok || !json.ok) {
        throw new Error(json.message || '通信に失敗しました。');
      }
      return json;
    });
  }

  function toastError(error, title = '処理できませんでした') {
    UI.toast(error instanceof Error ? error.message : String(error || ''), { type: 'error', title });
  }

  /* ---------------- ビュー切替 ---------------- */
  const VIEW_LOADERS = {
    dashboardView: () => loadDashboard(),
    calendarView: () => loadCalendar(),
    passcodeView: () => loadPasscodes(),
    adminView: () => Promise.allSettled([loadAdminUsers(), loadAuditLogs()]),
  };
  let activeView = 'dashboardView';

  function activateView(targetId, { updateHash = true } = {}) {
    const view = q(`#${targetId}`);
    if (!view) return;
    activeView = targetId;
    document.querySelectorAll('.sidebar-link').forEach((el) => {
      const on = el.dataset.viewTarget === targetId;
      el.classList.toggle('is-active', on);
      if (on) el.setAttribute('aria-current', 'page'); else el.removeAttribute('aria-current');
    });
    document.querySelectorAll('.content-view').forEach((el) => el.classList.toggle('is-active', el.id === targetId));
    setText('#viewTitle', view.dataset.title || '');
    setText('#viewSubtitle', view.dataset.subtitle || '');
    document.title = `${view.dataset.title || '管理'} | 貸し部屋予約 管理`;
    if (updateHash && history.replaceState) history.replaceState(null, '', `#${targetId.replace(/View$/, '')}`);
    document.querySelector('.app-shell')?.classList.remove('is-nav-open');
  }

  function initSidebar() {
    document.querySelectorAll('.sidebar-link').forEach((button) => {
      button.addEventListener('click', () => activateView(button.dataset.viewTarget));
    });
    const fromHash = window.location.hash.replace(/^#/, '');
    if (fromHash && q(`#${fromHash}View`) && document.querySelector(`[data-view-target="${fromHash}View"]`)) {
      activateView(`${fromHash}View`, { updateHash: false });
    }
    q('#viewReloadBtn')?.addEventListener('click', async (event) => {
      const button = event.currentTarget;
      button.classList.add('is-spinning');
      await Promise.resolve(VIEW_LOADERS[activeView]?.());
      button.classList.remove('is-spinning');
    });
  }

  /* ---------------- 予約一覧 ---------------- */
  async function loadDashboard() {
    const tbody = q('#dashboardTableBody');
    if (tbody && !tbody.querySelector('tr[data-row]')) tbody.innerHTML = `<tr><td colspan="8" class="empty"><span class="spinner"></span></td></tr>`;
    setText('#dashboardStatusText', '読み込み中…');
    try {
      const json = await api('dashboard_list', {
        month: q('#dashboardMonth')?.value || '',
        room_code: q('#dashboardRoom')?.value || '',
        reservation_status: q('#dashboardStatus')?.value || '',
        keyword: q('#dashboardKeyword')?.value || '',
      });
      if (!tbody) return;
      const rows = json.rows || [];
      tbody.innerHTML = rows.length
        ? rows.map((row) => `
          <tr data-row class="${row.reservation_status === 'error' ? 'is-attention' : ''}">
            <td>${rangeLabel(row)}<span class="cell-sub">${escapeHtml(row.usage_time || '')}</span></td>
            <td>${roomBadge(row)}</td>
            <td>${orgCell(row)}</td>
            <td>${statusBadge(row.reservation_status)}</td>
            <td>${passcodeCell(row.access_code)}</td>
            <td><div class="ab-pairs"><span>SwitchBot</span>${statusBadge(row.switchbot_status)}<span>Google</span>${statusBadge(row.google_sync_status)}</div></td>
            <td><div class="ab-pairs"><span>利用者</span>${statusBadge(row.user_mail_status)}<span>事務局</span>${statusBadge(row.admin_mail_status)}</div></td>
            <td><span class="cell-sub">#${escapeHtml(row.id)}</span><span class="cell-sub">${escapeHtml(row.created_at || '')}</span></td>
          </tr>
        `).join('')
        : emptyRow(8, '該当する予約はありません。');

      setText('#dashboardMetaText', `${rows.length}件`);
      const s = json.summary || {};
      setText('#summaryConfirmedCount', String(s.confirmed_upcoming_count ?? 0));
      setText('#summarySwitchbotIssueCount', String(s.switchbot_issue_count ?? 0));
      setText('#summaryTodayCount', String(s.today_count ?? 0));
      setText('#summaryMailIssueCount', String(s.mail_issue_count ?? 0));
      const navBadge = q('#navSwitchbotIssue');
      if (navBadge) {
        navBadge.textContent = String(s.switchbot_issue_count ?? 0);
        navBadge.hidden = !Number(s.switchbot_issue_count || 0);
      }
      setText('#dashboardStatusText', json.message || '読み込みました');
    } catch (error) {
      if (tbody) tbody.innerHTML = errorRow(8, error instanceof Error ? error.message : '読み込みに失敗しました。');
      setText('#dashboardStatusText', '読み込みに失敗しました');
    }
  }

  /* ---------------- カレンダー ---------------- */
  function buildCalendarRoomGroups(items) {
    return items.reduce((groups, item) => {
      const key = item.room_code || item.room_label || 'unknown';
      if (!groups[key]) groups[key] = { room_code: item.room_code || '', room_label: item.room_label || item.room_code || '', rows: [] };
      groups[key].rows.push(item);
      return groups;
    }, {});
  }

  function switchbotRetryButton(row, statusTargetId, variant = 'btn--sm') {
    if (!hasPermission('access.edit') || row.can_retry_switchbot !== true) return '';
    return `
      <button type="button" class="btn ${variant} switchbot-retry-btn"
        data-slot-id="${escapeHtml(String(row.id || ''))}"
        data-status-target-id="${escapeHtml(statusTargetId)}"
        data-use-date="${escapeHtml(row.use_date || '')}"
        data-room-label="${escapeHtml(row.room_label || '')}"
        data-organization-name="${escapeHtml(row.organization_name || '')}">${icon('rotate-ccw')}SwitchBot を再試行</button>`;
  }

  function renderCalendarDateDetail(dateKey) {
    const titleEl = q('#calendarDetailTitle');
    const leadEl = q('#calendarDetailLead');
    const cardsEl = q('#calendarDetailCards');
    if (!titleEl || !leadEl || !cardsEl) return;
    setText('#calendarDetailStatusText', '');

    if (!dateKey) {
      titleEl.textContent = '日付を選択してください';
      leadEl.textContent = '';
      cardsEl.innerHTML = '';
      return;
    }
    const items = Array.isArray(calendarState.dayMap[dateKey]) ? calendarState.dayMap[dateKey] : [];
    titleEl.textContent = formatCalendarDateLabel(dateKey);
    leadEl.textContent = items.length ? `${items.length}件の予約` : 'この日の予約はありません';
    setText('#calendarDetailMetaText', leadEl.textContent);

    if (!items.length) {
      cardsEl.innerHTML = `<div class="empty"><span class="empty__icon">${icon('calendar')}</span><span>この日はまだ予約がありません。</span></div>`;
      return;
    }

    const groups = Object.values(buildCalendarRoomGroups(items));
    cardsEl.innerHTML = groups.map((group) => `
      <section class="ab-detail-group ab-detail-group--${escapeHtml(group.room_code)}">
        <header class="ab-detail-group__head">
          ${roomBadge(group)}
          <span class="text-subtle text-xs">${escapeHtml(String(group.rows.length))}件</span>
        </header>
        ${group.rows.map((row) => `
          <article class="ab-detail-item">
            <div class="ab-detail-item__main">
              <strong>${escapeHtml(row.organization_name || '')}</strong>
              <span class="chip">${icon('clock')}${escapeHtml(row.usage_time || '')}</span>
            </div>
            <dl class="dl dl--grid ab-detail-dl">
              <div><dt>利用者メール</dt><dd class="allow-select">${row.email ? escapeHtml(row.email) : '<span class="text-subtle">未入力</span>'}</dd></div>
              <div><dt>パスコード</dt><dd>${passcodeCell(row.access_code)}</dd></div>
              <div><dt>SwitchBot</dt><dd>${statusBadge(row.switchbot_status)}</dd></div>
              <div><dt>Google</dt><dd>${statusBadge(row.google_sync_status)}</dd></div>
            </dl>
            ${(hasPermission('calendar.delete') || (hasPermission('access.edit') && row.can_retry_switchbot === true)) ? `
              <div class="cluster cluster--end">
                ${switchbotRetryButton(row, 'calendarDetailStatusText')}
                ${hasPermission('calendar.delete') ? `
                  <button type="button" class="btn btn--sm btn--danger-ghost calendar-slot-delete-btn"
                    data-slot-id="${escapeHtml(String(row.id || ''))}"
                    data-use-date="${escapeHtml(row.use_date || '')}"
                    data-room-label="${escapeHtml(row.room_label || '')}"
                    data-organization-name="${escapeHtml(row.organization_name || '')}">${icon('trash')}この予約枠を削除</button>` : ''}
              </div>` : ''}
          </article>`).join('')}
      </section>
    `).join('');
  }

  function selectCalendarDate(date, { openDialog = true } = {}) {
    calendarState.selectedDate = date || '';
    const input = q('#manualUseDate');
    if (input && date) input.value = date;
    document.querySelectorAll('.calendar-day[data-date]').forEach((dayEl) => {
      dayEl.classList.toggle('is-selected', dayEl.dataset.date === calendarState.selectedDate);
    });
    renderCalendarDateDetail(calendarState.selectedDate);
    if (date && openDialog) UI.openModal('calendarDetailDialog');
  }

  function detectConflictLabel(date, roomCode) {
    const row = calendarState.rows.find((item) => item.use_date === date && item.room_code === roomCode);
    if (!row) return '';
    return `${row.room_label || roomCode} / ${row.organization_name || '既存予約'}（${row.usage_time || ''}）`;
  }

  function shiftMonth(value, offset) {
    const [y, m] = String(value || '').split('-').map(Number);
    if (!y || !m) return value;
    const d = new Date(y, m - 1 + offset, 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
  }

  async function loadCalendar() {
    setText('#calendarStatusText', '読み込み中…');
    const grid = q('#calendarAdminGrid');
    const tbody = q('#calendarListBody');
    try {
      const month = q('#calendarMonth')?.value || '';
      const json = await api('calendar_month', { month });
      if (!grid || !tbody) return;

      calendarState.month = json.month || month;
      calendarState.rows = Array.isArray(json.rows) ? json.rows : [];
      calendarState.dayMap = json.day_map || {};

      const target = new Date(`${calendarState.month}-01T00:00:00`);
      const startDay = new Date(target.getFullYear(), target.getMonth(), 1).getDay();
      const daysInMonth = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();

      let html = WEEKDAYS.map((label, i) => `<div class="calendar-weekday${i === 0 ? ' is-sun' : i === 6 ? ' is-sat' : ''}">${label}</div>`).join('');
      for (let i = 0; i < startDay; i += 1) html += '<div class="calendar-day is-empty"></div>';
      for (let day = 1; day <= daysInMonth; day += 1) {
        const dateKey = `${calendarState.month}-${String(day).padStart(2, '0')}`;
        const items = calendarState.dayMap[dateKey] || [];
        const dow = new Date(target.getFullYear(), target.getMonth(), day).getDay();
        const classNames = [
          'calendar-day',
          dateKey === calendarState.today ? 'is-today' : '',
          items.length ? 'has-reservation' : '',
          dow === 0 ? 'is-sun' : dow === 6 ? 'is-sat' : '',
          dateKey === calendarState.selectedDate ? 'is-selected' : '',
        ].filter(Boolean).join(' ');
        const visible = items.slice(0, 3);
        html += `
          <div class="${classNames}" data-date="${escapeHtml(dateKey)}" role="button" tabindex="0" aria-label="${escapeHtml(formatCalendarDateLabel(dateKey))} 予約${items.length}件">
            <div class="calendar-day-head">
              <span class="calendar-day-number">${day}</span>
              ${hasPermission('calendar.create') ? `<button type="button" class="calendar-add-btn" data-date="${escapeHtml(dateKey)}" aria-label="${escapeHtml(formatCalendarDateLabel(dateKey))} に予約を追加" title="この日に追加">${icon('plus', 'ico--sm')}</button>` : ''}
            </div>
            <div class="calendar-entries">
              ${visible.map((item) => `
                <div class="calendar-entry calendar-entry--${escapeHtml(item.room_code || '')}${item.switchbot_status === 'failed' ? ' has-issue' : ''}">
                  <span class="calendar-entry__time">${escapeHtml((item.usage_time || '').split(/[〜~-]/)[0].trim())}</span>
                  <span class="calendar-entry__org">${escapeHtml(item.organization_name || '')}</span>
                </div>`).join('')}
              ${items.length > visible.length ? `<div class="calendar-entry calendar-entry--more">他 ${items.length - visible.length}件</div>` : ''}
            </div>
          </div>`;
      }
      grid.innerHTML = html;
      grid.classList.remove('is-entering');
      void grid.offsetWidth;
      grid.classList.add('is-entering');

      tbody.innerHTML = calendarState.rows.length
        ? calendarState.rows.map((row) => `
          <tr data-row>
            <td><span class="cell-main">${escapeHtml(formatDay(row.use_date))}</span><span class="cell-sub">${escapeHtml(row.usage_time || '')}</span></td>
            <td>${roomBadge(row)}</td>
            <td>${orgCell(row)}</td>
            <td>${passcodeCell(row.access_code)}</td>
            <td>${statusBadge(row.switchbot_status)}</td>
            <td>${statusBadge(row.google_sync_status)}</td>
          </tr>`).join('')
        : emptyRow(6, 'この月の予約はありません。', 'calendar');

      if (calendarState.selectedDate.startsWith(`${calendarState.month}-`)) {
        renderCalendarDateDetail(calendarState.selectedDate);
      } else {
        calendarState.selectedDate = '';
        if (q('#calendarDetailDialog')?.open) UI.closeModal('calendarDetailDialog');
      }

      setText('#calendarMetaText', `${Object.keys(calendarState.dayMap).length}日 ・ ${calendarState.rows.length}件`);
      setText('#calendarStatusText', json.message || '読み込みました');
    } catch (error) {
      if (grid) grid.innerHTML = `<div class="calendar-error"><div class="empty"><span class="empty__icon">${icon('alert-triangle')}</span><span>${escapeHtml(error instanceof Error ? error.message : '読み込みに失敗しました。')}</span></div></div>`;
      setText('#calendarStatusText', '読み込みに失敗しました');
    }
  }

  function bindCalendar() {
    const grid = q('#calendarAdminGrid');
    grid?.addEventListener('click', (event) => {
      const add = event.target.closest('.calendar-add-btn');
      if (add) {
        event.stopPropagation();
        openManualDialog(add.dataset.date || '');
        return;
      }
      const day = event.target.closest('.calendar-day[data-date]');
      if (day) selectCalendarDate(day.dataset.date || '', { openDialog: true });
    });
    grid?.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      const day = event.target.closest('.calendar-day[data-date]');
      if (!day || event.target.closest('button')) return;
      event.preventDefault();
      selectCalendarDate(day.dataset.date || '', { openDialog: true });
    });

    q('#calendarDetailCards')?.addEventListener('click', async (event) => {
      const button = event.target.closest('.calendar-slot-delete-btn');
      if (!button) return;
      const slotId = Number(button.dataset.slotId || 0);
      if (!slotId) return;
      const ok = await UI.confirm({
        title: 'この予約枠を削除しますか？',
        message: `${formatCalendarDateLabel(button.dataset.useDate || calendarState.selectedDate)}\n${button.dataset.roomLabel || ''} / ${button.dataset.organizationName || ''}`,
        okText: '削除する',
        tone: 'danger',
      });
      if (!ok) return;
      button.disabled = true;
      button.classList.add('is-loading');
      try {
        const json = await api('calendar_slot_delete', { slot_id: slotId });
        UI.toast(json.message || '削除しました。', { type: 'success' });
        await Promise.allSettled([loadCalendar(), loadDashboard(), loadPasscodes(), loadAuditLogs()]);
      } catch (error) {
        toastError(error, '削除できませんでした');
      } finally {
        button.disabled = false;
        button.classList.remove('is-loading');
      }
    });

    q('#calendarPrevBtn')?.addEventListener('click', () => changeCalendarMonth(-1));
    q('#calendarNextBtn')?.addEventListener('click', () => changeCalendarMonth(1));
    q('#calendarTodayBtn')?.addEventListener('click', () => {
      const input = q('#calendarMonth');
      if (!input) return;
      input.value = calendarState.today.slice(0, 7);
      loadCalendar();
    });
    q('#calendarMonth')?.addEventListener('change', () => loadCalendar());

    document.querySelectorAll('[data-calendar-mode]').forEach((button) => {
      button.addEventListener('click', () => {
        const mode = button.dataset.calendarMode;
        document.querySelectorAll('[data-calendar-mode]').forEach((b) => {
          b.classList.toggle('is-active', b === button);
          b.setAttribute('aria-selected', b === button ? 'true' : 'false');
        });
        document.querySelectorAll('[data-calendar-panel]').forEach((panel) => panel.classList.toggle('hidden', panel.dataset.calendarPanel !== mode));
      });
    });
  }

  function changeCalendarMonth(offset) {
    const input = q('#calendarMonth');
    if (!input) return;
    input.value = shiftMonth(input.value, offset);
    const grid = q('#calendarAdminGrid');
    if (grid) grid.dataset.dir = offset > 0 ? 'next' : 'prev';
    loadCalendar();
  }

  /* ---------------- 予約の追加 ---------------- */
  function resetManualCalendarForm() {
    const set = (sel, value) => { const el = q(sel); if (el) el.value = value; };
    set('#manualUseDate', '');
    set('#manualRoomCode', 'tamoku');
    set('#manualOrganizationName', '');
    set('#manualUsageStartTime', '09:00');
    set('#manualUsageEndTime', '10:00');
    set('#manualEmail', '');
    if (q('#manualSyncGoogle')) q('#manualSyncGoogle').checked = true;
    if (q('#manualIssueSwitchbot')) q('#manualIssueSwitchbot').checked = true;
    setText('#calendarManualStatusText', '');
  }

  function openManualDialog(date = '') {
    if (!hasPermission('calendar.create') || !q('#calendarManualDialog')) return;
    resetManualCalendarForm();
    if (date) q('#manualUseDate').value = date;
    const after = () => UI.openModal('calendarManualDialog', { focus: date ? '#manualOrganizationName' : '#manualUseDate' });
    if (q('#calendarDetailDialog')?.open) UI.closeModal('calendarDetailDialog').then(after);
    else after();
  }

  async function submitManualCalendarCreate(event) {
    event?.preventDefault();
    if (!hasPermission('calendar.create')) return;
    const payload = {
      use_date: q('#manualUseDate').value || '',
      room_code: q('#manualRoomCode').value || 'tamoku',
      organization_name: q('#manualOrganizationName').value || '',
      usage_start_time: q('#manualUsageStartTime').value || '09:00',
      usage_end_time: q('#manualUsageEndTime').value || '10:00',
      email: q('#manualEmail').value || '',
      sync_google: q('#manualSyncGoogle').checked,
      issue_switchbot: q('#manualIssueSwitchbot').checked,
    };
    if (!payload.use_date || !payload.organization_name.trim()) {
      setText('#calendarManualStatusText', '利用日と団体名を入力してください。');
      (payload.use_date ? q('#manualOrganizationName') : q('#manualUseDate'))?.focus();
      return;
    }
    const conflictLabel = detectConflictLabel(payload.use_date, payload.room_code);
    if (conflictLabel) {
      const ok = await UI.confirm({
        title: '既存の予約を上書きしますか？',
        message: `同じ日・同じ部屋に予約があります。\n${conflictLabel}`,
        okText: '上書きする',
        tone: 'warning',
      });
      if (!ok) return;
    }

    const submitButton = q('#calendarManualCreateBtn');
    if (submitButton) { submitButton.disabled = true; submitButton.classList.add('is-loading'); }
    setText('#calendarManualStatusText', '');
    try {
      const json = await api('calendar_manual_create', payload);
      calendarState.selectedDate = payload.use_date;
      UI.closeModal('calendarManualDialog');
      UI.toast(`予約状態: ${(STATUS_META[json.reservation_status] || [json.reservation_status || '-'])[0]} ・ 利用者メール: ${(STATUS_META[json.user_mail_status] || [json.user_mail_status || '-'])[0]} ・ 事務局通知: ${(STATUS_META[json.admin_mail_status] || [json.admin_mail_status || '-'])[0]}`, {
        type: json.reservation_status === 'error' ? 'warning' : 'success',
        title: json.message || '登録しました',
        duration: 6500,
      });
      await Promise.allSettled([loadCalendar(), loadDashboard(), loadPasscodes(), loadAuditLogs()]);
    } catch (error) {
      setText('#calendarManualStatusText', error instanceof Error ? error.message : '登録に失敗しました。');
      toastError(error, '登録できませんでした');
    } finally {
      if (submitButton) { submitButton.disabled = false; submitButton.classList.remove('is-loading'); }
    }
  }

  /* ---------------- パスコード ---------------- */
  function isPasscodeIssue(row) {
    return ['failed', 'error'].includes(String(row.switchbot_status || '').toLowerCase()) || row.can_retry_switchbot === true;
  }

  function renderPasscodes() {
    const tbody = q('#passcodeTableBody');
    if (!tbody) return;
    const rows = passcodeFilter === 'issue' ? passcodeRows.filter(isPasscodeIssue) : passcodeRows;
    tbody.innerHTML = rows.length
      ? rows.map((row) => `
        <tr data-row class="${isPasscodeIssue(row) ? 'is-attention' : ''}">
          <td><span class="cell-main">${escapeHtml(formatDay(row.use_date))}</span><span class="cell-sub">${escapeHtml(row.usage_time || '')}</span></td>
          <td>${roomBadge(row)}</td>
          <td>${orgCell(row)}</td>
          <td>${passcodeCell(row.access_code)}</td>
          <td><span class="cell-sub">${escapeHtml(row.access_code_start_at || '')}</span><span class="cell-sub">〜 ${escapeHtml(row.access_code_end_at || '')}</span></td>
          <td>
            ${statusBadge(row.switchbot_status)}
            ${row.switchbot_message ? `<span class="cell-sub ab-msg allow-select">${escapeHtml(row.switchbot_message)}</span>` : ''}
            ${row.switchbot_request_id ? `<span class="cell-sub mono allow-select" title="request_id">${escapeHtml(row.switchbot_request_id)}</span>` : ''}
          </td>
          <td class="col-actions">${switchbotRetryButton(row, 'passcodeStatusText')}</td>
        </tr>`).join('')
      : emptyRow(7, passcodeFilter === 'issue' ? '要確認のパスコードはありません。' : '表示できるデータがありません。', passcodeFilter === 'issue' ? 'check-circle' : 'key');
    setText('#passcodeMetaText', `${rows.length}件`);
  }

  async function loadPasscodes() {
    setText('#passcodeStatusText', '読み込み中…');
    try {
      const json = await api('passcode_list');
      passcodeRows = json.rows || [];
      renderPasscodes();
      setText('#passcodeStatusText', json.message || '読み込みました');
    } catch (error) {
      const tbody = q('#passcodeTableBody');
      if (tbody) tbody.innerHTML = errorRow(7, error instanceof Error ? error.message : '読み込みに失敗しました。');
      setText('#passcodeStatusText', '読み込みに失敗しました');
    }
  }

  async function handleSwitchbotRetryClick(event) {
    if (!(event.target instanceof Element)) return;
    const button = event.target.closest('.switchbot-retry-btn');
    if (!(button instanceof HTMLButtonElement)) return;
    const slotId = Number(button.dataset.slotId || 0);
    if (!slotId) return;
    const ok = await UI.confirm({
      title: 'SwitchBot への発行を再試行しますか？',
      message: `${formatCalendarDateLabel(button.dataset.useDate || '')}\n${button.dataset.roomLabel || ''} / ${button.dataset.organizationName || ''}\n既存のパスコードをそのまま使って再送します。`,
      okText: '再試行する',
      tone: 'warning',
    });
    if (!ok) return;
    button.disabled = true;
    button.classList.add('is-loading');
    try {
      const json = await api('switchbot_retry', { slot_id: slotId });
      await Promise.allSettled([loadCalendar(), loadDashboard(), loadPasscodes(), loadAuditLogs()]);
      const failed = json.switchbot_message && json.switchbot_status !== 'requested';
      UI.toast(failed ? `${json.switchbot_status || 'failed'}: ${json.switchbot_message}` : '', {
        type: failed ? 'warning' : 'success',
        title: json.message || '再試行しました',
      });
    } catch (error) {
      toastError(error, 'SwitchBot の再試行に失敗しました');
    } finally {
      button.disabled = false;
      button.classList.remove('is-loading');
    }
  }

  /* ---------------- アカウント・ログ ---------------- */
  async function loadAdminUsers() {
    const tbody = q('#adminUsersTableBody');
    if (!hasPermission('admin.user.manage')) {
      if (tbody) tbody.innerHTML = emptyRow(6, 'このロールではアカウントを管理できません。', 'lock');
      return;
    }
    try {
      const json = await api('admin_user_list');
      adminUserRows = json.rows || [];
      if (!tbody) return;
      tbody.innerHTML = adminUserRows.length
        ? adminUserRows.map((row, index) => `
          <tr data-row class="is-clickable" data-admin-index="${index}">
            <td><span class="cell-main">${escapeHtml(row.display_name)}</span><span class="cell-sub">${escapeHtml(row.login_id)}</span></td>
            <td class="allow-select">${escapeHtml(row.email || '')}</td>
            <td><span class="badge${row.role_key === 'admin' ? ' badge--accent' : ''}">${escapeHtml(row.role_label)}</span></td>
            <td>${row.is_active ? '<span class="badge badge--success badge--dot">有効</span>' : '<span class="badge badge--dot">無効</span>'}</td>
            <td><span class="cell-sub">${escapeHtml(row.last_login_at || '—')}</span></td>
            <td class="col-actions"><button type="button" class="btn btn--ghost btn--sm" data-admin-edit="${index}">${icon('pen')}編集</button></td>
          </tr>`).join('')
        : emptyRow(6, '管理者アカウントがありません。', 'users');
      setText('#adminUsersMetaText', `${adminUserRows.length}件`);
      setText('#adminUsersStatusText', json.message || '読み込みました');
    } catch (error) {
      if (tbody) tbody.innerHTML = errorRow(6, error instanceof Error ? error.message : '読み込みに失敗しました。');
    }
  }

  async function loadAuditLogs() {
    const tbody = q('#auditTableBody');
    if (!hasPermission('admin.audit.view')) {
      if (tbody) tbody.innerHTML = emptyRow(5, 'このロールでは監査ログを表示できません。', 'lock');
      return;
    }
    try {
      const json = await api('audit_log_list');
      const rows = json.rows || [];
      if (!tbody) return;
      tbody.innerHTML = rows.length
        ? rows.map((row) => `
          <tr data-row>
            <td><span class="cell-sub nowrap">${escapeHtml(row.created_at)}</span></td>
            <td><code>${escapeHtml(row.action)}</code></td>
            <td>${escapeHtml(row.actor_display_name || row.actor_login_id || '')}</td>
            <td><span class="cell-sub">${escapeHtml(`${row.target_type || ''} ${row.target_id || ''}`.trim())}</span></td>
            <td>${row.summary_json ? `<details class="ab-json"><summary>内容を表示</summary><pre class="allow-select">${escapeHtml(prettyJson(row.summary_json))}</pre></details>` : '<span class="text-subtle">—</span>'}</td>
          </tr>`).join('')
        : emptyRow(5, '監査ログはありません。', 'history');
      setText('#auditMetaText', `${rows.length}件`);
      setText('#auditStatusText', json.message || '読み込みました');
    } catch (error) {
      if (tbody) tbody.innerHTML = errorRow(5, error instanceof Error ? error.message : '読み込みに失敗しました。');
    }
  }

  function prettyJson(raw) {
    try { return JSON.stringify(JSON.parse(raw), null, 2); } catch (e) { return String(raw); }
  }

  function fillAdminForm(row = null) {
    const set = (sel, value) => { const el = q(sel); if (el) el.value = value; };
    set('#adminUserId', row?.id || '');
    set('#adminLoginId', row?.login_id || '');
    set('#adminDisplayName', row?.display_name || '');
    set('#adminEmail', row?.email || '');
    set('#adminRoleKey', row?.role_key || 'viewer');
    set('#adminIsActive', row ? (row.is_active ? '1' : '0') : '1');
    set('#adminPassword', '');
    setText('#adminUserDialogTitle', row ? 'アカウントを編集' : 'アカウントを追加');
    setText('#adminPasswordHint', row ? '変更する場合のみ入力（10文字以上）' : '10文字以上');
    setText('#adminUsersFormStatus', '');
  }

  function openAdminUserDialog(row = null) {
    if (!q('#adminUserDialog')) return;
    fillAdminForm(row);
    UI.openModal('adminUserDialog', { focus: '#adminLoginId' });
  }

  async function submitAdminUser(event) {
    event?.preventDefault();
    const id = q('#adminUserId').value || '';
    const payload = {
      id,
      login_id: q('#adminLoginId').value || '',
      display_name: q('#adminDisplayName').value || '',
      email: q('#adminEmail').value || '',
      role_key: q('#adminRoleKey').value || 'viewer',
      is_active: q('#adminIsActive').value || '1',
      password: q('#adminPassword').value || '',
    };
    const button = q('#adminUserSaveBtn');
    if (button) { button.disabled = true; button.classList.add('is-loading'); }
    try {
      const json = await api(id ? 'admin_user_update' : 'admin_user_create', payload);
      UI.closeModal('adminUserDialog');
      UI.toast(json.message || '保存しました。', { type: 'success' });
      loadAdminUsers();
      loadAuditLogs();
    } catch (error) {
      setText('#adminUsersFormStatus', error instanceof Error ? error.message : '保存に失敗しました。');
    } finally {
      if (button) { button.disabled = false; button.classList.remove('is-loading'); }
    }
  }

  /* ---------------- 初期化 ---------------- */
  function initControls() {
    const now = new Date();
    const monthString = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    if (q('#dashboardMonth')) q('#dashboardMonth').value = monthString;
    if (q('#calendarMonth')) q('#calendarMonth').value = monthString;

    let keywordTimer = null;
    q('#dashboardFilterForm')?.addEventListener('submit', (event) => { event.preventDefault(); loadDashboard(); });
    q('#dashboardMonth')?.addEventListener('change', loadDashboard);
    q('#dashboardStatus')?.addEventListener('change', loadDashboard);
    q('#dashboardKeyword')?.addEventListener('input', () => {
      window.clearTimeout(keywordTimer);
      keywordTimer = window.setTimeout(loadDashboard, 350);
    });
    document.querySelectorAll('[data-room-filter]').forEach((button) => {
      button.addEventListener('click', () => {
        document.querySelectorAll('[data-room-filter]').forEach((b) => {
          b.classList.toggle('is-active', b === button);
          b.setAttribute('aria-pressed', b === button ? 'true' : 'false');
        });
        const hidden = q('#dashboardRoom');
        if (hidden) hidden.value = button.dataset.roomFilter || '';
        loadDashboard();
      });
    });
    q('#dashboardClearBtn')?.addEventListener('click', () => {
      q('#dashboardMonth').value = monthString;
      q('#dashboardStatus').value = '';
      q('#dashboardKeyword').value = '';
      q('#dashboardRoom').value = '';
      document.querySelectorAll('[data-room-filter]').forEach((b) => {
        const on = (b.dataset.roomFilter || '') === '';
        b.classList.toggle('is-active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      loadDashboard();
    });

    q('#calendarAddBtn')?.addEventListener('click', () => openManualDialog(calendarState.selectedDate || ''));
    q('#calendarDetailAddBtn')?.addEventListener('click', () => openManualDialog(calendarState.selectedDate || ''));
    q('#calendarManualSection')?.addEventListener('submit', submitManualCalendarCreate);
    q('#calendarManualResetBtn')?.addEventListener('click', resetManualCalendarForm);

    document.querySelectorAll('[data-passcode-filter]').forEach((button) => {
      button.addEventListener('click', () => {
        passcodeFilter = button.dataset.passcodeFilter || 'all';
        document.querySelectorAll('[data-passcode-filter]').forEach((b) => {
          b.classList.toggle('is-active', b === button);
          b.setAttribute('aria-pressed', b === button ? 'true' : 'false');
        });
        renderPasscodes();
      });
    });
    document.addEventListener('click', handleSwitchbotRetryClick);

    q('#adminUserNewBtn')?.addEventListener('click', () => openAdminUserDialog(null));
    q('#adminUserForm')?.addEventListener('submit', submitAdminUser);
    q('#adminUsersTableBody')?.addEventListener('click', (event) => {
      const rowEl = event.target.closest('[data-admin-index]');
      if (!rowEl) return;
      const row = adminUserRows[Number(rowEl.dataset.adminIndex)];
      if (row) openAdminUserDialog(row);
    });
  }

  async function init() {
    initSidebar();
    initControls();
    bindCalendar();
    await Promise.allSettled([
      loadDashboard(),
      loadCalendar(),
      loadPasscodes(),
      loadAdminUsers(),
      loadAuditLogs(),
    ]);
  }

  init();
})();
