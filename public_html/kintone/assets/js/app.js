// kintone管理（確認ダイアログ・トースト・タブなどは共通 UI が担当）
(function () {
  'use strict';

  // 行クリックで詳細へ
  document.querySelectorAll('tr[data-href]').forEach(function (row) {
    row.addEventListener('click', function (event) {
      if (event.target.closest('a, button, input, select, label, form, .menu')) return;
      window.location.href = row.dataset.href;
    });
  });

  // 「すべて選択」と選択件数
  var updateCount = function (form) {
    var out = form && form.querySelector('[data-selected-count]');
    if (!out) return;
    var all = form.querySelectorAll('tbody input[type="checkbox"]:not(:disabled)');
    var checked = Array.prototype.filter.call(all, function (c) { return c.checked; }).length;
    out.textContent = all.length ? '（' + checked + '件選択中）' : '';
  };
  document.querySelectorAll('[data-check-all]').forEach(function (master) {
    var form = master.closest('form');
    var name = master.dataset.checkAll;
    var boxes = function (visibleOnly) {
      return Array.prototype.filter.call(form.querySelectorAll('input[type="checkbox"][name="' + name + '"]'), function (c) {
        return !c.disabled && (!visibleOnly || !c.closest('tr').hidden);
      });
    };
    var sync = function () {
      var list = boxes(true);
      var checked = list.filter(function (c) { return c.checked; }).length;
      master.checked = list.length > 0 && checked === list.length;
      master.indeterminate = checked > 0 && checked < list.length;
      updateCount(form);
    };
    master.addEventListener('change', function () {
      boxes(true).forEach(function (c) { c.checked = master.checked; });
      sync();
    });
    form.addEventListener('change', function (event) { if (event.target !== master) sync(); });
    form.addEventListener('kt:filtered', sync);
    sync();
  });

  // 差分のリスク絞り込み
  document.querySelectorAll('[data-risk-filter]').forEach(function (group) {
    var form = group.closest('form') || document;
    group.addEventListener('click', function (event) {
      var button = event.target.closest('button[data-risk]');
      if (!button) return;
      var risk = button.dataset.risk;
      group.querySelectorAll('button').forEach(function (b) {
        var on = b === button;
        b.classList.toggle('is-active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      form.querySelectorAll('tbody tr[data-risk]').forEach(function (tr) {
        tr.hidden = risk !== 'all' && tr.dataset.risk !== risk;
      });
      form.dispatchEvent(new CustomEvent('kt:filtered'));
    });
  });

  // 接続テスト（API は JSON を返すので画面遷移せずに結果を表示）
  document.querySelectorAll('form[data-kt-test-connection]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.fetch || !window.UI) return;
      event.preventDefault();
      var button = form.querySelector('[type="submit"]');
      if (button) button.disabled = true;
      var pending = UI.toast('kintone に接続しています…', { type: 'info', duration: 0 });
      fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (res) {
          return res.text().then(function (text) {
            var data = null;
            try { data = JSON.parse(text); } catch (e) { data = { ok: false, message: text || '接続テストに失敗しました。' }; }
            return data;
          });
        })
        .then(function (data) {
          if (pending && pending.close) pending.close();
          if (data && data.ok) {
            var name = data.app && data.app.name ? '（' + data.app.name + '）' : '';
            UI.toast('kintoneアプリに接続できました' + name + '。', { type: 'success', title: '接続テスト成功' });
            window.setTimeout(function () { window.location.reload(); }, 1400);
          } else {
            UI.toast((data && data.message) || '接続テストに失敗しました。', { type: 'error', title: '接続テスト失敗', duration: 0 });
            window.setTimeout(function () { window.location.reload(); }, 2600);
          }
        })
        .catch(function () {
          if (pending && pending.close) pending.close();
          UI.toast('通信に失敗しました。', { type: 'error' });
        })
        .then(function () { if (button) button.disabled = false; });
    });
  });

  // アプリ設定: 追加ボタンはページ遷移せずにダイアログを開く（新規用のとき）
  var appDialog = document.getElementById('appDialog');
  if (appDialog && window.UI) {
    var originalKey = appDialog.querySelector('input[name="original_app_key"]');
    document.querySelectorAll('[data-new-app]').forEach(function (link) {
      link.addEventListener('click', function (event) {
        if (!originalKey || originalKey.value !== '') return;
        event.preventDefault();
        UI.openModal(appDialog);
      });
    });
    appDialog.addEventListener('ui:close', function () {
      var url = new URL(window.location.href);
      if (!url.searchParams.has('app_key')) return;
      url.searchParams.delete('app_key');
      window.history.replaceState(null, '', url.pathname + url.search);
    });
  }
})();
