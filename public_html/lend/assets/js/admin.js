function formatRange(start, end) {
  const fmt = (value) => String(value || '').replace('T', ' ').slice(0, 16);
  return `${fmt(start)} 〜 ${fmt(end)}`;
}

function setCount(key, value) {
  const el = document.querySelector(`[data-count="${key}"]`);
  if (el) el.textContent = String(value);
}

function renderPendingReservations(items) {
  const root = document.getElementById('pending-reservations');
  if (!root) return;
  setCount('pending', items.length);
  root.innerHTML = items.length ? items.map((item) => `
    <article class="ld-item">
      <div class="ld-item__head">
        <strong>${escapeHtml(item.title)}</strong>
        ${statusBadge('pending')}
      </div>
      <div class="ld-item__meta">
        <span>${UI.icon('user', 'ico--sm')}${escapeHtml(item.user_name)}${item.organization ? `（${escapeHtml(item.organization)}）` : ''}</span>
        <span>${UI.icon('calendar', 'ico--sm')}${escapeHtml(formatRange(item.start_at, item.end_at))}</span>
        <span>${UI.icon('package', 'ico--sm')}${escapeHtml(item.asset_names || '')}</span>
        ${item.place ? `<span>${UI.icon('map-pin', 'ico--sm')}${escapeHtml(item.place)}</span>` : ''}
      </div>
      ${item.purpose ? `<p class="ld-item__text allow-select">${escapeHtml(item.purpose)}</p>` : ''}
      <div class="ld-item__actions">
        <button type="button" class="btn btn--danger-ghost btn--sm" data-admin-reservation="${item.id}" data-action="reject">${UI.icon('x')}却下</button>
        <button type="button" class="btn btn--primary btn--sm" data-admin-reservation="${item.id}" data-action="approve">${UI.icon('check')}承認</button>
      </div>
    </article>
  `).join('') : emptyState('承認待ちの予約はありません。', 'check-circle');
}

function renderReturnReview(items) {
  const root = document.getElementById('return-review-list');
  if (!root) return;
  setCount('review', items.length);
  root.innerHTML = items.length ? items.map((item) => `
    <article class="ld-item">
      <div class="ld-item__head">
        <strong>${escapeHtml(item.asset_name)}</strong>
        ${statusBadge(item.state)}
      </div>
      <div class="ld-item__meta">
        <span>${UI.icon('user', 'ico--sm')}${escapeHtml(item.user_name)}</span>
        <span>${UI.icon('hash', 'ico--sm')}<code>${escapeHtml(item.asset_code)}</code></span>
        ${item.return_declared_at ? `<span>${UI.icon('clock', 'ico--sm')}${escapeHtml(item.return_declared_at)}</span>` : ''}
      </div>
      ${item.issue_note ? `<p class="ld-item__text ld-item__text--warn allow-select">${UI.icon('alert-triangle', 'ico--sm')}${escapeHtml(item.issue_note)}</p>` : '<p class="ld-item__text text-subtle">異常の申告はありません</p>'}
      <div class="ld-item__actions">
        <button type="button" class="btn btn--danger-ghost btn--sm" data-review-id="${item.id}" data-review-action="flag">${UI.icon('alert-triangle')}異常として保留</button>
        <button type="button" class="btn btn--primary btn--sm" data-review-id="${item.id}" data-review-action="complete">${UI.icon('check')}確認完了</button>
      </div>
    </article>
  `).join('') : emptyState('確認待ちの返却はありません。', 'check-circle');
}

function renderOverdues(items) {
  const root = document.getElementById('overdue-list');
  if (!root) return;
  setCount('overdue', items.length);
  root.innerHTML = items.length ? items.map((item) => `
    <article class="ld-item ld-item--danger">
      <div class="ld-item__head">
        <strong>${escapeHtml(item.asset_name)}</strong>
        ${statusBadge(item.state)}
      </div>
      <div class="ld-item__meta">
        <span>${UI.icon('user', 'ico--sm')}${escapeHtml(item.user_name)}</span>
        <span class="text-danger">${UI.icon('clock', 'ico--sm')}期限 ${escapeHtml(String(item.end_at || '').slice(0, 16))}</span>
      </div>
      <p class="ld-item__text">${escapeHtml(item.title)}</p>
    </article>
  `).join('') : emptyState('延滞中の貸出はありません。', 'check-circle');
}

async function loadAdminData() {
  try {
    const result = await apiGet('api/admin_data.php');
    if (!result.ok) {
      UI.toast(result.message || '読み込みに失敗しました。', { type: 'error' });
      return;
    }
    renderPendingReservations(result.pending_reservations || []);
    renderReturnReview(result.return_review || []);
    renderOverdues(result.overdues || []);
  } catch (error) {
    UI.toast('通信に失敗しました。', { type: 'error' });
  }
}

async function runAction(button, url, payload, confirmOptions) {
  if (confirmOptions && !(await UI.confirm(confirmOptions))) return;
  button.classList.add('is-loading');
  button.disabled = true;
  try {
    const result = await apiPost(url, payload);
    UI.toast(result.message || (result.ok ? '更新しました。' : '更新に失敗しました。'), { type: result.ok ? 'success' : 'error' });
    if (result.ok) {
      button.closest('.ld-item')?.classList.add('is-leaving');
      setTimeout(loadAdminData, 220);
    }
  } catch (error) {
    UI.toast('通信に失敗しました。', { type: 'error' });
  } finally {
    button.classList.remove('is-loading');
    button.disabled = false;
  }
}

document.addEventListener('click', (event) => {
  const reservationButton = event.target.closest('[data-admin-reservation]');
  if (reservationButton) {
    const action = reservationButton.dataset.action;
    runAction(reservationButton, 'api/admin_reservation_action.php', {
      reservation_id: reservationButton.dataset.adminReservation,
      action,
    }, action === 'reject' ? { title: 'この予約を却下しますか？', message: '申請者には却下として表示されます。', okText: '却下する', tone: 'danger' } : null);
    return;
  }

  const reviewButton = event.target.closest('[data-review-id]');
  if (reviewButton) {
    const action = reviewButton.dataset.reviewAction;
    runAction(reviewButton, 'api/admin_return_review.php', {
      checkout_id: reviewButton.dataset.reviewId,
      action,
    }, action === 'flag' ? { title: '異常案件として保留しますか？', message: '返却は完了にせず、異常案件として残します。', okText: '保留する', tone: 'warning' } : null);
  }
});

document.getElementById('admin-reload')?.addEventListener('click', loadAdminData);

loadAdminData();
