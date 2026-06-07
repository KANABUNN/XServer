const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

function makeClientRequestId() {
  const rand = (typeof crypto !== 'undefined' && crypto.getRandomValues)
    ? Array.from(crypto.getRandomValues(new Uint8Array(4)), (b) => b.toString(16).padStart(2, '0')).join('')
    : Math.random().toString(16).slice(2, 10);
  return 'C-' + Date.now().toString(36) + '-' + rand;
}

// code -> 利用者向けメッセージ
function apiErrorUserMessage(code) {
  switch (code) {
    case 'NETWORK':
      return 'ネットワークに接続できませんでした。電波・接続状況をご確認のうえ、再度お試しください。';
    case 'TIMEOUT':
      return '通信がタイムアウトしました。回線が不安定な可能性があります。少し時間をおいて再送信してください。';
    case 'ABORTED':
      return '送信が中断されました。ページを離れずに、もう一度お試しください。';
    case 'EMPTY_RESPONSE':
      return 'サーバーから空の応答が返されました。時間をおいて再度お試しください。';
    case 'NON_JSON':
      return 'サーバーから想定外の応答が返されました。学内・公共Wi-Fiのログイン（認証ページ）や、拡張機能・プロキシの影響が考えられます。';
    default:
      return '通信に失敗しました。時間をおいて再度お試しください。';
  }
}

// 構造化APIエラー：code で原因分類、status/requestId/detail を保持。
class ApiError extends Error {
  constructor(code, { status = 0, requestId = '', detail = '' } = {}) {
    super(apiErrorUserMessage(code));
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
    this.requestId = requestId;
    this.detail = detail;
  }
}

// 利用者向けメッセージと、サポート連絡用の技術詳細(コード/状態/参照ID)を返す。
function describeApiError(error) {
  if (error instanceof ApiError) {
    const parts = ['コード: ' + error.code];
    if (error.status) parts.push('状態: ' + error.status);
    if (error.requestId) parts.push('参照ID: ' + error.requestId);
    return { code: error.code, message: error.message, detail: parts.join(' / ') };
  }
  return {
    code: 'UNKNOWN',
    message: apiErrorUserMessage('UNKNOWN'),
    detail: 'コード: UNKNOWN' + (error?.message ? ' / ' + String(error.message).slice(0, 120) : ''),
  };
}

function apiBackoffDelay(attempt) {
  const base = 400 * Math.pow(2, attempt); // 400ms, 800ms, 1600ms...
  return Math.min(base + Math.floor(Math.random() * 200), 2000); // ジッタ付き、上限2s
}
function apiSleep(ms) {
  return new Promise((resolve) => window.setTimeout(resolve, ms));
}

// fetch 本体：タイムアウト(AbortController)＋瞬断/タイムアウト/指定HTTP状態の再試行。
// 非idempotentなPOSTでは retries を低く・retryStatuses を空に保つこと(二重送信回避)。
async function apiFetch(url, fetchOptions = {}, opts = {}) {
  const { timeoutMs = 12000, retries = 0, retryStatuses = [], requestId = '', onRetry = null } = opts;
  let lastError = null;

  for (let attempt = 0; attempt <= retries; attempt += 1) {
    const controller = new AbortController();
    let timedOut = false;
    const timer = window.setTimeout(() => { timedOut = true; controller.abort(); }, timeoutMs);

    try {
      const response = await fetch(url, { ...fetchOptions, signal: controller.signal });
      window.clearTimeout(timer);

      if (retryStatuses.includes(response.status) && attempt < retries) {
        if (onRetry) onRetry({ attempt, reason: 'status', status: response.status });
        await apiSleep(apiBackoffDelay(attempt));
        continue;
      }
      return response;
    } catch (err) {
      window.clearTimeout(timer);

      let code = 'NETWORK';
      if (timedOut) code = 'TIMEOUT';
      else if (err && err.name === 'AbortError') code = 'ABORTED';
      lastError = new ApiError(code, { requestId });

      // ABORTED(ページ離脱等)は再試行しない。NETWORK/TIMEOUT のみ瞬断として掬う。
      if ((code === 'NETWORK' || code === 'TIMEOUT') && attempt < retries) {
        if (onRetry) onRetry({ attempt, reason: code });
        await apiSleep(apiBackoffDelay(attempt));
        continue;
      }
      throw lastError;
    }
  }
  throw lastError || new ApiError('NETWORK', { requestId });
}

async function readApiJson(response, requestId = '') {
  const text = await response.text();
  if (!text) {
    throw new ApiError('EMPTY_RESPONSE', { status: response.status, requestId });
  }
  let data = null;
  try {
    data = JSON.parse(text);
  } catch (error) {
    const snippet = text.replace(/<[^>]*>/g, '').trim().slice(0, 300);
    throw new ApiError('NON_JSON', { status: response.status, requestId, detail: snippet });
  }
  if (!data || typeof data !== 'object') {
    throw new ApiError('NON_JSON', { status: response.status, requestId });
  }
  if (!response.ok && data.ok !== false) {
    data.ok = false;
  }
  return data;
}

async function apiGet(url, opts = {}) {
  const requestId = makeClientRequestId();
  const response = await apiFetch(url, {
    method: 'GET',
    credentials: 'same-origin',
  }, {
    timeoutMs: 12000,
    retries: 2,                       // GETはidempotentなので積極的に瞬断を掬う
    retryStatuses: [502, 503, 504],   // ゲートウェイ系のみ。500(アプリJSON)は再試行しない
    requestId,
    ...opts,
  });
  return readApiJson(response, requestId);
}

async function apiPost(url, payload = {}, opts = {}) {
  const requestId = makeClientRequestId();
  const response = await apiFetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ csrf_token: csrfToken, client_request_id: requestId, ...payload }),
  }, {
    timeoutMs: 15000,
    retries: 0,   // 非idempotent：既定は再試行しない(呼び出し側で明示的に有効化)
    requestId,
    ...opts,
  });
  return readApiJson(response, requestId);
}

async function apiPostForm(url, formData, opts = {}) {
  const requestId = makeClientRequestId();
  formData.set('csrf_token', csrfToken);
  formData.set('client_request_id', requestId);
  const response = await apiFetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    body: formData,
  }, {
    timeoutMs: 20000,  // 添付アップロードを考慮し長め
    retries: 0,        // 既定は再試行しない(呼び出し側で明示的に有効化)
    requestId,
    ...opts,
  });
  return readApiJson(response, requestId);
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
  if (options.detail) {
    const detail = document.createElement('small');
    detail.className = 'flash-message__detail';
    detail.textContent = options.detail;
    body.appendChild(detail);
  }
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
