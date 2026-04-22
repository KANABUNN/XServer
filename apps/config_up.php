<?php
declare(strict_types=1);

$mysqlHost = 'localhost';
$mysqlPort = 3306;
$mysqlCharset = 'utf8mb4';

return [
    /*
     * 共通ポリシー
     * - 認証系(shared_accounts / shared_account_app_roles) は fitsc_account に集約し、必ず fitsc_admin で接続する
     * - 各業務DBは原則として専用ユーザーで接続する
     *   - fitsc_book  -> fitsc_book
     *   - fitsc_forms -> fitsc_forms
     *   - fitsc_lend  -> fitsc_lend
     * - apps/config.php を正本とし、個別 config はここを参照する
     */

    'db_connections' => [
        'account' => [
            'driver' => 'mysql',
            'host' => $mysqlHost,
            'port' => $mysqlPort,
            'dbname' => 'fitsc_account',
            'charset' => $mysqlCharset,
            'user' => 'fitsc_admin',
            'password' => '',
        ],
        'book' => [
            'driver' => 'mysql',
            'host' => $mysqlHost,
            'port' => $mysqlPort,
            'dbname' => 'fitsc_book',
            'charset' => $mysqlCharset,
            'user' => 'fitsc_book',
            'password' => '',
        ],
        'forms' => [
            'driver' => 'mysql',
            'host' => $mysqlHost,
            'port' => $mysqlPort,
            'dbname' => 'fitsc_forms',
            'charset' => $mysqlCharset,
            'user' => 'fitsc_forms',
            'password' => '',
        ],
        'lend' => [
            'driver' => 'mysql',
            'host' => $mysqlHost,
            'port' => $mysqlPort,
            'dbname' => 'fitsc_lend',
            'charset' => $mysqlCharset,
            'user' => 'fitsc_lend',
            'password' => '',
        ],
    ],

    // 既存コード互換: 予約システム(book)の既定接続
    'db' => [
        'connection' => 'book',
        'dsn' => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $mysqlHost, $mysqlPort, 'fitsc_book', $mysqlCharset),
        'user' => 'fitsc_book',
        'password' => '',
    ],

    // 既存コード互換: 認証DB(shared_accounts)用
    'account_db' => [
        'connection' => 'account',
        'host' => $mysqlHost,
        'port' => $mysqlPort,
        'dbname' => 'fitsc_account',
        'charset' => $mysqlCharset,
        'user' => 'fitsc_admin',
        'pass' => '',
    ],

    // 既存コード互換: forms 用
    'forms_db' => [
        'connection' => 'forms',
        'host' => $mysqlHost,
        'port' => $mysqlPort,
        'dbname' => 'fitsc_forms',
        'charset' => $mysqlCharset,
        'user' => 'fitsc_forms',
        'pass' => '',
    ],

    // 将来の共通参照用
    'lend_db' => [
        'connection' => 'lend',
        'host' => $mysqlHost,
        'port' => $mysqlPort,
        'dbname' => 'fitsc_lend',
        'charset' => $mysqlCharset,
        'user' => 'fitsc_lend',
        'pass' => '',
    ],

    'smtp_host'   => 'sv16171.xserver.jp',
    'smtp_port'   => 465,
    'smtp_secure' => 'ssl',
    'smtp_user'   => 'info@fit-sc.jp',
    'smtp_pass'   => '',

    'from_addr'   => 'info@fit-sc.jp',
    'from_name'   => '貸し部屋予約システム',
    'reservation_admin_notify_to' => 'sogokanri@bene.fit.ac.jp',

    'timezone' => 'Asia/Tokyo',

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
        'storage_dir' => __DIR__ . '/storage/switchbot',
        'detail_dir' => __DIR__ . '/storage/switchbot/requests',
        'request_table' => 'switchbot_passcode_requests',
        'webhook_secret' => '',
        'webhook_url' => 'https://set.book.fit-sc.jp/switchbot_webhook.php',
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
