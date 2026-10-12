function renderKintoneLendHints(result) {
  const notice = document.getElementById('kintone-lend-cache-notice');
  const ref = document.getElementById('kintone-asset-reference');
  if (!result || !notice || !ref) return;

  const assetStatus = result.kintone_cache?.assets || {};
  if (assetStatus.available) {
    notice.classList.remove('hidden');
    notice.classList.toggle('warning', Boolean(assetStatus.is_stale));
    notice.classList.toggle('info', !assetStatus.is_stale);
    notice.textContent = `${assetStatus.message || 'kintone備品マスタのキャッシュを参照できます。'}${assetStatus.last_synced_at ? ` 最終同期: ${assetStatus.last_synced_at}` : ''}`;
  } else {
    notice.classList.add('hidden');
    notice.textContent = '';
  }

  const assets = Array.isArray(result.kintone_reference?.assets) ? result.kintone_reference.assets : [];
  if (!assets.length) {
    ref.innerHTML = '';
    return;
  }

  ref.innerHTML = `
    <details class="helper-panel allow-select">
      <summary>kintone備品マスタ参考情報（表示のみ）</summary>
      <div class="pill-line">
        ${assets.slice(0, 20).map((asset) => `<span class="pill">${escapeHtml(asset.label)}${asset.value && asset.value !== asset.label ? ` / ${escapeHtml(asset.value)}` : ''}</span>`).join('')}
      </div>
      ${assets.length > 20 ? `<p class="small-note">ほか ${assets.length - 20} 件</p>` : ''}
    </details>
  `;
}

async function loadUserDashboard() {
  const result = await apiGet('api/user_data.php');
  if (!result.ok) return;

  const assetSelect = document.getElementById('asset-set-select');
  const reservationList = document.getElementById('reservation-list');

  assetSelect.innerHTML = '<option value="">選択してください</option>' + result.asset_sets.map((asset) => `
    <option value="${asset.id}">${escapeHtml(asset.name)} (${escapeHtml(asset.category)})</option>
  `).join('');

  renderKintoneLendHints(result);

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
