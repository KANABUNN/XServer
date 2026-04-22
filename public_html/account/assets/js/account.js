document.addEventListener('DOMContentLoaded', () => {
  const closeFlash = (el) => {
    if (!el || el.classList.contains('is-hiding')) return;
    el.classList.add('is-hiding');
    setTimeout(() => el.remove(), 260);
  };

  document.querySelectorAll('.flash-close').forEach((button) => {
    button.addEventListener('click', () => closeFlash(button.closest('.flash')));
  });

  document.querySelectorAll('.flash[data-auto-dismiss="1"]').forEach((el) => {
    setTimeout(() => closeFlash(el), 5000);
  });
});


document.querySelectorAll('form[data-confirm]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    const message = form.getAttribute('data-confirm') || 'この操作を実行します。よろしいですか？';
    if (!window.confirm(message)) {
      event.preventDefault();
    }
  });
});
