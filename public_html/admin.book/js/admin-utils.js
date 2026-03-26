/* admin-utils.js
 * - 小物（フォーマット/エスケープ/ステータス表示など）
 */
(function () {
  'use strict';

  const Admin = (window.Admin = window.Admin || {});
  const el = () => Admin.el || {};

  function formatMonthValue(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    return `${y}-${m}`;
  }

  function formatDateValue(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }

  function setStatus(message, isError = false) {
    const { statusText } = el();
    if (!statusText) return;
    statusText.textContent = message || '';
    statusText.style.color = isError ? '#c62828' : '#5e6b7a';
  }

  function setElementStatus(targetEl, message, tone = '') {
    if (!targetEl) return;
    targetEl.textContent = message || '';
    targetEl.className = 'status';
    if (tone === 'error') targetEl.classList.add('status-error');
    if (tone === 'ok') targetEl.classList.add('status-ok');
  }

  function labelForSort(value) {
    const labels = {
      created_at: '受付日時',
      id: 'ID',
      email: 'メールアドレス',
      room: '予約部屋',
      original_name: '元の名前',
      stored_name: '保存後の名前',
      note: '備考',
    };
    return labels[value] || value;
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
  }

  function formatBytes(bytes) {
    if (!Number.isFinite(bytes) || bytes < 0) return '-';
    if (bytes < 1024) return `${bytes} B`;
    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let unit = units[0];
    for (let i = 1; i < units.length && value >= 1024; i += 1) {
      value /= 1024;
      unit = units[i];
    }
    return `${value.toFixed(value >= 10 ? 1 : 2)} ${unit}`;
  }

  function shortenText(value, maxLength = 60) {
    const text = String(value || '');
    if (text.length <= maxLength) return text;
    return `${text.slice(0, maxLength)}…`;
  }

  function nl2br(value) {
    return String(value).replace(/\n/g, '<br>');
  }

  function roomLabel(code) {
    const value = String(code || '').trim();
    const map = (Admin.constants && Admin.constants.roomLabelMap) || {};
    return map[value] || value || '—';
  }

  function normalizePeopleCountForSend(value) {
    const v = String(value ?? '').trim();
    if (v === '') return null;
    if (!/^\d+$/.test(v) || Number(v) < 1) {
      throw new Error('人数は 1 以上の整数で入力してください。');
    }
    return Number(v);
  }

  function peopleCountLabel(value) {
    const v = String(value ?? '').trim();
    return v !== '' ? `${v}人` : '—';
  }

  function usageTimeLabel(value) {
    const v = String(value ?? '').trim();
    return v !== '' ? v : '—';
  }

  function buildCalendarExtraMeta(entry) {
    const parts = [];
    const people = String(entry.people_count ?? '').trim();
    const time = String(entry.usage_time ?? '').trim();

    if (people !== '') parts.push(`人数 ${people}人`);
    if (time !== '') parts.push(`利用時間 ${time}`);

    return parts.join(' / ');
  }

  function bindDialogBackdropClose(dialog) {
    if (!dialog || typeof dialog.addEventListener !== 'function') return;
    dialog.addEventListener('click', (event) => {
      const rect = dialog.getBoundingClientRect();
      const clickedInside = rect.top <= event.clientY && event.clientY <= rect.bottom
        && rect.left <= event.clientX && event.clientX <= rect.right;
      if (!clickedInside) dialog.close();
    });
  }

  // -------------------------------------------------------
  // 表示形式（表 / カード）切り替え（スマホで縦スクロール読みに最適化）
  // - body[data-view-mode] を切り替えて、CSS側で表示を制御
  // - localStorage に保存（ユーザーが切替えたら固定）
  // -------------------------------------------------------
  const VIEW_MODE_KEY = 'admin.book.viewMode';
  const VIEW_MODE_BREAKPOINT = 768; // px
  const VALID_VIEW_MODES = ['table', 'card'];

  function readStoredViewMode() {
    try {
      const v = localStorage.getItem(VIEW_MODE_KEY);
      return VALID_VIEW_MODES.includes(v) ? v : null;
    } catch (_) {
      return null;
    }
  }

  function writeStoredViewMode(mode) {
    try {
      localStorage.setItem(VIEW_MODE_KEY, mode);
    } catch (_) {
      // ignore
    }
  }

  function resolveInitialViewMode() {
    const stored = readStoredViewMode();
    if (stored) return stored;
    const mql = window.matchMedia(`(max-width: ${VIEW_MODE_BREAKPOINT}px)`);
    return mql.matches ? 'card' : 'table';
  }

  function applyViewMode(mode) {
    if (!VALID_VIEW_MODES.includes(mode)) return;

    // body が存在しないタイミング対策
    const body = document.body || document.documentElement;
    if (body && body.dataset) {
      body.dataset.viewMode = mode;
    }

    // ボタン状態（複数箇所にあってもまとめて反映）
    document.querySelectorAll('[data-view-mode-btn]').forEach((btn) => {
      const btnMode = btn.dataset.viewMode;
      const active = btnMode === mode;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  }

  function setViewMode(mode, persist = true) {
    if (!VALID_VIEW_MODES.includes(mode)) return;
    applyViewMode(mode);
    if (persist) writeStoredViewMode(mode);
  }

  function initViewMode() {
    // 初期適用
    const stored = readStoredViewMode();
    applyViewMode(stored || resolveInitialViewMode());

    // ボタン（表/カード）クリック
    document.addEventListener('click', (event) => {
      const btn = event.target.closest('[data-view-mode-btn]');
      if (!btn) return;
      const mode = btn.dataset.viewMode;
      setViewMode(mode, true);
    });

    // 未保存の場合のみ、画面幅変更に追従（保存されていれば固定）
    const mql = window.matchMedia(`(max-width: ${VIEW_MODE_BREAKPOINT}px)`);
    const handleChange = () => {
      if (readStoredViewMode()) return;
      applyViewMode(mql.matches ? 'card' : 'table');
    };

    if (mql && typeof mql.addEventListener === 'function') {
      mql.addEventListener('change', handleChange);
    } else if (mql && typeof mql.addListener === 'function') {
      mql.addListener(handleChange);
    }
  }


  Admin.utils = {
    formatMonthValue,
    formatDateValue,
    setStatus,
    setElementStatus,
    labelForSort,
    escapeHtml,
    formatBytes,
    shortenText,
    nl2br,
    roomLabel,
    normalizePeopleCountForSend,
    peopleCountLabel,
    usageTimeLabel,
    buildCalendarExtraMeta,
    bindDialogBackdropClose,
    initViewMode,
    setViewMode,
    applyViewMode,
  };
})();
