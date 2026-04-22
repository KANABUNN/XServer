<?php
declare(strict_types=1);

return [
    'smtp_host'   => 'sv16171.xserver.jp',
    'smtp_port'   => 465,
    'smtp_secure' => 'ssl',
    'smtp_user'   => 'info@fit-sc.jp',
    'smtp_pass'   => '',

    'from_addr'   => 'info@fit-sc.jp',
    'from_name'   => '貸し部屋予約システム',
    'reservation_admin_notify_to' => 'sogokanri@bene.fit.ac.jp',

    'db' => [
        'dsn' => 'mysql:host=localhost;dbname=fitsc_book;charset=utf8mb4',
        'user' => 'fitsc_book',
        'password' => '',
    ],

    'account_db' => [
       'host' => 'localhost',
       'port' => 3306,
       'dbname' => 'fitsc_account',
       'charset' => 'utf8mb4',
       'user' => 'fitsc_admin',
       'pass' => '',
    ],

    'reservation' => [
        'timezone' => 'Asia/Tokyo',
        'email_domain' => 'bene.fit.ac.jp',
        'booking_min_days_before' => 2,
        'booking_max_months_ahead' => 2,
        'time_step_minutes' => 15,
        'access_code_digits' => 6,
        'access_code_padding_minutes' => 10,
        'switchbot_required_for_confirmation' => true,
    ],

    'google_calendar' => [
        'enabled' => true,
        'gas_url' => 'https://script.google.com/a/macros/fit-sc.jp/s/AKfycbyjUhRHiX_ROqCGDvqOVty1P2ocSsu3kaywRX8fu355CgSWgESDrr_EgEI1tvWRH4kOdA/exec',
        'shared_secret' => '',
        'timezone' => 'Asia/Tokyo',
        'connect_timeout' => 5,
        'timeout' => 15,
        'room_calendar_map' => [
        'tamoku' => '@resource.calendar.google.com',
        'orange' => '@resource.calendar.google.com',
        ],
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
        'webhook_secret' => 'YHyLNp4yfLGf3MYnNRnJ3fy5pEPEAF54GPtFPhNH',
        'webhook_url' => 'https://set.book.fit-sc.jp/switchbot_webhook.php?token=YHyLNp4yfLGf3MYnNRnJ3fy5pEPEAF54GPtFPhNH',
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
