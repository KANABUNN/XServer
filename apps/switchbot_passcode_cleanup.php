<?php

declare(strict_types=1);

require_once __DIR__ . '/switchbot_api.php';

/**
 * 有効期限切れ SwitchBot パスコードの自動削除。
 *
 * 背景:
 *   予約成立時に switchbot_create_time_limited_key() で時限パスコードを発行しているが、
 *   端末側のパスコードを消す経路が存在しなかったため、期限切れコードがキーパッドに
 *   蓄積し続けていた。DB 側の平文マスク（storage_maintenance.php）は「記録」を消すだけで
 *   「物理ドアの解錠コード」は生き残る。ここではその実体を削除する。
 *
 * 安全方針（物理ドアの締め出しを避けるため意図的に保守的にしている）:
 *   1. type=timeLimit 以外（permanent / disposable）は絶対に削除しない。
 *   2. 本システムの管理下にあるものだけを削除する。
 *      - DB 照合: switchbot_passcode_requests の passcode_name と一致
 *      - 孤児: 命名規則 `YYYY-MM-DD_部屋名_団体名` に一致するもの
 *      上記どちらにも当てはまらない＝職員が手動作成したコードは対象外。
 *   3. 期限到来から猶予時間（既定 24h）を過ぎたものだけ削除する。
 *   4. 孤児は SwitchBot 自身が status=expired と返した場合のみ削除する。
 *   5. 1 回の実行あたりの削除上限を設け、暴走時の影響範囲を限定する。
 */

function switchbot_cleanup_config(array $cfg): array
{
    $raw = $cfg['switchbot_passcode_cleanup'] ?? [];
    if (!is_array($raw)) {
        $raw = [];
    }

    $sb = switchbot_config($cfg);

    $defaults = [
        'enabled' => true,
        'dry_run' => false,
        'grace_hours' => 24,
        'max_deletions_per_run' => 50,
        'require_expired_status_for_orphans' => true,
        'managed_name_pattern' => '/^(\d{4}-\d{2}-\d{2})_/u',
        'api_sleep_ms' => 500,
        'purge_passcode_on_delete' => true,
        'log_file' => rtrim($sb['storage_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'passcode_cleanup.jsonl',
        'lock_file' => rtrim($sb['storage_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'passcode_cleanup.lock',
        'log_retention_days' => 365,
    ];

    $merged = array_merge($defaults, $raw);

    $merged['grace_hours'] = max(0, (int)$merged['grace_hours']);
    $merged['max_deletions_per_run'] = max(1, (int)$merged['max_deletions_per_run']);
    $merged['api_sleep_ms'] = max(0, (int)$merged['api_sleep_ms']);
    $merged['enabled'] = (bool)$merged['enabled'];
    $merged['dry_run'] = (bool)$merged['dry_run'];
    $merged['require_expired_status_for_orphans'] = (bool)$merged['require_expired_status_for_orphans'];
    $merged['purge_passcode_on_delete'] = (bool)$merged['purge_passcode_on_delete'];

    return $merged;
}

function switchbot_cleanup_now(array $cfg): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(switchbot_config($cfg)['timezone']));
}

/**
 * 監査ログを 1 行追記する。パスコードそのものは絶対に書かない。
 */
function switchbot_cleanup_log(array $cfg, array $entry): void
{
    $conf = switchbot_cleanup_config($cfg);
    $path = (string)$conf['log_file'];
    if ($path === '') {
        return;
    }

    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }

    $entry['logged_at'] = switchbot_cleanup_now($cfg)->format('Y-m-d H:i:s');
    $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    $fp = @fopen($path, 'ab');
    if ($fp === false) {
        return;
    }
    try {
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, $json . PHP_EOL);
            fflush($fp);
            flock($fp, LOCK_UN);
        }
    } finally {
        fclose($fp);
    }
}

