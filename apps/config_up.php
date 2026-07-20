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
            'user' => 'fitsc_account',
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
        'mail' => [
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'dbname' => 'fitsc_mail',
            'charset' => 'utf8mb4',
            'user' => 'fitsc_mail',
            'password' => '',
        ],
        'backup' => [
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'dbname' => 'fitsc_backup',
            'charset' => 'utf8mb4',
            'user' => 'fitsc_backup',
            'password' => '',
        ],  
        //kintone用  
        'org' => [
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'dbname' => 'fitsc_org',
            'charset' => 'utf8mb4',
            'user' => 'fitsc_org',
            'password' => '',
        ]
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
        'user' => 'fitsc_account',
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
    'smtp_user'   => 'info@',
    'smtp_pass'   => '',

    'from_addr'   => 'info@',
    'from_name'   => '貸し部屋予約システム',
    'reservation_admin_notify_to' => '',

    'timezone' => 'Asia/Tokyo',

    'reservation' => [
        'timezone' => 'Asia/Tokyo',
        'email_domains' => ['bene.fit.ac.jp', 'fit.ac.jp'],
        'booking_min_days_before' => 2,
        'booking_max_months_ahead' => 2,
        'time_step_minutes' => 15,
        'access_code_digits' => 6,
        'access_code_padding_minutes' => 10,
        'switchbot_required_for_confirmation' => true,
    ],

    'google_calendar' => [
        'enabled' => true,
        'gas_url' => '',
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

    'storage_maintenance' => [
        'enabled' => true,
        'timezone' => 'Asia/Tokyo',
        'batch_size' => 500,
        'state_dir' => __DIR__ . '/storage/maintenance',
        'log_file' => __DIR__ . '/storage/maintenance/storage_cleanup.log',
        'report_dir' => __DIR__ . '/storage/maintenance/reports',
        'tmp_dir' => __DIR__ . '/storage/maintenance/tmp',
        'temp_file_retention_days' => 2,
        'report_retention_days' => 1095,
        'report_mail_to' => 'jyohokanri@fit-sc.jp',
        'send_report_mail' => true,
        'forms_uploads' => [
            'live_root' => __DIR__ . '/forms_uploads',
            'archive_root' => __DIR__ . '/storage/archives/forms_uploads',
            'archive_after_days' => 180,
            'zip_retention_days' => 1095,
        ],
        'switchbot' => [
            'detail_file_retention_days' => 90,
            'webhook_event_retention_days' => 90,
        ],
        'db_retention_tables' => [
            [
                'connection' => 'account',
                'table' => 'admin_audit_logs',
                'date_column' => 'created_at',
                'retention_days' => 1461,
                'label' => 'fitsc_account.admin_audit_logs',
            ],
            [
                'connection' => 'book',
                'table' => 'admin_audit_logs',
                'date_column' => 'created_at',
                'retention_days' => 1461,
                'label' => 'fitsc_book.admin_audit_logs',
            ],
            [
                'connection' => 'lend',
                'table' => 'audit_logs',
                'date_column' => 'created_at',
                'retention_days' => 1461,
                'label' => 'fitsc_lend.audit_logs',
            ],
            [
                'connection' => 'forms',
                'table' => 'managed_form_submission_status_logs',
                'date_column' => 'created_at',
                'retention_days' => 365,
                'label' => 'fitsc_forms.managed_form_submission_status_logs',
            ],
            [
                'connection' => 'book',
                'table' => 'reservation_mail_logs',
                'date_column' => 'created_at',
                'retention_days' => 180,
                'label' => 'fitsc_book.reservation_mail_logs',
            ],
        ],
    ],

    'backup_manager' => [
        'enabled' => true,
        'timezone' => 'Asia/Tokyo',
        'backup_root' => dirname(__DIR__) . '/private_backups',
        'state_dir' => __DIR__ . '/storage/backup_logs',
        'alert_mail_to' => 'jyohokanri@fit-sc.jp',

        // 容量警告。disk_total_space / disk_free_space から計算します。
        'disk_warning_ratio' => 0.80,
        'disk_critical_ratio' => 0.90,

        // 月次バックアップレポートをメール送信する場合のみ true。
        // 通常の失敗通知は alert_mail_to へ送られます。
        'send_monthly_report_mail' => false,

        // アラート判定しきい値。cron遅延を考慮してやや余裕を持たせています。
        'alerts' => [
            'daily_success_hours' => 36,
            'weekly_success_hours' => 192,
            'verify_success_hours' => 36,
        ],

        // 整合性チェック時に相対パス探索へ追加するディレクトリ。
        // 標準候補で見つからない場合のみ、実環境のアップロード保存先を追加してください。
        'integrity' => [
            'forms_file_roots' => [],
            'mail_file_roots' => [],
            'switchbot_file_roots' => [],
        ],

        // 保持期間。バックアップ本体と管理メタデータを分けて整理します。
        'retention' => [
            'daily_days' => 60,
            'weekly_days' => 84,
            'monthly_days' => 730,
            'verify_days' => 30,
            'cleanup_days' => 365,
        ],
        'storage_snapshot_retention_days' => 1461,
        'report_retention_days' => 1461,

        // 第一段階と同じくDBをdumpする
        'db_targets' => [
            ['key' => 'account', 'connection' => 'account', 'dbname' => 'fitsc_account', 'required' => true],
            ['key' => 'book',    'connection' => 'book',    'dbname' => 'fitsc_book',    'required' => true],
            ['key' => 'forms',   'connection' => 'forms',   'dbname' => 'fitsc_forms',   'required' => true],
            ['key' => 'lend',    'connection' => 'lend',    'dbname' => 'fitsc_lend',    'required' => false],
            ['key' => 'mail',    'connection' => 'mail',    'dbname' => 'fitsc_mail',    'required' => false],
        ],

        // Web非公開領域に tar.gz として保存する
        'file_targets' => [
            [
                'key' => 'apps',
                'label' => 'apps',
                'path' => __DIR__,
                'required' => true,
                'exclude' => [
                    'apps/storage/maintenance/tmp',
                    'apps/storage/backup_logs/tmp',
                    'apps/storage/backups',
                    'apps/vendor',
                    'apps/node_modules',
                ],
            ],
            [
                'key' => 'public_html',
                'label' => 'public_html',
                'path' => dirname(__DIR__) . '/public_html',
                'required' => true,
                'exclude' => [
                    'public_html/cache',
                    'public_html/node_modules',
                ],
            ],
        ],
    ],
    'kintone' => [
        'storage_root' => __DIR__ . '/kintone_core/storage',
        'crypto_key_file' => __DIR__ . '/kintone_core/config/kintone_crypto.key',
        'max_roster_upload_mb' => 10,
        'api_timeout_seconds' => 45,
        'api_connect_timeout_seconds' => 15,
        'api_max_retries' => 3,
        'api_chunk_pause_ms' => 200,

        'role_rank_map' => [
            '代表' => 100,
            '代表者' => 100,
            '部長' => 90,
            '会長' => 90,
            '代表幹事' => 90,
            '委員長' => 80,
            '副代表' => 70,
            '副部長' => 70,
            '会計' => 50,
            '書記' => 50,
        ],
        
        'field_map' => [
            'organization_code' => 'organization_code',
            'organization_name' => 'organization_name',
            'organization_kana' => 'organization_kana',
            'category' => 'category',
            'representative_name' => 'representative_name',
            'representative_email' => 'representative_email',
            'activity_status' => 'activity_status',
            'member_count' => 'member_count',
            'last_roster_imported_at' => 'last_roster_imported_at',
            'last_sync_source' => 'last_sync_source',
            'last_sync_status' => 'last_sync_status',
            'xserver_org_id' => 'xserver_org_id',
            'notes' => 'notes',
        ],
    ],
];
