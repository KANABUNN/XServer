// 共通アカウント管理（フラッシュ・確認ダイアログは共通 UI が担当）
document.addEventListener('DOMContentLoaded', () => {
  const dialog = document.getElementById('accountDialog');
  const idInput = dialog?.querySelector('input[name="id"]');

  // 行クリックで詳細を開く
  document.querySelectorAll('tr[data-href]').forEach((row) => {
    row.addEventListener('click', (event) => {
      if (event.target.closest('a, button, input, label')) return;
      window.location.href = row.dataset.href;
    });
  });

  // 新規作成: 画面上のダイアログが新規用ならページ遷移せずに開く
  document.querySelectorAll('[data-new-account]').forEach((link) => {
    link.addEventListener('click', (event) => {
      if (!dialog || !window.UI || !idInput || idInput.value !== '0') return;
      event.preventDefault();
      dialog.querySelector('form')?.reset();
      UI.openModal(dialog);
    });
  });

  // 閉じたら URL から ?edit= を外す（再読み込みで再び開かないように）
  dialog?.addEventListener('ui:close', () => {
    const url = new URL(window.location.href);
    if (!url.searchParams.has('edit')) return;
    url.searchParams.delete('edit');
    window.history.replaceState(null, '', url.pathname + url.search);
  });
});
