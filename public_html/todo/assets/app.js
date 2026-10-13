// 会議・ToDo（送信中表示・確認ダイアログ・タブ・トーストは共通 UI が担当）
(function () {
  'use strict';

  // 行クリックで詳細へ
  document.querySelectorAll('tr[data-href]').forEach(function (row) {
    row.addEventListener('click', function (event) {
      if (event.target.closest('a, button, input, select, label, form')) return;
      window.location.href = row.dataset.href;
    });
  });

  // 進捗スライダーの値表示
  document.querySelectorAll('input[type="range"][data-range-output]').forEach(function (range) {
    var out = range.closest('.field') && range.closest('.field').querySelector('output');
    var sync = function () {
      if (out) out.textContent = range.value + '%';
      range.style.setProperty('--range-pct', range.value + '%');
    };
    range.addEventListener('input', sync);
    sync();
  });

  // 会議詳細: #agendas / #tasks / #agenda-1 / #task-1 で該当タブと項目を開く
  var tabsBar = document.querySelector('[data-tabs]');
  if (tabsBar) {
    var hash = decodeURIComponent(window.location.hash.slice(1));
    var tabName = '';
    if (hash === 'tasks' || hash.indexOf('task-') === 0) tabName = 'tasks';
    else if (hash === 'records') tabName = 'records';
    else if (hash === 'agendas' || hash.indexOf('agenda-') === 0) tabName = 'agendas';
    if (tabName) {
      var preset = tabsBar.querySelector('[data-tab="' + tabName + '"]');
      if (preset) preset.classList.add('is-active');
    }
    var target = /^(task|agenda)-\d+$/.test(hash) ? document.getElementById(hash) : null;
    if (target && target.tagName === 'DETAILS') {
      target.open = true;
      target.classList.add('is-highlight');
      window.addEventListener('load', function () {
        target.scrollIntoView({ block: 'center', behavior: 'smooth' });
        window.setTimeout(function () { target.classList.remove('is-highlight'); }, 2400);
      });
    }
    tabsBar.addEventListener('ui:tab', function (event) {
      if (!window.history.replaceState) return;
      window.history.replaceState(null, '', '#' + event.detail.tab);
    });
  }
})();
