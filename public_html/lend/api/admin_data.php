<?php
require_once __DIR__ . '/../../../apps/lend_core/bootstrap.php';
api_require_admin();

$snapshotSelect = lend_reservation_user_snapshot_sql();

$pendingReservations = db()->query(<<<SQL
    SELECT
        r.id,
        r.user_id,
        r.title,
        r.purpose,
        r.place,
        r.start_at,
        r.end_at,
        {$snapshotSelect}
        GROUP_CONCAT(a.name SEPARATOR " / ") AS asset_names
    FROM reservations r
    LEFT JOIN reservation_asset_sets ras ON ras.reservation_id = r.id
    LEFT JOIN asset_sets a ON a.id = ras.asset_set_id
    WHERE r.status = "pending"
    GROUP BY r.id
    ORDER BY r.start_at ASC
SQL)->fetchAll();
$pendingReservations = lend_apply_account_labels($pendingReservations, 'user_id');

$returnReview = db()->query(<<<SQL
    SELECT
        ct.id,
        ct.user_id,
        r.id AS reservation_id,
        r.title,
        {$snapshotSelect}
        a.name AS asset_name,
        a.asset_code,
        a.is_high_value,
        ct.return_declared_at,
        ct.issue_flag,
        ct.issue_note,
        ct.state
    FROM checkout_transactions ct
    INNER JOIN reservations r ON r.id = ct.reservation_id
    INNER JOIN asset_sets a ON a.id = ct.asset_set_id
    WHERE ct.state IN ("return_declared","flagged")
    ORDER BY ct.return_declared_at DESC
SQL)->fetchAll();
$returnReview = lend_apply_account_labels($returnReview, 'user_id');

$overdues = db()->query(<<<SQL
    SELECT
        ct.id,
        ct.user_id,
        r.title,
        r.end_at,
        {$snapshotSelect}
        a.name AS asset_name,
        ct.checkout_at,
        ct.state
    FROM checkout_transactions ct
    INNER JOIN reservations r ON r.id = ct.reservation_id
    INNER JOIN asset_sets a ON a.id = ct.asset_set_id
    WHERE ct.state = "checked_out"
      AND r.end_at < NOW()
    ORDER BY r.end_at ASC
SQL)->fetchAll();
$overdues = lend_apply_account_labels($overdues, 'user_id');

json_response([
    'ok' => true,
    'pending_reservations' => $pendingReservations,
    'return_review' => $returnReview,
    'overdues' => $overdues,
]);
