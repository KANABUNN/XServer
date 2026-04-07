<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/reservation_service.php';

try {
    /** @var mixed $cfg */
    $cfg = require __DIR__ . '/config.php';
    if (!is_array($cfg)) {
        throw new RuntimeException('config.php の形式が不正です。');
    }

    $year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
    $month = isset($_GET['month']) ? (int)$_GET['month'] : 0;
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        throw new RuntimeException('year/month が不正です。');
    }

    $pdo = db_connect($cfg);
    $days = reservation_fetch_month_status($pdo, $year, $month);

    [$minDate, $maxDate] = reservation_allowed_range($cfg);
    [$timeStart, $timeEnd] = reservation_allowed_time_bounds($cfg);
    echo json_encode([
        'ok' => true,
        'days' => $days,
        'min_date' => $minDate->format('Y-m-d'),
        'max_date' => $maxDate->format('Y-m-d'),
        'today' => reservation_now($cfg)->format('Y-m-d'),
        'time_start' => $timeStart,
        'time_end' => $timeEnd,
        'time_step_minutes' => reservation_time_step_minutes($cfg),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
