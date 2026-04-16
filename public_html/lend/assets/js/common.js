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

function setMessage(element, message, type = 'info') {
  if (!element) return;
  element.className = `alert ${type}`;
  element.textContent = message;
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

function statusBadge(status) {
  const labelMap = {
    pending: '承認待ち',
    approved: '承認済み',
    rejected: '却下',
    checked_out: '貸出中',
    return_declared: '返却確認待ち',
    completed: '返却完了',
    flagged: '異常案件',
    reserved: '未貸出',
    cancelled: '取消',
  };
  const label = labelMap[status] || status;
  return `<span class="badge ${escapeHtml(status)}">${escapeHtml(label)}</span>`;
}

(function wireLoginForm() {
  const form = document.getElementById('login-form');
  if (!form) return;
  const message = document.getElementById('login-message');

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    setMessage(message, 'ログイン中です...', 'info');
    showElement(message);

    const data = Object.fromEntries(new FormData(form).entries());
    const result = await apiPost('api/login.php', data);

    if (!result.ok) {
      setMessage(message, result.message || 'ログインに失敗しました。', 'error');
      return;
    }

    setMessage(message, result.message || 'ログインしました。', 'success');
    window.location.href = result.redirect || 'index.php';
  });
})();
