<?php

declare(strict_types=1);

return [
    'db_connections' => [
        'mail' => [
            'dsn' => 'mysql:host=localhost;dbname=fitsc_mail;charset=utf8mb4',
            'user' => 'fitsc_mail',
            'password' => '',
        ],
        'account' => [
            'dsn' => 'mysql:host=localhost;dbname=fitsc_account;charset=utf8mb4',
            'user' => 'fitsc_account',
            'password' => '',
        ],
    ],

    'storage' => [
        'attachments_dir' => dirname(__DIR__) . '/storage/mail_attachments',
        'tmp_dir' => dirname(__DIR__) . '/storage/mail_tmp',
    ],

    'security' => [
        'max_upload_bytes' => 50 * 1024 * 1024,
        'allowed_attachment_extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip'],
        'allowed_attachment_mime_prefixes' => ['application/', 'text/'],
    ],


    'mail_delivery' => [
        // 現在の主経路。Gmail APIで下書きを作る場合は gmail_draft を指定します。
        // SMTP Relayで直接送信する場合は smtp、将来Microsoft 365を利用できる場合は graph に切替可能です。
        'driver' => 'gmail_draft', // gmail_draft / smtp / graph / manual_export
    ],


    'gmail_api' => [
        // Google Workspaceの特権管理者で、サービスアカウントにドメイン全体の委任を設定したうえで使います。
        // 主用途はGmail下書き作成です。SMTP Relayとは別経路です。
        'enabled' => true,

        // service_account_json_path か service_account_json のどちらかを設定します。
        // JSONファイルは public_html 直下に置かず、apps/mail_core など非公開領域に置いてください。
        'service_account_json_path' => mail_core_dir() . '/google-service-account.json',
        'service_account_json' => '',

        // 下書きを作成するGoogle Workspaceユーザー。通常は送信用アカウントです。
        'delegated_user' => 'sogokanri@fit-sc.jp',
        'from_address' => 'sogokanri@fit-sc.jp',
        'from_name' => '総合管理事務局',
        'reply_to' => 'sogokanri@fit-sc.jp',

        'api_base' => 'https://gmail.googleapis.com/gmail/v1',
        'token_uri' => 'https://oauth2.googleapis.com/token',
        // 下書き作成だけなら gmail.compose を推奨します。送信までAPI化する場合のみスコープ追加を検討してください。

        'scopes' => ['https://www.googleapis.com/auth/gmail.compose'],
        
        'max_drafts_per_run' => 10,
    ],

    'smtp' => [
        // Google Workspace SMTP認証方式の例です。bookのメール送信と同様にPHPMailerからSMTP送信します。
        'enabled' => false,
        'host' => 'smtp-relay.gmail.com',
        'port' => 587,
        'secure' => 'tls', // tls / ssl / none
        'smtp_auth' => false,

        'username' => 'sogokanri@fit-sc.jp', // 例: mail@fit-sc.jp
        'password' => '', // Google Workspaceのアプリパスワード等。通常パスワードの直書きは避けてください。

        'from_address' => 'sogokanri@fit-sc.jp', // 例: mail@fit-sc.jp
        'from_name' => '総合管理事務局',
        'reply_to' => 'sogokanri@fit-sc.jp',
        'envelope_sender' => 'sogokanri@fit-sc.jp',

        'max_sends_per_run' => 10,
        'per_message_delay_seconds' => 1,
        'timeout' => 30,
        // SMTP通信の詳細をPHP error_logに出す。通常運用ではfalse。
        'debug' => false,
        'debug_level' => 2,
    ],

    'graph' => [
        // 初期実装は client_credentials 方式です。送信用メールボックスを固定し、
        // Microsoft Entra ID のアプリケーション権限でOutlook下書きを作成します。
        'enabled' => false,
        
        'auth_mode' => 'client_credentials',
        'tenant_id' => '',
        'client_id' => '',
        'client_secret' => '',
        'sender_user_id' => 'sogokanri@fit-sc.jp', // userPrincipalName またはユーザーID。例: student-council@example.ac.jp
        'api_base' => 'https://graph.microsoft.com/v1.0',
        'draft_only_default' => true,
        
        // true にすると Graph 画面に送信ボタンを表示します。Mail.Send 権限が必要です。
        'allow_send_from_ui' => false,
        
        'max_drafts_per_run' => 20,
        'max_sends_per_run' => 10,
        
        // 後続実装用の予約値です。現段階では client_credentials のみ実処理します。
        'delegated_enabled' => false,
    ],
];
