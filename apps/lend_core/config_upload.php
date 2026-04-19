<?php
return [
    'app_name' => '備品貸出システム',
    'base_url' => 'https://lend.fit-sc.jp',
    'session_name' => 'equipment_kiosk_session',
    'timezone' => 'Asia/Tokyo',

    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'dbname' => 'fitsc_lend',
        'charset' => 'utf8mb4',
        'user' => '',
        'pass' => '',
    ],

    'reservation' => [
        // 貸出開始可能: 予約開始の何分前から許可するか
        'checkout_early_minutes' => 60,
        // 返却申告可能: 予約終了の何分後まで通常返却扱いにするか
        'return_grace_minutes' => 240,
    ],

    'kiosk' => [
        // 無操作時にログアウトするまでの秒数（フロント側）
        'idle_logout_seconds' => 180,
    ],
];