/**
 * 命名規則 `YYYY-MM-DD_...` から利用日を取り出す。
 * 一致しなければ null（＝本システム管理外とみなす）。
 */
function switchbot_cleanup_parse_managed_name(array $cfg, string $name): ?DateTimeImmutable
{
    $conf = switchbot_cleanup_config($cfg);
    $pattern = (string)$conf['managed_name_pattern'];

    if (@preg_match($pattern, '') === false) {
        throw new RuntimeException('switchbot_passcode_cleanup.managed_name_pattern が正規表現として不正です。');
    }

    if (!preg_match($pattern, $name, $m)) {
        return null;
    }

    $date = $m[1] ?? '';
    $tz = new DateTimeZone(switchbot_config($cfg)['timezone']);
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' 23:59:59', $tz);
    if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
        return null;
    }

    return $parsed;
}

/**
 * 端末上のパスコード名 → DB 行のインデックスを作る。
 *
 * 同名（同じ日・部屋・団体）が複数ある場合は end_at が最も遅い行を採用する。
 * 削除判定を最も保守的な（＝最も遅く期限切れになる）側に寄せるため。
 */
/**
 * 削除結果の記録に必要な列が存在するか事前に確認する。
 *
 * 列が無いまま削除だけ実行すると「端末からは消えたが DB に記録が残らない」
 * 状態になり追跡できなくなる。先に落として気付けるようにする。
 */
function switchbot_cleanup_assert_schema(array $cfg): void
{
    $pdo = switchbot_db_connect($cfg);
    $table = switchbot_request_table_name($cfg);

    $required = ['switchbot_key_id', 'key_deleted_at', 'key_delete_status', 'key_delete_command_id', 'key_delete_error'];

    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $stmt->execute([':t' => $table]);
    $existing = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $missing = array_diff($required, $existing);
    if ($missing !== []) {
        throw new RuntimeException(sprintf(
            '%s に必要な列がありません: %s / reservation_install_schema() を実行してスキーマを更新してください。',
            $table,
            implode(', ', $missing)
        ));
    }
}

function switchbot_cleanup_load_db_index(array $cfg): array
{
    $pdo = switchbot_db_connect($cfg);
    $table = switchbot_request_table_name($cfg);

    $stmt = $pdo->query(
        "SELECT id, local_request_id, passcode_name, device_id, end_at, status
           FROM `{$table}`
          WHERE passcode_name IS NOT NULL AND passcode_name <> ''
          ORDER BY id ASC"
    );

    $index = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $name = trim((string)($row['passcode_name'] ?? ''));
        $deviceId = trim((string)($row['device_id'] ?? ''));
        if ($name === '') {
            continue;
        }
        $key = $deviceId . "\0" . $name;

        $existing = $index[$key] ?? null;
        if ($existing !== null) {
            $prevEnd = (string)($existing['end_at'] ?? '');
            $currEnd = (string)($row['end_at'] ?? '');
            if ($prevEnd !== '' && ($currEnd === '' || $currEnd <= $prevEnd)) {
                continue;
            }
        }
        $index[$key] = $row;
    }

    return $index;
}

/**
 * 1 件のパスコードについて削除可否を判定する。
 *
 * 戻り値の 'delete' が true のものだけが削除対象。
 * 'reason' には判断根拠を必ず入れ、監査ログで追跡できるようにする。
 */
