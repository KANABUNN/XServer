async function loadUserDashboard() {
  const result = await apiGet('api/user_data.php');
  if (!result.ok) return;

  const assetSelect = document.getElementById('asset-set-select');
  const reservationList = document.getElementById('reservation-list');

  assetSelect.innerHTML = '<option value="">選択してください</option>' + result.asset_sets.map((asset) => `
    <option value="${asset.id}">${escapeHtml(asset.name)} (${escapeHtml(asset.category)})</option>
  `).join('');

  reservationList.innerHTML = result.reservations.length ? result.reservations.map((r) => `
    <article class="list-item">
      <div class="list-item-header">
        <div>
          <h3>${escapeHtml(r.title)}</h3>
          <div class="pill-line">
            <span class="pill">${escapeHtml(r.start_at)} 〜 ${escapeHtml(r.end_at)}</span>
            <span class="pill">${escapeHtml(r.asset_names || '備品なし')}</span>
            ${r.place ? `<span class="pill">${escapeHtml(r.place)}</span>` : ''}
          </div>
        </div>
        ${statusBadge(r.status)}
      </div>
      <p>${escapeHtml(r.purpose)}</p>
    </article>
  `).join('') : '<div class="alert info">まだ申請はありません。</div>';
}

(function wireReservationForm() {
  const form = document.getElementById('reservation-form');
  const message = document.getElementById('reservation-message');
  if (!form) return;

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(form).entries());

    const result = await apiPost('api/create_reservation.php', data);
    showElement(message);

    if (!result.ok) {
      setMessage(message, result.message || '申請に失敗しました。', 'error');
      return;
    }

    setMessage(message, result.message || '申請しました。', 'success');
    form.reset();
    await loadUserDashboard();
  });
})();

loadUserDashboard();
