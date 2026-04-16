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
      window.location.href = 'logout.php';
    }, seconds * 1000);
  };

  ['click', 'keydown', 'touchstart', 'mousemove'].forEach((name) => {
    document.addEventListener(name, resetTimer, { passive: true });
  });
  resetTimer();
}

async function loadKioskReservations() {
  const result = await apiGet('api/user_data.php');
  if (!result.ok) return;

  const select = document.getElementById('kiosk-reservation-select');
  const approvedReservations = result.reservations.filter((r) => r.status === 'approved');
  select.innerHTML = approvedReservations.length
    ? approvedReservations.map((r) => `<option value="${r.id}">${escapeHtml(r.title)} / ${escapeHtml(r.start_at)}</option>`).join('')
    : '<option value="">承認済み予約がありません</option>';
}

function setKioskStatus(message, type = 'info') {
  const box = document.getElementById('scan-status');
  setMessage(box, message, type);
}

function selectAction(nextAction) {
  kioskState.action = nextAction;
  document.querySelectorAll('[data-kiosk-action]').forEach((button) => {
    const active = button.dataset.kioskAction === nextAction;
    button.classList.toggle('primary', active);
  });

  const issueForm = document.getElementById('issue-form');
  if (nextAction === 'return') {
    showElement(issueForm);
  } else {
    hideElement(issueForm);
  }
}

async function handleDecodedText(decodedText) {
  if (!decodedText) return;
  if (!kioskState.isScanning) return;

  await stopScanner();

  const reservationId = document.getElementById('kiosk-reservation-select').value;
  if (!reservationId) {
    setKioskStatus('承認済み予約を選択してください。', 'error');
    return;
  }

  const issueNote = document.getElementById('issue-note')?.value || '';
  setKioskStatus(`照合中: ${decodedText}`, 'info');

  const result = await apiPost('api/kiosk_action.php', {
    action: kioskState.action,
    reservation_id: reservationId,
    asset_code: decodedText.trim(),
    issue_note: issueNote,
  });

  if (!result.ok) {
    setKioskStatus(result.message || '処理に失敗しました。', 'error');
    return;
  }

  const doneMessage = `${result.message} (${result.result?.asset_name || decodedText})`;
  setKioskStatus(doneMessage, result.result?.state === 'completed' ? 'success' : 'info');
  await loadKioskReservations();
}

async function startScanner() {
  if (kioskState.isScanning) return;

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
    setKioskStatus('カメラを起動しました。QRコードをかざしてください。', 'info');
  } catch (error) {
    setKioskStatus(`カメラ起動に失敗しました: ${error}`, 'error');
  }
}

async function stopScanner() {
  if (!kioskState.scanner || !kioskState.isScanning) return;
  try {
    await kioskState.scanner.stop();
    await kioskState.scanner.clear();
  } catch (_) {
  }
  kioskState.isScanning = false;
}

document.querySelectorAll('[data-kiosk-action]').forEach((button) => {
  button.addEventListener('click', () => selectAction(button.dataset.kioskAction));
});

document.getElementById('start-scan-btn')?.addEventListener('click', startScanner);
document.getElementById('stop-scan-btn')?.addEventListener('click', stopScanner);

selectAction('checkout');
wireIdleLogout();
loadKioskReservations();
