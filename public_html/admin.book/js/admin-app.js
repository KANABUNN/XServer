(() => {
  const bootstrap = window.AdminBootstrap || {};
  const csrfToken = bootstrap.csrfToken || '';
  const currentUser = bootstrap.currentUser || {};
  const permissions = new Set(currentUser.permissions || []);

  const q = (selector) => document.querySelector(selector);
  const qa = (selector) => Array.from(document.querySelectorAll(selector));

  function hasPermission(name) {
    return permissions.has(name);
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  async function api(action, payload = {}) {
    const response = await fetch('./manage_reservations.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'fetch',
        'X-CSRF-Token': csrfToken,
      },
      credentials: 'same-origin',
      body: JSON.stringify({ action, csrf_token: csrfToken, ...payload }),
    });
    const json = await response.json();
    if (!response.ok || !json.ok) {
      throw new Error(json.message || 'API エラーが発生しました。');
    }
    return json;
  }

  function setText(selector, value) {
    const el = q(selector);
    if (el) {
      el.textContent = value;
    }
  }

  function toggleAdminViewAccess() {
    if (!hasPermission('admin.user.manage')) {
      const adminNav = qa('.sidebar-link').find((el) => el.dataset.viewTarget === 'adminView');
      if (adminNav) {
        adminNav.hidden = true;
      }
    }
  }

  function initSidebar() {
    qa('.sidebar-link').forEach((button) => {
      button.addEventListener('click', () => {
        qa('.sidebar-link').forEach((el) => el.classList.remove('is-active'));
        qa('.content-view').forEach((el) => el.classList.remove('is-active'));
        button.classList.add('is-active');
        const target = document.getElementById(button.dataset.viewTarget);
        if (target) {
          target.classList.add('is-active');
        }
      });
    });
  }

  function statusBadge(value) {
    const safe = escapeHtml(value);
    return `<span class="badge badge-${safe.replace(/[^a-z0-9_-]/gi, '-')}">${safe}</span>`;
  }

  async function loadDashboard() {
    setText('#dashboardStatusText', '読み込み中...');
    try {
      const month = q('#dashboardMonth')?.value || '';
      const room = q('#dashboardRoom')?.value || '';
      const status = q('#dashboardStatus')?.value || '';
      const keyword = q('#dashboardKeyword')?.value || '';

      const json = await api('dashboard_list', { month, room_code: room, reservation_status: status, keyword });

      const tbody = q('#dashboardTableBody');
      if (!tbody) return;

      tbody.innerHTML = '';
      if (!json.rows.length) {
        tbody.innerHTML = '<tr><td colspan="10" class="empty">該当する予約はありません。</td></tr>';
      } else {
        tbody.innerHTML = json.rows.map((row) => `
          <tr>
            <td>${escapeHtml(row.id)}</td>
            <td>${escapeHtml(row.created_at)}</td>
            <td>${escapeHtml(row.use_date)}</td>
            <td>${escapeHtml(row.room_label)}</td>
            <td>${escapeHtml(row.organization_name)}</td>
            <td>${escapeHtml(row.email)}</td>
            <td>${statusBadge(row.reservation_status)}</td>
            <td>${escapeHtml(row.access_code || '')}</td>
            <td>${statusBadge(row.switchbot_status || '')}</td>
            <td>利用者:${statusBadge(row.user_mail_status || '')}<br>管理:${statusBadge(row.admin_mail_status || '')}</td>
          </tr>
        `).join('');
      }

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

  async function loadCalendar() {
    setText('#calendarStatusText', '読み込み中...');
    try {
      const month = q('#calendarMonth')?.value || '';
      const json = await api('calendar_month', { month });

      const grid = q('#calendarAdminGrid');
      const tbody = q('#calendarListBody');
      if (!grid || !tbody) return;

      const weekdayLabels = ['日', '月', '火', '水', '木', '金', '土'];
      const target = new Date(`${json.month}-01T00:00:00`);
      const firstDay = new Date(target.getFullYear(), target.getMonth(), 1);
      const startDay = firstDay.getDay();
      const daysInMonth = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();

      grid.innerHTML = weekdayLabels.map((label) => `<div class="calendar-weekday">${label}</div>`).join('');
      for (let i = 0; i < startDay; i++) {
        grid.insertAdjacentHTML('beforeend', '<div class="calendar-day is-empty"></div>');
      }

      const map = json.day_map || {};
      for (let day = 1; day <= daysInMonth; day++) {
        const dateKey = `${json.month}-${String(day).padStart(2, '0')}`;
        const items = map[dateKey] || [];
        const content = items.length
          ? items.map((item) => `<div class="calendar-entry"><strong>${escapeHtml(item.room_label)}</strong><span>${escapeHtml(item.organization_name)}</span></div>`).join('')
          : '<div class="calendar-entry empty">予約なし</div>';

        grid.insertAdjacentHTML('beforeend', `
          <div class="calendar-day">
            <span class="calendar-day-number">${day}</span>
            ${content}
          </div>
        `);
      }

      tbody.innerHTML = json.rows.length
        ? json.rows.map((row) => `
          <tr>
            <td>${escapeHtml(row.use_date)}</td>
            <td>${escapeHtml(row.room_label)}</td>
            <td>${escapeHtml(row.organization_name)}</td>
            <td>${escapeHtml(row.email)}</td>
            <td>${escapeHtml(row.access_code)}</td>
            <td>${statusBadge(row.switchbot_status || '')}</td>
          </tr>
        `).join('')
        : '<tr><td colspan="6" class="empty">該当する確定予約はありません。</td></tr>';

      setText('#calendarMetaText', `${json.rows.length}件の確定予約があります。`);
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
            <td>${escapeHtml(row.room_label)}</td>
            <td>${escapeHtml(row.organization_name)}</td>
            <td>${escapeHtml(row.email)}</td>
            <td>${escapeHtml(row.access_code)}</td>
            <td>${escapeHtml(row.access_code_start_at)}<br>${escapeHtml(row.access_code_end_at)}</td>
            <td>${statusBadge(row.switchbot_status || '')}</td>
            <td>${escapeHtml(row.switchbot_request_id || '')}</td>
          </tr>
        `).join('')
        : '<tr><td colspan="8" class="empty">表示できるデータがありません。</td></tr>';

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
    q('#calendarMonth')?.addEventListener('change', loadCalendar);
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
    initSidebar();
    initButtons();
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
