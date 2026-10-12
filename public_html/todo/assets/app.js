document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', () => {
      const submitters = form.querySelectorAll('button[type="submit"]');
      submitters.forEach((btn) => {
        btn.disabled = true;
        btn.dataset.originalText = btn.textContent;
        btn.textContent = '送信中...';
      });
    });
  });
});
