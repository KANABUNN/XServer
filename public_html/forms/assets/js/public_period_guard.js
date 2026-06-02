(function () {
  'use strict';

  const BEFORE_MESSAGE = 'このフォームは提出期間前です。';
  const CLOSED_MESSAGE = 'このフォームの受け付けは終了しました。\n提出物がある際はメールにて連絡してください。\nsogokanri@bene.fit.ac.jp';
  const TARGET_PUBLIC_FORMS_API = 'api/public_forms.php';
  const REPLACEMENT_PUBLIC_FORMS_API = 'api/public_forms_with_closed.php';
  const DESKTOP_LIST_MEDIA = '(min-width: 921px)';
  const autoDialogShownFor = new Set();
  let activeDialog = null;
  let activeListDialog = null;
  let listSearchQuery = '';

  function periodEscapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function isAvailabilityOpenGuard(availability) {
    if (!availability || typeof availability !== 'object') return true;
    if (availability.is_open === false) return false;
    if (availability.is_open === true) return true;
    const status = String(availability.status || '').toLowerCase();
    if (!status) return true;
    return status === 'open' || status === 'available' || status === 'active' || status === 'always_open';
  }

  function availabilityRank(availability) {
    const status = String(availability?.status || '').toLowerCase();
    if (isAvailabilityOpenGuard(availability)) return 0;
    if (status === 'scheduled') return 1;
    if (status === 'closed') return 2;
    return 3;
  }

  function sortedPublicForms(forms) {
    return [...(forms || [])].sort((a, b) => {
      const rankDiff = availabilityRank(a.availability) - availabilityRank(b.availability);
      if (rankDiff !== 0) return rankDiff;
      const orderDiff = Number(a.sort_order || 0) - Number(b.sort_order || 0);
      if (orderDiff !== 0) return orderDiff;
      return Number(a.id || 0) - Number(b.id || 0);
    });
  }

  function periodMessageForAvailability(availability) {
    const status = String(availability?.status || '').toLowerCase();
    if (status === 'scheduled') return BEFORE_MESSAGE;
    if (status === 'closed') return CLOSED_MESSAGE;
    return String(availability?.note || '現在このフォームは受付できません。');
  }

  function availabilityLabel(form) {
    const availability = form?.availability || {};
    if (!form?.is_active) return '非公開';
    if (availability.label) return String(availability.label);
    const status = String(availability.status || '').toLowerCase();
    if (status === 'scheduled') return '受付前';
    if (status === 'closed') return '受付終了';
    if (status === 'open') return '公開期間内';
    return '受付中';
  }

  function availabilityWindowText(form) {
    const availability = form?.availability || {};
    const settings = form?.settings || {};
    const windowText = String(availability.window_text || '').trim();
    if (windowText) return windowText;

    const startDate = availability.start_date || settings.public_start_date || '';
    const startTime = availability.start_time || settings.public_start_time || '';
    const endDate = availability.end_date || settings.public_end_date || '';
    const endTime = availability.end_time || settings.public_end_time || '';
    const start = startDate ? `${startDate}${startTime ? ` ${startTime}` : ''}` : '';
    const end = endDate ? `${endDate}${endTime ? ` ${endTime}` : ''}` : '';
    if (start && end) return `${start} 〜 ${end}`;
    if (start) return `${start} 以降`;
    if (end) return `${end} まで`;
    return '常時公開';
  }

  function installPeriodDialogStyles() {
    if (document.getElementById('forms-period-dialog-style')) return;
    const style = document.createElement('style');
    style.id = 'forms-period-dialog-style';
    style.textContent = `
      .forms-period-dialog-backdrop,
      .forms-public-list-backdrop {
        position: fixed;
        inset: 0;
        z-index: 9999;
        display: grid;
        place-items: center;
        padding: 24px;
        background: rgba(15, 23, 42, 0.42);
        backdrop-filter: blur(4px);
      }
      .forms-period-dialog,
      .forms-public-list-dialog {
        width: min(440px, 100%);
        max-height: min(86vh, 760px);
        overflow: auto;
        border-radius: 22px;
        background: #fff;
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.24);
        padding: 24px;
        color: #111827;
      }
      .forms-public-list-dialog { width: min(920px, 100%); }
      .forms-period-dialog h2,
      .forms-public-list-dialog h2 {
        margin: 0 0 12px;
        font-size: 1.18rem;
      }
      .forms-period-dialog__body {
        margin: 0 0 20px;
        line-height: 1.8;
        white-space: normal;
      }
      .forms-period-dialog__actions,
      .forms-public-list-dialog__actions {
        display: flex;
        justify-content: flex-end;
        gap: 12px;
      }
      .forms-period-unavailable {
        display: grid;
        gap: 12px;
        padding: 24px;
        border-radius: 20px;
        background: rgba(248, 250, 252, 0.92);
        border: 1px solid rgba(148, 163, 184, 0.36);
      }
      .forms-period-unavailable h3 {
        margin: 0;
        font-size: 1.1rem;
      }
      .forms-period-unavailable p {
        margin: 0;
        line-height: 1.8;
      }
      .forms-period-unavailable__mail { font-weight: 700; }
      .forms-public-list-dialog__head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        margin-bottom: 18px;
      }
      .forms-public-list-dialog__head p { margin: 4px 0 0; }
      .forms-public-list-search { margin: 0 0 16px; }
      .forms-public-list-stats {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
        margin-bottom: 16px;
      }
      .forms-public-list-stat {
        padding: 12px;
        border-radius: 16px;
        background: rgba(248, 250, 252, 0.96);
        border: 1px solid rgba(148, 163, 184, 0.28);
      }
      .forms-public-list-stat span {
        display: block;
        font-size: 0.78rem;
        color: #64748b;
        margin-bottom: 4px;
      }
      .forms-public-list-stat strong { font-size: 1.25rem; }
      .forms-public-list-group { margin-top: 18px; }
      .forms-public-list-group h3 {
        margin: 0 0 10px;
        font-size: 0.96rem;
      }
      .forms-public-list-items {
        display: grid;
        gap: 10px;
      }
      .forms-public-list-item {
        display: grid;
        gap: 10px;
        padding: 14px;
        border-radius: 18px;
        background: rgba(255, 255, 255, 0.96);
        border: 1px solid rgba(148, 163, 184, 0.34);
      }
      .forms-public-list-item.is-closed,
      .forms-public-list-item.is-scheduled {
        background: rgba(248, 250, 252, 0.9);
      }
      .forms-public-list-item__top {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: flex-start;
      }
      .forms-public-list-item__top strong { display: block; font-size: 1rem; }
      .forms-public-list-item__meta {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 6px;
      }
      .forms-public-list-item__description {
        margin: 0;
        line-height: 1.65;
      }
      .forms-public-list-empty {
        padding: 18px;
        border-radius: 16px;
        background: rgba(248, 250, 252, 0.92);
        border: 1px solid rgba(148, 163, 184, 0.28);
      }
      @media (max-width: 720px) {
        .forms-public-list-backdrop { padding: 12px; }
        .forms-public-list-dialog { padding: 18px; }
        .forms-public-list-dialog__head,
        .forms-public-list-item__top { display: grid; }
        .forms-public-list-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      }
    `;
    document.head.appendChild(style);
  }

  function closePeriodDialog() {
    if (!activeDialog) return;
    activeDialog.remove();
    activeDialog = null;
  }

  function showPeriodDialog(message) {
    installPeriodDialogStyles();
    closePeriodDialog();

    const backdrop = document.createElement('div');
    backdrop.className = 'forms-period-dialog-backdrop';
    backdrop.setAttribute('role', 'presentation');
    backdrop.innerHTML = `
      <section class="forms-period-dialog" role="dialog" aria-modal="true" aria-labelledby="forms-period-dialog-title">
        <h2 id="forms-period-dialog-title">公開範囲外です</h2>
        <p class="forms-period-dialog__body">${periodEscapeHtml(message).replaceAll('\n', '<br>')}</p>
        <div class="forms-period-dialog__actions">
          <button type="button" class="btn primary" data-forms-period-dialog-close>閉じる</button>
        </div>
      </section>
    `;

    backdrop.addEventListener('click', (event) => {
      if (event.target === backdrop || event.target.closest('[data-forms-period-dialog-close]')) {
        closePeriodDialog();
      }
    });

    const escapeHandler = (event) => {
      if (event.key === 'Escape') {
        closePeriodDialog();
        document.removeEventListener('keydown', escapeHandler);
      }
    };
    document.addEventListener('keydown', escapeHandler);

    document.body.appendChild(backdrop);
    activeDialog = backdrop;
    backdrop.querySelector('[data-forms-period-dialog-close]')?.focus();
  }

  function getGuardFormById(formId) {
    try {
      if (typeof publicState === 'undefined' || !Array.isArray(publicState.forms)) return null;
      return publicState.forms.find((form) => Number(form.id) === Number(formId)) || null;
    } catch (error) {
      return null;
    }
  }

  function renderUnavailablePanel(form, showDialogOnce) {
    const root = document.getElementById('public-form-host');
    const messageBox = document.getElementById('public-message');
    if (!root || !form) return false;

    const availability = form.availability || {};
    const message = periodMessageForAvailability(availability);
    const isClosed = String(availability.status || '').toLowerCase() === 'closed';

    try {
      if (typeof clearMessage === 'function') clearMessage(messageBox);
    } catch (error) {
      // noop
    }

    root.setAttribute('aria-labelledby', `tab-${form.id}`);
    root.innerHTML = `
      <section class="forms-period-unavailable" role="status" aria-live="polite">
        <h3>このフォームは現在、公開範囲外です。</h3>
        <p>${periodEscapeHtml(message).replaceAll('\n', '<br>')}</p>
        ${isClosed ? '<p class="forms-period-unavailable__mail">連絡先: sogokanri@bene.fit.ac.jp</p>' : ''}
      </section>
    `;

    if (showDialogOnce && !autoDialogShownFor.has(Number(form.id))) {
      autoDialogShownFor.add(Number(form.id));
      setTimeout(() => showPeriodDialog(message), 0);
    }

    return true;
  }

  function activatePublicFormFromList(form) {
    if (!form) return;
    const formId = Number(form.id);

    try {
      publicState.activeFormId = formId;
      if (typeof renderSidebar === 'function') renderSidebar();
      if (typeof renderHero === 'function') renderHero();
      if (isAvailabilityOpenGuard(form.availability)) {
        if (typeof renderActiveForm === 'function') renderActiveForm();
        closePublicListDialog();
        return;
      }
      renderUnavailablePanel(form, false);
    } catch (error) {
      // noop
    }

    closePublicListDialog();
    showPeriodDialog(periodMessageForAvailability(form.availability));
  }

  function publicFormsSummary(forms) {
    const summary = { total: forms.length, open: 0, scheduled: 0, closed: 0, other: 0 };
    forms.forEach((form) => {
      const rank = availabilityRank(form.availability);
      if (rank === 0) summary.open += 1;
      else if (rank === 1) summary.scheduled += 1;
      else if (rank === 2) summary.closed += 1;
      else summary.other += 1;
    });
    return summary;
  }

  function filteredPublicForms() {
    let forms = [];
    try {
      forms = typeof publicState !== 'undefined' && Array.isArray(publicState.forms) ? publicState.forms : [];
    } catch (error) {
      forms = [];
    }

    const sorted = sortedPublicForms(forms);
    const q = String(listSearchQuery || '').trim().toLowerCase();
    if (!q) return sorted;
    return sorted.filter((form) => [form.name, form.slug, form.description, availabilityLabel(form), availabilityWindowText(form)]
      .filter(Boolean)
      .some((value) => String(value).toLowerCase().includes(q)));
  }

  function groupTitle(rank) {
    if (rank === 0) return '受付中のフォーム';
    if (rank === 1) return '提出期間前のフォーム';
    if (rank === 2) return '受付終了後のフォーム';
    return 'その他のフォーム';
  }

  function renderPublicListContent(container) {
    const allForms = (() => {
      try {
        return typeof publicState !== 'undefined' && Array.isArray(publicState.forms) ? sortedPublicForms(publicState.forms) : [];
      } catch (error) {
        return [];
      }
    })();
    const forms = filteredPublicForms();
    const summary = publicFormsSummary(allForms);

    const stats = container.querySelector('[data-public-list-stats]');
    const body = container.querySelector('[data-public-list-body]');
    if (stats) {
      stats.innerHTML = `
        <article class="forms-public-list-stat"><span>全フォーム</span><strong>${summary.total}</strong></article>
        <article class="forms-public-list-stat"><span>受付中</span><strong>${summary.open}</strong></article>
        <article class="forms-public-list-stat"><span>提出期間前</span><strong>${summary.scheduled}</strong></article>
        <article class="forms-public-list-stat"><span>受付終了</span><strong>${summary.closed}</strong></article>
      `;
    }

    if (!body) return;
    if (!forms.length) {
      body.innerHTML = '<div class="forms-public-list-empty">条件に一致するフォームはありません。</div>';
      return;
    }

    const grouped = new Map();
    forms.forEach((form) => {
      const rank = availabilityRank(form.availability);
      if (!grouped.has(rank)) grouped.set(rank, []);
      grouped.get(rank).push(form);
    });

    body.innerHTML = [0, 1, 2, 3].filter((rank) => grouped.has(rank)).map((rank) => `
      <section class="forms-public-list-group">
        <h3>${groupTitle(rank)}</h3>
        <div class="forms-public-list-items">
          ${grouped.get(rank).map((form) => {
            const status = String(form.availability?.status || '').toLowerCase();
            const statusClass = status ? `period-${status}` : 'period-open';
            const stateClass = rank === 1 ? 'is-scheduled' : rank === 2 ? 'is-closed' : '';
            const fieldCount = Array.isArray(form.fields) ? form.fields.length : 0;
            return `
              <article class="forms-public-list-item ${stateClass}">
                <div class="forms-public-list-item__top">
                  <div>
                    <strong>${periodEscapeHtml(form.name || '名称未設定')}</strong>
                    <div class="forms-public-list-item__meta">
                      <span class="pill period-pill ${periodEscapeHtml(statusClass)}">${periodEscapeHtml(availabilityLabel(form))}</span>
                      <span class="pill">slug: ${periodEscapeHtml(form.slug || '')}</span>
                      <span class="pill">項目 ${periodEscapeHtml(String(fieldCount))}件</span>
                    </div>
                  </div>
                  <button type="button" class="btn ${rank === 0 ? 'primary' : 'ghost'}" data-public-list-select="${periodEscapeHtml(String(form.id))}">${rank === 0 ? '開く' : '確認する'}</button>
                </div>
                <div class="small-note allow-select">受付期間: ${periodEscapeHtml(availabilityWindowText(form))}</div>
                ${form.description ? `<p class="forms-public-list-item__description small-note allow-select">${periodEscapeHtml(form.description)}</p>` : ''}
              </article>
            `;
          }).join('')}
        </div>
      </section>
    `).join('');
  }

  function closePublicListDialog() {
    if (!activeListDialog) return;
    activeListDialog.remove();
    activeListDialog = null;
  }

  function showPublicListDialog() {
    installPeriodDialogStyles();
    closePublicListDialog();

    const backdrop = document.createElement('div');
    backdrop.className = 'forms-public-list-backdrop';
    backdrop.setAttribute('role', 'presentation');
    backdrop.innerHTML = `
      <section class="forms-public-list-dialog" role="dialog" aria-modal="true" aria-labelledby="forms-public-list-title">
        <div class="forms-public-list-dialog__head">
          <div>
            <h2 id="forms-public-list-title">フォーム一覧</h2>
            <p class="small-note">受付中のフォームを先頭に表示し、受付終了後のフォームは下部にまとめて表示します。</p>
          </div>
          <button type="button" class="btn ghost" data-public-list-close>閉じる</button>
        </div>
        <div class="forms-public-list-stats" data-public-list-stats></div>
        <label class="search-box forms-public-list-search">
          <span class="small-note">フォームを検索</span>
          <input type="search" data-public-list-search placeholder="フォーム名・説明・slugで検索" value="${periodEscapeHtml(listSearchQuery)}">
        </label>
        <div data-public-list-body></div>
      </section>
    `;

    backdrop.addEventListener('click', (event) => {
      if (event.target === backdrop || event.target.closest('[data-public-list-close]')) {
        closePublicListDialog();
      }
    });

    backdrop.addEventListener('input', (event) => {
      const input = event.target.closest('[data-public-list-search]');
      if (!input) return;
      listSearchQuery = input.value || '';
      renderPublicListContent(backdrop);
      backdrop.querySelector('[data-public-list-search]')?.focus();
    });

    backdrop.addEventListener('click', (event) => {
      const button = event.target.closest('[data-public-list-select]');
      if (!button) return;
      const form = getGuardFormById(Number(button.dataset.publicListSelect));
      activatePublicFormFromList(form);
    });

    const escapeHandler = (event) => {
      if (event.key === 'Escape') {
        closePublicListDialog();
        document.removeEventListener('keydown', escapeHandler);
      }
    };
    document.addEventListener('keydown', escapeHandler);

    document.body.appendChild(backdrop);
    activeListDialog = backdrop;
    renderPublicListContent(backdrop);
    backdrop.querySelector('[data-public-list-search]')?.focus();
  }

  function rewritePublicFormsApi(input) {
    if (typeof input !== 'string') return input;
    if (!input.includes(TARGET_PUBLIC_FORMS_API)) return input;
    return input.replace(TARGET_PUBLIC_FORMS_API, REPLACEMENT_PUBLIC_FORMS_API);
  }

  if (typeof window.fetch === 'function') {
    const originalFetch = window.fetch.bind(window);
    window.fetch = function guardedFetch(input, init) {
      if (typeof input === 'string') {
        return originalFetch(rewritePublicFormsApi(input), init);
      }
      if (input && typeof input.url === 'string' && input.url.includes(TARGET_PUBLIC_FORMS_API)) {
        return originalFetch(new Request(rewritePublicFormsApi(input.url), input), init);
      }
      return originalFetch(input, init);
    };
  }

  document.addEventListener('click', (event) => {
    const listButton = event.target.closest('#public-drawer-open');
    if (!listButton) return;
    if (!window.matchMedia(DESKTOP_LIST_MEDIA).matches) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    showPublicListDialog();
  }, true);

  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-form-tab]');
    if (!button) return;

    const form = getGuardFormById(Number(button.dataset.formTab));
    if (!form || isAvailabilityOpenGuard(form.availability)) return;

    event.preventDefault();
    event.stopImmediatePropagation();

    try {
      publicState.activeFormId = Number(form.id);
      if (typeof renderSidebar === 'function') renderSidebar();
      if (typeof renderHero === 'function') renderHero();
      renderUnavailablePanel(form, false);
    } catch (error) {
      // noop
    }

    showPeriodDialog(periodMessageForAvailability(form.availability));
  }, true);

  function installRenderGuard() {
    try {
      if (typeof renderActiveForm !== 'function' || typeof getActiveForm !== 'function') return false;
      if (renderActiveForm.__formsPeriodGuardInstalled) return true;

      const originalRenderActiveForm = renderActiveForm;
      renderActiveForm = function guardedRenderActiveForm() {
        const form = getActiveForm();
        if (form && !isAvailabilityOpenGuard(form.availability)) {
          return renderUnavailablePanel(form, true);
        }
        return originalRenderActiveForm.apply(this, arguments);
      };
      renderActiveForm.__formsPeriodGuardInstalled = true;
      renderActiveForm();
      return true;
    } catch (error) {
      return false;
    }
  }

  let attempts = 0;
  const timer = window.setInterval(() => {
    attempts += 1;
    if (installRenderGuard() || attempts > 300) {
      window.clearInterval(timer);
    }
  }, 10);
})();
