<?php
return [
    // 基本的に変更不要
  'smtp_host'   => 'sv16171.xserver.jp',
  'smtp_port'   => 465,
  'smtp_secure' => 'ssl',
  'smtp_user'   => 'info@fit-sc.jp',
  'smtp_pass'   => 'Zh991:dJH4Qqy|2*q$C/ZPTWP_PccBbq',

  'from_addr'   => 'info@fit-sc.jp',
  'from_name'   => '貸し部屋予約申請フォーム',
  'to_addr'     => 'kanabun@kb-dev.jp',

  'db' => [
    'dsn' => 'mysql:host=localhost;dbname=fitsc_book;charset=utf8mb4',
    'user' => 'fitsc_book',
    'password' => 'UzxhKhMPu9s!GRH',
  ],

  'upload' => [
    'reservation_dir' => dirname(__DIR__) . '/storage/reservations',
    'reservation_path_prefix' => 'storage/reservations',
  ],

];
