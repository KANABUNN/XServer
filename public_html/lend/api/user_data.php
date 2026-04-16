<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
$user = api_require_login();

$assetSets = db()->query('SELECT id, asset_code, name, category, storage_location FROM asset_sets WHERE is_active = 1 ORDER BY category, name')->fetchAll();

$stmt = db()->prepare('
    SELECT
        r.id,
        r.title,
        r.purpose,
        r.place,
        r.start_at,
        r.end_at,
        r.status,
        GROUP_CONCAT(a.name SEPARATOR " / ") AS asset_names
    FROM reservations r
    LEFT JOIN reservation_asset_sets ras ON ras.reservation_id = r.id
    LEFT JOIN asset_sets a ON a.id = ras.asset_set_id
    WHERE r.user_id = :user_id
    GROUP BY r.id
    ORDER BY r.start_at DESC
');
$stmt->execute([':user_id' => $user['id']]);
$reservations = $stmt->fetchAll();

json_response([
    'ok' => true,
    'user' => $user,
    'asset_sets' => $assetSets,
    'reservations' => $reservations,
]);
