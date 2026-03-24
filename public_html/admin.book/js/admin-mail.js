/* admin-mail.js
 * - 予約通知メール（確定予約 + 発行済みパスコードの送信）
 */
(function () {
  'use strict';

  const Admin = (window.Admin = window.Admin || {});
  const u = Admin.utils;

  function ensureLoaded() {
    const state = Admin.mail.state;
    if (!state.initialized) {
      bindEvents();
      state.initialized = true;
    }
    if (state.loaded) return;
    state.loaded = true;
    loadOptions();
  }

  function bindEvents() {
    const el = Admin.el;
    el.reservationMailReloadBtn?.addEventListener('click', () => loadOptions());
    el.reservationMailReservationSelect?.addEventListener('change', () => {
      renderReservationSummary();
      renderPasscodeOptions();
      renderPasscodeSummary();
    });
    el.reservationMailPasscodeSelect?.addEventListener('change', () => {
      renderPasscodeSummary();
    });
    el.reservationMailSendBtn?.addEventListener('click', () => sendMail());
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
      renderPasscodeSummary();

      const extra = [];
      if (data.passcode_source_note) {
        extra.push(String(data.passcode_source_note));
      }
      el.reservationMailMetaText.textContent = `確定予約 ${state.reservations.length} 件 / パスコード ${state.passcodes.length} 件${extra.length ? ' / ' + extra.join(' / ') : ''}`;
      u.setElementStatus(el.reservationMailStatusText, data.message || '');
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

  function getSelectedReservation() {
    const token = String(Admin.el.reservationMailReservationSelect?.value || '');
    if (!token) return null;
    return Admin.mail.state.reservations.find((item) => String(item.selection_token || '') === token) || null;
  }

  function getSelectedPasscode() {
    const token = String(Admin.el.reservationMailPasscodeSelect?.value || '');
    if (!token) return null;
    return Admin.mail.state.passcodes.find((item) => String(item.selection_token || '') === token) || null;
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
    const current = String(el.reservationMailReservationSelect?.value || '');
    const options = ['<option value="">選択してください</option>']
      .concat(Admin.mail.state.reservations.map((item) => (
        `<option value="${u.escapeHtml(String(item.selection_token || ''))}">${u.escapeHtml(buildReservationLabel(item))}</option>`
      )));
    if (el.reservationMailReservationSelect) {
      el.reservationMailReservationSelect.innerHTML = options.join('');
      if ([...el.reservationMailReservationSelect.options].some((opt) => opt.value === current)) {
        el.reservationMailReservationSelect.value = current;
      }
    }
  }

  function getVisiblePasscodes() {
    const reservation = getSelectedReservation();
    const all = Admin.mail.state.passcodes.slice();
    if (!reservation) return all;

    const roomCode = String(reservation.room_code || '').trim();
    const sameRoom = all.filter((item) => String(item.room_code || '').trim() === roomCode);
    return sameRoom.length ? sameRoom : all;
  }

  function renderPasscodeOptions() {
    const el = Admin.el;
    const current = String(el.reservationMailPasscodeSelect?.value || '');
    const visible = getVisiblePasscodes();
    const options = ['<option value="">選択してください</option>']
      .concat(visible.map((item) => (
        `<option value="${u.escapeHtml(String(item.selection_token || ''))}">${u.escapeHtml(buildPasscodeLabel(item))}</option>`
      )));

    if (el.reservationMailPasscodeSelect) {
      el.reservationMailPasscodeSelect.innerHTML = options.join('');
      if ([...el.reservationMailPasscodeSelect.options].some((opt) => opt.value === current)) {
        el.reservationMailPasscodeSelect.value = current;
      }
    }
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
    const reservation = getSelectedReservation();
    if (!el.reservationMailReservationSummary) return;

    if (!reservation) {
      el.reservationMailReservationSummary.innerHTML = '<div class="detail-empty">予約を選択してください。</div>';
      return;
    }

    el.reservationMailReservationSummary.innerHTML = [
      summaryItem('使用日', reservation.use_date || '—'),
      summaryItem('部屋', u.roomLabel(reservation.room_code || '')),
      summaryItem('団体名', reservation.organization_name || '—'),
      summaryItem('利用時間', reservation.usage_time || '未登録'),
      summaryItem('人数', reservation.people_count ? `${reservation.people_count}人` : '未登録'),
    ].join('');
  }

  function renderPasscodeSummary() {
    const el = Admin.el;
    const passcode = getSelectedPasscode();
    if (!el.reservationMailPasscodeSummary) return;

    if (!passcode) {
      el.reservationMailPasscodeSummary.innerHTML = '<div class="detail-empty">パスコードを選択してください。</div>';
      return;
    }

    const period = passcode.start_at && passcode.end_at
      ? `${passcode.start_at} 〜 ${passcode.end_at}`
      : '未登録';

    el.reservationMailPasscodeSummary.innerHTML = [
      summaryItem('部屋', u.roomLabel(passcode.room_code || '')),
      summaryItem('パスワード名', passcode.passcode_name || '—'),
      summaryItem('パスワード', `<code>${u.escapeHtml(String(passcode.passcode || ''))}</code>`, true),
      summaryItem('有効期間', period),
      summaryItem('状態', passcode.status_label || passcode.status || '—'),
    ].join('');
  }

  async function sendMail() {
    const el = Admin.el;
    const to = String(el.reservationMailTo?.value || '').trim();
    const reservationToken = String(el.reservationMailReservationSelect?.value || '');
    const passcodeToken = String(el.reservationMailPasscodeSelect?.value || '');

    if (!to || !reservationToken || !passcodeToken) {
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
          reservation_token: reservationToken,
          passcode_token: passcodeToken,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || 'メール送信に失敗しました。');
      }

      u.setElementStatus(el.reservationMailStatusText, data.message || 'メールを送信しました。', 'ok');
    } catch (err) {
      u.setElementStatus(el.reservationMailStatusText, err.message || 'メール送信に失敗しました。', 'error');
    } finally {
      el.reservationMailSendBtn.disabled = false;
    }
  }

  Admin.mail = Object.assign(Admin.mail || {}, {
    ensureLoaded,
    loadOptions,
  });
})();
