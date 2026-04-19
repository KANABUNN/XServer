<?php
require_once __DIR__ . '/../../../apps/lend_core/bootstrap.php';
require_post();

$admin = api_require_admin();
$data = request_json();

if (!verify_csrf($data['csrf_token'] ?? '')) {
    json_response(['ok' => false, 'message' => 'CSRF トークンが不正です。'], 419);
}

$reservationId = (int)($data['reservation_id'] ?? 0);
$action = (string)($data['action'] ?? '');
$note = trim((string)($data['note'] ?? ''));

if ($reservationId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    json_response(['ok' => false, 'message' => '入力が不正です。'], 422);
}

$newStatus = $action === 'approve' ? 'approved' : 'rejected';
$stmt = db()->prepare('
    UPDATE reservations
    SET status = :status,
        approval_note = :note,
        approved_by = :approved_by,
        approved_at = NOW()
    WHERE id = :id AND status = "pending"
');
$stmt->execute([
    ':status' => $newStatus,
    ':note' => $note !== '' ? $note : null,
    ':approved_by' => $admin['id'],
    ':id' => $reservationId,
]);

if ($stmt->rowCount() === 0) {
    json_response(['ok' => false, 'message' => '対象予約が承認待ちではありません。'], 409);
}

audit_log((int)$admin['id'], 'admin', $action, 'reservation', $reservationId, [
    'note' => $note,
]);

json_response([
    'ok' => true,
    'message' => $action === 'approve' ? '承認しました。' : '却下しました。',
]);
