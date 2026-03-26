/* admin-admin.js
 * - 管理者アカウント管理 / 監査ログ
 */
(function () {
  'use strict';

  const Admin = (window.Admin = window.Admin || {});
  const el = Admin.el || {};
  const state = (Admin.adminView = Admin.adminView || {}).state || { loaded: false, users: [], auditRows: [], editingUserId: null };

  function setUsersStatus(message, isError = false) {
    if (Admin.utils && typeof Admin.utils.setElementStatus === 'function') {
      Admin.utils.setElementStatus(el.adminUsersStatusText, message, isError ? 'error' : '');
    }
  }

  function setAuditStatus(message, isError = false) {
    if (Admin.utils && typeof Admin.utils.setElementStatus === 'function') {
      Admin.utils.setElementStatus(el.adminAuditStatusText, message, isError ? 'error' : '');
    }
  }

  function resetForm() {
    state.editingUserId = null;
    if (el.adminUserId) el.adminUserId.value = '';
    if (el.adminLoginId) el.adminLoginId.value = '';
    if (el.adminDisplayName) el.adminDisplayName.value = '';
    if (el.adminEmail) el.adminEmail.value = '';
    if (el.adminRoleKey) el.adminRoleKey.value = 'viewer';
    if (el.adminIsActive) el.adminIsActive.value = '1';
    if (el.adminPassword) el.adminPassword.value = '';
  }

  function fillForm(row) {
    state.editingUserId = Number(row.id || 0);
    if (el.adminUserId) el.adminUserId.value = String(row.id || '');
    if (el.adminLoginId) el.adminLoginId.value = row.login_id || '';
    if (el.adminDisplayName) el.adminDisplayName.value = row.display_name || '';
    if (el.adminEmail) el.adminEmail.value = row.email || '';
    if (el.adminRoleKey) el.adminRoleKey.value = row.role_key || 'viewer';
    if (el.adminIsActive) el.adminIsActive.value = String(Number(row.is_active || 0) ? 1 : 0);
    if (el.adminPassword) el.adminPassword.value = '';
  }

  function roleLabel(roleKey) {
    const map = (Admin.constants && Admin.constants.roleLabelMap) || { viewer: '閲覧者', user: '編集者', admin: '管理者' };
    return map[roleKey] || roleKey || '—';
  }

  function renderUsers() {
    if (!el.adminUsersBody) return;
    if (!state.users.length) {
      el.adminUsersBody.innerHTML = '<tr><td colspan="8" class="empty">管理者アカウントはまだありません。</td></tr>';
      return;
    }

    el.adminUsersBody.innerHTML = state.users.map((row) => {
      const isCurrent = Number(Admin.currentUser.id || 0) === Number(row.id || 0);
      const activeLabel = Number(row.is_active || 0) ? '有効' : '無効';
      const lastLogin = row.last_login_at || '—';
      return `
        <tr>
          <td>${row.id ?? ''}</td>
          <td>${row.login_id ?? ''}${isCurrent ? ' <span class="mail-log-badge">自分</span>' : ''}</td>
          <td>${row.display_name ?? ''}</td>
          <td>${row.email || '—'}</td>
          <td>${roleLabel(row.role_key)}</td>
          <td>${activeLabel}</td>
          <td>${lastLogin}</td>
          <td><button type="button" class="secondary admin-user-edit-btn" data-admin-edit-id="${row.id}">編集</button></td>
        </tr>
      `;
    }).join('');

    el.adminUsersBody.querySelectorAll('[data-admin-edit-id]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = Number(btn.getAttribute('data-admin-edit-id') || '0');
        const row = state.users.find((item) => Number(item.id || 0) === id);
        if (row) fillForm(row);
      });
    });
  }

  function renderAudit() {
    if (!el.adminAuditBody) return;
    if (!state.auditRows.length) {
      el.adminAuditBody.innerHTML = '<tr><td colspan="6" class="empty">監査ログはまだありません。</td></tr>';
      return;
    }

    el.adminAuditBody.innerHTML = state.auditRows.map((row) => {
      const actor = [row.actor_display_name || '', row.actor_login_id ? `(${row.actor_login_id})` : ''].filter(Boolean).join(' ' ) || 'system';
      const target = [row.target_type || '', row.target_id ? `#${row.target_id}` : ''].filter(Boolean).join(' ') || '—';
      let summary = '—';
      if (row.summary && typeof row.summary === 'object') {
        try {
          summary = JSON.stringify(row.summary);
        } catch (_) {
          summary = '—';
        }
      }
      if (summary.length > 160) summary = summary.slice(0, 157) + '...';
      return `
        <tr>
          <td>${row.id ?? ''}</td>
          <td>${row.created_at ?? ''}</td>
          <td>${actor}</td>
          <td>${row.action ?? ''}</td>
          <td>${target}</td>
          <td>${summary}</td>
        </tr>
      `;
    }).join('');
  }

  async function loadUsers() {
    if (!Admin.hasPermission('admin.user.manage')) return;
    setUsersStatus('管理者アカウントを読み込み中です。', false);
    const url = `${Admin.apiPath}?action=admin_user_list`;
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    const payload = await response.json();
    if (!response.ok || payload.ok === false) throw new Error(payload.message || '管理者アカウント取得に失敗しました。');
    state.users = Array.isArray(payload.rows) ? payload.rows : [];
    renderUsers();
    if (el.adminUsersMetaText) el.adminUsersMetaText.textContent = `${state.users.length} 件の管理者アカウントがあります。`;
    setUsersStatus(payload.message || '管理者アカウントを取得しました。', false);
  }

  async function loadAuditLogs() {
    if (!Admin.hasPermission('admin.audit.view')) return;
    setAuditStatus('監査ログを読み込み中です。', false);
    const url = `${Admin.apiPath}?action=audit_log_list&limit=100`;
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    const payload = await response.json();
    if (!response.ok || payload.ok === false) throw new Error(payload.message || '監査ログ取得に失敗しました。');
    state.auditRows = Array.isArray(payload.rows) ? payload.rows : [];
    renderAudit();
    if (el.adminAuditMetaText) el.adminAuditMetaText.textContent = `${state.auditRows.length} 件の監査ログを表示しています。`;
    setAuditStatus(payload.message || '監査ログを取得しました。', false);
  }

  function collectFormPayload() {
    return {
      id: Number(el.adminUserId?.value || 0),
      login_id: String(el.adminLoginId?.value || '').trim(),
      display_name: String(el.adminDisplayName?.value || '').trim(),
      email: String(el.adminEmail?.value || '').trim(),
      role_key: String(el.adminRoleKey?.value || 'viewer'),
      is_active: String(el.adminIsActive?.value || '1') === '1',
      password: String(el.adminPassword?.value || ''),
    };
  }

  async function createUser() {
    const payload = collectFormPayload();
    const response = await fetch(`${Admin.apiPath}?action=admin_user_create`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ action: 'admin_user_create', ...payload }),
    });
    const json = await response.json();
    if (!response.ok || json.ok === false) throw new Error(json.message || '管理者アカウント作成に失敗しました。');
    resetForm();
    await loadUsers();
    await loadAuditLogs();
    setUsersStatus(json.message || '管理者アカウントを作成しました。', false);
  }

  async function updateUser() {
    const payload = collectFormPayload();
    if (!payload.id) throw new Error('更新対象のユーザーを選択してください。');
    const response = await fetch(`${Admin.apiPath}?action=admin_user_update`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ action: 'admin_user_update', ...payload }),
    });
    const json = await response.json();
    if (!response.ok || json.ok === false) throw new Error(json.message || '管理者アカウント更新に失敗しました。');
    state.editingUserId = payload.id;
    const selfUpdated = Number(Admin.currentUser.id || 0) === Number(payload.id || 0);
    if (json.current_user && typeof json.current_user === 'object') {
      Admin.currentUser = json.current_user;
      if (Array.isArray(json.current_user.permissions) && typeof Admin.setPermissions === 'function') {
        Admin.setPermissions(json.current_user.permissions);
      }
    }
    if (selfUpdated && (!json.current_user || !Array.isArray(json.current_user.permissions) || !json.current_user.permissions.includes('admin.user.manage'))) {
      window.location.reload();
      return;
    }
    await loadUsers();
    await loadAuditLogs();
    setUsersStatus(json.message || '管理者アカウントを更新しました。', false);
  }

  async function reloadAll() {
    await loadUsers();
    await loadAuditLogs();
    state.loaded = true;
  }

  function ensureLoaded() {
    if (!Admin.hasPermission('admin.user.manage')) return;
    if (!state.loaded) {
      reloadAll().catch((error) => {
        setUsersStatus(error.message || '管理設定の読み込みに失敗しました。', true);
        setAuditStatus(error.message || '監査ログの読み込みに失敗しました。', true);
      });
      return;
    }
    if (!state.users.length && !state.auditRows.length) {
      reloadAll().catch((error) => {
        setUsersStatus(error.message || '管理設定の再読込に失敗しました。', true);
        setAuditStatus(error.message || '監査ログの再読込に失敗しました。', true);
      });
    }
  }

  function bindEvents() {
    if (!Admin.hasPermission('admin.user.manage')) return;
    el.adminReloadBtn?.addEventListener('click', () => {
      reloadAll().catch((error) => {
        setUsersStatus(error.message || '再読込に失敗しました。', true);
        setAuditStatus(error.message || '再読込に失敗しました。', true);
      });
    });
    el.adminUserResetBtn?.addEventListener('click', resetForm);
    el.adminUserCreateBtn?.addEventListener('click', () => {
      createUser().catch((error) => setUsersStatus(error.message || '作成に失敗しました。', true));
    });
    el.adminUserUpdateBtn?.addEventListener('click', () => {
      updateUser().catch((error) => setUsersStatus(error.message || '更新に失敗しました。', true));
    });
  }

  Admin.adminView = {
    ...Admin.adminView,
    state,
    bindEvents,
    ensureLoaded,
    loadUsers,
    loadAuditLogs,
    resetForm,
  };
})();
