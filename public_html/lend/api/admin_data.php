<?php
require_once __DIR__ . '/../../../apps/lend_core/bootstrap.php';
api_require_admin();

$pendingReservations = db()->query('
    SELECT
        r.id,
        r.title,
        r.purpose,
        r.place,
        r.start_at,
        r.end_at,
        u.name AS user_name,
        u.organization,
        GROUP_CONCAT(a.name SEPARATOR " / ") AS asset_names
    FROM reservations r
    INNER JOIN users u ON u.id = r.user_id
    LEFT JOIN reservation_asset_sets ras ON ras.reservation_id = r.id
    LEFT JOIN asset_sets a ON a.id = ras.asset_set_id
    WHERE r.status = "pending"
    GROUP BY r.id
    ORDER BY r.start_at ASC
')->fetchAll();

$returnReview = db()->query('
    SELECT
        ct.id,
        r.id AS reservation_id,
        r.title,
        u.name AS user_name,
        a.name AS asset_name,
        a.asset_code,
        a.is_high_value,
        ct.return_declared_at,
        ct.issue_flag,
        ct.issue_note,
        ct.state
    FROM checkout_transactions ct
    INNER JOIN reservations r ON r.id = ct.reservation_id
    INNER JOIN users u ON u.id = ct.user_id
    INNER JOIN asset_sets a ON a.id = ct.asset_set_id
    WHERE ct.state IN ("return_declared","flagged")
    ORDER BY ct.return_declared_at DESC
')->fetchAll();

$overdues = db()->query('
    SELECT
        ct.id,
        r.title,
        r.end_at,
        u.name AS user_name,
        a.name AS asset_name,
        ct.checkout_at,
        ct.state
    FROM checkout_transactions ct
    INNER JOIN reservations r ON r.id = ct.reservation_id
    INNER JOIN users u ON u.id = ct.user_id
    INNER JOIN asset_sets a ON a.id = ct.asset_set_id
    WHERE ct.state = "checked_out"
      AND r.end_at < NOW()
    ORDER BY r.end_at ASC
')->fetchAll();

json_response([
    'ok' => true,
    'pending_reservations' => $pendingReservations,
    'return_review' => $returnReview,
    'overdues' => $overdues,
]);