function switchbot_cleanup_evaluate_key(array $cfg, array $key, string $deviceId, array $dbIndex, DateTimeImmutable $now): array
{
    $conf = switchbot_cleanup_config($cfg);
    $graceSeconds = $conf['grace_hours'] * 3600;
    $tz = new DateTimeZone(switchbot_config($cfg)['timezone']);

    $name = (string)($key['name'] ?? '');
    $type = (string)($key['type'] ?? '');
    $status = (string)($key['status'] ?? '');

    $base = [
        'key_id' => (int)($key['id'] ?? 0),
        'name' => $name,
        'type' => $type,
        'status' => $status,
        'device_id' => $deviceId,
        'delete' => false,
        'owner' => 'unknown',
        'reason' => '',
        'db_row_id' => null,
        'local_request_id' => null,
        'expires_at' => null,
    ];

    // 1. 時限パスコード以外は対象外。permanent は職員用の常設コードであり、
    //    誤って削除すると恒久的な締め出しになる。
    if ($type !== 'timeLimit') {
        $base['reason'] = 'skip: type is not timeLimit';
        return $base;
    }

    // 2. 所有者判定。DB 照合を優先し、無ければ命名規則で孤児を拾う。
    $dbRow = $dbIndex[$deviceId . "\0" . $name] ?? null;
    $expiresAt = null;

    if ($dbRow !== null) {
        $base['owner'] = 'db';
        $base['db_row_id'] = (int)($dbRow['id'] ?? 0);
        $base['local_request_id'] = (string)($dbRow['local_request_id'] ?? '');

        $endAt = trim((string)($dbRow['end_at'] ?? ''));
        if ($endAt !== '') {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endAt, $tz);
            if ($parsed !== false) {
                $expiresAt = $parsed;
            }
        }
        // DB 行はあるが end_at が壊れている場合は命名規則にフォールバックする。
        if ($expiresAt === null) {
            $expiresAt = switchbot_cleanup_parse_managed_name($cfg, $name);
        }
    } else {
        $expiresAt = switchbot_cleanup_parse_managed_name($cfg, $name);
        if ($expiresAt !== null) {
            $base['owner'] = 'name_pattern';
        }
    }

    if ($base['owner'] === 'unknown') {
        $base['reason'] = 'skip: not managed by this system (no DB match, name pattern mismatch)';
        return $base;
    }

    if ($expiresAt === null) {
        $base['reason'] = 'skip: expiry time could not be determined';
        return $base;
    }
    $base['expires_at'] = $expiresAt->format('Y-m-d H:i:s');

    // 3. 孤児は SwitchBot 自身の expired 判定を必須にする。
    //    命名規則の日付だけを根拠に消すのは危険なため。
    if ($base['owner'] === 'name_pattern' && $conf['require_expired_status_for_orphans'] && $status !== 'expired') {
        $base['reason'] = 'skip: orphan key is not reported as expired by SwitchBot';
        return $base;
    }

    // 4. 猶予時間。期限到来直後の延長・忘れ物対応の余地を残す。
    $deleteAfter = $expiresAt->getTimestamp() + $graceSeconds;
    if ($deleteAfter >= $now->getTimestamp()) {
        $base['reason'] = sprintf('skip: within grace period (deletable after %s)', date('Y-m-d H:i:s', $deleteAfter));
        return $base;
    }

    $base['delete'] = true;
    $base['reason'] = sprintf(
        'delete: owner=%s, expired_at=%s, grace=%dh, switchbot_status=%s',
        $base['owner'],
        $base['expires_at'],
        $conf['grace_hours'],
        $status !== '' ? $status : 'unknown'
    );

    return $base;
}

/**
 * 削除成功をDBに反映する。併せて平文パスコードを破棄する。
 * 端末から消えた以上、平文を保持し続ける理由がない。
 */
function switchbot_cleanup_mark_deleted(array $cfg, array $decision, string $commandId): void
{
    $rowId = (int)($decision['db_row_id'] ?? 0);
    if ($rowId <= 0) {
        return;
    }

    $conf = switchbot_cleanup_config($cfg);
    $pdo = switchbot_db_connect($cfg);
    $table = switchbot_request_table_name($cfg);
    $now = switchbot_cleanup_now($cfg)->format('Y-m-d H:i:s');

    $passcodeSql = $conf['purge_passcode_on_delete'] ? ', passcode = NULL' : '';

    $stmt = $pdo->prepare(
        "UPDATE `{$table}`
            SET switchbot_key_id = :key_id,
                key_deleted_at = :deleted_at,
                key_delete_status = :delete_status,
                key_delete_command_id = :command_id,
                updated_at = :updated_at
                {$passcodeSql}
          WHERE id = :id"
    );

    $stmt->execute([
        ':key_id' => (int)$decision['key_id'],
        ':deleted_at' => $now,
        ':delete_status' => 'deleted',
        ':command_id' => ($commandId !== '' ? $commandId : null),
        ':updated_at' => $now,
        ':id' => $rowId,
    ]);
}

