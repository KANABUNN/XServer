<?php
return [
  // 基本的に変更不要
  'smtp_host'   => '',
  'smtp_port'   => 465,
  'smtp_secure' => 'ssl',
  'smtp_user'   => '',
  'smtp_pass'   => '',

  'from_addr'   => '',
  'from_name'   => '貸し部屋予約申請フォーム',
  'to_addr'     => '',

  'db' => [
    'dsn' => 'mysql:host=localhost;dbname=fitsc_book;charset=utf8mb4',
    'user' => '',
    'password' => '',
  ],

  'upload' => [
    'reservation_dir' => dirname(__DIR__) . '/storage/reservations',
    'reservation_path_prefix' => 'storage/reservations',
  ],
  
  'google_calendar' => [
    'enabled' => true,
    'gas_url' => 'https://script.google.com/macros/s/XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX/exec',
    'shared_secret' => 'CHANGE_ME_TO_LONG_RANDOM_SECRET',
    'timezone' => 'Asia/Tokyo',
    'connect_timeout' => 5,
    'timeout' => 15,
    'room_calendar_map' => [
        'tamoku' => 'tamoku_calendar_id@group.calendar.google.com',
        'orange' => 'orange_calendar_id@group.calendar.google.com',
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
