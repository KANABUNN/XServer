const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

async function apiGet(url) {
  const response = await fetch(url, {
    method: 'GET',
    credentials: 'same-origin',
  });
  return response.json();
}

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

async function apiPostForm(url, formData) {
  formData.set('csrf_token', csrfToken);
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    body: formData,
  });
  return response.json();
}

function setMessage(element, message, type = 'info') {
  if (!element) return;
  element.className = `alert ${type}`;
  element.textContent = message;
  element.classList.remove('hidden');
}

function clearMessage(element) {
  if (!element) return;
  element.className = 'alert hidden';
  element.textContent = '';
}

function escapeHtml(value = '') {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

(function wireLoginForm() {
  const form = document.getElementById('login-form');
  if (!form) return;
  const message = document.getElementById('login-message');

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    setMessage(message, 'ログイン中です...', 'info');
    const data = Object.fromEntries(new FormData(form).entries());
    const result = await apiPost('api/login.php', data);
    if (!result.ok) {
      setMessage(message, result.message || 'ログインに失敗しました。', 'error');
      return;
    }
    setMessage(message, result.message || 'ログインしました。', 'success');
    window.location.href = result.redirect || 'admin.php';
  });
})();
