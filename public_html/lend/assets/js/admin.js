function renderPendingReservations(items) {
  const root = document.getElementById('pending-reservations');
  if (!root) return;
  root.innerHTML = items.length ? items.map((item) => `
    <article class="list-item">
      <div class="list-item-header">
        <div>
          <h3>${escapeHtml(item.title)}</h3>
          <div class="pill-line">
            <span class="pill">${escapeHtml(item.user_name)}</span>
            <span class="pill">${escapeHtml(item.organization || '')}</span>
            <span class="pill">${escapeHtml(item.start_at)} 〜 ${escapeHtml(item.end_at)}</span>
            <span class="pill">${escapeHtml(item.asset_names || '')}</span>
          </div>
        </div>
        ${statusBadge('pending')}
      </div>
      <p>${escapeHtml(item.purpose)}</p>
      ${item.place ? `<p class="muted">利用場所: ${escapeHtml(item.place)}</p>` : ''}
      <div class="list-item-actions">
        <button class="btn primary" data-admin-reservation="${item.id}" data-action="approve">承認</button>
        <button class="btn danger" data-admin-reservation="${item.id}" data-action="reject">却下</button>
      </div>
    </article>
  `).join('') : '<div class="alert info">承認待ち予約はありません。</div>';
}

function renderReturnReview(items) {
  const root = document.getElementById('return-review-list');
  if (!root) return;
  root.innerHTML = items.length ? items.map((item) => `
    <article class="list-item">
      <div class="list-item-header">
        <div>
          <h3>${escapeHtml(item.asset_name)}</h3>
          <div class="pill-line">
            <span class="pill">${escapeHtml(item.user_name)}</span>
            <span class="pill">${escapeHtml(item.asset_code)}</span>
            ${item.return_declared_at ? `<span class="pill">${escapeHtml(item.return_declared_at)}</span>` : ''}
          </div>
        </div>
        ${statusBadge(item.state)}
      </div>
      ${item.issue_note ? `<p>${escapeHtml(item.issue_note)}</p>` : '<p class="muted">異常メモなし</p>'}
      <div class="list-item-actions">
        <button class="btn primary" data-review-id="${item.id}" data-review-action="complete">確認完了</button>
        <button class="btn danger" data-review-id="${item.id}" data-review-action="flag">異常案件として保持</button>
      </div>
    </article>
  `).join('') : '<div class="alert info">確認待ち返却はありません。</div>';
}

function renderOverdues(items) {
  const root = document.getElementById('overdue-list');
  if (!root) return;
  root.innerHTML = items.length ? items.map((item) => `
    <article class="list-item">
      <div class="list-item-header">
        <div>
          <h3>${escapeHtml(item.asset_name)}</h3>
          <div class="pill-line">
            <span class="pill">${escapeHtml(item.user_name)}</span>
            <span class="pill">返却期限: ${escapeHtml(item.end_at)}</span>
          </div>
        </div>
        ${statusBadge(item.state)}
      </div>
      <p>${escapeHtml(item.title)}</p>
    </article>
  `).join('') : '<div class="alert success">延滞中の貸出はありません。</div>';
}

async function loadAdminData() {
  const result = await apiGet('api/admin_data.php');
  if (!result.ok) return;
  renderPendingReservations(result.pending_reservations || []);
  renderReturnReview(result.return_review || []);
  renderOverdues(result.overdues || []);
}

document.addEventListener('click', async (event) => {
  const reservationButton = event.target.closest('[data-admin-reservation]');
  if (reservationButton) {
    const reservationId = reservationButton.dataset.adminReservation;
    const action = reservationButton.dataset.action;
    const result = await apiPost('api/admin_reservation_action.php', {
      reservation_id: reservationId,
      action,
    });
    alert(result.message || (result.ok ? '更新しました。' : '更新に失敗しました。'));
    if (result.ok) loadAdminData();
    return;
  }

  const reviewButton = event.target.closest('[data-review-id]');
  if (reviewButton) {
    const checkoutId = reviewButton.dataset.reviewId;
    const action = reviewButton.dataset.reviewAction;
    const result = await apiPost('api/admin_return_review.php', {
      checkout_id: checkoutId,
      action,
    });
    alert(result.message || (result.ok ? '更新しました。' : '更新に失敗しました。'));
    if (result.ok) loadAdminData();
  }
});

loadAdminData();
