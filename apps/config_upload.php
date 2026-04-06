<?php
declare(strict_types=1);

return [
    'smtp_host'   => '',
    'smtp_port'   => 465,
    'smtp_secure' => 'ssl',
    'smtp_user'   => '',
    'smtp_pass'   => '',

    'from_addr'   => '',
    'from_name'   => '貸し部屋予約システム',
    'reservation_admin_notify_to' => 'sogokanri@bene.fit.ac.jp',

    'db' => [
        'dsn' => 'mysql:host=localhost;dbname=fitsc_book;charset=utf8mb4',
        'user' => '',
        'password' => '',
    ],

    'reservation' => [
        'timezone' => 'Asia/Tokyo',
        'email_domain' => 'bene.fit.ac.jp',
        'booking_min_days_before' => 2,
        'booking_max_months_ahead' => 2,
        'access_code_digits' => 6,
        'access_code_valid_from' => '00:00',
        'access_code_valid_until' => '23:59',
        'switchbot_required_for_confirmation' => true,
    ],

    'switchbot' => [
        'token' => '',
        'secret' => '',
        'api_base' => 'https://api.switch-bot.com/v1.1',
        'timeout' => 15,
        'timezone' => 'Asia/Tokyo',
        'storage_dir' => dirname(__DIR__) . '/storage/switchbot',
        'detail_dir' => dirname(__DIR__) . '/storage/switchbot/requests',
        'request_table' => 'switchbot_passcode_requests',
        'webhook_secret' => 'CHANGE_ME_TO_LONG_RANDOM_TOKEN',
        'webhook_url' => '',
        'webhook_path' => '/switchbot_webhook.php',
        'keypads' => [
            'tamoku' => [
                'device_id' => '',
                'device_name' => '多目的室',
            ],
            'orange' => [
                'device_id' => '',
                'device_name' => 'オレンジの部屋',
            ],
        ],
    ],
];
