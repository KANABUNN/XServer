<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

try {
    /** @var mixed $cfg */
    $cfg = require __DIR__ . '/config.php';

    if (!is_array($cfg)) {
        throw new RuntimeException('config.php の戻り値が配列ではありません。');
    }

    $pdo = db_connect($cfg);

    $year  = isset($_GET['year']) ? (int)$_GET['year'] : 0;
    $month = isset($_GET['month']) ? (int)$_GET['month'] : 0;

    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        http_response_code(400);
        echo json_encode(['error' => 'year/month が不正です'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd   = date('Y-m-d', strtotime($monthStart . ' +1 month'));

    $sql = "
        SELECT
            use_date,
            room_code
        FROM room_calendar_reservations
        WHERE use_date >= :month_start
          AND use_date < :month_end
        ORDER BY use_date ASC, room_code ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':month_start' => $monthStart,
        ':month_end'   => $monthEnd,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $daysInMonth = (int)date('t', strtotime($monthStart));
    $result = [];

    for ($day = 1; $day <= $daysInMonth; $day++) {
        $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $result[$dateKey] = [
            'tamoku' => false,
            'orange' => false,
        ];
    }

    foreach ($rows as $row) {
        $dateKey = (string)($row['use_date'] ?? '');
        $roomCode = (string)($row['room_code'] ?? '');

        if ($dateKey !== '' && isset($result[$dateKey][$roomCode])) {
            $result[$dateKey][$roomCode] = true;
        }
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => '予約状況の取得に失敗しました。',
        'detail' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
