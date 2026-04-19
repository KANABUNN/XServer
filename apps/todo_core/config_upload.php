<?php
return [
    'app_name' => '総合管理事務局 会議・ToDo管理',
    'organization' => '総合管理事務局',
    'timezone' => 'Asia/Tokyo',
    'security' => [
        'session_name' => 'todo_app_sid',
        'csrf_key' => '_csrf_token',
    ],
    'db' => [
        'driver' => 'sqlite',
        'database' => dirname(__DIR__) . '/storage/todo.sqlite',
    ],
    'auth' => [
        'username' => 'admin',
        'password_hash' => '$2y$12$WA4SG3XW/TWxFx9FO1QdKOrqPtAOT9WlemL30NWvVGxzK/9aYEiZ2',
    ],
];
