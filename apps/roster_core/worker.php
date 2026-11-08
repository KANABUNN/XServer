<?php

declare(strict_types=1);

/* =====================================================================
   反映ワーカー（apps/jobs/roster_kintone_apply.php から cron で実行）
   - accept 済みジョブを1件ずつ原子的に取得し（期限付きロック）、承認時のスナップショットだけを ID20 へ反映する。
   - 実行時にも accept の有効性（失効・取消・最新リビジョン・対象年度）を確認する。
   - 書込みの成否が分からないときは「結果確認待ち」にし、次回 kintone を検索・照合してから判断する。
   ===================================================================== */

final class RosterLeaseLost extends RuntimeException {}

const ROSTER_WORKER_KEY = 'roster_apply';

function roster_worker_lock_file(): string
{
    $path = (string)roster_config('worker.lock_file', '');
    return $path !== '' ? $path : kintone_storage_root() . '/roster_worker.lock';
}

/**
 * @param array{only_job_id?:int, skip_directory?:bool, max_jobs?:int, worker_name?:string} $opts
 */
function roster_worker_run(array $opts = []): array
{
    $lockPath = roster_worker_lock_file();
    kintone_ensure_dir(dirname($lockPath), 0700);
    $fp = fopen($lockPath, 'c');
    if ($fp === false) {
        throw new RuntimeException('ワーカーのロックファイルを開けません。');
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return ['status' => 'locked', 'message' => '別のワーカーが実行中です。'];
    }
    $summary = ['status' => 'ok', 'recovered' => 0, 'processed' => [], 'directory' => null, 'purged_verifications' => 0];
    $pdo = roster_db();
    roster_worker_heartbeat($pdo, 'start');
    try {
        $summary['recovered'] = roster_worker_recover_leases($pdo);
        if (empty($opts['skip_directory'])) {
            try {
                $status = roster_directory_status($pdo);
                if ($status['is_stale']) {
                    $summary['directory'] = roster_directory_refresh($pdo);
                }
            } catch (Throwable $e) {
                $summary['directory'] = ['error' => mb_substr($e->getMessage(), 0, 300, 'UTF-8')];
                roster_log('warning', 'directory refresh failed', ['error' => $e->getMessage()]);
            }
            $summary['purged_verifications'] = roster_purge_old_verifications($pdo);
        }
        $max = max(1, (int)($opts['max_jobs'] ?? roster_config('worker.max_jobs_per_run', 20)));
        $onlyJobId = (int)($opts['only_job_id'] ?? 0);
        for ($i = 0; $i < $max; $i++) {
            $claim = roster_worker_claim($pdo, $onlyJobId > 0 ? $onlyJobId : null, (string)($opts['worker_name'] ?? ''));
            if ($claim === null) {
                break;
            }
            $summary['processed'][] = roster_worker_execute($pdo, $claim);
            if ($onlyJobId > 0) {
                break;
            }
        }
        roster_worker_heartbeat($pdo, 'ok', $summary);
    } catch (Throwable $e) {
        $summary['status'] = 'error';
        $summary['error'] = mb_substr($e->getMessage(), 0, 500, 'UTF-8');
        roster_log('error', 'worker failed', ['error' => $e->getMessage()]);
        roster_worker_heartbeat($pdo, 'error', $summary);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
    return $summary;
}

function roster_worker_heartbeat(PDO $pdo, string $status, array $summary = []): void
{
    try {
        $now = roster_now_sql();
        if ($status === 'start') {
            $pdo->prepare('INSERT INTO roster_worker_heartbeats (worker_key, last_started_at, last_status) VALUES (:key, :now, "running") ON DUPLICATE KEY UPDATE last_started_at = VALUES(last_started_at), last_status = "running"')->execute([':key' => ROSTER_WORKER_KEY, ':now' => $now]);
            return;
        }
        $compact = [
            'recovered' => $summary['recovered'] ?? 0,
            'processed' => array_map(static fn(array $p): string => '#' . ($p['job_id'] ?? '?') . ':' . ($p['status'] ?? '?'), $summary['processed'] ?? []),
            'directory' => $summary['directory'] ?? null,
            'error' => $summary['error'] ?? null,
        ];
        $pdo->prepare('UPDATE roster_worker_heartbeats SET last_finished_at = :now, last_status = :status, last_summary = :summary WHERE worker_key = :key')->execute([
            ':now' => $now,
            ':status' => $status,
            ':summary' => mb_substr(roster_json($compact), 0, 4000, 'UTF-8'),
            ':key' => ROSTER_WORKER_KEY,
        ]);
    } catch (Throwable $e) {
        roster_log('warning', 'heartbeat failed', ['error' => $e->getMessage()]);
    }
}

function roster_worker_heartbeat_status(?PDO $pdo = null): array
{
    $pdo = $pdo ?? roster_db();
    $stmt = $pdo->prepare('SELECT * FROM roster_worker_heartbeats WHERE worker_key = :key LIMIT 1');
    $stmt->execute([':key' => ROSTER_WORKER_KEY]);
    $row = $stmt->fetch() ?: null;
    $staleMinutes = (int)roster_config('worker.heartbeat_stale_minutes', 30);
    $last = is_array($row) ? (string)($row['last_finished_at'] ?: $row['last_started_at'] ?: '') : '';
    $age = $last !== '' ? (int)floor((roster_now()->getTimestamp() - (new DateTimeImmutable($last, kintone_timezone()))->getTimestamp()) / 60) : null;
    return [
        'row' => $row,
        'age_minutes' => $age,
        'is_stale' => $age === null || $age > $staleMinutes,
    ];
}

/** 期限切れのロックを復旧する。書込み送信後に止まったジョブは未実行へ戻さず「結果確認待ち」にする。 */
function roster_worker_recover_leases(PDO $pdo): int
{
    $stmt = $pdo->prepare('SELECT id, case_id FROM roster_apply_jobs WHERE status = :running AND lease_expires_at < :now ORDER BY id ASC LIMIT 50');
    $stmt->execute([':running' => ROSTER_JOB_RUNNING, ':now' => roster_now_sql()]);
    $count = 0;
    foreach ($stmt->fetchAll() ?: [] as $candidate) {
        $pdo->beginTransaction();
        try {
            $case = roster_case_by_id($pdo, (int)$candidate['case_id'], true);
            $job = roster_job_by_id($pdo, (int)$candidate['id'], true);
            if (!is_array($job) || (string)$job['status'] !== ROSTER_JOB_RUNNING || (string)$job['lease_expires_at'] >= roster_now_sql()) {
                $pdo->commit();
                continue;
            }
            $writeSent = in_array((string)$job['phase'], ['post_sent', 'put_sent', 'written'], true);
            $next = $writeSent ? ROSTER_JOB_UNKNOWN : ROSTER_JOB_QUEUED;
            $pdo->prepare('UPDATE roster_apply_jobs SET status = :status, lease_token = NULL, lease_expires_at = NULL, next_run_at = :next, error_class = :class, error_message = :msg WHERE id = :id')->execute([
                ':status' => $next,
                ':next' => roster_now_sql($writeSent ? (int)roster_config('worker.unknown_reconcile_delay_seconds', 300) : 0),
                ':class' => $writeSent ? 'unknown' : 'transient',
                ':msg' => $writeSent ? '書込み送信後にワーカーが停止しました。kintoneを照合してから再処理します。' : 'ワーカーが途中で停止したため、未送信の状態から再実行します。',
                ':id' => (int)$job['id'],
            ]);
            $pdo->prepare('UPDATE roster_apply_attempts SET finished_at = :now, outcome = "lease_expired" WHERE job_id = :id AND finished_at IS NULL')->execute([':now' => roster_now_sql(), ':id' => (int)$job['id']]);
            if (is_array($case) && (int)$case['current_job_id'] === (int)$job['id']) {
                $pdo->prepare('UPDATE roster_cases SET apply_state = :state WHERE id = :id')->execute([':state' => $next, ':id' => (int)$case['id']]);
            }
            $pdo->commit();
            $count++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
    return $count;
}

/** accept がいまも有効か。無効なら理由を返す（手動・再試行・CLI・cron のすべての経路で確認する） */
function roster_worker_invalid_reason(PDO $pdo, array $case, array $job, ?array $acceptance): ?string
{
    if ($job['invalidated_at'] !== null) {
        return 'invalidated';
    }
    if (!is_array($acceptance) || $acceptance['revoked_at'] !== null || (int)$acceptance['active_revision_id'] !== (int)$job['revision_id']) {
        return 'acceptance_revoked';
    }
    if ((int)$case['current_job_id'] !== (int)$job['id'] || (int)$case['current_acceptance_id'] !== (int)$acceptance['id']) {
        return 'not_current_job';
    }
    if ((int)$case['latest_revision_id'] !== (int)$job['revision_id']) {
        return 'superseded';
    }
    $revision = roster_revision_by_id($pdo, (int)$job['revision_id']);
    if (!is_array($revision) || (string)$revision['review_state'] !== ROSTER_REVIEW_ACCEPTED) {
        return 'revision_not_accepted';
    }
    $form = roster_form_row($pdo, (int)$case['form_id']);
    if (!is_array($form) || !$form['roster']['is_roster']) {
        return 'form_not_roster';
    }
    if ((int)$form['roster']['fiscal_year'] !== (int)$case['fiscal_year']) {
        return 'past_fiscal_year';
    }
    $snapshot = roster_json_decode((string)$acceptance['snapshot_json'], []);
    if (roster_payload_hash((array)($snapshot['rows'] ?? [])) !== (string)$acceptance['snapshot_hash']) {
        return 'snapshot_hash_mismatch';
    }
    return null;
}

function roster_worker_claim(PDO $pdo, ?int $onlyJobId = null, string $workerName = ''): ?array
{
    if ($onlyJobId !== null) {
        $stmt = $pdo->prepare('SELECT id, case_id FROM roster_apply_jobs WHERE id = :id AND status IN (:queued, :unknown) AND invalidated_at IS NULL');
        $stmt->execute([':id' => $onlyJobId, ':queued' => ROSTER_JOB_QUEUED, ':unknown' => ROSTER_JOB_UNKNOWN]);
    } else {
        $stmt = $pdo->prepare('SELECT id, case_id FROM roster_apply_jobs WHERE status IN (:queued, :unknown) AND invalidated_at IS NULL AND next_run_at <= :now ORDER BY next_run_at ASC, id ASC LIMIT 20');
        $stmt->execute([':queued' => ROSTER_JOB_QUEUED, ':unknown' => ROSTER_JOB_UNKNOWN, ':now' => roster_now_sql()]);
    }
    foreach ($stmt->fetchAll() ?: [] as $candidate) {
        $pdo->beginTransaction();
        try {
            // 同じ団体の名簿処理を直列化するため、案件 → ジョブの順で行ロックを取る
            $case = roster_case_by_id($pdo, (int)$candidate['case_id'], true);
            $job = roster_job_by_id($pdo, (int)$candidate['id'], true);
            if (!is_array($case) || !is_array($job) || !in_array((string)$job['status'], [ROSTER_JOB_QUEUED, ROSTER_JOB_UNKNOWN], true) || $job['invalidated_at'] !== null) {
                $pdo->commit();
                continue;
            }
            if ($onlyJobId === null && (string)$job['next_run_at'] > roster_now_sql()) {
                $pdo->commit();
                continue;
            }
            $acceptance = roster_acceptance_by_id($pdo, (int)$job['acceptance_id'], true);
            $reason = roster_worker_invalid_reason($pdo, $case, $job, $acceptance);
            if ($reason !== null) {
                $wasUnknown = (string)$job['status'] === ROSTER_JOB_UNKNOWN;
                $pdo->prepare('UPDATE roster_apply_jobs SET status = :cancelled, invalidated_at = :now, invalidated_reason = :reason, finished_at = :now2 WHERE id = :id')->execute([
                    ':cancelled' => ROSTER_JOB_CANCELLED,
                    ':now' => roster_now_sql(),
                    ':now2' => roster_now_sql(),
                    ':reason' => mb_substr($reason . ($wasUnknown ? '_while_unknown' : ''), 0, 64),
                    ':id' => (int)$job['id'],
                ]);
                if ((int)$case['current_job_id'] === (int)$job['id']) {
                    $pdo->prepare('UPDATE roster_cases SET apply_state = :state WHERE id = :id')->execute([':state' => ROSTER_APPLY_NONE, ':id' => (int)$case['id']]);
                }
                $pdo->commit();
                roster_log('warning', 'job invalidated at claim', ['job_id' => (int)$job['id'], 'reason' => $reason]);
                continue;
            }
            $mode = (string)$job['status'] === ROSTER_JOB_UNKNOWN ? 'reconcile' : 'apply';
            $token = bin2hex(random_bytes(16));
            $attemptNo = (int)$job['attempts'] + 1;
            $updated = $pdo->prepare(
                'UPDATE roster_apply_jobs SET status = :running, mode = :mode, lease_token = :token, lease_expires_at = :expires, locked_by = :by, attempts = :attempts '
                . 'WHERE id = :id AND status = :prev AND invalidated_at IS NULL'
            );
            $updated->execute([
                ':running' => ROSTER_JOB_RUNNING,
                ':mode' => $mode,
                ':token' => $token,
                ':expires' => roster_now_sql(max(60, (int)roster_config('worker.lease_seconds', 600))),
                ':by' => mb_substr(($workerName !== '' ? $workerName : (PHP_SAPI . '@' . (gethostname() ?: 'host'))) . ':' . getmypid(), 0, 120),
                ':attempts' => $attemptNo,
                ':id' => (int)$job['id'],
                ':prev' => (string)$job['status'],
            ]);
            if ($updated->rowCount() !== 1) {
                $pdo->commit();
                continue;
            }
            $pdo->prepare('UPDATE roster_cases SET apply_state = :state WHERE id = :id')->execute([':state' => ROSTER_JOB_RUNNING, ':id' => (int)$case['id']]);
            $pdo->prepare('INSERT INTO roster_apply_attempts (job_id, attempt_no, mode, started_at) VALUES (:job, :no, :mode, :now)')->execute([
                ':job' => (int)$job['id'],
                ':no' => $attemptNo,
                ':mode' => $mode,
                ':now' => roster_now_sql(),
            ]);
            $attemptId = (int)$pdo->lastInsertId();
            $pdo->commit();
            $job = roster_job_by_id($pdo, (int)$job['id']);
            return ['job' => $job, 'case' => $case, 'acceptance' => $acceptance, 'token' => $token, 'attempt_id' => $attemptId, 'mode' => $mode];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
    return null;
}

/** リース（期限付きロック）を持っている間だけジョブを更新する */
function roster_worker_update_job(PDO $pdo, array $claim, array $fields): void
{
    $sets = [];
    $params = [':id' => (int)$claim['job']['id'], ':token' => $claim['token'], ':running' => ROSTER_JOB_RUNNING];
    foreach ($fields as $column => $value) {
        $sets[] = $column . ' = :f_' . $column;
        $params[':f_' . $column] = $value;
    }
    $stmt = $pdo->prepare('UPDATE roster_apply_jobs SET ' . implode(', ', $sets) . ' WHERE id = :id AND lease_token = :token AND status = :running');
    $stmt->execute($params);
    if ($stmt->rowCount() !== 1) {
        // 値が変わらない UPDATE でも rowCount は 0 になるため、リースを再確認する
        $check = $pdo->prepare('SELECT COUNT(*) FROM roster_apply_jobs WHERE id = :id AND lease_token = :token AND status = :running');
        $check->execute([':id' => (int)$claim['job']['id'], ':token' => $claim['token'], ':running' => ROSTER_JOB_RUNNING]);
        if ((int)$check->fetchColumn() !== 1) {
            throw new RosterLeaseLost('ジョブのロックが失われました。');
        }
    }
}

function roster_worker_execute(PDO $pdo, array $claim): array
{
    $job = $claim['job'];
    $case = $claim['case'];
    $snapshot = roster_json_decode((string)$claim['acceptance']['snapshot_json'], []);
    $rows = (array)($snapshot['rows'] ?? []);
    $groupCode = (string)$case['group_code'];
    $phase = 'search';
    $detail = [];
    try {
        $preflight = roster_kintone_preflight();
        if (!$preflight['ok']) {
            return roster_worker_finish($pdo, $claim, ROSTER_JOB_FAILED, [
                'error_class' => 'config',
                'error_code' => 'FIELD_CHECK',
                'error_message' => 'kintoneのフィールド設定が不足しているため反映を止めました：' . implode(' ', $preflight['errors']),
            ], ['phase' => $phase, 'preflight' => $preflight['errors']]);
        }
        roster_worker_update_job($pdo, $claim, ['phase' => 'search']);
        $found = roster_kintone_find_group_records($groupCode);
        if ($claim['mode'] === 'reconcile') {
            $outcome = roster_worker_reconcile($pdo, $claim, $found, $rows);
            if ($outcome !== null) {
                return $outcome;
            }
            $detail['reconciled'] = 'not_applied_confirmed';
        }
        $acceptance = $claim['acceptance'];
        if ($found['count'] > 1) {
            return roster_worker_finish($pdo, $claim, ROSTER_JOB_CONFLICT, [
                'error_class' => 'conflict', 'error_code' => 'MULTIPLE_RECORDS',
                'error_message' => 'kintone上に同じ団体のレコードが' . $found['count'] . '件あります。最初の1件を選ばずに停止しました。',
            ], $detail);
        }
        if ($found['count'] === 0) {
            if ((int)$acceptance['compared_record_count'] !== 0) {
                return roster_worker_finish($pdo, $claim, ROSTER_JOB_CONFLICT, [
                    'error_class' => 'conflict', 'error_code' => 'RECORD_DELETED',
                    'error_message' => 'accept時にあった kintone のレコード（ID ' . (int)$acceptance['compared_record_id'] . '）が見つかりません。',
                ], $detail);
            }
            $build = roster_kintone_build_table($rows, ['rows' => [], 'raw_rows' => []]);
            $phase = 'post_sent';
            roster_worker_update_job($pdo, $claim, ['phase' => 'post_sent', 'write_kind' => 'add', 'before_revision' => null]);
            $res = roster_kintone_add_record($groupCode, $build['table']);
            $phase = 'written';
            roster_worker_update_job($pdo, $claim, ['phase' => 'written', 'target_record_id' => $res['record_id'] ?: null, 'after_revision' => $res['revision'] ?: null]);
            $record = roster_kintone_get_record((int)$res['record_id']);
            return roster_worker_complete_verified($pdo, $claim, $record, $rows, [], $detail + ['write' => 'add']);
        }
        $record = $found['records'][0];
        if ((int)$acceptance['compared_record_count'] === 0) {
            return roster_worker_finish($pdo, $claim, ROSTER_JOB_CONFLICT, [
                'error_class' => 'conflict', 'error_code' => 'RECORD_CREATED',
                'error_message' => 'accept後に kintone で同じ団体のレコード（ID ' . $record['record_id'] . '）が作成されました。差分を確認し直してください。',
                'target_record_id' => $record['record_id'],
            ], $detail);
        }
        if ($record['record_id'] !== (int)$acceptance['compared_record_id'] || $record['revision'] !== (int)$acceptance['compared_revision']) {
            return roster_worker_finish($pdo, $claim, ROSTER_JOB_CONFLICT, [
                'error_class' => 'conflict', 'error_code' => 'REVISION_CHANGED',
                'error_message' => 'accept後に kintone の名簿が更新されました（リビジョン ' . (int)$acceptance['compared_revision'] . ' → ' . $record['revision'] . '）。上書きせずに停止しました。',
                'target_record_id' => $record['record_id'],
            ], $detail);
        }
        $build = roster_kintone_build_table($rows, $record);
        $keep = array_map(static fn(array $r): string => (string)$r['kintone_row_id'], $record['rows']);
        if (!$build['changed']) {
            roster_worker_update_job($pdo, $claim, ['phase' => 'written', 'write_kind' => 'update', 'target_record_id' => $record['record_id'], 'before_revision' => $record['revision'], 'after_revision' => $record['revision']]);
            return roster_worker_complete_verified($pdo, $claim, $record, $rows, $keep, $detail + ['write' => 'none_needed']);
        }
        $phase = 'put_sent';
        roster_worker_update_job($pdo, $claim, ['phase' => 'put_sent', 'write_kind' => 'update', 'target_record_id' => $record['record_id'], 'before_revision' => $record['revision']]);
        $res = roster_kintone_update_record($record['record_id'], $record['revision'], $build['table']);
        $phase = 'written';
        roster_worker_update_job($pdo, $claim, ['phase' => 'written', 'after_revision' => $res['revision'] ?: null]);
        $after = roster_kintone_get_record($record['record_id']);
        return roster_worker_complete_verified($pdo, $claim, $after, $rows, $keep, $detail + ['write' => 'update', 'kept_rows' => $build['kept']]);
    } catch (RosterLeaseLost $e) {
        roster_log('warning', 'lease lost', ['job_id' => (int)$job['id']]);
        return ['job_id' => (int)$job['id'], 'status' => 'lease_lost'];
    } catch (Throwable $e) {
        return roster_worker_handle_error($pdo, $claim, $e, $phase, $detail);
    }
}

/**
 * 結果確認待ちの照合。null を返したら「未適用と確認できた」ので通常の反映へ進む。
 */
function roster_worker_reconcile(PDO $pdo, array $claim, array $found, array $rows): ?array
{
    $job = $claim['job'];
    $kind = (string)($job['write_kind'] ?? '');
    $detail = ['reconcile' => true, 'write_kind' => $kind];
    if ($kind === 'add') {
        if ($found['count'] === 0) {
            return null;
        }
        if ($found['count'] === 1) {
            $record = $found['records'][0];
            $verify = roster_kintone_verify($record, $rows);
            if ($verify['ok']) {
                return roster_worker_complete_verified($pdo, $claim, $record, $rows, [], $detail + ['result' => 'add_confirmed']);
            }
            return roster_worker_finish($pdo, $claim, ROSTER_JOB_CONFLICT, [
                'error_class' => 'conflict', 'error_code' => 'RECONCILE_MISMATCH', 'target_record_id' => $record['record_id'],
                'error_message' => '成否不明だった新規登録を照合しましたが、kintone 上のレコード内容が承認内容と一致しません。重複作成や手動登録の可能性があるため確認してください。',
            ], $detail + ['mismatches' => array_slice($verify['mismatches'], 0, 10)]);
        }
        return roster_worker_finish($pdo, $claim, ROSTER_JOB_CONFLICT, [
            'error_class' => 'conflict', 'error_code' => 'MULTIPLE_RECORDS',
            'error_message' => '成否不明だった新規登録を照合したところ、同じ団体のレコードが' . $found['count'] . '件あります（重複作成の可能性）。',
        ], $detail);
    }
    if ($kind === 'update') {
        if ($found['count'] !== 1 || $found['records'][0]['record_id'] !== (int)$job['target_record_id']) {
            return roster_worker_finish($pdo, $claim, ROSTER_JOB_CONFLICT, [
                'error_class' => 'conflict', 'error_code' => 'RECONCILE_RECORD',
                'error_message' => '成否不明だった更新を照合しましたが、対象レコードを特定できません（' . $found['count'] . '件）。',
            ], $detail);
        }
        $record = $found['records'][0];
        if ($record['revision'] === (int)$job['before_revision']) {
            return null;
        }
        $verify = roster_kintone_verify($record, $rows);
        if ($verify['ok']) {
            return roster_worker_complete_verified($pdo, $claim, $record, $rows, [], $detail + ['result' => 'update_confirmed']);
        }
        return roster_worker_finish($pdo, $claim, ROSTER_JOB_CONFLICT, [
            'error_class' => 'conflict', 'error_code' => 'RECONCILE_MISMATCH', 'target_record_id' => $record['record_id'],
            'error_message' => '成否不明だった更新を照合しましたが、kintone の名簿が承認内容と一致しません（別の更新があった可能性）。',
        ], $detail + ['mismatches' => array_slice($verify['mismatches'], 0, 10)]);
    }
    return null;
}

function roster_worker_complete_verified(PDO $pdo, array $claim, array $record, array $rows, array $mustKeep, array $detail): array
{
    $verify = roster_kintone_verify($record, $rows, $mustKeep);
    if (!$verify['ok']) {
        return roster_worker_finish($pdo, $claim, ROSTER_JOB_FAILED, [
            'error_class' => 'verification', 'error_code' => 'READBACK_MISMATCH',
            'target_record_id' => $record['record_id'], 'after_revision' => $record['revision'],
            'error_message' => 'kintoneへ書き込みましたが、読み戻した内容が承認内容と一致しません：' . implode(' ', array_slice($verify['mismatches'], 0, 5)),
        ], $detail + ['mismatches' => array_slice($verify['mismatches'], 0, 20)]);
    }
    return roster_worker_finish($pdo, $claim, ROSTER_JOB_APPLIED, [
        'target_record_id' => $record['record_id'],
        'after_revision' => $record['revision'],
        'error_class' => null, 'error_code' => null, 'error_message' => null,
    ], $detail + ['rows' => count($rows)], $verify['links']);
}

function roster_worker_handle_error(PDO $pdo, array $claim, Throwable $e, string $phase, array $detail): array
{
    $writeSent = in_array($phase, ['post_sent', 'put_sent', 'written'], true);
    if ($e instanceof RosterKintoneError) {
        $class = roster_kintone_classify($e, $phase === 'post_sent' || $phase === 'put_sent');
        if ($phase === 'written') {
            // 書込みの応答は受け取ったが、読み戻しに失敗した。照合で確定させる。
            $class = 'unknown';
        }
        $code = $e->kintoneCode ?: ($e->transport ? 'TRANSPORT_' . $e->transportErrno : 'HTTP_' . $e->httpStatus);
        $detail += $e->summary();
    } elseif ($e instanceof RosterException || ($e instanceof RosterKintoneError && $e->kintoneCode === 'ROSTER_AMBIGUOUS_ROWS')) {
        $class = 'conflict';
        $code = 'DOMAIN';
    } else {
        $class = $writeSent ? 'unknown' : 'transient';
        $code = 'INTERNAL';
        roster_log('error', 'worker internal error', ['job_id' => (int)$claim['job']['id'], 'error' => $e->getMessage()]);
    }
    if ($e instanceof RosterKintoneError && $e->kintoneCode === 'ROSTER_AMBIGUOUS_ROWS') {
        $class = 'conflict';
    }
    $message = mb_substr($e->getMessage(), 0, 1000, 'UTF-8');
    $attempts = (int)$claim['job']['attempts'];
    $maxAttempts = max(1, (int)roster_config('worker.max_attempts', 6));
    switch ($class) {
        case 'transient':
            if ($attempts >= $maxAttempts) {
                return roster_worker_finish($pdo, $claim, ROSTER_JOB_FAILED, ['error_class' => 'transient', 'error_code' => $code, 'error_message' => '一時的な障害が続いたため自動再試行を止めました：' . $message], $detail + ['phase' => $phase]);
            }
            return roster_worker_finish($pdo, $claim, ROSTER_JOB_QUEUED, [
                'error_class' => 'transient', 'error_code' => $code, 'error_message' => $message,
                'next_run_at' => roster_now_sql(roster_worker_backoff($attempts)),
                // 未送信が確実なので、書込みの記録を消して通常の反映から再開する
                'phase' => null,
            ], $detail + ['phase' => $phase], [], false);
        case 'unknown':
            return roster_worker_finish($pdo, $claim, ROSTER_JOB_UNKNOWN, [
                'error_class' => 'unknown', 'error_code' => $code,
                'error_message' => 'kintoneへの書込みの成否が確認できません。同じ登録を繰り返さず、次回の実行で照合します：' . $message,
                'next_run_at' => roster_now_sql(max((int)roster_config('worker.unknown_reconcile_delay_seconds', 300), roster_worker_backoff($attempts))),
            ], $detail + ['phase' => $phase], [], false);
        case 'conflict':
            return roster_worker_finish($pdo, $claim, ROSTER_JOB_CONFLICT, ['error_class' => 'conflict', 'error_code' => $code, 'error_message' => $message], $detail + ['phase' => $phase]);
        default:
            return roster_worker_finish($pdo, $claim, ROSTER_JOB_FAILED, ['error_class' => $class, 'error_code' => $code, 'error_message' => $message], $detail + ['phase' => $phase]);
    }
}

function roster_worker_backoff(int $attempts): int
{
    $steps = array_values(array_map('intval', (array)roster_config('worker.retry_backoff_seconds', [60, 300, 900, 1800, 3600])));
    if ($steps === []) {
        return 300;
    }
    return max(30, $steps[min(max(0, $attempts - 1), count($steps) - 1)]);
}

/**
 * ジョブの状態を確定する（リースを持っている場合だけ）。
 * $terminal=false は再実行待ち（queued / unknown）。
 */
function roster_worker_finish(PDO $pdo, array $claim, string $status, array $fields, array $detail = [], array $links = [], bool $terminal = true): array
{
    $job = $claim['job'];
    $case = $claim['case'];
    $now = roster_now_sql();
    $pdo->beginTransaction();
    try {
        $lockedCase = roster_case_by_id($pdo, (int)$case['id'], true);
        $set = array_merge($fields, [
            'status' => $status,
            'lease_token' => null,
            'lease_expires_at' => null,
            'finished_at' => $terminal ? $now : null,
        ]);
        if ($status === ROSTER_JOB_APPLIED) {
            $set['applied_at'] = $now;
            $set['phase'] = 'verified';
        }
        $sets = [];
        $params = [':id' => (int)$job['id'], ':token' => $claim['token'], ':running' => ROSTER_JOB_RUNNING];
        foreach ($set as $column => $value) {
            $sets[] = $column . ' = :f_' . $column;
            $params[':f_' . $column] = $value;
        }
        $stmt = $pdo->prepare('UPDATE roster_apply_jobs SET ' . implode(', ', $sets) . ' WHERE id = :id AND lease_token = :token AND status = :running');
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            roster_log('warning', 'finish skipped (lease lost)', ['job_id' => (int)$job['id'], 'status' => $status]);
            return ['job_id' => (int)$job['id'], 'status' => 'lease_lost'];
        }
        if (is_array($lockedCase) && (int)$lockedCase['current_job_id'] === (int)$job['id']) {
            if ($status === ROSTER_JOB_APPLIED) {
                $pdo->prepare('UPDATE roster_cases SET apply_state = :state, last_applied_job_id = :job, last_applied_revision_number = :rev, last_applied_at = :now WHERE id = :id')->execute([
                    ':state' => $status,
                    ':job' => (int)$job['id'],
                    ':rev' => (int)$claim['acceptance']['revision_number'],
                    ':now' => $now,
                    ':id' => (int)$lockedCase['id'],
                ]);
            } else {
                $pdo->prepare('UPDATE roster_cases SET apply_state = :state WHERE id = :id')->execute([':state' => $status, ':id' => (int)$lockedCase['id']]);
            }
        }
        if ($links !== []) {
            $linkStmt = $pdo->prepare(
                'INSERT INTO roster_row_links (group_code, student_id, kintone_record_id, kintone_row_id, last_job_id) VALUES (:code, :sid, :record, :row, :job) '
                . 'ON DUPLICATE KEY UPDATE kintone_record_id = VALUES(kintone_record_id), kintone_row_id = VALUES(kintone_row_id), last_job_id = VALUES(last_job_id)'
            );
            foreach ($links as $sid => $rowId) {
                $linkStmt->execute([
                    ':code' => (string)$case['group_code'],
                    ':sid' => (string)$sid,
                    ':record' => (int)($fields['target_record_id'] ?? 0),
                    ':row' => (int)$rowId,
                    ':job' => (int)$job['id'],
                ]);
            }
        }
        $pdo->prepare('UPDATE roster_apply_attempts SET finished_at = :now, outcome = :outcome, phase = :phase, http_status = :http, kintone_code = :code, error_class = :class, message = :msg, detail_json = :detail WHERE id = :id')->execute([
            ':now' => $now,
            ':outcome' => $status,
            ':phase' => isset($detail['phase']) ? (string)$detail['phase'] : (string)($set['phase'] ?? ''),
            ':http' => isset($detail['http_status']) ? (int)$detail['http_status'] : null,
            ':code' => $fields['error_code'] ?? null,
            ':class' => $fields['error_class'] ?? null,
            ':msg' => $fields['error_message'] ?? null,
            ':detail' => $detail !== [] ? roster_json($detail) : null,
            ':id' => (int)$claim['attempt_id'],
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    roster_log($status === ROSTER_JOB_APPLIED ? 'info' : 'warning', 'job finished', [
        'job_id' => (int)$job['id'],
        'case_id' => (int)$case['id'],
        'status' => $status,
        'error_class' => $fields['error_class'] ?? null,
        'error_code' => $fields['error_code'] ?? null,
    ]);
    return ['job_id' => (int)$job['id'], 'case_id' => (int)$case['id'], 'status' => $status, 'error_class' => $fields['error_class'] ?? null, 'message' => $fields['error_message'] ?? null];
}
