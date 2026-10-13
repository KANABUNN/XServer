// バックアップ管理（CSP: script-src 'self' のためインライン処理は使わない）
(function () {
  'use strict';

  // 行クリックで詳細へ
  document.querySelectorAll('tr[data-href]').forEach(function (row) {
    row.addEventListener('click', function (event) {
      if (event.target.closest('a, button, input, select, label, form')) return;
      window.location.href = row.dataset.href;
    });
  });

  // アラートの「解決済みにする」: 共通ダイアログに対象を差し込んで開く
  var dialog = document.getElementById('resolveDialog');
  if (dialog) {
    var idInput = dialog.querySelector('input[name="id"]');
    var note = dialog.querySelector('textarea[name="note"]');
    var message = dialog.querySelector('[data-resolve-message]');
    document.addEventListener('click', function (event) {
      var button = event.target.closest('[data-resolve-alert]');
      if (!button || !window.UI) return;
      event.preventDefault();
      idInput.value = button.dataset.resolveAlert;
      message.textContent = button.dataset.alertMessage || '';
      if (note) note.value = '';
      UI.openModal(dialog, { focus: note });
    });
  }
})();
