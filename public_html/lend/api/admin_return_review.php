<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_post();

$admin = api_require_admin();
$data = request_json();

if (!verify_csrf($data['csrf_token'] ?? '')) {
    json_response(['ok' => false, 'message' => 'CSRF トークンが不正です。'], 419);
}

$checkoutId = (int)($data['checkout_id'] ?? 0);
$action = (string)($data['action'] ?? '');
$note = trim((string)($data['note'] ?? ''));

if ($checkoutId <= 0 || !in_array($action, ['complete', 'flag'], true)) {
    json_response(['ok' => false, 'message' => '入力が不正です。'], 422);
}

$newState = $action === 'complete' ? 'completed' : 'flagged';

$stmt = db()->prepare('
    UPDATE checkout_transactions
    SET state = :state,
        return_reviewed_at = NOW(),
        issue_flag = CASE WHEN :state = "flagged" THEN 1 ELSE issue_flag END,
        issue_note = CASE
            WHEN :note IS NULL OR :note = "" THEN issue_note
            WHEN issue_note IS NULL OR issue_note = "" THEN :note
            ELSE CONCAT(issue_note, "\n", :note)
        END
    WHERE id = :id
      AND state IN ("return_declared","flagged")
');
$stmt->execute([
    ':state' => $newState,
    ':note' => $note !== '' ? $note : null,
    ':id' => $checkoutId,
]);

if ($stmt->rowCount() === 0) {
    json_response(['ok' => false, 'message' => '確認対象ではありません。'], 409);
}

audit_log((int)$admin['id'], 'admin', 'return_review_' . $action, 'checkout_transaction', $checkoutId, [
    'note' => $note,
]);

json_response([
    'ok' => true,
    'message' => $action === 'complete' ? '返却確認を完了しました。' : '異常案件として保持しました。',
]);
