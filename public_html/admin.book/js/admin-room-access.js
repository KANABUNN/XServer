/* admin-room-access.js
 * - 借用部屋管理（SwitchBot キーパッド連携 + webhook 追跡）
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
    el.switchbotWebhookSyncBtn?.addEventListener('click', () => submitWebhookSync());
    el.switchbotWebhookToggleBtn?.addEventListener('click', () => submitWebhookToggle());
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
    if (el.switchbotWebhookSummary) el.switchbotWebhookSummary.textContent = '現在の登録状態を確認します。';
    if (el.switchbotSuggestedUrl) el.switchbotSuggestedUrl.textContent = '読込中…';
    if (el.switchbotWebhookList) el.switchbotWebhookList.innerHTML = '<div class="empty">読み込み中です。</div>';
    if (el.switchbotCommandList) el.switchbotCommandList.innerHTML = '<div class="empty">履歴を読み込み中です。</div>';
    u.setElementStatus(el.switchbotWebhookStatusText, 'SwitchBot Cloud 上の webhook 設定を確認します。');

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
      if (el.switchbotWebhookList) el.switchbotWebhookList.innerHTML = `<div class="empty">${u.escapeHtml(err.message || '読込エラー')}</div>`;
      if (el.switchbotCommandList) el.switchbotCommandList.innerHTML = `<div class="empty">${u.escapeHtml(err.message || '読込エラー')}</div>`;
      u.setElementStatus(el.switchbotStatusText, err.message || 'SwitchBot 状態の取得に失敗しました。', 'error');
      u.setElementStatus(el.switchbotWebhookStatusText, err.message || 'Webhook 状態の取得に失敗しました。', 'error');
    }
  }

  function renderStatus(data) {
    const el = Admin.el;
    const roomStates = data.rooms || {};
    const keypadCount = Number(data.available_keypads_count || 0);
    const configuredRooms = ROOM_CODES.filter((roomCode) => Boolean(roomStates[roomCode]?.ready)).length;
    const recentCommands = Array.isArray(data.recent_commands) ? data.recent_commands : [];

    el.switchbotMetaText.textContent = `利用可能キーパッド ${keypadCount} 台 / 設定済み ${configuredRooms} 室 / 履歴 ${recentCommands.length} 件`;
    el.switchbotRoomGrid.innerHTML = ROOM_CODES.map((roomCode) => renderRoomCard(roomCode, roomStates[roomCode] || {})).join('');

    renderWebhookPanel(data.webhook || {});
    renderCommandList(recentCommands);

    const nowLocal = formatLocalDateTimeInputValue(new Date());
    const afterOneDay = formatLocalDateTimeInputValue(new Date(Date.now() + 24 * 60 * 60 * 1000));
    ROOM_CODES.forEach((roomCode) => {
      const startEl = document.getElementById(`switchbotStartAt_${roomCode}`);
      const endEl = document.getElementById(`switchbotEndAt_${roomCode}`);
      if (startEl && !startEl.value) startEl.value = nowLocal;
      if (endEl && !endEl.value) endEl.value = afterOneDay;
    });
  }

  function renderWebhookPanel(webhook) {
    const el = Admin.el;
    const details = Array.isArray(webhook.details) ? webhook.details : [];
    const suggestedUrl = String(webhook.suggested_url || '公開 URL を生成できませんでした。');
    const registered = Boolean(webhook.current_url_registered);
    const enabled = webhook.current_url_enabled === null ? null : Boolean(webhook.current_url_enabled);
    const label = !registered ? '未登録' : enabled ? '有効' : '無効';

    if (el.switchbotSuggestedUrl) el.switchbotSuggestedUrl.textContent = suggestedUrl;
    if (el.switchbotWebhookSummary) {
      el.switchbotWebhookSummary.textContent = `現在URL: ${label} / Cloud登録 ${details.length} 件 / 検証トークン ${webhook.webhook_secret_configured ? '設定済み' : '未設定'}`;
    }

    if (el.switchbotWebhookToggleBtn) {
      el.switchbotWebhookToggleBtn.disabled = !registered && !enabled;
      el.switchbotWebhookToggleBtn.textContent = enabled ? '無効化' : '有効化';
    }

    if (webhook.query_error) {
      u.setElementStatus(el.switchbotWebhookStatusText, webhook.query_error, 'error');
    } else {
      const statusText = registered
        ? (enabled ? '現在の URL は SwitchBot Cloud 上で有効です。' : '現在の URL は登録済みですが無効です。')
        : '現在の URL はまだ SwitchBot Cloud に登録されていません。';
      u.setElementStatus(el.switchbotWebhookStatusText, statusText, enabled ? 'ok' : '');
    }

    if (!el.switchbotWebhookList) return;
    if (!details.length) {
      el.switchbotWebhookList.innerHTML = '<div class="empty">SwitchBot Cloud 側に webhook URL はまだ登録されていません。</div>';
      return;
    }

    el.switchbotWebhookList.innerHTML = details.map((detail) => renderWebhookItem(detail, suggestedUrl)).join('');
  }

  function renderWebhookItem(detail, suggestedUrl) {
    const isCurrent = String(detail.url || '') === suggestedUrl;
    const stateText = detail.enable === true ? '有効' : detail.enable === false ? '無効' : '不明';
    const badgeClass = detail.enable === true ? 'is-ok' : detail.enable === false ? 'is-error' : '';
    return `
      <article class="switchbot-webhook-item ${isCurrent ? 'is-current' : ''}">
        <div class="switchbot-room-header">
          <div>
            <div class="switchbot-urlbox-label">${isCurrent ? '現在URL' : '登録済みURL'}</div>
            <code>${u.escapeHtml(String(detail.url || ''))}</code>
          </div>
          <span class="switchbot-badge ${badgeClass}">${u.escapeHtml(stateText)}</span>
        </div>
        <div class="switchbot-webhook-meta">
          <span>deviceList: ${u.escapeHtml(String(detail.device_list || '')) || '—'}</span>
          <span>createTime: ${formatEpochMaybe(detail.create_time)}</span>
          <span>lastUpdate: ${formatEpochMaybe(detail.last_update_time)}</span>
        </div>
      </article>
    `;
  }

  function renderCommandList(commands) {
    const el = Admin.el;
    if (!el.switchbotCommandList) return;
    if (!commands.length) {
      el.switchbotCommandList.innerHTML = '<div class="empty">まだ履歴がありません。</div>';
      return;
    }

    el.switchbotCommandList.innerHTML = commands.map(renderCommandItem).join('');
  }

  function renderCommandItem(item) {
    const roomLabel = item.room_label || u.roomLabel(item.room_code || '') || '未特定';
    const status = String(item.status || 'accepted');
    const badge = commandBadge(status, item.result || '');
    const period = item.start_at && item.end_at ? `${u.escapeHtml(item.start_at)} 〜 ${u.escapeHtml(item.end_at)}` : '—';
    const commandId = item.command_id ? `<code>${u.escapeHtml(item.command_id)}</code>` : '未取得';
    const localRequestId = item.local_request_id ? `<code>${u.escapeHtml(item.local_request_id)}</code>` : '未採番';
    const dbId = Number(item.id || 0) > 0 ? String(item.id) : '—';
    const webhookText = item.webhook_received_at ? `${u.escapeHtml(item.webhook_received_at)} / ${u.escapeHtml(item.result || '') || '受信'}` : '未受信';

    return `
      <article class="switchbot-command-item">
        <div class="switchbot-command-main">
          <div class="switchbot-command-head">
            <strong>${u.escapeHtml(roomLabel)}</strong>
            <span class="switchbot-badge ${badge.className}">${u.escapeHtml(badge.label)}</span>
          </div>
          <div class="switchbot-command-meta">
            <span>DB ID: ${u.escapeHtml(dbId)}</span>
            <span>local_request_id: ${localRequestId}</span>
            <span>パスワード名: ${u.escapeHtml(item.passcode_name || '—')}</span>
            <span>要求時刻: ${u.escapeHtml(item.requested_at || '—')}</span>
            <span>有効期間: ${period}</span>
            <span>Webhook: ${webhookText}</span>
            <span>event: ${u.escapeHtml(item.event_name || '—')}</span>
          </div>
        </div>
        <div class="switchbot-command-side">
          ${commandId}
        </div>
      </article>
    `;
  }

  function commandBadge(status, result) {
    switch (status) {
      case 'success':
        return { label: '成功', className: 'is-ok' };
      case 'error':
        return { label: result ? `失敗:${result}` : '失敗', className: 'is-error' };
      case 'api_error':
        return { label: 'API失敗', className: 'is-error' };
      case 'queued':
        return { label: '送信準備', className: '' };
      case 'webhook_received':
        return { label: '受信済み', className: '' };
      case 'accepted':
      default:
        return { label: '受付済み', className: '' };
    }
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

  function formatEpochMaybe(value) {
    const n = Number(value);
    if (!Number.isFinite(n) || n <= 0) return '—';
    const ms = n > 1000000000000 ? n : n * 1000;
    const d = new Date(ms);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString('ja-JP');
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
        ? `${data.message || '受付しました。'} local_request_id: ${data.local_request_id || '—'} / commandId: ${data.command_id}`
        : `${data.message || '受付しました。'} local_request_id: ${data.local_request_id || '—'}`;
      u.setElementStatus(statusEl, detail, 'ok');
      const passwordEl = document.getElementById(`switchbotPassword_${roomCode}`);
      if (passwordEl) passwordEl.value = '';
      await loadStatus();
      const newStatusEl = document.getElementById(`switchbotRoomStatus_${roomCode}`);
      u.setElementStatus(newStatusEl, detail, 'ok');
    } catch (err) {
      u.setElementStatus(statusEl, err.message || 'パスワード作成に失敗しました。', 'error');
    }
  }

  async function submitWebhookSync() {
    await runWebhookAction('switchbot_webhook_sync', {}, 'Webhook を更新しています…');
  }

  async function submitWebhookToggle() {
    const status = Admin.switchbot.state.status || {};
    const webhook = status.webhook || {};
    const enable = !(webhook.current_url_registered && webhook.current_url_enabled === true);
    await runWebhookAction('switchbot_webhook_toggle', { enable }, enable ? 'Webhook を有効化しています…' : 'Webhook を無効化しています…');
  }

  async function runWebhookAction(action, payload, workingMessage) {
    const el = Admin.el;
    try {
      u.setElementStatus(el.switchbotWebhookStatusText, workingMessage);
      const res = await fetch(`${Admin.apiPath}?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload || {}),
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || 'Webhook 設定に失敗しました。');
      }
      await loadStatus();
      u.setElementStatus(el.switchbotWebhookStatusText, data.message || 'Webhook を更新しました。', 'ok');
    } catch (err) {
      u.setElementStatus(el.switchbotWebhookStatusText, err.message || 'Webhook 設定に失敗しました。', 'error');
    }
  }

  Admin.switchbot = Object.assign(Admin.switchbot || {}, {
    ensureLoaded,
    bindEvents,
    loadStatus,
  });
})();
