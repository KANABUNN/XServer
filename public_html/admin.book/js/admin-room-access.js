/* admin-room-access.js
 * - 借用部屋管理（SwitchBot キーパッド連携）
 */
(function () {
  'use strict';

  const Admin = (window.Admin = window.Admin || {});
  const u = Admin.utils;

  const ROOM_CODES = ['tamoku', 'orange'];

  function ensureLoaded() {
    const state = Admin.switchbot.state;
    if (state.loaded) return;
    state.loaded = true;
    loadStatus();
  }

  function bindEvents() {
    const el = Admin.el;
    el.switchbotReloadBtn?.addEventListener('click', () => loadStatus());
    el.switchbotRoomGrid?.addEventListener('click', (event) => {
      const button = event.target.closest('button[data-action="switchbot-create"]');
      if (!button) return;
      submitCreateKey(button.dataset.roomCode || '');
    });
  }

  async function loadStatus() {
    const el = Admin.el;
    const state = Admin.switchbot.state;
    if (!el.switchbotRoomGrid) return;

    u.setElementStatus(el.switchbotStatusText, 'SwitchBot 情報を読み込み中です…');
    el.switchbotMetaText.textContent = 'SwitchBot 設定を確認しています。';
    el.switchbotRoomGrid.innerHTML = ROOM_CODES.map((roomCode) => renderLoadingCard(roomCode)).join('');

    try {
      const url = new URL(Admin.apiPath, window.location.href);
      url.searchParams.set('action', 'switchbot_status');
      const res = await fetch(url, { cache: 'no-store' });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || 'SwitchBot 状態の取得に失敗しました。');
      }

      state.status = data;
      renderStatus(data);
      u.setElementStatus(el.switchbotStatusText, data.message || '読込完了', data.configured ? 'ok' : '');
    } catch (err) {
      state.status = null;
      el.switchbotMetaText.textContent = 'SwitchBot 状態の取得に失敗しました。';
      el.switchbotRoomGrid.innerHTML = ROOM_CODES.map((roomCode) => renderErrorCard(roomCode, err.message || '読込エラー')).join('');
      u.setElementStatus(el.switchbotStatusText, err.message || 'SwitchBot 状態の取得に失敗しました。', 'error');
    }
  }

  function renderStatus(data) {
    const el = Admin.el;
    const roomStates = data.rooms || {};
    const keypadCount = Number(data.available_keypads_count || 0);
    const configuredRooms = ROOM_CODES.filter((roomCode) => Boolean(roomStates[roomCode]?.ready)).length;

    el.switchbotMetaText.textContent = `利用可能キーパッド ${keypadCount} 台 / 設定済み ${configuredRooms} 室`;
    el.switchbotRoomGrid.innerHTML = ROOM_CODES.map((roomCode) => renderRoomCard(roomCode, roomStates[roomCode] || {})).join('');

    const nowLocal = formatLocalDateTimeInputValue(new Date());
    const afterOneDay = formatLocalDateTimeInputValue(new Date(Date.now() + 24 * 60 * 60 * 1000));
    ROOM_CODES.forEach((roomCode) => {
      const startEl = document.getElementById(`switchbotStartAt_${roomCode}`);
      const endEl = document.getElementById(`switchbotEndAt_${roomCode}`);
      if (startEl && !startEl.value) startEl.value = nowLocal;
      if (endEl && !endEl.value) endEl.value = afterOneDay;
    });
  }

  function renderLoadingCard(roomCode) {
    return renderRoomCard(roomCode, {
      room_code: roomCode,
      room_label: u.roomLabel(roomCode),
      configured: false,
      ready: false,
      note: '読込中です…',
    });
  }

  function renderErrorCard(roomCode, message) {
    return renderRoomCard(roomCode, {
      room_code: roomCode,
      room_label: u.roomLabel(roomCode),
      configured: false,
      ready: false,
      note: message,
    });
  }

  function renderRoomCard(roomCode, roomState) {
    const roomLabel = roomState.room_label || u.roomLabel(roomCode);
    const deviceName = roomState.device_name ? u.escapeHtml(roomState.device_name) : '未設定';
    const deviceType = roomState.device_type ? u.escapeHtml(roomState.device_type) : '—';
    const deviceId = roomState.device_id ? `<code>${u.escapeHtml(roomState.device_id)}</code>` : '—';
    const note = roomState.note ? `<p class="switchbot-room-note">${u.escapeHtml(roomState.note)}</p>` : '';
    const statusTone = roomState.ready ? 'ok' : roomState.configured ? '' : 'error';
    const disabled = roomState.ready ? '' : 'disabled';
    const statusLabel = roomState.ready ? '準備完了' : roomState.configured ? '確認待ち' : '未設定';

    return `
      <article class="switchbot-room-card" data-room-code="${u.escapeHtml(roomCode)}">
        <div class="switchbot-room-header">
          <div>
            <h2>${u.escapeHtml(roomLabel)}</h2>
            <p class="dialog-sub">${u.escapeHtml(statusLabel)}</p>
          </div>
          <span class="switchbot-badge ${statusTone === 'ok' ? 'is-ok' : statusTone === 'error' ? 'is-error' : ''}">${u.escapeHtml(statusLabel)}</span>
        </div>

        <dl class="switchbot-device-meta">
          <div>
            <dt>デバイス名</dt>
            <dd>${deviceName}</dd>
          </div>
          <div>
            <dt>デバイスタイプ</dt>
            <dd>${deviceType}</dd>
          </div>
          <div>
            <dt>deviceId</dt>
            <dd>${deviceId}</dd>
          </div>
        </dl>

        ${note}

        <div class="switchbot-form-grid">
          <label class="field field-wide">
            <span>パスワード名 <strong style="color: var(--danger)">*</strong></span>
            <input type="text" id="switchbotName_${u.escapeHtml(roomCode)}" maxlength="100" placeholder="例：FITSC 2026-03-22 午後利用" ${disabled}>
          </label>

          <label class="field">
            <span>パスワード（6〜12桁数字） <strong style="color: var(--danger)">*</strong></span>
            <input type="password" id="switchbotPassword_${u.escapeHtml(roomCode)}" inputmode="numeric" autocomplete="new-password" pattern="\d{6,12}" maxlength="12" placeholder="例：12345678" ${disabled}>
          </label>

          <label class="field">
            <span>開始日時 <strong style="color: var(--danger)">*</strong></span>
            <input type="datetime-local" id="switchbotStartAt_${u.escapeHtml(roomCode)}" ${disabled}>
          </label>

          <label class="field">
            <span>終了日時 <strong style="color: var(--danger)">*</strong></span>
            <input type="datetime-local" id="switchbotEndAt_${u.escapeHtml(roomCode)}" ${disabled}>
          </label>
        </div>

        <p class="status" id="switchbotRoomStatus_${u.escapeHtml(roomCode)}"></p>

        <div class="dialog-actions" style="justify-content:flex-end; margin-top:12px;">
          <button type="button" data-action="switchbot-create" data-room-code="${u.escapeHtml(roomCode)}" ${disabled}>期間内に有効のパスワードを追加</button>
        </div>
      </article>
    `;
  }

  function formatLocalDateTimeInputValue(date) {
    const d = new Date(date.getTime() - (date.getTimezoneOffset() * 60000));
    return d.toISOString().slice(0, 16);
  }

  function readForm(roomCode) {
    const roomLabel = u.roomLabel(roomCode);
    const name = String(document.getElementById(`switchbotName_${roomCode}`)?.value || '').trim();
    const password = String(document.getElementById(`switchbotPassword_${roomCode}`)?.value || '').trim();
    const startAt = String(document.getElementById(`switchbotStartAt_${roomCode}`)?.value || '').trim();
    const endAt = String(document.getElementById(`switchbotEndAt_${roomCode}`)?.value || '').trim();

    if (!name) throw new Error(`${roomLabel}: パスワード名を入力してください。`);
    if (!/^\d{6,12}$/.test(password)) throw new Error(`${roomLabel}: パスワードは 6〜12 桁の数字で入力してください。`);
    if (!startAt) throw new Error(`${roomLabel}: 開始日時を入力してください。`);
    if (!endAt) throw new Error(`${roomLabel}: 終了日時を入力してください。`);

    const startDate = new Date(startAt);
    const endDate = new Date(endAt);
    if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) {
      throw new Error(`${roomLabel}: 日時の形式が不正です。`);
    }
    if (endDate.getTime() <= startDate.getTime()) {
      throw new Error(`${roomLabel}: 終了日時は開始日時より後にしてください。`);
    }

    return {
      room_code: roomCode,
      name,
      password,
      start_at: startAt,
      end_at: endAt,
    };
  }

  async function submitCreateKey(roomCode) {
    const statusEl = document.getElementById(`switchbotRoomStatus_${roomCode}`);
    try {
      const payload = readForm(roomCode);
      u.setElementStatus(statusEl, 'SwitchBot へ送信しています…');

      const res = await fetch(`${Admin.apiPath}?action=switchbot_create_key`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || 'パスワード作成に失敗しました。');
      }

      const detail = data.command_id
        ? `${data.message || '受付しました。'} commandId: ${data.command_id}`
        : (data.message || '受付しました。');
      u.setElementStatus(statusEl, detail, 'ok');
      const passwordEl = document.getElementById(`switchbotPassword_${roomCode}`);
      if (passwordEl) passwordEl.value = '';
    } catch (err) {
      u.setElementStatus(statusEl, err.message || 'パスワード作成に失敗しました。', 'error');
    }
  }

  Admin.switchbot = Object.assign(Admin.switchbot || {}, {
    ensureLoaded,
    bindEvents,
    loadStatus,
  });
})();
