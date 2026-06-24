(() => {
  const bootstrap = window.AdminBootstrap || {};
  const csrfToken = bootstrap.csrfToken || '';
  const permissions = Array.isArray(bootstrap.currentUser?.permissions) ? bootstrap.currentUser.permissions : [];

  const q = (selector) => document.querySelector(selector);
  const hasPermission = (permission) => permissions.includes(permission);

  const calendarState = {
    month: '',
    rows: [],
    dayMap: {},
    selectedDate: '',
    today: (() => {
      const now = new Date();
      const year = now.getFullYear();
      const month = String(now.getMonth() + 1).padStart(2, '0');
      const day = String(now.getDate()).padStart(2, '0');
      return `${year}-${month}-${day}`;
    })(),
  };

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

  function badgeClass(status) {
    const safe = String(status || 'default').replace(/[^a-z0-9_-]/gi, '_').toLowerCase();
    return `badge badge-${safe}`;
  }

  function statusBadge(status) {
    const label = status || '-';
    return `<span class="${badgeClass(label)}">${escapeHtml(label)}</span>`;
  }

  function rangeLabel(row) {
    const first = row.use_date || '';
    const last = row.use_date_end || row.use_date || '';
    const count = Number(row.selected_dates_count || 0);
    if (!first) return '';
    if (count <= 1 || first === last) return escapeHtml(first);
    return `${escapeHtml(first)} ～ ${escapeHtml(last)}<br>${escapeHtml(String(count))}日分`;
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
      const json = await response.json().catch(() => ({ ok: false, message: 'JSON を解釈できませんでした。' }));
      if (!response.ok || !json.ok) {
        throw new Error(json.message || '通信に失敗しました。');
      }
      return json;
    });
  }

  function initSidebar() {
    document.querySelectorAll('.sidebar-link').forEach((button) => {
      button.addEventListener('click', () => {
        const targetId = button.dataset.viewTarget;
        if (!targetId) return;
        document.querySelectorAll('.sidebar-link').forEach((el) => el.classList.remove('is-active'));
        document.querySelectorAll('.content-view').forEach((el) => el.classList.remove('is-active'));
        button.classList.add('is-active');
        q(`#${targetId}`)?.classList.add('is-active');
      });
    });
  }

  function toggleAdminViewAccess() {
    if (hasPermission('admin.user.manage') || hasPermission('admin.audit.view')) return;
    const adminNavBtn = document.querySelector('[data-view-target="adminView"]');
    if (adminNavBtn) adminNavBtn.style.display = 'none';
  }

  function toggleCalendarCreateAccess() {
    const section = q('#calendarManualSection');
    if (!section) return;
    if (hasPermission('calendar.create')) return;
    section.hidden = true;
  }

  function formatCalendarDateLabel(dateKey) {
    if (!dateKey) return '';
    const date = new Date(`${dateKey}T00:00:00`);
    if (Number.isNaN(date.getTime())) return dateKey;
    const weekdays = ['日', '月', '火', '水', '木', '金', '土'];
    return `${dateKey} (${weekdays[date.getDay()]})`;
  }

  function isCalendarDetailDialogOpen() {
    const dialog = q('#calendarDetailDialog');
    return !!(dialog && !dialog.hidden);
  }

  function openCalendarDetailDialog() {
    const dialog = q('#calendarDetailDialog');
    if (!dialog) return;
    dialog.hidden = false;
    document.body.classList.add('modal-open');
  }

  function closeCalendarDetailDialog() {
    const dialog = q('#calendarDetailDialog');
    if (!dialog) return;
    dialog.hidden = true;
    document.body.classList.remove('modal-open');
    setText('#calendarDetailStatusText', '');
  }

  function selectCalendarDate(date, options = {}) {
    const { openDialog = true } = options;
    calendarState.selectedDate = date || '';
    const input = q('#manualUseDate');
    if (input && date) input.value = date;
    document.querySelectorAll('.calendar-day[data-date]').forEach((dayEl) => {
      dayEl.classList.toggle('is-selected', dayEl.dataset.date === calendarState.selectedDate);
    });
    renderCalendarDateDetail(calendarState.selectedDate);
    if (date && openDialog) {
      openCalendarDetailDialog();
    }
  }

  function detectConflictLabel(date, roomCode) {
    const row = calendarState.rows.find((item) => item.use_date === date && item.room_code === roomCode);
    if (!row) return '';
    return `${row.room_label || roomCode} / ${row.organization_name || '既存予約'}`;
  }


  function buildCalendarRoomGroups(items) {
    return items.reduce((groups, item) => {
      const key = item.room_code || item.room_label || 'unknown';
      if (!groups[key]) {
        groups[key] = {
          room_code: item.room_code || '',
          room_label: item.room_label || item.room_code || '',
          rows: [],
        };
      }
      groups[key].rows.push(item);
      return groups;
    }, {});
  }

  function bindCalendarDetailDeleteButtons() {
    document.querySelectorAll('.calendar-slot-delete-btn').forEach((button) => {
      button.addEventListener('click', async () => {
        const slotId = Number(button.dataset.slotId || 0);
        const roomLabel = button.dataset.roomLabel || '';
        const organizationName = button.dataset.organizationName || '';
        const useDate = button.dataset.useDate || calendarState.selectedDate || '';
        if (!slotId) return;

        const confirmed = window.confirm(
          `${useDate}\n${roomLabel} / ${organizationName}\n\nこの予約枠を削除しますか？`
        );
        if (!confirmed) return;

        button.disabled = true;
        setText('#calendarDetailStatusText', '削除中...');
        try {
          const json = await api('calendar_slot_delete', { slot_id: slotId });
          setText('#calendarDetailStatusText', json.message || '削除しました。');
          await Promise.allSettled([loadCalendar(), loadDashboard(), loadPasscodes(), loadAuditLogs()]);
        } catch (error) {
          setText('#calendarDetailStatusText', error instanceof Error ? error.message : '削除に失敗しました。');
        } finally {
          button.disabled = false;
        }
      });
    });
  }

  function renderCalendarDateDetail(dateKey) {
    const titleEl = q('#calendarDetailTitle');
    const leadEl = q('#calendarDetailLead');
    const metaEl = q('#calendarDetailMetaText');
    const cardsEl = q('#calendarDetailCards');
    if (!titleEl || !leadEl || !metaEl || !cardsEl) return;

    if (!dateKey) {
      titleEl.textContent = '日付を選択してください';
      leadEl.textContent = '予約が入っている日をクリックすると、部屋ごとの詳細を表示します。';
      metaEl.textContent = 'まだ日付が選択されていません。';
      cardsEl.innerHTML = '<article class="calendar-detail-empty">日付を選択すると詳細が表示されます。</article>';
      return;
    }

    const items = Array.isArray(calendarState.dayMap[dateKey]) ? calendarState.dayMap[dateKey] : [];
    titleEl.textContent = `${formatCalendarDateLabel(dateKey)} の予約詳細`;
    leadEl.textContent = items.length > 0
      ? '部屋ごとの予約内容です。削除はこの画面から行えます。'
      : 'この日は現在、予約が入っていません。';
    metaEl.textContent = items.length > 0 ? `${items.length}件の予約枠があります。` : 'この日の予約枠はありません。';

    if (items.length === 0) {
      cardsEl.innerHTML = '<article class="calendar-detail-empty">この日の予約はありません。</article>';
      return;
    }

    const groups = Object.values(buildCalendarRoomGroups(items));
    cardsEl.innerHTML = groups.map((group) => {
      const body = group.rows.map((row) => `
        <div class="calendar-detail-item">
          <dl class="calendar-detail-list">
            <div><dt>団体名</dt><dd>${escapeHtml(row.organization_name || '')}</dd></div>
            <div><dt>利用時間</dt><dd>${escapeHtml(row.usage_time || '')}</dd></div>
            <div><dt>利用者メール</dt><dd>${row.email ? escapeHtml(row.email) : '<span class="muted-inline">未入力</span>'}</dd></div>
            <div><dt>パスコード</dt><dd>${row.access_code ? escapeHtml(row.access_code) : '<span class="muted-inline">未発行</span>'}</dd></div>
            <div><dt>SwitchBot</dt><dd>${statusBadge(row.switchbot_status || '')}</dd></div>
            <div><dt>Google</dt><dd>${statusBadge(row.google_sync_status || '')}</dd></div>
          </dl>
          ${hasPermission('calendar.delete') ? `
            <div class="calendar-detail-actions">
              <button
                type="button"
                class="danger calendar-slot-delete-btn"
                data-slot-id="${escapeHtml(String(row.id || ''))}"
                data-use-date="${escapeHtml(row.use_date || '')}"
                data-room-label="${escapeHtml(row.room_label || '')}"
                data-organization-name="${escapeHtml(row.organization_name || '')}"
              >この予約枠を削除</button>
            </div>
          ` : ''}
        </div>
      `).join('');

      return `
        <article class="calendar-detail-card">
          <header class="calendar-detail-card-head">
            <h3>${escapeHtml(group.room_label || group.room_code || '部屋未設定')}</h3>
            <span class="calendar-detail-count">${escapeHtml(String(group.rows.length))}件</span>
          </header>
          ${body}
        </article>
      `;
    }).join('');

    bindCalendarDetailDeleteButtons();
  }

  async function loadDashboard() {
    setText('#dashboardStatusText', '読み込み中...');
    try {
      const json = await api('dashboard_list', {
        month: q('#dashboardMonth')?.value || '',
        room_code: q('#dashboardRoom')?.value || '',
        reservation_status: q('#dashboardStatus')?.value || '',
        keyword: q('#dashboardKeyword')?.value || '',
      });

      const tbody = q('#dashboardTableBody');
      if (!tbody) return;

      tbody.innerHTML = json.rows.length
        ? json.rows.map((row) => `
          <tr>
            <td>${escapeHtml(row.id)}</td>
            <td>${escapeHtml(row.created_at || '')}</td>
            <td>${rangeLabel(row)}</td>
            <td>${escapeHtml(row.usage_time || '')}</td>
            <td>${escapeHtml(row.room_label || '')}</td>
            <td>${escapeHtml(row.organization_name || '')}</td>
            <td>${escapeHtml(row.email || '')}</td>
            <td>${statusBadge(row.reservation_status || '')}</td>
            <td>${escapeHtml(row.access_code || '')}</td>
            <td>SwitchBot:${statusBadge(row.switchbot_status || '')}<br>Google:${statusBadge(row.google_sync_status || '')}</td>
            <td>利用者:${statusBadge(row.user_mail_status || '')}<br>管理:${statusBadge(row.admin_mail_status || '')}</td>
          </tr>
        `).join('')
        : '<tr><td colspan="11" class="empty">該当する予約がありません。</td></tr>';

      setText('#dashboardMetaText', `${json.rows.length}件を表示しています。`);
      setText('#summaryConfirmedCount', String(json.summary.confirmed_upcoming_count ?? 0));
      setText('#summarySwitchbotIssueCount', String(json.summary.switchbot_issue_count ?? 0));
      setText('#summaryTodayCount', String(json.summary.today_count ?? 0));
      setText('#summaryMailIssueCount', String(json.summary.mail_issue_count ?? 0));
      setText('#dashboardStatusText', json.message || '読込完了');
    } catch (error) {
      setText('#dashboardStatusText', error instanceof Error ? error.message : '読込に失敗しました。');
    }
  }

  function bindCalendarDaySelection() {
    document.querySelectorAll('.calendar-day[data-date]').forEach((dayEl) => {
      dayEl.addEventListener('click', (event) => {
        if (event.target instanceof HTMLElement && event.target.closest('.calendar-add-btn')) {
          return;
        }
        selectCalendarDate(dayEl.dataset.date || '', { openDialog: true });
      });
    });

    document.querySelectorAll('.calendar-add-btn').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.stopPropagation();
        const date = button.dataset.date || '';
        selectCalendarDate(date, { openDialog: false });
        q('#manualOrganizationName')?.focus();
      });
    });
  }

  async function loadCalendar() {
    setText('#calendarStatusText', '読み込み中...');
    try {
      const month = q('#calendarMonth')?.value || '';
      const json = await api('calendar_month', { month });

      const grid = q('#calendarAdminGrid');
      const tbody = q('#calendarListBody');
      if (!grid || !tbody) return;

      calendarState.month = json.month || month;
      calendarState.rows = Array.isArray(json.rows) ? json.rows : [];
      calendarState.dayMap = json.day_map || {};

      const weekdayLabels = ['日', '月', '火', '水', '木', '金', '土'];
      const target = new Date(`${json.month}-01T00:00:00`);
      const firstDay = new Date(target.getFullYear(), target.getMonth(), 1);
      const startDay = firstDay.getDay();
      const daysInMonth = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();

      grid.innerHTML = weekdayLabels.map((label) => `<div class="calendar-weekday">${label}</div>`).join('');
      for (let i = 0; i < startDay; i += 1) {
        grid.insertAdjacentHTML('beforeend', '<div class="calendar-day is-empty"></div>');
      }

      const map = json.day_map || {};
      for (let day = 1; day <= daysInMonth; day += 1) {
        const dateKey = `${json.month}-${String(day).padStart(2, '0')}`;
        const items = map[dateKey] || [];
        const content = items.length
          ? items.map((item) => `
              <div class="calendar-entry">
                <strong>${escapeHtml(item.room_label)}</strong>
                <span>${escapeHtml(item.organization_name)} / ${escapeHtml(item.usage_time || '')}</span>
              </div>
            `).join('')
          : '<div class="calendar-entry empty">予約なし</div>';

        const classNames = [
          'calendar-day',
          dateKey === calendarState.today ? 'is-today' : '',
          items.length > 0 ? 'has-reservation' : '',
        ].filter(Boolean).join(' ');

        grid.insertAdjacentHTML('beforeend', `
          <div class="${classNames}" data-date="${escapeHtml(dateKey)}">
            <div class="calendar-day-head">
              <span class="calendar-day-number">${day}</span>
              ${dateKey === calendarState.today ? '<span class="calendar-today-chip">今日</span>' : ''}
              ${hasPermission('calendar.create') ? `<button type="button" class="calendar-add-btn secondary" data-date="${escapeHtml(dateKey)}">追加</button>` : ''}
            </div>
            ${content}
          </div>
        `);
      }

      tbody.innerHTML = calendarState.rows.length
        ? calendarState.rows.map((row) => `
          <tr>
            <td>${escapeHtml(row.use_date)}</td>
            <td>${escapeHtml(row.usage_time || '')}</td>
            <td>${escapeHtml(row.room_label)}</td>
            <td>${escapeHtml(row.organization_name)}</td>
            <td>${escapeHtml(row.email || '')}</td>
            <td>${escapeHtml(row.access_code || '')}</td>
            <td>${statusBadge(row.switchbot_status || '')}</td>
            <td>${statusBadge(row.google_sync_status || '')}</td>
          </tr>
        `).join('')
        : '<tr><td colspan="8" class="empty">該当する予約はありません。</td></tr>';

      bindCalendarDaySelection();
      if (calendarState.selectedDate.startsWith(`${json.month}-`)) {
        selectCalendarDate(calendarState.selectedDate, { openDialog: isCalendarDetailDialogOpen() });
      } else {
        closeCalendarDetailDialog();
        renderCalendarDateDetail('');
      }

      const reservedDateCount = Object.keys(calendarState.dayMap).length;
      setText('#calendarMetaText', `${reservedDateCount}日 / ${calendarState.rows.length}件の予約枠があります。`);
      setText('#calendarStatusText', json.message || '読込完了');
    } catch (error) {
      setText('#calendarStatusText', error instanceof Error ? error.message : '読込に失敗しました。');
    }
  }

  async function loadPasscodes() {
    setText('#passcodeStatusText', '読み込み中...');
    try {
      const json = await api('passcode_list');
      const tbody = q('#passcodeTableBody');
      if (!tbody) return;

      tbody.innerHTML = json.rows.length
        ? json.rows.map((row) => `
          <tr>
            <td>${escapeHtml(row.use_date)}</td>
            <td>${escapeHtml(row.usage_time || '')}</td>
            <td>${escapeHtml(row.room_label)}</td>
            <td>${escapeHtml(row.organization_name)}</td>
            <td>${escapeHtml(row.email || '')}</td>
            <td>${escapeHtml(row.access_code || '')}</td>
            <td>${escapeHtml(row.access_code_start_at || '')}<br>${escapeHtml(row.access_code_end_at || '')}</td>
            <td>${statusBadge(row.switchbot_status || '')}</td>
            <td>${escapeHtml(row.switchbot_request_id || '')}</td>
          </tr>
        `).join('')
        : '<tr><td colspan="9" class="empty">表示できるデータがありません。</td></tr>';

      setText('#passcodeMetaText', `${json.rows.length}件を表示しています。`);
      setText('#passcodeStatusText', json.message || '読込完了');
    } catch (error) {
      setText('#passcodeStatusText', error instanceof Error ? error.message : '読込に失敗しました。');
    }
  }

  async function loadAdminUsers() {
    if (!hasPermission('admin.user.manage')) {
      setText('#adminUsersStatusText', 'このロールでは編集できません。');
      return;
    }
    try {
      const json = await api('admin_user_list');
      const tbody = q('#adminUsersTableBody');
      if (!tbody) return;

      tbody.innerHTML = json.rows.length
        ? json.rows.map((row) => `
          <tr class="clickable-row" data-admin-row='${escapeHtml(JSON.stringify(row))}'>
            <td>${escapeHtml(row.id)}</td>
            <td>${escapeHtml(row.login_id)}</td>
            <td>${escapeHtml(row.display_name)}</td>
            <td>${escapeHtml(row.email || '')}</td>
            <td>${escapeHtml(row.role_label)}</td>
            <td>${row.is_active ? '有効' : '無効'}</td>
            <td>${escapeHtml(row.last_login_at || '')}</td>
          </tr>
        `).join('')
        : '<tr><td colspan="7" class="empty">管理者アカウントがありません。</td></tr>';

      tbody.querySelectorAll('.clickable-row').forEach((rowEl) => {
        rowEl.addEventListener('click', () => {
          const raw = rowEl.dataset.adminRow || '{}';
          const row = JSON.parse(raw);
          q('#adminUserId').value = row.id || '';
          q('#adminLoginId').value = row.login_id || '';
          q('#adminDisplayName').value = row.display_name || '';
          q('#adminEmail').value = row.email || '';
          q('#adminRoleKey').value = row.role_key || 'viewer';
          q('#adminIsActive').value = row.is_active ? '1' : '0';
          q('#adminPassword').value = '';
        });
      });

      setText('#adminUsersMetaText', `${json.rows.length}件を表示しています。`);
      setText('#adminUsersStatusText', json.message || '読込完了');
    } catch (error) {
      setText('#adminUsersStatusText', error instanceof Error ? error.message : '読込に失敗しました。');
    }
  }

  async function loadAuditLogs() {
    if (!hasPermission('admin.audit.view')) {
      setText('#auditStatusText', 'このロールでは表示できません。');
      return;
    }

    try {
      const json = await api('audit_log_list');
      const tbody = q('#auditTableBody');
      if (!tbody) return;

      tbody.innerHTML = json.rows.length
        ? json.rows.map((row) => `
          <tr>
            <td>${escapeHtml(row.created_at)}</td>
            <td>${escapeHtml(row.action)}</td>
            <td>${escapeHtml(row.actor_display_name || row.actor_login_id || '')}</td>
            <td>${escapeHtml(`${row.target_type || ''} ${row.target_id || ''}`.trim())}</td>
            <td><pre class="summary-json">${escapeHtml(row.summary_json || '')}</pre></td>
          </tr>
        `).join('')
        : '<tr><td colspan="5" class="empty">監査ログがありません。</td></tr>';

      setText('#auditMetaText', `${json.rows.length}件を表示しています。`);
      setText('#auditStatusText', json.message || '読込完了');
    } catch (error) {
      setText('#auditStatusText', error instanceof Error ? error.message : '読込に失敗しました。');
    }
  }

  async function submitAdminUser(action) {
    const payload = {
      id: q('#adminUserId').value || '',
      login_id: q('#adminLoginId').value || '',
      display_name: q('#adminDisplayName').value || '',
      email: q('#adminEmail').value || '',
      role_key: q('#adminRoleKey').value || 'viewer',
      is_active: q('#adminIsActive').value || '1',
      password: q('#adminPassword').value || '',
    };

    try {
      const json = await api(action, payload);
      setText('#adminUsersStatusText', json.message || '保存しました。');
      q('#adminPassword').value = '';
      loadAdminUsers();
      loadAuditLogs();
    } catch (error) {
      setText('#adminUsersStatusText', error instanceof Error ? error.message : '保存に失敗しました。');
    }
  }

  function resetAdminForm() {
    q('#adminUserId').value = '';
    q('#adminLoginId').value = '';
    q('#adminDisplayName').value = '';
    q('#adminEmail').value = '';
    q('#adminRoleKey').value = 'viewer';
    q('#adminIsActive').value = '1';
    q('#adminPassword').value = '';
  }

  function resetManualCalendarForm() {
    calendarState.selectedDate = '';
    q('#manualUseDate').value = '';
    q('#manualRoomCode').value = 'tamoku';
    q('#manualOrganizationName').value = '';
    q('#manualUsageStartTime').value = '09:00';
    q('#manualUsageEndTime').value = '10:00';
    q('#manualEmail').value = '';
    q('#manualSyncGoogle').checked = true;
    q('#manualIssueSwitchbot').checked = true;
    setText('#calendarManualStatusText', '');
    setText('#calendarDetailStatusText', '');
    closeCalendarDetailDialog();
    document.querySelectorAll('.calendar-day[data-date]').forEach((dayEl) => dayEl.classList.remove('is-selected'));
    renderCalendarDateDetail('');
  }

  async function submitManualCalendarCreate() {
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
      return;
    }

    const conflictLabel = detectConflictLabel(payload.use_date, payload.room_code);
    if (conflictLabel && !window.confirm(`同日・同室に既存予約があります。\n${conflictLabel}\n\nこのまま上書きしますか？`)) {
      return;
    }

    const submitButton = q('#calendarManualCreateBtn');
    if (submitButton) submitButton.disabled = true;
    setText('#calendarManualStatusText', '登録中...');

    try {
      const json = await api('calendar_manual_create', payload);
      setText('#calendarManualStatusText', `${json.message || '登録しました。'} 予約状態:${json.reservation_status || '-'} / 利用者メール:${json.user_mail_status || '-'} / 管理通知:${json.admin_mail_status || '-'}`);
      calendarState.selectedDate = payload.use_date;
      await Promise.allSettled([loadCalendar(), loadDashboard(), loadPasscodes(), loadAuditLogs()]);
    } catch (error) {
      setText('#calendarManualStatusText', error instanceof Error ? error.message : '登録に失敗しました。');
    } finally {
      if (submitButton) submitButton.disabled = false;
    }
  }

  function initButtons() {
    const now = new Date();
    const monthString = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    if (q('#dashboardMonth')) q('#dashboardMonth').value = monthString;
    if (q('#calendarMonth')) q('#calendarMonth').value = monthString;

    q('#dashboardReloadBtn')?.addEventListener('click', loadDashboard);
    q('#dashboardSearchBtn')?.addEventListener('click', loadDashboard);
    q('#dashboardClearBtn')?.addEventListener('click', () => {
      q('#dashboardMonth').value = monthString;
      q('#dashboardRoom').value = '';
      q('#dashboardStatus').value = '';
      q('#dashboardKeyword').value = '';
      loadDashboard();
    });

    q('#calendarReloadBtn')?.addEventListener('click', loadCalendar);
    q('#calendarMonth')?.addEventListener('change', () => {
      resetManualCalendarForm();
      loadCalendar();
    });
    q('#calendarManualCreateBtn')?.addEventListener('click', submitManualCalendarCreate);
    q('#calendarManualResetBtn')?.addEventListener('click', resetManualCalendarForm);
    q('#calendarDetailCloseBtn')?.addEventListener('click', closeCalendarDetailDialog);
    q('#calendarDetailDialog')?.addEventListener('click', (event) => {
      if (event.target === event.currentTarget) {
        closeCalendarDetailDialog();
      }
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && isCalendarDetailDialogOpen()) {
        closeCalendarDetailDialog();
      }
    });

    q('#passcodeReloadBtn')?.addEventListener('click', loadPasscodes);

    q('#adminReloadBtn')?.addEventListener('click', () => {
      loadAdminUsers();
      loadAuditLogs();
    });
    q('#adminUserCreateBtn')?.addEventListener('click', () => submitAdminUser('admin_user_create'));
    q('#adminUserUpdateBtn')?.addEventListener('click', () => submitAdminUser('admin_user_update'));
    q('#adminUserResetBtn')?.addEventListener('click', resetAdminForm);
  }

  async function init() {
    toggleAdminViewAccess();
    toggleCalendarCreateAccess();
    initSidebar();
    initButtons();
    resetManualCalendarForm();
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

/* ========================================================================
   モバイル用: 各テーブルの <td> に列見出しを data-label として自動付与。
   reservation-admin-responsive.css のカード変換(<=768px)が ::before で参照する。
   ======================================================================== */
(function setupResponsiveTableLabels() {
  function stamp(table) {
    const heads = Array.from(table.querySelectorAll('thead th')).map((th) => th.textContent.trim());
    if (!heads.length) return;
    table.querySelectorAll('tbody tr').forEach((tr) => {
      const cells = tr.children;
      if (cells.length !== heads.length) return;   // colspan のプレースホルダ行はスキップ
      for (let i = 0; i < cells.length; i++) {
        cells[i].setAttribute('data-label', heads[i]);
      }
    });
  }
  function stampAll() {
    document.querySelectorAll('.table-wrap table').forEach(stamp);
  }
  function init() {
    stampAll();
    const observer = new MutationObserver(stampAll);
    document.querySelectorAll('.table-wrap table tbody').forEach((tbody) => {
      observer.observe(tbody, { childList: true });
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();