<?php

declare(strict_types=1);

require_once __DIR__ . '/kintone_registry.php';

$orgMasterPath = __DIR__ . '/org_master.php';
if (is_file($orgMasterPath)) {
    require_once $orgMasterPath;
}

/**
 * 他サイト表示用の軽量ステータスを返す。
 * 秘密情報は参照しない。障害時は fail-open で inactive を返す。
 */
function fitsc_kintone_cache_status(string $appKey, int $staleHours = 24): array
{
    $status = [
        'app_key' => $appKey,
        'available' => false,
        'label' => '未連携',
        'message' => 'kintoneキャッシュは未設定です。',
        'last_synced_at' => null,
        'is_stale' => false,
        'record_count' => 0,
    ];

    try {
        $app = kintone_registry_app($appKey);
        if (!is_array($app)) {
            return $status;
        }

        $stmt = kintone_pdo('org')->prepare(
            'SELECT COUNT(*) AS record_count, MAX(synced_at) AS latest_synced_at ' .
            'FROM kintone_app_records WHERE app_key = :app_key'
        );
        $stmt->execute([':app_key' => $appKey]);
        $row = $stmt->fetch();
        $count = is_array($row) ? (int)($row['record_count'] ?? 0) : 0;
        $latest = is_array($row) ? (string)($row['latest_synced_at'] ?? '') : '';

        $isStale = false;
        if ($latest !== '') {
            $synced = new DateTimeImmutable($latest);
            $threshold = (new DateTimeImmutable('now'))->modify('-' . max(1, $staleHours) . ' hours');
            $isStale = $synced < $threshold;
        }

        return [
            'app_key' => (string)$app['app_key'],
            'display_name' => (string)($app['display_name'] ?? $appKey),
            'role' => (string)($app['app_role'] ?? ''),
            'available' => $count > 0,
            'label' => $count > 0 ? ($isStale ? 'キャッシュ鮮度注意' : '連携済み') : 'データ未取得',
            'message' => $count > 0
                ? ($isStale ? 'kintoneキャッシュの同期時刻が古くなっています。入力は継続できます。' : 'kintoneキャッシュを参照できます。')
                : 'アプリは登録済みですが、キャッシュデータはまだありません。',
            'last_synced_at' => $latest !== '' ? $latest : ($app['last_synced_at'] ?? null),
            'is_stale' => $isStale,
            'record_count' => $count,
        ];
    } catch (Throwable $e) {
        error_log('[fitsc_kintone_cache_status] ' . $e->getMessage());
        return $status + ['message' => 'kintoneキャッシュ状態を取得できませんでした。入力は継続できます。'];
    }
}

/**
 * 汎用pullキャッシュをUI候補リスト用に整形する。
 * label/valueフィールドが存在しない場合は record_key を使う。
 */
function fitsc_kintone_option_list(string $appKey, string $labelField = 'name', string $valueField = 'code', int $limit = 500): array
{
    try {
        $rows = kintone_registry_list($appKey, $limit);
        $options = [];
        foreach ($rows as $row) {
            $record = is_array($row['record'] ?? null) ? $row['record'] : [];
            $label = trim((string)($record[$labelField] ?? $record['name'] ?? $record['label'] ?? $row['record_key'] ?? ''));
            $value = trim((string)($record[$valueField] ?? $record['code'] ?? $row['record_key'] ?? $label));
            if ($label === '' && $value === '') {
                continue;
            }
            $options[] = [
                'label' => $label !== '' ? $label : $value,
                'value' => $value !== '' ? $value : $label,
                'record_key' => (string)($row['record_key'] ?? ''),
                'synced_at' => (string)($row['synced_at'] ?? ''),
            ];
        }
        return $options;
    } catch (Throwable $e) {
        error_log('[fitsc_kintone_option_list] ' . $e->getMessage());
        return [];
    }
}

/**
 * forms/book/lend 等の表示補助用に、団体名候補を正本から返す。
 * ここでは提出・予約・貸出を止めない。あくまで候補表示と警告表示に使う。
 */
function fitsc_kintone_org_suggestions(int $limit = 500): array
{
    if (!function_exists('org_master_list_active')) {
        return [];
    }

    try {
        $limit = max(1, min(2000, $limit));
        $rows = org_master_list_active();
        $suggestions = [];
        foreach ($rows as $row) {
            $name = trim((string)($row['organization_name'] ?? ''));
            $code = trim((string)($row['organization_code'] ?? ''));
            if ($name === '' && $code === '') {
                continue;
            }
            $suggestions[] = [
                'code' => $code,
                'name' => $name,
                'category' => (string)($row['category'] ?? ''),
                'activity_status' => (string)($row['activity_status'] ?? ''),
                'last_synced_at' => (string)($row['last_synced_at'] ?? ''),
            ];
            if (count($suggestions) >= $limit) {
                break;
            }
        }
        return $suggestions;
    } catch (Throwable $e) {
        error_log('[fitsc_kintone_org_suggestions] ' . $e->getMessage());
        return [];
    }
}

function fitsc_kintone_public_payload(array $appKeys = ['categories'], int $limit = 500): array
{
    $payload = [
        'generated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        'organization_suggestions' => fitsc_kintone_org_suggestions($limit),
        'apps' => [],
    ];

    foreach ($appKeys as $appKey) {
        $appKey = kintone_registry_normalize_app_key((string)$appKey);
        if ($appKey === '') {
            continue;
        }
        $payload['apps'][$appKey] = [
            'status' => fitsc_kintone_cache_status($appKey),
            'options' => fitsc_kintone_option_list($appKey, 'name', 'code', $limit),
        ];
    }

    return $payload;
}
