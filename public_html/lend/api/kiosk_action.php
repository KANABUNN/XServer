<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_post();

$user = api_require_login();
$data = request_json();

if (!verify_csrf($data['csrf_token'] ?? '')) {
    json_response(['ok' => false, 'message' => 'CSRF トークンが不正です。'], 419);
}

$action = (string)($data['action'] ?? '');
$reservationId = (int)($data['reservation_id'] ?? 0);
$assetCode = trim((string)($data['asset_code'] ?? ''));
$issueNote = trim((string)($data['issue_note'] ?? ''));

if (!in_array($action, ['checkout', 'return'], true)) {
    json_response(['ok' => false, 'message' => '操作種別が不正です。'], 422);
}
if ($reservationId <= 0 || $assetCode === '') {
    json_response(['ok' => false, 'message' => '予約または備品コードが不足しています。'], 422);
}

$stmt = db()->prepare('
    SELECT
        r.*,
        ras.asset_set_id,
        a.asset_code,
        a.name AS asset_name,
        a.is_high_value,
        ct.id AS checkout_id,
        ct.state
    FROM reservations r
    INNER JOIN reservation_asset_sets ras ON ras.reservation_id = r.id
    INNER JOIN asset_sets a ON a.id = ras.asset_set_id
    INNER JOIN checkout_transactions ct
        ON ct.reservation_id = r.id
       AND ct.asset_set_id = a.id
       AND ct.user_id = r.user_id
    WHERE r.id = :reservation_id
      AND r.user_id = :user_id
      AND a.asset_code = :asset_code
    LIMIT 1
');
$stmt->execute([
    ':reservation_id' => $reservationId,
    ':user_id' => $user['id'],
    ':asset_code' => $assetCode,
]);
$row = $stmt->fetch();

if (!$row) {
    json_response(['ok' => false, 'message' => '選択した予約にこの備品は紐づいていません。'], 404);
}

if ($row['status'] !== 'approved') {
    json_response(['ok' => false, 'message' => '承認済み予約のみ貸出・返却できます。'], 409);
}

if ($action === 'checkout') {
    if (!reservation_window_allows_checkout($row)) {
        json_response(['ok' => false, 'message' => 'まだ貸出可能時間内ではありません。'], 409);
    }
    if ($row['state'] !== 'reserved') {
        json_response(['ok' => false, 'message' => 'この備品はすでに貸出処理済みです。'], 409);
    }

    $stmt = db()->prepare('
        UPDATE checkout_transactions
        SET checkout_at = :checkout_at, state = :state
        WHERE id = :id
    ');
    $stmt->execute([
        ':checkout_at' => now_str(),
        ':state' => 'checked_out',
        ':id' => $row['checkout_id'],
    ]);

    audit_log((int)$user['id'], 'user', 'checkout', 'checkout_transaction', (int)$row['checkout_id'], [
        'reservation_id' => (int)$reservationId,
        'asset_code' => $assetCode,
    ]);

    json_response([
        'ok' => true,
        'message' => '貸出を確定しました。',
        'result' => [
            'asset_name' => $row['asset_name'],
            'state' => 'checked_out',
        ],
    ]);
}

if ($row['state'] !== 'checked_out') {
    json_response(['ok' => false, 'message' => 'この備品は貸出中ではありません。'], 409);
}

$issueFlag = ($issueNote !== '') ? 1 : 0;
$nextState = ($issueFlag || (int)$row['is_high_value'] === 1) ? 'return_declared' : 'completed';

if (!reservation_window_allows_return($row)) {
    $issueFlag = 1;
    $nextState = 'return_declared';
    $issueNote = trim($issueNote . "\n返却期限を過ぎています。");
}

$stmt = db()->prepare('
    UPDATE checkout_transactions
    SET return_declared_at = :return_declared_at,
        state = :state,
        issue_flag = :issue_flag,
        issue_note = :issue_note,
        return_reviewed_at = CASE WHEN :state = "completed" THEN :return_reviewed_at ELSE return_reviewed_at END
    WHERE id = :id
');
$stmt->execute([
    ':return_declared_at' => now_str(),
    ':state' => $nextState,
    ':issue_flag' => $issueFlag,
    ':issue_note' => $issueNote !== '' ? $issueNote : null,
    ':return_reviewed_at' => $nextState === 'completed' ? now_str() : null,
    ':id' => $row['checkout_id'],
]);

audit_log((int)$user['id'], 'user', 'return_declared', 'checkout_transaction', (int)$row['checkout_id'], [
    'reservation_id' => (int)$reservationId,
    'asset_code' => $assetCode,
    'issue_flag' => $issueFlag,
]);

json_response([
    'ok' => true,
    'message' => $nextState === 'completed'
        ? '返却を完了しました。'
        : '返却を受け付けました。管理者確認待ちです。',
    'result' => [
        'asset_name' => $row['asset_name'],
        'state' => $nextState,
    ],
]);