function switchbot_cleanup_mark_failed(array $cfg, array $decision, string $message): void
{
    $rowId = (int)($decision['db_row_id'] ?? 0);
    if ($rowId <= 0) {
        return;
    }

    $pdo = switchbot_db_connect($cfg);
    $table = switchbot_request_table_name($cfg);
    $now = switchbot_cleanup_now($cfg)->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        "UPDATE `{$table}`
            SET switchbot_key_id = :key_id,
                key_delete_status = :delete_status,
                key_delete_error = :error,
                updated_at = :updated_at
          WHERE id = :id"
    );

    $stmt->execute([
        ':key_id' => (int)$decision['key_id'],
        ':delete_status' => 'error',
        ':error' => (function_exists('mb_substr') ? mb_substr($message, 0, 500, 'UTF-8') : substr($message, 0, 500)),
        ':updated_at' => $now,
        ':id' => $rowId,
    ]);
}

/**
 * 多重起動防止のロックを取得する。cron が詰まった際に
 * 同じパスコードへ二重に deleteKey を投げるのを防ぐ。
 */
function switchbot_cleanup_acquire_lock(array $cfg)
{
    $conf = switchbot_cleanup_config($cfg);
    $path = (string)$conf['lock_file'];

    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('ロックファイルのディレクトリを作成できませんでした: ' . $dir);
    }

    $fp = fopen($path, 'c');
    if ($fp === false) {
        throw new RuntimeException('ロックファイルを開けませんでした: ' . $path);
    }

    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return null;
    }

    return $fp;
}

