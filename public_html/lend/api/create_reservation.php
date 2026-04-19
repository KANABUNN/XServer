<?php
require_once __DIR__ . '/../../../apps/lend_core/bootstrap.php';
require_post();

$user = api_require_login();
$data = request_json();

if (!verify_csrf($data['csrf_token'] ?? '')) {
    json_response(['ok' => false, 'message' => 'CSRF トークンが不正です。'], 419);
}

$title = trim((string)($data['title'] ?? ''));
$purpose = trim((string)($data['purpose'] ?? ''));
$place = trim((string)($data['place'] ?? ''));
$assetSetId = (int)($data['asset_set_id'] ?? 0);

try {
    $start = new DateTimeImmutable((string)($data['start_at'] ?? ''));
    $end = new DateTimeImmutable((string)($data['end_at'] ?? ''));
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => '日時の形式が不正です。'], 422);
}

if ($title === '' || $purpose === '' || $assetSetId <= 0) {
    json_response(['ok' => false, 'message' => '入力内容が不足しています。'], 422);
}
if ($end <= $start) {
    json_response(['ok' => false, 'message' => '終了日時は開始日時より後にしてください。'], 422);
}

$assetStmt = db()->prepare('SELECT id, name FROM asset_sets WHERE id = :id AND is_active = 1');
$assetStmt->execute([':id' => $assetSetId]);
$assetSet = $assetStmt->fetch();
if (!$assetSet) {
    json_response(['ok' => false, 'message' => '指定された貸出セットが見つかりません。'], 404);
}

db()->beginTransaction();
try {
    $stmt = db()->prepare('
        INSERT INTO reservations (user_id, title, purpose, place, start_at, end_at, status)
        VALUES (:user_id, :title, :purpose, :place, :start_at, :end_at, :status)
    ');
    $stmt->execute([
        ':user_id' => $user['id'],
        ':title' => $title,
        ':purpose' => $purpose,
        ':place' => $place ?: null,
        ':start_at' => $start->format('Y-m-d H:i:s'),
        ':end_at' => $end->format('Y-m-d H:i:s'),
        ':status' => 'pending',
    ]);
    $reservationId = (int)db()->lastInsertId();

    $stmt = db()->prepare('
        INSERT INTO reservation_asset_sets (reservation_id, asset_set_id, quantity)
        VALUES (:reservation_id, :asset_set_id, 1)
    ');
    $stmt->execute([
        ':reservation_id' => $reservationId,
        ':asset_set_id' => $assetSetId,
    ]);

    $stmt = db()->prepare('
        INSERT INTO checkout_transactions (reservation_id, user_id, asset_set_id, state)
        VALUES (:reservation_id, :user_id, :asset_set_id, :state)
    ');
    $stmt->execute([
        ':reservation_id' => $reservationId,
        ':user_id' => $user['id'],
        ':asset_set_id' => $assetSetId,
        ':state' => 'reserved',
    ]);

    audit_log((int)$user['id'], 'user', 'reservation_created', 'reservation', $reservationId, [
        'asset_set_id' => $assetSetId,
    ]);

    db()->commit();
} catch (Throwable $e) {
    db()->rollBack();
    json_response(['ok' => false, 'message' => '申請の保存に失敗しました。', 'error' => $e->getMessage()], 500);
}

json_response([
    'ok' => true,
    'message' => '申請を受け付けました。',
]);
