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

];
