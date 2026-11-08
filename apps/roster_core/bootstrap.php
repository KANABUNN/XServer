<?php

declare(strict_types=1);

/**
 * 部員名簿（Forms 提出 → 担当者 accept → kintone ID20 反映）の共通基盤。
 *
 * - 名簿の下書き・提出原本・審査・accept・反映ジョブは fitsc_forms に保存する（kintone_pdo('forms')）。
 * - kintone への書込みは apps/roster_core/worker.php（cron の CLI）からだけ行う。
 *   Forms の提出・下書き・差し戻し・画面表示は kintone を更新しない。
 */

require_once dirname(__DIR__) . '/kintone_core/bootstrap.php';

function roster_default_config(): array
{
    return [
        'enabled' => true,
        'kintone' => [
            // kintone管理サイト「アプリ設定」に登録する app_key（APIトークンもこのキーで保存される）
            'roster_app_key' => 'member_roster', // ID20 部員名簿（同期ロールは manual で登録）
            'group_app_key' => 'groups',         // ID7 公認団体・組織（団体一覧・団体確認・ルックアップ参照用）
            'record_fields' => [
                'group_code' => 'group_id',
                'group_name' => 'group_name',
                'rank' => 'rank',
                'org' => 'org',
                'table' => 'list',
            ],
            'row_fields' => [
                'name' => 'name',
                'student_id' => 'student_id',
                'phone_number' => 'phone_number',
                'role' => 'role',
                'role_name' => 'role_name',
                'membership_status' => 'membership_status',
                'uses_group_email' => 'uses_group_email',
                'is_website_editor' => 'is_website_editor',
            ],
            'checkbox_options' => [
                'uses_group_email' => '使用',
                'is_website_editor' => '担当',
            ],
            'group_fields' => [
                'code' => 'id',
                'name' => 'name',
                'rank' => 'rank',
                'status' => 'status',
                'mail' => 'mail',
            ],
            'timeout_seconds' => 30,
            'connect_timeout_seconds' => 10,
        ],
        'groups' => [
            'allowed_ranks' => ['部', '同好会', '愛好会', '組織'],
            'allowed_statuses' => ['活動中', '結成登録中'],
            'directory_max_age_minutes' => 360,
        ],
        'rules' => [
            'max_rows' => 500,
            'min_rows' => 1,
            'name_max_length' => 100,
        ],
        'verification' => [
            'code_ttl_minutes' => 15,
            'max_attempts' => 5,
            'session_ttl_minutes' => 240,
            'max_requests_per_group_per_hour' => 5,
            'max_requests_per_ip_per_hour' => 20,
            // smtp: 共通SMTP設定で送信 / log: 開発用（環境変数 FITSC_ROSTER_DEV_MAIL=1 のときだけ有効）
            'mail_transport' => 'smtp',
            'mail_from_name' => '総合管理事務局 提出フォーム',
            'mail_subject' => '【部員名簿】団体確認コードのお知らせ',
        ],
        'worker' => [
            'lock_file' => '',
            'lease_seconds' => 600,
            'max_jobs_per_run' => 20,
            'max_attempts' => 6,
            'retry_backoff_seconds' => [60, 300, 900, 1800, 3600],
            'unknown_reconcile_delay_seconds' => 300,
            'heartbeat_stale_minutes' => 30,
            'verification_retention_days' => 30,
        ],
        // Forms 管理画面の提出一覧から kintone管理サイトの提出詳細へ移動するときのURL（末尾 /）
        'kintone_site_base_url' => '',
    ];
}

function roster_array_merge(array $base, array $override): array
{
    foreach ($override as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($base[$key])) {
            $base[$key] = roster_array_merge($base[$key], $value);
            continue;
        }
        $base[$key] = $value;
    }
    return $base;
}

function roster_config(?string $key = null, mixed $default = null): mixed
{
    static $config = null;
    if (!is_array($config)) {
        $configured = kintone_config_value('roster', []);
        $config = roster_array_merge(roster_default_config(), is_array($configured) ? $configured : []);
    }
    if ($key === null) {
        return $config;
    }
    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function roster_db(): PDO
{
    $pdo = kintone_pdo('forms');
    roster_ensure_schema($pdo);
    return $pdo;
}

function roster_now(): DateTimeImmutable
{
    return kintone_now();
}

function roster_now_sql(int $offsetSeconds = 0): string
{
    $now = roster_now();
    if ($offsetSeconds !== 0) {
        $now = $now->modify(($offsetSeconds >= 0 ? '+' : '') . $offsetSeconds . ' seconds');
    }
    return $now->format('Y-m-d H:i:s');
}

function roster_json(mixed $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('JSON の生成に失敗しました。');
    }
    return $json;
}

function roster_json_decode(?string $json, mixed $default = []): mixed
{
    if (!is_string($json) || trim($json) === '') {
        return $default;
    }
    $decoded = json_decode($json, true);
    return $decoded === null ? $default : $decoded;
}

function roster_log(string $level, string $message, array $context = []): void
{
    kintone_write_log($level, '[roster] ' . $message, $context);
}

/** 業務上の例外（利用者・担当者にそのまま表示してよい） */
class RosterException extends RuntimeException
{
    public function __construct(string $message, public string $reason = 'invalid', public int $httpStatus = 422, public array $detail = [])
    {
        parent::__construct($message);
    }
}

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/domain.php';
require_once __DIR__ . '/kintone_adapter.php';
require_once __DIR__ . '/directory.php';
require_once __DIR__ . '/case_service.php';
require_once __DIR__ . '/verification.php';
require_once __DIR__ . '/worker.php';
