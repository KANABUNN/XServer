const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

async function readApiJson(response) {
  const text = await response.text();
  let data = null;

  if (text) {
    try {
      data = JSON.parse(text);
    } catch (error) {
      data = null;
    }
  }

  if (!data || typeof data !== 'object') {
    const fallbackMessage = text
      ? text.replace(/<[^>]*>/g, '').trim().slice(0, 300)
      : 'サーバーから空の応答が返されました。';
    throw new Error(fallbackMessage || 'サーバー応答を解析できませんでした。');
  }

  if (!response.ok && data.ok !== false) {
    data.ok = false;
  }

  return data;
}

async function apiGet(url) {
  const response = await fetch(url, {
    method: 'GET',
    credentials: 'same-origin',
  });
  return readApiJson(response);
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
  return readApiJson(response);
}

async function apiPostForm(url, formData) {
  formData.set('csrf_token', csrfToken);
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    body: formData,
  });
  return readApiJson(response);
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

function ensureFlashStack() {
  let stack = document.getElementById('flash-message-stack');
  if (stack) return stack;
  stack = document.createElement('div');
  stack.id = 'flash-message-stack';
  stack.className = 'flash-message-stack';
  document.body.appendChild(stack);
  return stack;
}

function dismissFlashMessage(element) {
  if (!element) return;
  element.classList.remove('visible');
  element.classList.add('leaving');
  window.setTimeout(() => {
    element.remove();
  }, 240);
}

function showFlashMessage(message, type = 'info', options = {}) {
  if (!message) return null;
  const duration = Number.isFinite(options.duration) ? options.duration : 4200;
  const stack = ensureFlashStack();
  const item = document.createElement('div');
  item.className = `flash-message ${type}`;

  const body = document.createElement('div');
  body.className = 'flash-message__body';

  const title = document.createElement('strong');
  title.className = 'flash-message__title';
  title.textContent = options.title || (type === 'success' ? '完了' : type === 'error' ? 'エラー' : 'お知らせ');

  const text = document.createElement('div');
  text.className = 'flash-message__text';
  text.textContent = message;

  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'flash-message__close';
  close.setAttribute('aria-label', '閉じる');
  close.textContent = '×';
  close.addEventListener('click', () => dismissFlashMessage(item));

  body.appendChild(title);
  body.appendChild(text);
  item.appendChild(body);
  item.appendChild(close);
  stack.appendChild(item);

  requestAnimationFrame(() => item.classList.add('visible'));
  if (duration > 0) {
    window.setTimeout(() => dismissFlashMessage(item), duration);
  }
  return item;
}

function parseDownloadFilename(response, fallbackName = 'download') {
  const disposition = response.headers.get('Content-Disposition') || '';
  const utf8Match = disposition.match(/filename\*=UTF-8''([^;]+)/i);
  if (utf8Match && utf8Match[1]) {
    try {
      return decodeURIComponent(utf8Match[1]);
    } catch (error) {
      return utf8Match[1];
    }
  }
  const quotedMatch = disposition.match(/filename="([^"]+)"/i);
  if (quotedMatch && quotedMatch[1]) {
    return quotedMatch[1];
  }
  return fallbackName;
}

async function downloadBinaryFile(url, fallbackName = 'download') {
  const response = await fetch(url, {
    method: 'GET',
    credentials: 'same-origin',
  });

  if (!response.ok) {
    const contentType = response.headers.get('Content-Type') || '';
    if (contentType.includes('application/json')) {
      const data = await response.json().catch(() => ({}));
      throw new Error(data.message || 'ダウンロードに失敗しました。');
    }
    const text = await response.text().catch(() => '');
    throw new Error(text || 'ダウンロードに失敗しました。');
  }

  const blob = await response.blob();
  const filename = parseDownloadFilename(response, fallbackName);
  const objectUrl = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = objectUrl;
  anchor.download = filename;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
  return filename;
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


(function enforceNoSelection() {
  if (!document.body.matches('.forms-public-page, .forms-admin-page')) return;
  document.addEventListener('selectstart', (event) => {
    if (event.target.closest('input, textarea, select, option, [contenteditable="true"], .allow-select')) {
      return;
    }
    event.preventDefault();
  });
})();
