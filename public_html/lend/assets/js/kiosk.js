let kioskState = {
  action: 'checkout',
  scanner: null,
  isScanning: false,
  idleTimer: null,
};

function wireIdleLogout() {
  const root = document.querySelector('.kiosk-layout');
  if (!root) return;
  const seconds = Number(root.dataset.idleSeconds || 180);

  const resetTimer = () => {
    if (kioskState.idleTimer) clearTimeout(kioskState.idleTimer);
    kioskState.idleTimer = setTimeout(() => {
      // logout.php は CSRF トークンを要求するため付与する
      window.location.href = `logout.php?csrf_token=${encodeURIComponent(csrfToken)}`;
    }, seconds * 1000);
  };

  ['click', 'keydown', 'touchstart', 'mousemove'].forEach((name) => {
    document.addEventListener(name, resetTimer, { passive: true });
  });
  resetTimer();
}

async function loadKioskReservations() {
  const select = document.getElementById('kiosk-reservation-select');
  let result;
  try {
    result = await apiGet('api/user_data.php');
  } catch (error) {
    setKioskStatus('予約を読み込めませんでした。', 'error');
    return;
  }
  if (!result.ok) return;

  const approvedReservations = result.reservations.filter((r) => r.status === 'approved');
  select.innerHTML = approvedReservations.length
    ? approvedReservations.map((r) => `<option value="${r.id}">${escapeHtml(r.title)} ／ ${escapeHtml(String(r.start_at || '').replace('T', ' ').slice(0, 16))}</option>`).join('')
    : '<option value="">承認済みの予約がありません</option>';
}

function setKioskStatus(message, type = 'info') {
  const box = document.getElementById('scan-status');
  setMessage(box, message, type);
  box?.animate?.([{ transform: 'scale(.98)', opacity: 0.6 }, { transform: 'none', opacity: 1 }], { duration: 280, easing: 'cubic-bezier(.16,1,.3,1)' });
}

function selectAction(nextAction) {
  kioskState.action = nextAction;
  document.querySelectorAll('[data-kiosk-action]').forEach((button) => {
    const active = button.dataset.kioskAction === nextAction;
    button.classList.toggle('is-active', active);
    button.setAttribute('aria-checked', active ? 'true' : 'false');
  });

  const issueForm = document.getElementById('issue-form');
  if (nextAction === 'return') {
    showElement(issueForm);
  } else {
    hideElement(issueForm);
  }
}

function setScanningUi(scanning) {
  document.getElementById('scanner-placeholder')?.classList.toggle('hidden', scanning);
  document.querySelector('.ld-scanner')?.classList.toggle('is-scanning', scanning);
}

async function handleDecodedText(decodedText) {
  if (!decodedText) return;
  if (!kioskState.isScanning) return;

  await stopScanner();

  const reservationId = document.getElementById('kiosk-reservation-select').value;
  if (!reservationId) {
    setKioskStatus('承認済みの予約を選択してください。', 'error');
    return;
  }

  const issueNote = document.getElementById('issue-note')?.value || '';
  setKioskStatus(`照合しています：${decodedText}`, 'info');

  let result;
  try {
    result = await apiPost('api/kiosk_action.php', {
      action: kioskState.action,
      reservation_id: reservationId,
      asset_code: decodedText.trim(),
      issue_note: issueNote,
    });
  } catch (error) {
    setKioskStatus('通信に失敗しました。もう一度お試しください。', 'error');
    return;
  }

  if (!result.ok) {
    setKioskStatus(result.message || '処理に失敗しました。', 'error');
    return;
  }

  const doneMessage = `${result.message}（${result.result?.asset_name || decodedText}）`;
  setKioskStatus(doneMessage, result.result?.state === 'completed' ? 'success' : 'info');
  await loadKioskReservations();
}

async function startScanner() {
  if (kioskState.isScanning) return;
  if (typeof Html5Qrcode === 'undefined') {
    setKioskStatus('QR リーダーを読み込めませんでした。ネットワークを確認してください。', 'error');
    return;
  }

  kioskState.scanner = new Html5Qrcode('reader');
  try {
    await kioskState.scanner.start(
      { facingMode: 'environment' },
      {
        fps: 10,
        qrbox: { width: 240, height: 240 },
        rememberLastUsedCamera: true,
      },
      handleDecodedText,
      () => {}
    );
    kioskState.isScanning = true;
    setScanningUi(true);
    setKioskStatus('カメラを起動しました。QR コードをかざしてください。', 'info');
  } catch (error) {
    setKioskStatus(`カメラを起動できませんでした：${error}`, 'error');
  }
}

async function stopScanner() {
  if (!kioskState.scanner || !kioskState.isScanning) return;
  try {
    await kioskState.scanner.stop();
    await kioskState.scanner.clear();
  } catch (_) {
    // noop
  }
  kioskState.isScanning = false;
  setScanningUi(false);
}

document.querySelectorAll('[data-kiosk-action]').forEach((button) => {
  button.addEventListener('click', () => selectAction(button.dataset.kioskAction));
});

document.getElementById('start-scan-btn')?.addEventListener('click', startScanner);
document.getElementById('stop-scan-btn')?.addEventListener('click', stopScanner);

selectAction('checkout');
wireIdleLogout();
loadKioskReservations();
