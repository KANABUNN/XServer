const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

async function apiPost(url, payload = {}) {
  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    credentials: 'same-origin',
    body: JSON.stringify({ csrf_token: csrfToken, ...payload }),
  });
  return response.json();
}

async function apiGet(url) {
  const response = await fetch(url, {
    method: 'GET',
    credentials: 'same-origin',
  });
  return response.json();
}

const ALERT_TONES = {
  info: ['info', 'info'],
  success: ['success', 'check-circle'],
  error: ['danger', 'x-circle'],
  warning: ['warning', 'alert-triangle'],
};

function setMessage(element, message, type = 'info') {
  if (!element) return;
  const [tone, iconName] = ALERT_TONES[type] || ALERT_TONES.info;
  element.className = `alert alert--${tone}${element.classList.contains('ld-scan-status') ? ' ld-scan-status' : ''}`;
  element.innerHTML = `${window.UI ? UI.icon(iconName) : ''}<div class="alert__body"></div>`;
  element.querySelector('.alert__body').textContent = message;
}

function hideElement(element) {
  if (element) element.classList.add('hidden');
}

function showElement(element) {
  if (element) element.classList.remove('hidden');
}

function escapeHtml(value = '') {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

const LEND_STATUS = {
  pending: ['承認待ち', 'info'],
  approved: ['承認済み', 'success'],
  rejected: ['却下', 'danger'],
  checked_out: ['貸出中', 'accent'],
  return_declared: ['返却確認待ち', 'warning'],
  completed: ['返却完了', ''],
  flagged: ['異常案件', 'danger'],
  reserved: ['未貸出', 'info'],
  cancelled: ['取消', ''],
};

function statusBadge(status) {
  const [label, tone] = LEND_STATUS[status] || [status, ''];
  return `<span class="badge badge--dot${tone ? ` badge--${tone}` : ''}">${escapeHtml(label)}</span>`;
}

function emptyState(message, iconName = 'inbox') {
  return `<div class="empty"><span class="empty__icon">${window.UI ? UI.icon(iconName) : ''}</span><span>${escapeHtml(message)}</span></div>`;
}

(function wireLoginForm() {
  const form = document.getElementById('login-form');
  if (!form) return;
  const message = document.getElementById('login-message');

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = form.querySelector('[type="submit"]');
    button?.classList.add('is-loading');
    hideElement(message);
    try {
      const data = Object.fromEntries(new FormData(form).entries());
      const result = await apiPost('api/login.php', data);
      if (!result.ok) {
        setMessage(message, result.message || 'ログインに失敗しました。', 'error');
        showElement(message);
        return;
      }
      window.location.href = result.redirect || 'index.php';
    } catch (error) {
      setMessage(message, '通信に失敗しました。時間をおいて再度お試しください。', 'error');
      showElement(message);
    } finally {
      button?.classList.remove('is-loading');
    }
  });
})();
