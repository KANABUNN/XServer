function enableOutsideClose(dlg) {
  dlg.addEventListener("click", (e) => {
    const r = dlg.getBoundingClientRect();
    const x = e.clientX;
    const y = e.clientY;
    const inside = r.left <= x && x <= r.right && r.top <= y && y <= r.bottom;
    if (!inside) dlg.close();
  });
}

const fileDlg = document.getElementById("fileDialog");
const openFileButton = document.getElementById("openfile");

if (fileDlg && openFileButton) {
  openFileButton.addEventListener("click", () => fileDlg.showModal());
  enableOutsideClose(fileDlg);
}

const termsDlg = document.getElementById("termsDialog");
const openTermsBtn = document.getElementById("openTerms");
const closeTermsBtn = document.getElementById("closeTerms");
const termsFrame = document.getElementById("termsFrame");

if (termsDlg && openTermsBtn) {
  openTermsBtn.addEventListener("click", () => {
    if (termsFrame) termsFrame.src = "terms.html?v=20260401c";
    termsDlg.showModal();
  });

  if (closeTermsBtn) closeTermsBtn.addEventListener("click", () => termsDlg.close());
  enableOutsideClose(termsDlg);
}

/* -----------------------------
 * 送信ボタン：同意まで無効化（任意・UX改善）
 * ※ required だけでも送信は止まる
 * ----------------------------- */
const agree = document.getElementById("agreeTerms");
const submitBtn = document.querySelector(".submit-btn");

if (agree && submitBtn) {
  const sync = () => { submitBtn.disabled = !agree.checked; };
  sync();
  agree.addEventListener("change", sync);
}