/* admin-application.js
 * - 予約申請状況（一覧/検索/詳細/ダウンロード/削除/確定登録）
 */
(function () {
  'use strict';

  const Admin = (window.Admin = window.Admin || {});
  const u = Admin.utils;

  function buildListUrl() {
    const el = Admin.el;
    const state = Admin.state;

    const url = new URL(Admin.apiPath, window.location.href);
    url.searchParams.set('action', 'list');
    url.searchParams.set('page', String(state.page));
    url.searchParams.set('per_page', el.perPageSelect.value);
    url.searchParams.set('sort', el.sortFieldSelect.value);
    url.searchParams.set('dir', el.sortDirSelect.value);

    const q = el.searchInput.value.trim();
    const room = el.roomFilter.value.trim();
    const status = el.statusFilter?.value.trim() || '';
    const dateFrom = el.dateFromInput.value;
    const dateTo = el.dateToInput.value;

    if (q) url.searchParams.set('q', q);
    if (room) url.searchParams.set('room', room);
    if (status) url.searchParams.set('status', status);
    if (dateFrom) url.searchParams.set('date_from', dateFrom);
    if (dateTo) url.searchParams.set('date_to', dateTo);

    return url;
  }

  async function loadRooms() {
    const el = Admin.el;

    try {
      const url = new URL(Admin.apiPath, window.location.href);
      url.searchParams.set('action', 'rooms');
      const res = await fetch(url, { cache: 'no-store' });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || '部屋一覧の取得に失敗しました。');
      }

      const currentValue = el.roomFilter.value;
      const options = ['<option value="">すべて</option>']
        .concat((data.rooms || []).map((room) => `<option value="${u.escapeHtml(room)}">${u.escapeHtml(room)}</option>`));
      el.roomFilter.innerHTML = options.join('');
      if ([...el.roomFilter.options].some((opt) => opt.value === currentValue)) {
        el.roomFilter.value = currentValue;
      }
    } catch (err) {
      u.setStatus(err.message || '部屋一覧の取得に失敗しました。', true);
    }
  }

  async function loadRows() {
    const el = Admin.el;
    const state = Admin.state;

    u.setStatus('読み込み中です…');
    el.tableBody.innerHTML = '<tr><td colspan="9" class="empty">読み込み中です…</td></tr>';

    try {
      const res = await fetch(buildListUrl(), { cache: 'no-store' });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || '一覧取得に失敗しました。');
      }

      state.currentRows = data.rows || [];
      state.page = Number(data.page || 1);
      state.totalPages = Number(data.total_pages || 1);

      renderRows(state.currentRows);
      renderMeta(data);
      renderActiveFilters(data.filters || {});
      updatePager();
      u.setStatus('');
    } catch (err) {
      state.currentRows = [];
      el.tableBody.innerHTML = `<tr><td colspan="9" class="empty">${u.escapeHtml(err.message || 'エラー')}</td></tr>`;
      el.metaText.textContent = '読み込みに失敗しました。';
      renderActiveFilters({});
      updatePager();
      u.setStatus(err.message || 'エラーが発生しました。', true);
    }
  }

  function renderRows(rows) {
    const el = Admin.el;
    const cardList = el.applicationCardList;

    if (!rows.length) {
      el.tableBody.innerHTML = '<tr><td colspan="9" class="empty">該当データはありません。</td></tr>';
      if (cardList) {
        cardList.innerHTML = '<div class="card-empty">この条件の申請データはありません。</div>';
      }
      return;
    }

    el.tableBody.innerHTML = rows.map((row) => {
      const statusCode = String(row.application_status || 'pending');
      const statusLabel = u.applicationStatusLabel(statusCode);
      const statusClass = u.applicationStatusClass(statusCode);
      return `
        <tr>
          <td>${u.escapeHtml(String(row.id ?? ''))}</td>
          <td>${u.escapeHtml(String(row.created_at ?? ''))}</td>
          <td>${u.escapeHtml(String(row.email ?? ''))}</td>
          <td>${u.escapeHtml(String(row.room ?? ''))}</td>
          <td><span class="status-badge ${u.escapeHtml(statusClass)}">${u.escapeHtml(statusLabel)}</span></td>
          <td><code>${u.escapeHtml(String(row.original_name ?? ''))}</code></td>
          <td><code>${u.escapeHtml(String(row.stored_name ?? ''))}</code></td>
          <td>${u.escapeHtml(u.shortenText(String(row.note ?? ''), 60))}</td>
          <td>
            <div class="actions">
              <button type="button" data-action="detail" data-id="${Number(row.id)}">詳細</button>
              <button type="button" class="secondary" data-action="calendar" data-id="${Number(row.id)}">確定</button>
              <button type="button" data-action="download" data-id="${Number(row.id)}">DL</button>
              <button type="button" class="danger" data-action="delete" data-id="${Number(row.id)}">削除</button>
            </div>
          </td>
        </tr>
      `;
    }).join('');

    if (cardList) {
      cardList.innerHTML = rows.map((row) => {
        const id = Number(row.id);
        const createdAt = u.escapeHtml(String(row.created_at ?? ''));
        const email = u.escapeHtml(String(row.email ?? ''));
        const room = u.escapeHtml(String(row.room ?? ''));
        const originalName = u.escapeHtml(String(row.original_name ?? ''));
        const storedName = u.escapeHtml(String(row.stored_name ?? ''));
        const note = u.escapeHtml(u.shortenText(String(row.note ?? ''), 140));
        const statusCode = String(row.application_status || 'pending');
        const statusLabel = u.escapeHtml(u.applicationStatusLabel(statusCode));
        const statusClass = u.escapeHtml(u.applicationStatusClass(statusCode));

        return `
          <article class="data-card" data-id="${id}">
            <header class="card-head">
              <div class="card-head-left">
                <div class="card-title">申請 ID #${u.escapeHtml(String(row.id ?? ''))}</div>
                <div class="card-sub">受付: ${createdAt || '—'}</div>
              </div>
              <div class="card-head-right">
                <span class="card-pill">${room || '—'}</span>
              </div>
            </header>

            <dl class="card-kv">
              <dt>メール</dt><dd>${email || '—'}</dd>
              <dt>部屋</dt><dd>${room || '—'}</dd>
              <dt>申請ステータス</dt><dd><span class="status-badge ${statusClass}">${statusLabel}</span></dd>
              <dt>元の名前</dt><dd><code>${originalName || '—'}</code></dd>
              <dt>保存後</dt><dd><code>${storedName || '—'}</code></dd>
              <dt>備考</dt><dd>${note || '—'}</dd>
            </dl>

            <div class="actions card-actions">
              <button type="button" data-action="detail" data-id="${id}">詳細</button>
              <button type="button" class="secondary" data-action="calendar" data-id="${id}">確定</button>
              <button type="button" data-action="download" data-id="${id}">DL</button>
              <button type="button" class="danger" data-action="delete" data-id="${id}">削除</button>
            </div>
          </article>
        `;
      }).join('');
    }
  }

  function renderMeta(data) {
    const el = Admin.el;
    const total = Number(data.total ?? 0);
    const current = Number(data.count ?? 0);
    el.metaText.textContent = `表示 ${current} 件 / 全 ${total} 件`;
  }

  function renderActiveFilters(filters) {
    const el = Admin.el;

    const chips = [];
    if (filters.q) chips.push(`検索: ${filters.q}`);
    if (filters.room) chips.push(`部屋: ${filters.room}`);
    if (filters.status) chips.push(`申請ステータス: ${u.applicationStatusLabel(filters.status)}`);
    if (filters.date_from) chips.push(`開始日: ${filters.date_from}`);
    if (filters.date_to) chips.push(`終了日: ${filters.date_to}`);
    chips.push(`並び替え: ${u.labelForSort(filters.sort || el.sortFieldSelect.value)} / ${(filters.dir || el.sortDirSelect.value) === 'asc' ? '昇順' : '降順'}`);
    chips.push(`表示件数: ${el.perPageSelect.value}`);
    el.activeFilters.innerHTML = chips.map((chipText) => `<span class="chip">${u.escapeHtml(chipText)}</span>`).join('');
  }

  function updatePager() {
    const el = Admin.el;
    const state = Admin.state;

    const totalPages = Math.max(1, state.totalPages || 1);
    el.pageInfo.textContent = `${state.page} / ${totalPages} ページ`;
    el.prevPageBtn.disabled = state.page <= 1;
    el.nextPageBtn.disabled = state.page >= totalPages;
  }

  async function openDetail(id) {
    const el = Admin.el;
    const state = Admin.state;

    state.lastDetailId = id;
    state.detailRow = null;
    el.detailSubText.textContent = `ID ${id}`;
    u.setElementStatus(el.detailStatus, '詳細を読み込み中です…');
    el.detailGrid.innerHTML = '<div class="detail-empty">詳細を読み込んでいます…</div>';

    if (!el.detailDialog.open) el.detailDialog.showModal();

    try {
      const url = new URL(Admin.apiPath, window.location.href);
      url.searchParams.set('action', 'detail');
      url.searchParams.set('id', String(id));
      const res = await fetch(url, { cache: 'no-store' });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || '詳細取得に失敗しました。');
      }

      state.detailRow = data.row || {};
      renderDetail(state.detailRow);
      u.setElementStatus(el.detailStatus, '');
    } catch (err) {
      el.detailGrid.innerHTML = `<div class="detail-empty">${u.escapeHtml(err.message || '詳細取得に失敗しました。')}</div>`;
      u.setElementStatus(el.detailStatus, err.message || '詳細取得に失敗しました。', 'error');
    }
  }

  function renderDetail(row) {
    const el = Admin.el;

    el.detailSubText.textContent = `ID ${u.escapeHtml(String(row.id ?? '-'))}`;

    const fileBadge = row.file_exists
      ? '<span class="file-badge ok">保存ファイル: あり</span>'
      : '<span class="file-badge missing">保存ファイル: なし</span>';

    const statusCode = String(row.application_status || 'pending');
    const statusLabel = u.applicationStatusLabel(statusCode);

    el.detailGrid.innerHTML = [
      detailCard('受付ID', u.escapeHtml(String(row.id ?? ''))),
      detailCard('受付日時', u.escapeHtml(String(row.created_at ?? ''))),
      detailCard('メールアドレス', u.escapeHtml(String(row.email ?? ''))),
      detailCard('予約部屋', u.escapeHtml(String(row.room ?? ''))),
      detailCard('申請ステータス', `<span class="status-badge ${u.escapeHtml(u.applicationStatusClass(statusCode))}">${u.escapeHtml(statusLabel)}</span>`),
      detailCard('備考', row.note ? u.nl2br(u.escapeHtml(String(row.note))) : '（なし）', true),
      detailCard('元のファイル名', `<code>${u.escapeHtml(String(row.original_name ?? ''))}</code>`),
      detailCard('保存後の名前', `<code>${u.escapeHtml(String(row.stored_name ?? ''))}</code>`),
      detailCard('DB上の保存パス', `<code>${u.escapeHtml(String(row.file_path ?? ''))}</code>`, true),
      detailCard(
        '保存ファイルの状態',
        `${fileBadge}${row.file_size !== null && row.file_size !== undefined
          ? `<div class="detail-value" style="margin-top:8px;">サイズ: ${u.escapeHtml(u.formatBytes(Number(row.file_size)))} / MIME: ${u.escapeHtml(String(row.mime_type || '-'))}</div>`
          : ''}`,
        true
      ),
    ].join('');

    el.detailDownloadBtn.disabled = !row.id;
    el.detailDeleteBtn.disabled = !row.id;
    el.detailDownloadBtn.dataset.id = String(row.id ?? '');
    el.detailDeleteBtn.dataset.id = String(row.id ?? '');
    if (el.detailApplicationStatusSelect) {
      el.detailApplicationStatusSelect.value = statusCode;
      el.detailApplicationStatusSelect.disabled = !row.id;
    }
    if (el.detailApplicationStatusSaveBtn) {
      el.detailApplicationStatusSaveBtn.disabled = !row.id;
    }
  }

  function detailCard(label, valueHtml, full = false) {
    return `
      <section class="detail-card${full ? ' full' : ''}">
        <div class="detail-label">${label}</div>
        <div class="detail-value">${valueHtml}</div>
      </section>
    `;
  }

  function downloadRow(id) {
    const url = new URL(Admin.apiPath, window.location.href);
    url.searchParams.set('action', 'download');
    url.searchParams.set('id', String(id));
    window.location.href = url.toString();
  }

  async function deleteRow(id) {
    const el = Admin.el;
    const state = Admin.state;

    if (!id) return;

    const ok = window.confirm(`ID ${id} のデータと保存ファイルを削除します。\nこの操作は取り消せません。`);
    if (!ok) return;

    u.setStatus(`ID ${id} を削除しています…`);

    try {
      const res = await fetch(`${Admin.apiPath}?action=delete`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id })
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || '削除に失敗しました。');
      }

      u.setStatus(data.message || '削除しました。');
      if (el.detailDialog.open && state.lastDetailId === id) {
        el.detailDialog.close();
      }
      const lastPageBecameEmpty = state.currentRows.length === 1 && state.page > 1;
      if (lastPageBecameEmpty) {
        state.page -= 1;
      }
      await loadRows();
    } catch (err) {
      u.setStatus(err.message || '削除に失敗しました。', true);
      if (el.detailDialog.open) {
        u.setElementStatus(el.detailStatus, err.message || '削除に失敗しました。', 'error');
      }
    }
  }

  async function saveApplicationStatus() {
    const el = Admin.el;
    const state = Admin.state;
    const id = Number(state.lastDetailId || 0);
    const applicationStatus = String(el.detailApplicationStatusSelect?.value || '').trim();
    if (!id || !applicationStatus) return;

    u.setElementStatus(el.detailStatus, '申請ステータスを更新しています…');
    try {
      const res = await fetch(`${Admin.apiPath}?action=application_status_update`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, application_status: applicationStatus })
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        throw new Error(data.message || '申請ステータスの更新に失敗しました。');
      }

      u.setElementStatus(el.detailStatus, data.message || '申請ステータスを更新しました。', 'ok');
      await loadRows();
      await openDetail(id);
    } catch (err) {
      u.setElementStatus(el.detailStatus, err.message || '申請ステータスの更新に失敗しました。', 'error');
    }
  }

  function resetFilters() {
    const el = Admin.el;
    const state = Admin.state;

    el.searchInput.value = '';
    el.roomFilter.value = '';
    if (el.statusFilter) el.statusFilter.value = '';
    el.dateFromInput.value = '';
    el.dateToInput.value = '';
    el.sortFieldSelect.value = 'created_at';
    el.sortDirSelect.value = 'desc';
    el.perPageSelect.value = '50';
    state.page = 1;
  }

  function bindEvents() {
    const el = Admin.el;
    const state = Admin.state;

    el.searchBtn.addEventListener('click', () => {
      state.page = 1;
      loadRows();
    });

    el.clearBtn.addEventListener('click', () => {
      resetFilters();
      loadRows();
    });

    el.reloadBtn.addEventListener('click', () => loadRows());

    el.searchInput.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        state.page = 1;
        loadRows();
      }
    });

    [el.roomFilter, el.statusFilter, el.dateFromInput, el.dateToInput, el.sortFieldSelect, el.sortDirSelect, el.perPageSelect]
      .filter(Boolean)
      .forEach((inputEl) => {
        inputEl.addEventListener('change', () => {
          state.page = 1;
          loadRows();
        });
      });

    el.prevPageBtn.addEventListener('click', () => {
      if (state.page <= 1) return;
      state.page -= 1;
      loadRows();
    });

    el.nextPageBtn.addEventListener('click', () => {
      if (state.page >= state.totalPages) return;
      state.page += 1;
      loadRows();
    });

    el.tableBody.addEventListener('click', (event) => {
      const button = event.target.closest('button[data-action]');
      if (!button) return;
      const id = Number(button.dataset.id || 0);
      if (!id) return;

      switch (button.dataset.action) {
        case 'detail':
          openDetail(id);
          break;
        case 'calendar':
          if (Admin.calendar && typeof Admin.calendar.openAdd === 'function') {
            Admin.calendar.openAdd(id);
          }
          break;
        case 'download':
          downloadRow(id);
          break;
        case 'delete':
          deleteRow(id);
          break;
      }
    });

    el.detailDownloadBtn.addEventListener('click', () => {
      const id = Number(el.detailDownloadBtn.dataset.id || 0);
      if (id) downloadRow(id);
    });

    el.detailDeleteBtn.addEventListener('click', () => {
      const id = Number(el.detailDeleteBtn.dataset.id || 0);
      if (id) deleteRow(id);
    });

    el.detailApplicationStatusSaveBtn?.addEventListener('click', () => saveApplicationStatus());
  }

  Admin.application = {
    buildListUrl,
    loadRooms,
    loadRows,
    bindEvents,
  };
})();
