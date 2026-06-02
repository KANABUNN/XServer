(function () {
  'use strict';

  const BEFORE_MESSAGE = 'このフォームは提出期間前です。';
  const CLOSED_MESSAGE = 'このフォームの受け付けは終了しました。\n提出物がある際はメールにて連絡してください。\nsogokanri@bene.fit.ac.jp';
  const TARGET_PUBLIC_FORMS_API = 'api/public_forms.php';
  const REPLACEMENT_PUBLIC_FORMS_API = 'api/public_forms_with_closed.php';
  const autoDialogShownFor = new Set();
  let activeDialog = null;

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

  function periodMessageForAvailability(availability) {
    const status = String(availability?.status || '').toLowerCase();
    if (status === 'scheduled') return BEFORE_MESSAGE;
    if (status === 'closed') return CLOSED_MESSAGE;
    return String(availability?.note || '現在このフォームは受付できません。');
  }

  function installPeriodDialogStyles() {
    if (document.getElementById('forms-period-dialog-style')) return;
    const style = document.createElement('style');
    style.id = 'forms-period-dialog-style';
    style.textContent = `
      .forms-period-dialog-backdrop {
        position: fixed;
        inset: 0;
        z-index: 9999;
        display: grid;
        place-items: center;
        padding: 24px;
        background: rgba(15, 23, 42, 0.42);
        backdrop-filter: blur(4px);
      }
      .forms-period-dialog {
        width: min(440px, 100%);
        border-radius: 22px;
        background: #fff;
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.24);
        padding: 24px;
        color: #111827;
      }
      .forms-period-dialog h2 {
        margin: 0 0 12px;
        font-size: 1.18rem;
      }
      .forms-period-dialog__body {
        margin: 0 0 20px;
        line-height: 1.8;
        white-space: normal;
      }
      .forms-period-dialog__actions {
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
      .forms-period-unavailable__mail {
        font-weight: 700;
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