function switchbot_cleanup_release_lock($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * 期限切れパスコードの棚卸しと削除を実行する。
 */
function switchbot_cleanup_run(array $cfg, bool $dryRunOverride = false): array
{
    $conf = switchbot_cleanup_config($cfg);
    $now = switchbot_cleanup_now($cfg);
    $dryRun = $dryRunOverride || $conf['dry_run'];

    $summary = [
        'timestamp' => $now->format('Y-m-d H:i:s'),
        'dry_run' => $dryRun,
        'enabled' => $conf['enabled'],
        'grace_hours' => $conf['grace_hours'],
        'devices' => [],
        'scanned_keys' => 0,
        'candidates' => 0,
        'deleted' => 0,
        'failed' => 0,
        'skipped' => 0,
        'limit_reached' => false,
        'errors' => [],
    ];

    if (!$conf['enabled']) {
        $summary['errors'][] = 'switchbot_passcode_cleanup.enabled が false のため実行しませんでした。';
        return $summary;
    }

    if (!switchbot_is_configured($cfg)) {
        $summary['errors'][] = 'SwitchBot API の認証情報が未設定です。';
        return $summary;
    }

    $lock = switchbot_cleanup_acquire_lock($cfg);
    if ($lock === null) {
        $summary['errors'][] = '別のクリーンアップ処理が実行中のためスキップしました。';
        return $summary;
    }

    try {
        // dry-run は書き込まないのでスキーマ更新前でも下見できるようにしておく。
        if (!$dryRun) {
            switchbot_cleanup_assert_schema($cfg);
        }

        $deviceMap = switchbot_get_keypad_key_lists($cfg);
        $dbIndex = switchbot_cleanup_load_db_index($cfg);

        foreach ($deviceMap as $deviceId => $device) {
            $deviceSummary = [
                'device_id' => $deviceId,
                'device_name' => (string)($device['device_name'] ?? ''),
                'key_list_supported' => (bool)($device['key_list_supported'] ?? false),
                'total_keys' => count($device['keys']),
                'deleted' => 0,
                'failed' => 0,
                'skipped' => 0,
                'decisions' => [],
            ];

            // keyList を返さないファームウェア／機種では棚卸し自体が成立しない。
            // 黙って «0 件» と報告すると異常に気付けないため明示的に記録する。
            if (!$deviceSummary['key_list_supported']) {
                $message = sprintf('デバイス %s (%s) は keyList を返しません。棚卸しできません。', $deviceId, $deviceSummary['device_name']);
                $summary['errors'][] = $message;
                switchbot_cleanup_log($cfg, ['event' => 'key_list_unsupported', 'device_id' => $deviceId]);
                $summary['devices'][] = $deviceSummary;
                continue;
            }

            foreach ($device['keys'] as $key) {
                $summary['scanned_keys']++;
                $decision = switchbot_cleanup_evaluate_key($cfg, $key, $deviceId, $dbIndex, $now);

                if (!$decision['delete']) {
                    $summary['skipped']++;
                    $deviceSummary['skipped']++;
                    $deviceSummary['decisions'][] = $decision;
                    continue;
                }

                $summary['candidates']++;

                if ($summary['deleted'] + $summary['failed'] >= $conf['max_deletions_per_run']) {
                    $summary['limit_reached'] = true;
                    $decision['result'] = 'deferred_limit';
                    $deviceSummary['decisions'][] = $decision;
                    continue;
                }

                if ($dryRun) {
                    $decision['result'] = 'dry_run';
                    $deviceSummary['decisions'][] = $decision;
                    switchbot_cleanup_log($cfg, ['event' => 'dry_run_candidate'] + $decision);
                    continue;
                }

                try {
                    $response = switchbot_delete_key($cfg, $deviceId, (int)$decision['key_id']);
                    $statusCode = (int)($response['statusCode'] ?? 0);
                    $commandId = trim((string)($response['body']['commandId'] ?? ''));

                    if ($statusCode !== 100) {
                        throw new RuntimeException(trim((string)($response['message'] ?? 'SwitchBot API error')) . ' (statusCode=' . $statusCode . ')');
                    }

                    switchbot_cleanup_mark_deleted($cfg, $decision, $commandId);
                    $decision['result'] = 'deleted';
                    $decision['command_id'] = $commandId;
                    $summary['deleted']++;
                    $deviceSummary['deleted']++;
                    switchbot_cleanup_log($cfg, ['event' => 'deleted'] + $decision);
                } catch (Throwable $e) {
                    $decision['result'] = 'error';
                    $decision['error'] = $e->getMessage();
                    $summary['failed']++;
                    $deviceSummary['failed']++;
                    $summary['errors'][] = sprintf('key_id=%d の削除に失敗: %s', (int)$decision['key_id'], $e->getMessage());
                    switchbot_cleanup_mark_failed($cfg, $decision, $e->getMessage());
                    switchbot_cleanup_log($cfg, ['event' => 'delete_failed'] + $decision);
                }

                $deviceSummary['decisions'][] = $decision;

                if ($conf['api_sleep_ms'] > 0) {
                    usleep($conf['api_sleep_ms'] * 1000);
                }
            }

            $summary['devices'][] = $deviceSummary;
        }

        switchbot_cleanup_log($cfg, [
            'event' => 'run_summary',
            'dry_run' => $dryRun,
            'scanned_keys' => $summary['scanned_keys'],
            'candidates' => $summary['candidates'],
            'deleted' => $summary['deleted'],
            'failed' => $summary['failed'],
            'skipped' => $summary['skipped'],
            'limit_reached' => $summary['limit_reached'],
        ]);
    } finally {
        switchbot_cleanup_release_lock($lock);
    }

    return $summary;
}
