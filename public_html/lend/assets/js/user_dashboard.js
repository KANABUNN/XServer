function renderKintoneLendHints(result) {
  const ref = document.getElementById('kintone-asset-reference');
  if (!result || !ref) return;

  const assets = Array.isArray(result.kintone_reference?.assets) ? result.kintone_reference.assets : [];
  if (!assets.length) {
    ref.innerHTML = '';
    return;
  }

  ref.innerHTML = `
    <details class="disclosure allow-select">
      <summary>${UI.icon('package')}<span>備品の一覧を見る（参考）</span><span class="badge">${assets.length}</span></summary>
      <div class="disclosure__body">
        <div class="cluster">
          ${assets.slice(0, 30).map((asset) => `<span class="chip">${escapeHtml(asset.label)}${asset.value && asset.value !== asset.label ? ` / ${escapeHtml(asset.value)}` : ''}</span>`).join('')}
        </div>
        ${assets.length > 30 ? `<p class="small-note mt-2">ほか ${assets.length - 30} 件</p>` : ''}
      </div>
    </details>
  `;
}

function formatRange(start, end) {
  const fmt = (value) => String(value || '').replace('T', ' ').slice(0, 16);
  return `${fmt(start)} 〜 ${fmt(end)}`;
}

async function loadUserDashboard() {
  const assetSelect = document.getElementById('asset-set-select');
  const reservationList = document.getElementById('reservation-list');
  const count = document.getElementById('reservation-count');
  let result;
  try {
    result = await apiGet('api/user_data.php');
  } catch (error) {
    if (reservationList) reservationList.innerHTML = emptyState('読み込みに失敗しました。', 'alert-triangle');
    return;
  }
  if (!result.ok) {
    if (reservationList) reservationList.innerHTML = emptyState(result.message || '読み込みに失敗しました。', 'alert-triangle');
    return;
  }

  const current = assetSelect.value;
  assetSelect.innerHTML = '<option value="">選択してください</option>' + result.asset_sets.map((asset) => `
    <option value="${asset.id}">${escapeHtml(asset.name)}（${escapeHtml(asset.category)}）</option>
  `).join('');
  if (current) assetSelect.value = current;

  renderKintoneLendHints(result);

  if (count) count.textContent = `${result.reservations.length}件`;
  reservationList.innerHTML = result.reservations.length ? result.reservations.map((r) => `
    <article class="ld-item">
      <div class="ld-item__head">
        <strong>${escapeHtml(r.title)}</strong>
        ${statusBadge(r.status)}
      </div>
      <div class="ld-item__meta">
        <span>${UI.icon('calendar', 'ico--sm')}${escapeHtml(formatRange(r.start_at, r.end_at))}</span>
        <span>${UI.icon('package', 'ico--sm')}${escapeHtml(r.asset_names || '備品なし')}</span>
        ${r.place ? `<span>${UI.icon('map-pin', 'ico--sm')}${escapeHtml(r.place)}</span>` : ''}
      </div>
      ${r.purpose ? `<p class="ld-item__text allow-select">${escapeHtml(r.purpose)}</p>` : ''}
    </article>
  `).join('') : emptyState('まだ申請はありません。左のフォームから申請できます。', 'clipboard');
}

(function wireReservationForm() {
  const form = document.getElementById('reservation-form');
  const message = document.getElementById('reservation-message');
  if (!form) return;

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = form.querySelector('[type="submit"]');
    button?.classList.add('is-loading');
    hideElement(message);
    try {
      const data = Object.fromEntries(new FormData(form).entries());
      const result = await apiPost('api/create_reservation.php', data);
      if (!result.ok) {
        setMessage(message, result.message || '申請に失敗しました。', 'error');
        showElement(message);
        return;
      }
      UI.toast(result.message || '申請しました。承認されるまでお待ちください。', { type: 'success', title: '申請しました' });
      form.reset();
      await loadUserDashboard();
    } catch (error) {
      setMessage(message, '通信に失敗しました。時間をおいて再度お試しください。', 'error');
      showElement(message);
    } finally {
      button?.classList.remove('is-loading');
    }
  });
})();

loadUserDashboard();
