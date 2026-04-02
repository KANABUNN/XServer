/* admin-mail.js
 * - 予約通知メール（複数の確定予約 + 発行済みパスコードの一括送信）
 * - 送信履歴表示 / CSV 出力
 */
(function () {
  'use strict';

  const Admin = (window.Admin = window.Admin || {});
  const u = Admin.utils;

  function can(permission) {
    return Admin.hasPermission(permission);
  }

  function applyPermissionUi() {
    const el = Admin.el;
    const canCompose = can('mail.form.view');
    const canSend = can('mail.send');
    const canExport = can('mail.export');

    if (el.mailComposeArea) el.mailComposeArea.hidden = !canCompose;
    if (el.mailViewerNotice) el.mailViewerNotice.hidden = canCompose;
    if (el.reservationMailSendBtn) {
      el.reservationMailSendBtn.hidden = !canSend;
      el.reservationMailSendBtn.disabled = !canSend;
    }
    if (el.reservationMailCsvExportBtn) el.reservationMailCsvExportBtn.hidden = !canExport;
  }

  function ensureLoaded() {
    const state = Admin.mail.state;
    applyPermissionUi();
    if (!state.initialized) {
      bindEvents();
      state.initialized = true;
    }
    if (state.loaded) return;
    state.loaded = true;
    loadAll();
  }

  async function loadAll() {
    if (can('mail.form.view')) {
      await loadOptions();
    } else {
      resetComposeForViewer();
    }
    await loadHistory();
  }

  function resetComposeForViewer() {
    const el = Admin.el;
    const state = Admin.mail.state;
    state.reservations = [];
    state.passcodes = [];
    renderReservationOptions();
    renderReservationSummary();
    renderPasscodeOptions();
    renderPasscodeSummary();
    if (el.reservationMailMetaText) el.reservationMailMetaText.textContent = '閲覧者ロールでは送信候補・パスコードは表示されません。';
    u.setElementStatus(el.reservationMailStatusText, '送信履歴のみ閲覧できます。');
  }

  function bindEvents() {
    const el = Admin.el;
    el.reservationMailReloadBtn?.addEventListener('click', () => loadAll());
    el.reservationMailCsvExportBtn?.addEventListener('click', () => exportHistoryCsv());
    el.reservationMailAppendBeneBtn?.addEventListener('click', () => appendBeneDomain());
    el.reservationMailReservationSelect?.addEventListener('change', () => {
      renderReservationSummary();
      renderPasscodeOptions();
      autoSelectMatchingPasscodes();
      renderPasscodeSummary();
    });
    el.reservationMailPasscodeSelect?.addEventListener('change', () => {
      renderPasscodeSummary();
    });
    el.reservationMailSendBtn?.addEventListener('click', () => sendMail());
  }

  function appendBeneDomain() {
    const el = Admin.el;
    const domain = '@bene.fit.ac.jp';
    const input = el.reservationMailTo;
    if (!input) return;

    const current = String(input.value || '').trim();
    if (!current) {
      input.value = domain;
      input.focus();
      input.setSelectionRange(0, 0);
      u.setElementStatus(el.reservationMailStatusText, '宛先欄に @bene.fit.ac.jp を入力しました。学内アカウント名を先頭に入力してください。');
      return;
    }

    if (current.toLowerCase().endsWith(domain)) {
      u.setElementStatus(el.reservationMailStatusText, '宛先欄には既に @bene.fit.ac.jp が含まれています。');
      input.focus();
      return;
    }

    if (current.includes('@')) {
      u.setElementStatus(el.reservationMailStatusText, '別のドメインが既に入力されているため、自動追加は行いませんでした。', 'error');
      input.focus();
      return;
    }

    input.value = current + domain;
    input.focus();
    u.setElementStatus(el.reservationMailStatusText, '@bene.fit.ac.jp を追加しました。');
  }

  async function loadOptions() {
    const el = Admin.el;
    const state = Admin.mail.state;
    const url = new URL(Admin.apiPath, window.location.href);
    url.searchParams.set('action', 'mail_form_options');

    u.setElementStatus(el.reservationMailStatusText, '候補を読み込んでいます…');

    try {
      const res = await fetch(url, { cache: 'no-store' });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || 'メール送信用候補の取得に失敗しました。');
      }

      state.reservations = Array.isArray(data.reservations) ? data.reservations : [];
      state.passcodes = Array.isArray(data.passcodes) ? data.passcodes : [];

      renderReservationOptions();
      renderReservationSummary();
      renderPasscodeOptions();
      autoSelectMatchingPasscodes();
      renderPasscodeSummary();

      const extra = [];
      if (data.passcode_source_note) {
        extra.push(String(data.passcode_source_note));
      }
      el.reservationMailMetaText.textContent = `確定予約 ${state.reservations.length} 件 / パスコード ${state.passcodes.length} 件${extra.length ? ' / ' + extra.join(' / ') : ''}`;
      u.setElementStatus(el.reservationMailStatusText, data.message || '', 'ok');
    } catch (err) {
      state.reservations = [];
      state.passcodes = [];
      renderReservationOptions();
      renderReservationSummary();
      renderPasscodeOptions();
      renderPasscodeSummary();
      el.reservationMailMetaText.textContent = '候補を読み込めませんでした。';
      u.setElementStatus(el.reservationMailStatusText, err.message || '候補の取得に失敗しました。', 'error');
    }
  }

  async function loadHistory() {
    const el = Admin.el;
    const state = Admin.mail.state;
    const url = new URL(Admin.apiPath, window.location.href);
    url.searchParams.set('action', 'mail_history_list');
    url.searchParams.set('limit', '100');

    u.setElementStatus(el.reservationMailHistoryStatusText, '送信履歴を読み込んでいます…');

    try {
      const res = await fetch(url, { cache: 'no-store' });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || '送信履歴の取得に失敗しました。');
      }

      state.historyRows = Array.isArray(data.rows) ? data.rows : [];
      renderHistory();
      el.reservationMailHistoryMetaText.textContent = data.available
        ? `最新 ${state.historyRows.length} 件を表示しています。`
        : '送信履歴テーブルが未作成です。';
      u.setElementStatus(el.reservationMailHistoryStatusText, data.message || '', data.available ? 'ok' : '');
    } catch (err) {
      state.historyRows = [];
      renderHistory();
      el.reservationMailHistoryMetaText.textContent = '送信履歴を読み込めませんでした。';
      u.setElementStatus(el.reservationMailHistoryStatusText, err.message || '送信履歴の取得に失敗しました。', 'error');
    }
  }

  function exportHistoryCsv() {
    if (!can('mail.export')) {
      u.setElementStatus(Admin.el.reservationMailHistoryStatusText, 'CSV 出力権限がありません。', 'error');
      return;
    }
    const url = new URL(Admin.apiPath, window.location.href);
    url.searchParams.set('action', 'export_csv');
    url.searchParams.set('type', 'mail_history');
    window.location.href = url.toString();
  }

  function getSelectedValues(selectEl) {
    if (!selectEl) return [];
    return Array.from(selectEl.selectedOptions || []).map((opt) => String(opt.value || '')).filter(Boolean);
  }

  function setSelectedValues(selectEl, values) {
    if (!selectEl) return;
    const wanted = new Set((values || []).map((v) => String(v || '')).filter(Boolean));
    Array.from(selectEl.options || []).forEach((opt) => {
      opt.selected = wanted.has(String(opt.value || ''));
    });
  }

  function getSelectedReservations() {
    const selected = new Set(getSelectedValues(Admin.el.reservationMailReservationSelect));
    return Admin.mail.state.reservations.filter((item) => selected.has(String(item.selection_token || '')));
  }

  function getSelectedPasscodes() {
    const selected = new Set(getSelectedValues(Admin.el.reservationMailPasscodeSelect));
    return Admin.mail.state.passcodes.filter((item) => selected.has(String(item.selection_token || '')));
  }

  function buildReservationLabel(item) {
    const parts = [
      String(item.use_date || '日付未設定'),
      u.roomLabel(item.room_code || ''),
      String(item.organization_name || '団体名未設定'),
    ];
    const extra = [];
    if (String(item.usage_time || '').trim() !== '') extra.push(`利用時間 ${item.usage_time}`);
    if (String(item.people_count || '').trim() !== '') extra.push(`人数 ${item.people_count}人`);
    if (extra.length) parts.push(`(${extra.join(' / ')})`);
    return parts.join(' / ');
  }

  function buildPasscodeLabel(item) {
    const parts = [
      u.roomLabel(item.room_code || ''),
      String(item.passcode_name || '名称未設定'),
      String(item.passcode || '').trim() !== '' ? `#${item.passcode}` : 'パスワード未保存',
    ];
    const period = String(item.start_at || '').trim() && String(item.end_at || '').trim()
      ? `${item.start_at} 〜 ${item.end_at}`
      : '';
    if (period) parts.push(`(${period})`);
    return parts.join(' / ');
  }

  function renderReservationOptions() {
    const el = Admin.el;
    const current = getSelectedValues(el.reservationMailReservationSelect);
    const options = Admin.mail.state.reservations.map((item) => (
      `<option value="${u.escapeHtml(String(item.selection_token || ''))}">${u.escapeHtml(buildReservationLabel(item))}</option>`
    ));
    if (el.reservationMailReservationSelect) {
      el.reservationMailReservationSelect.innerHTML = options.join('');
      setSelectedValues(el.reservationMailReservationSelect, current);
    }
  }

  function parseDateOnly(value) {
    const raw = String(value || '').trim();
    if (!raw) return null;
    const m = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return null;
    return `${m[1]}-${m[2]}-${m[3]}`;
  }

  function dateRangeDays(start, end) {
    const startKey = parseDateOnly(start);
    const endKey = parseDateOnly(end);
    if (!startKey || !endKey) return Number.MAX_SAFE_INTEGER;
    const startMs = Date.parse(`${startKey}T00:00:00Z`);
    const endMs = Date.parse(`${endKey}T00:00:00Z`);
    if (Number.isNaN(startMs) || Number.isNaN(endMs)) return Number.MAX_SAFE_INTEGER;
    return Math.max(0, Math.round((endMs - startMs) / 86400000));
  }

  function isPasscodeMatchingReservation(passcode, reservation) {
    const roomCode = String(reservation.room_code || '').trim();
    if (roomCode && String(passcode.room_code || '').trim() !== roomCode) return false;

    const useDate = parseDateOnly(reservation.use_date);
    if (!useDate) return false;

    const startDate = parseDateOnly(passcode.start_at);
    const endDate = parseDateOnly(passcode.end_at);
    if (!startDate || !endDate) return false;

    return startDate <= useDate && useDate <= endDate;
  }

  function pickBestPasscode(passcodes, reservation) {
    const candidates = (passcodes || []).filter((item) => isPasscodeMatchingReservation(item, reservation));
    if (!candidates.length) return null;
    return candidates.sort((a, b) => {
      const rangeDiff = dateRangeDays(a.start_at, a.end_at) - dateRangeDays(b.start_at, b.end_at);
      if (rangeDiff !== 0) return rangeDiff;
      const updatedDiff = String(b.updated_at || '').localeCompare(String(a.updated_at || ''));
      if (updatedDiff !== 0) return updatedDiff;
      return Number(b.id || 0) - Number(a.id || 0);
    })[0];
  }

  function getVisiblePasscodes() {
    const reservations = getSelectedReservations();
    const all = Admin.mail.state.passcodes.slice();
    if (!reservations.length) return all;

    const roomCodes = new Set(reservations.map((item) => String(item.room_code || '').trim()).filter(Boolean));
    const sameRooms = all.filter((item) => roomCodes.has(String(item.room_code || '').trim()));
    return sameRooms.length ? sameRooms : all;
  }

  function renderPasscodeOptions() {
    const el = Admin.el;
    const current = getSelectedValues(el.reservationMailPasscodeSelect);
    const visible = getVisiblePasscodes();
    const options = visible.map((item) => (
      `<option value="${u.escapeHtml(String(item.selection_token || ''))}">${u.escapeHtml(buildPasscodeLabel(item))}</option>`
    ));

    if (el.reservationMailPasscodeSelect) {
      el.reservationMailPasscodeSelect.innerHTML = options.join('');
      setSelectedValues(el.reservationMailPasscodeSelect, current);
    }
  }

  function autoSelectMatchingPasscodes() {
    const el = Admin.el;
    const reservations = getSelectedReservations();
    if (!el.reservationMailPasscodeSelect) return;
    if (!reservations.length) {
      setSelectedValues(el.reservationMailPasscodeSelect, []);
      return;
    }

    const visiblePasscodes = getVisiblePasscodes();
    const tokens = [];
    reservations.forEach((reservation) => {
      const match = pickBestPasscode(visiblePasscodes, reservation);
      if (match?.selection_token) tokens.push(String(match.selection_token));
    });
    setSelectedValues(el.reservationMailPasscodeSelect, Array.from(new Set(tokens)));
  }

  function summaryItem(label, value, allowHtml = false) {
    return `
      <div class="mail-summary-item">
        <div class="mail-summary-label">${u.escapeHtml(label)}</div>
        <div class="mail-summary-value">${allowHtml ? String(value) : u.escapeHtml(String(value || '—'))}</div>
      </div>
    `;
  }

  function renderReservationSummary() {
    const el = Admin.el;
    const reservations = getSelectedReservations();
    if (!el.reservationMailReservationSummary) return;

    if (!reservations.length) {
      el.reservationMailReservationSummary.innerHTML = '<div class="detail-empty">予約を選択してください。</div>';
      return;
    }

    const body = reservations.map((reservation) => `
      <div class="mail-summary-group">
        <div class="mail-summary-badge">${u.escapeHtml(String(reservation.use_date || '日付未設定'))}</div>
        ${summaryItem('部屋', u.roomLabel(reservation.room_code || ''))}
        ${summaryItem('団体名', reservation.organization_name || '—')}
        ${summaryItem('利用時間', reservation.usage_time || '未登録')}
        ${summaryItem('人数', reservation.people_count ? `${reservation.people_count}人` : '未登録')}
      </div>
    `).join('');

    el.reservationMailReservationSummary.innerHTML = `
      <div class="mail-summary-badge">${u.escapeHtml(String(reservations.length))} 件選択中</div>
      ${body}
    `;
  }

  function renderPasscodeSummary() {
    const el = Admin.el;
    const passcodes = getSelectedPasscodes();
    if (!el.reservationMailPasscodeSummary) return;

    if (!passcodes.length) {
      el.reservationMailPasscodeSummary.innerHTML = '<div class="detail-empty">パスコードを選択してください。</div>';
      return;
    }

    const body = passcodes.map((passcode) => {
      const period = passcode.start_at && passcode.end_at
        ? `${passcode.start_at} 〜 ${passcode.end_at}`
        : '未登録';
      return `
        <div class="mail-summary-group">
          <div class="mail-summary-badge">${u.escapeHtml(u.roomLabel(passcode.room_code || ''))}</div>
          ${summaryItem('パスワード名', passcode.passcode_name || '—')}
          ${summaryItem('パスワード', `<code>${u.escapeHtml(String(passcode.passcode || ''))}</code>`, true)}
          ${summaryItem('有効期間', period)}
          ${summaryItem('状態', passcode.status_label || passcode.status || '—')}
        </div>
      `;
    }).join('');

    el.reservationMailPasscodeSummary.innerHTML = `
      <div class="mail-summary-badge">${u.escapeHtml(String(passcodes.length))} 件選択中</div>
      ${body}
    `;
  }

  function renderHistory() {
    const el = Admin.el;
    const rows = Admin.mail.state.historyRows || [];
    if (!el.reservationMailHistoryBody) return;

    if (!rows.length) {
      el.reservationMailHistoryBody.innerHTML = '<tr><td colspan="10" class="empty">送信履歴はまだありません。</td></tr>';
      return;
    }

    el.reservationMailHistoryBody.innerHTML = rows.map((row) => {
      const statusClass = String(row.send_status || '').trim() === 'failed' ? 'is-failed' : 'is-sent';
      const hasPassword = String(row.passcode_name || '').trim() !== '' || String(row.passcode || '').trim() !== '';
      const passwordLabel = hasPassword
        ? `${String(row.passcode_name || '名称未設定')} / #${String(row.passcode || '')}`
        : '—';
      return `
        <tr>
          <td>${u.escapeHtml(String(row.id || ''))}</td>
          <td>${u.escapeHtml(String(row.sent_at || ''))}</td>
          <td><span class="mail-log-badge ${statusClass}">${u.escapeHtml(String(row.send_status_label || row.send_status || '—'))}</span></td>
          <td>${u.escapeHtml(String(row.to_email || ''))}</td>
          <td>${u.escapeHtml(String(row.mail_subject || ''))}</td>
          <td>${u.escapeHtml(String(row.room_label || u.roomLabel(row.room_code || '')))}</td>
          <td>${u.escapeHtml(String(row.use_date || ''))}</td>
          <td>${u.escapeHtml(String(row.organization_name || ''))}</td>
          <td><code>${u.escapeHtml(passwordLabel)}</code></td>
          <td>${u.escapeHtml(String(row.error_message || ''))}</td>
        </tr>
      `;
    }).join('');
  }

  async function sendMail() {
    const el = Admin.el;
    if (!can('mail.send')) {
      u.setElementStatus(el.reservationMailStatusText, 'メール送信権限がありません。', 'error');
      return;
    }
    const to = String(el.reservationMailTo?.value || '').trim();
    const reservationTokens = getSelectedValues(el.reservationMailReservationSelect);
    const passcodeTokens = getSelectedValues(el.reservationMailPasscodeSelect);

    if (!to || !reservationTokens.length || !passcodeTokens.length) {
      u.setElementStatus(el.reservationMailStatusText, '宛先・確定済み予約・発行済みパスコードをすべて選択してください。', 'error');
      return;
    }

    el.reservationMailSendBtn.disabled = true;
    u.setElementStatus(el.reservationMailStatusText, 'メールを送信しています…');

    try {
      const res = await fetch(`${Admin.apiPath}?action=reservation_mail_send`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          to,
          reservation_tokens: reservationTokens,
          passcode_tokens: passcodeTokens,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || 'メール送信に失敗しました。');
      }

      u.setElementStatus(el.reservationMailStatusText, data.message || 'メールを送信しました。', 'ok');
      await loadHistory();
    } catch (err) {
      u.setElementStatus(el.reservationMailStatusText, err.message || 'メール送信に失敗しました。', 'error');
      await loadHistory();
    } finally {
      el.reservationMailSendBtn.disabled = false;
    }
  }

  Admin.mail = Object.assign(Admin.mail || {}, {
    ensureLoaded,
    loadOptions,
    loadHistory,
    loadAll,
  });
})();
