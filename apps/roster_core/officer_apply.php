<?php

declare(strict_types=1);

/* =====================================================================
   第2段階: 役員の反映（承認計画の固定・団体単位の書込み予約・ステップ実行・代表者の履歴）
   - 書込みは accept で固定した計画（roster_acceptances.snapshot_json）だけを、ID20 → ID22 → ID21 の順に処理する。
   - 各ステップは「待機 → 送信前記録 → 読戻し → 確認済み」。送信後に成否が分からなければ「結果確認待ち」にし、
     一意な値（学籍番号・メール・団体キー）と期待リビジョンで照合してから再送の要否を決める。
   - ロック順は「団体の予約 → 案件 → ジョブ → ステップ」。kintone への HTTP は DB ロックを持たない区間で行う。
   - ステップ・試行の記録には個人情報を残さない（人物は person_N で識別する）。
   ===================================================================== */

const ROSTER_SNAPSHOT_VERSION_OFFICERS = 2;
const ROSTER_STEP_PENDING = 'pending';
const ROSTER_STEP_VERIFIED = 'verified';
const ROSTER_STEP_UNKNOWN = 'unknown';
const ROSTER_STEP_FAILED = 'failed';
const ROSTER_STEP_CONFLICT = 'conflict';

/** 実行中のステップと送信の段階（例外処理で成否不明かどうかを判断するために使う） */
final class RosterStepContext
{
    public ?array $step = null;
    public string $phase = 'search';
}

/* ---------------- 団体単位の書込み予約と、役員の書込み対象 ---------------- */

function roster_guard_tenant_or_default(): string
{
    try {
        return roster_kintone_tenant();
    } catch (Throwable) {
        return 'default';
    }
}

function roster_guard_get(PDO $pdo, string $tenant, string $groupCode): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM roster_group_apply_guards WHERE tenant = :t AND group_code = :g LIMIT 1');
    $stmt->execute([':t' => $tenant, ':g' => $groupCode]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** 団体の予約行をロックする（なければ作る）。案件・ジョブより先に取る。 */
function roster_guard_lock(PDO $pdo, string $tenant, string $groupCode): array
{
    $pdo->prepare('INSERT IGNORE INTO roster_group_apply_guards (tenant, group_code, generation) VALUES (:t, :g, 0)')->execute([':t' => $tenant, ':g' => $groupCode]);
    $stmt = $pdo->prepare('SELECT * FROM roster_group_apply_guards WHERE tenant = :t AND group_code = :g FOR UPDATE');
    $stmt->execute([':t' => $tenant, ':g' => $groupCode]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('団体の書込み予約を取得できません。');
    }
    return $row;
}

function roster_guard_release(PDO $pdo, string $tenant, string $groupCode, int $jobId): void
{
    $pdo->prepare('UPDATE roster_group_apply_guards SET active_job_id = NULL WHERE tenant = :t AND group_code = :g AND active_job_id = :job')->execute([
        ':t' => $tenant, ':g' => $groupCode, ':job' => $jobId,
    ]);
}

/**
 * 役員（ID22・ID21）への書込みを認めるか。サーバー設定・フォームの役員連携・admin が指定した団体／フォーム／年度の三つがそろったときだけ。
 */
function roster_officer_write_gate(PDO $pdo, array $case, ?array $form = null): array
{
    $gate = ['enabled' => false, 'reason' => '', 'tenant' => '', 'generation' => null];
    if (!roster_config('officers.write_enabled', false)) {
        $gate['reason'] = 'サーバー設定で役員の書込みが無効です（roster.officers.write_enabled）。';
        return $gate;
    }
    $form = $form ?? roster_form_row($pdo, (int)$case['form_id']);
    if (!is_array($form) || empty($form['roster']['officer_sync'])) {
        $gate['reason'] = 'このフォームは役員情報を受け付けていません。';
        return $gate;
    }
    try {
        $gate['tenant'] = roster_kintone_tenant();
    } catch (Throwable) {
        $gate['reason'] = 'kintone の接続先を確認できません。';
        return $gate;
    }
    $guard = roster_guard_get($pdo, $gate['tenant'], (string)$case['group_code']);
    if (!is_array($guard) || (int)$guard['officer_form_id'] !== (int)$case['form_id'] || (int)$guard['officer_fiscal_year'] !== (int)$case['fiscal_year']) {
        $gate['reason'] = 'この団体は、このフォーム・年度での役員の書込み対象に指定されていません（「反映・接続の確認」で admin が指定します）。';
        return $gate;
    }
    $gate['enabled'] = true;
    $gate['generation'] = (int)$guard['generation'];
    return $gate;
}

/** admin が指定した役員の書込み対象（団体・フォーム・年度）の一覧 */
function roster_officer_targets(?PDO $pdo = null): array
{
    $pdo = $pdo ?? roster_db();
    $rows = $pdo->query(
        'SELECT g.tenant, g.group_code, g.officer_form_id, g.officer_fiscal_year, g.generation, g.active_job_id, g.updated_by, g.updated_at, f.name AS form_name, d.group_name '
        . 'FROM roster_group_apply_guards g LEFT JOIN managed_forms f ON f.id = g.officer_form_id LEFT JOIN roster_group_directory d ON d.group_code = g.group_code '
        . 'WHERE g.officer_form_id IS NOT NULL ORDER BY g.group_code'
    )->fetchAll();
    return $rows ?: [];
}

/** 役員の書込み対象に団体を指定する（設定の世代を進め、指定前の accept は実行時に止まる） */
function roster_officer_target_set(string $groupCode, int $formId, array $actor): array
{
    $code = roster_normalize_group_code($groupCode);
    $pdo = roster_db();
    if ($code === '' || roster_directory_get($code, $pdo) === null) {
        throw new RosterException('団体識別記号が団体一覧にありません。団体一覧を更新してから指定してください。');
    }
    $form = roster_form_row($pdo, $formId);
    if (!is_array($form) || !$form['roster']['is_roster'] || empty($form['roster']['officer_sync'])) {
        throw new RosterException('役員情報を受け付ける名簿フォームを選んでください。');
    }
    if ((int)$form['roster']['fiscal_year'] < 2000) {
        throw new RosterException('フォームの対象年度が設定されていません。');
    }
    $tenant = roster_kintone_tenant();
    $pdo->beginTransaction();
    try {
        roster_guard_lock($pdo, $tenant, $code);
        $pdo->prepare('UPDATE roster_group_apply_guards SET officer_form_id = :f, officer_fiscal_year = :y, generation = generation + 1, updated_by = :by WHERE tenant = :t AND group_code = :g')->execute([
            ':f' => $formId,
            ':y' => (int)$form['roster']['fiscal_year'],
            ':by' => mb_substr((string)($actor['display_name'] ?? ''), 0, 190, 'UTF-8'),
            ':t' => $tenant,
            ':g' => $code,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['group_code' => $code, 'form_id' => $formId, 'fiscal_year' => (int)$form['roster']['fiscal_year']];
}

function roster_officer_target_clear(string $groupCode, array $actor): void
{
    $code = roster_normalize_group_code($groupCode);
    $tenant = roster_kintone_tenant();
    $pdo = roster_db();
    $pdo->beginTransaction();
    try {
        roster_guard_lock($pdo, $tenant, $code);
        $pdo->prepare('UPDATE roster_group_apply_guards SET officer_form_id = NULL, officer_fiscal_year = NULL, generation = generation + 1, updated_by = :by WHERE tenant = :t AND group_code = :g')->execute([
            ':by' => mb_substr((string)($actor['display_name'] ?? ''), 0, 190, 'UTF-8'),
            ':t' => $tenant,
            ':g' => $code,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/* ---------------- 承認計画の固定 ---------------- */

/** accept と同じ時点の日本時間の日付（register_date に使う。再送・再試行でも変わらない） */
function roster_officer_accept_date(DateTimeImmutable $acceptedAt): string
{
    return $acceptedAt->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d');
}

/**
 * 第2段階の承認スナップショット（版2）。ID20 の rows に加えて、対象・比較元・各ステップの期待値を固定する。
 * 人物の申告はこの承認原本にだけ保存し、ステップ・試行の記録には複製しない。
 */
function roster_officer_snapshot(array $case, array $revision, array $rows, array $plan, array $gate, string $acceptedDate): array
{
    $people = [];
    foreach ($plan['people'] as $p) {
        $declared = is_array($p['declared']) ? $p['declared'] : [];
        $people[] = [
            'step_key' => $p['step_key'],
            'student_id' => $p['student_id'],
            'operation' => $p['operation'],
            'record_id' => $p['kintone']['record_id'] ?? null,
            'revision' => $p['kintone']['revision'] ?? null,
            'expected' => [
                'student_id' => $p['student_id'],
                'family_name' => (string)($declared['family_name'] ?? ''),
                'personal_name' => (string)($declared['personal_name'] ?? ''),
                'mail' => (string)($declared['mail'] ?? ''),
                'depend' => (string)($declared['depend'] ?? ''),
                'type' => (string)($declared['type'] ?? ''),
                'org_depend_state' => (string)($declared['org_depend_state'] ?? ''),
            ],
        ];
    }
    $ledger = $plan['ledger'];
    return [
        'snapshot_version' => ROSTER_SNAPSHOT_VERSION_OFFICERS,
        'group_code' => (string)$case['group_code'],
        'rows' => $rows,
        'case' => [
            'case_id' => (int)$case['id'],
            'form_id' => (int)$case['form_id'],
            'fiscal_year' => (int)$case['fiscal_year'],
            'revision_id' => (int)$revision['id'],
            'revision_number' => (int)$revision['revision_number'],
            'payload_hash' => (string)$revision['payload_hash'],
        ],
        'target' => [
            'tenant' => (string)$gate['tenant'],
            'app_ids' => roster_kintone_officer_app_ids(),
            'guard_generation' => (int)$gate['generation'],
        ],
        'accepted_date' => $acceptedDate,
        'officers' => [
            'fingerprint' => (string)$plan['fingerprint'],
            'group' => [
                'record_id' => (int)($plan['group']['record_id'] ?? 0),
                'revision' => (int)($plan['group']['revision'] ?? 0),
            ],
            'people' => $people,
            'ledger' => [
                'operation' => (string)$ledger['operation'],
                'record_id' => $ledger['record_id'],
                'revision' => $ledger['revision'],
                'register_date_mode' => (string)$ledger['register_date_mode'],
                'current_register_date' => (string)$ledger['register_date'],
                'register_date' => $ledger['register_date_mode'] === 'accept_date' ? $acceptedDate : (string)$ledger['register_date'],
            ],
            'slots' => array_map(static fn(array $s): array => [
                'key' => $s['key'],
                'operation' => $s['operation'],
                'current_student_id' => $s['current_student_id'],
                'current_name' => $s['current_name'],
                'planned_student_id' => $s['planned_student_id'],
            ], $plan['slots']),
            'representative' => $plan['representative'],
        ],
    ];
}

function roster_officer_snapshot_hash(array $snapshot): string
{
    return roster_canonical_hash($snapshot);
}

/** accept と同じトランザクションで、計画のステップの初期行を作る */
function roster_officer_insert_steps(PDO $pdo, int $jobId, array $snapshot): void
{
    $appIds = $snapshot['target']['app_ids'];
    $stmt = $pdo->prepare('INSERT INTO roster_apply_steps (job_id, step_key, step_order, target_app, target_app_id, operation, status) VALUES (:job, :key, :ord, :app, :app_id, :op, :status)');
    $insert = static function (string $key, int $order, string $app, ?int $appId, string $operation) use ($stmt, $jobId): void {
        $stmt->execute([':job' => $jobId, ':key' => $key, ':ord' => $order, ':app' => $app, ':app_id' => $appId, ':op' => $operation, ':status' => ROSTER_STEP_PENDING]);
    };
    $insert('roster', 10, 'roster', (int)$appIds['roster'], 'apply');
    foreach ($snapshot['officers']['people'] as $i => $person) {
        $insert((string)$person['step_key'], 100 + $i, 'people', (int)$appIds['people'], (string)$person['operation']);
    }
    $insert('ledger', 900, 'officers', (int)$appIds['officers'], (string)$snapshot['officers']['ledger']['operation']);
}

function roster_steps_for_job(PDO $pdo, int $jobId): array
{
    $stmt = $pdo->prepare('SELECT * FROM roster_apply_steps WHERE job_id = :id ORDER BY step_order ASC, id ASC');
    $stmt->execute([':id' => $jobId]);
    return $stmt->fetchAll() ?: [];
}

function roster_step_label(array $step): string
{
    return match ((string)$step['target_app']) {
        'roster' => '部員名簿（ID20）',
        'people' => '登録者（ID22）' . (string)$step['step_key'],
        'officers' => '役員台帳（ID21）',
        default => (string)$step['step_key'],
    };
}

function roster_step_status_label(string $status): string
{
    return match ($status) {
        ROSTER_STEP_PENDING => '未処理',
        ROSTER_STEP_VERIFIED => '確認済み',
        ROSTER_STEP_UNKNOWN => '結果確認待ち',
        ROSTER_STEP_FAILED => '失敗',
        ROSTER_STEP_CONFLICT => '競合',
        default => $status,
    };
}

/* ---------------- ステップの記録（リースを持つ間だけ） ---------------- */

function roster_step_update(PDO $pdo, array $claim, array $step, array $fields): void
{
    $sets = [];
    $params = [':id' => (int)$step['id'], ':job' => (int)$claim['job']['id'], ':token' => $claim['token'], ':running' => ROSTER_JOB_RUNNING];
    foreach ($fields as $column => $value) {
        if ($column === 'attempts+') {
            $sets[] = 's.attempts = s.attempts + 1';
            continue;
        }
        $sets[] = 's.' . $column . ' = :f_' . $column;
        $params[':f_' . $column] = $value;
    }
    $stmt = $pdo->prepare(
        'UPDATE roster_apply_steps s JOIN roster_apply_jobs j ON j.id = s.job_id SET ' . implode(', ', $sets)
        . ' WHERE s.id = :id AND s.job_id = :job AND j.lease_token = :token AND j.status = :running'
    );
    $stmt->execute($params);
    if ($stmt->rowCount() !== 1) {
        $check = $pdo->prepare('SELECT COUNT(*) FROM roster_apply_jobs WHERE id = :id AND lease_token = :token AND status = :running');
        $check->execute([':id' => (int)$claim['job']['id'], ':token' => $claim['token'], ':running' => ROSTER_JOB_RUNNING]);
        if ((int)$check->fetchColumn() !== 1) {
            throw new RosterLeaseLost('ジョブのロックが失われました。');
        }
    }
}

/** ステップを確認済みにする。案件・履歴の更新が伴う場合は同じトランザクションで行う（団体の予約 → 案件 → ジョブ → ステップ）。 */
function roster_step_complete(PDO $pdo, array $claim, array $step, array $fields, ?callable $extra = null): void
{
    $pdo->beginTransaction();
    try {
        roster_guard_lock($pdo, (string)$claim['tenant'], (string)$claim['case']['group_code']);
        roster_case_by_id($pdo, (int)$claim['case']['id'], true);
        $job = roster_job_by_id($pdo, (int)$claim['job']['id'], true);
        if (!is_array($job) || (string)$job['lease_token'] !== $claim['token'] || (string)$job['status'] !== ROSTER_JOB_RUNNING) {
            throw new RosterLeaseLost('ジョブのロックが失われました。');
        }
        roster_step_update($pdo, $claim, $step, $fields + [
            'status' => ROSTER_STEP_VERIFIED,
            'verified_at' => roster_now_sql(),
            'error_class' => null,
            'error_code' => null,
            'error_message' => null,
        ]);
        if ($extra !== null) {
            $extra($pdo);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** ステップを失敗・競合で止め、ジョブも同じ状態にする（残りのステップは未処理のまま残す） */
function roster_worker_step_stop(PDO $pdo, array $claim, array $step, string $jobStatus, string $class, string $code, string $message, array $detail = []): array
{
    roster_step_update($pdo, $claim, $step, [
        'status' => $jobStatus === ROSTER_JOB_CONFLICT ? ROSTER_STEP_CONFLICT : ROSTER_STEP_FAILED,
        'error_class' => $class,
        'error_code' => $code,
        'error_message' => $message,
    ]);
    return roster_worker_finish($pdo, $claim, $jobStatus, [
        'error_class' => $class,
        'error_code' => $code,
        'error_message' => roster_step_label($step) . '：' . $message,
    ], $detail + ['step' => (string)$step['step_key'], 'target_app_id' => $step['target_app_id']]);
}

/* ---------------- 実行時の確認 ---------------- */

/** 承認時から接続先・アプリ・書込み対象の設定が変わっていないか。変わっていれば新しい書込みはしない（理由を返す）。 */
function roster_officer_runtime_block(PDO $pdo, array $claim, array $snapshot): ?string
{
    if (!roster_config('officers.write_enabled', false)) {
        return 'サーバー設定で役員の書込みが無効になったため、新しい書込みを止めました。';
    }
    $form = roster_form_row($pdo, (int)$claim['case']['form_id']);
    if (!is_array($form) || empty($form['roster']['officer_sync'])) {
        return 'フォームの役員連携が無効になったため、新しい書込みを止めました。';
    }
    if (roster_kintone_tenant() !== (string)$snapshot['target']['tenant']) {
        return '接続先の kintone が accept 時と異なります。';
    }
    if (roster_canonicalize(roster_kintone_officer_app_ids()) !== roster_canonicalize((array)$snapshot['target']['app_ids'])) {
        return 'アプリIDの設定が accept 時と異なります。';
    }
    $guard = roster_guard_get($pdo, (string)$snapshot['target']['tenant'], (string)$claim['case']['group_code']);
    if (!is_array($guard)
        || (int)$guard['officer_form_id'] !== (int)$claim['case']['form_id']
        || (int)$guard['officer_fiscal_year'] !== (int)$claim['case']['fiscal_year']
        || (int)$guard['generation'] !== (int)$snapshot['target']['guard_generation']) {
        return '役員の書込み対象の設定が accept 後に変更されました。差分を確認し直して accept し直してください。';
    }
    return null;
}

/* ---------------- ワーカー（版2のスナップショット） ---------------- */

function roster_worker_execute_v2(PDO $pdo, array $claim, array $snapshot): array
{
    $job = $claim['job'];
    $ctx = new RosterStepContext();
    try {
        $steps = roster_steps_for_job($pdo, (int)$job['id']);
        if ($steps === []) {
            return roster_worker_finish($pdo, $claim, ROSTER_JOB_FAILED, ['error_class' => 'config', 'error_code' => 'NO_STEPS', 'error_message' => '承認計画のステップがありません。'], []);
        }
        $blocked = roster_officer_runtime_block($pdo, $claim, $snapshot);
        foreach ($steps as $step) {
            if ((string)$step['status'] === ROSTER_STEP_VERIFIED) {
                continue;
            }
            $ctx->step = $step;
            $ctx->phase = 'search';
            $reconcile = (string)$step['status'] === ROSTER_STEP_UNKNOWN;
            if ($blocked !== null && !$reconcile) {
                // 照合だけは行い、新しい書込みはしない
                return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_FAILED, 'config', 'OFFICER_WRITE_BLOCKED', $blocked . '確認済みのステップはそのまま残しています。');
            }
            $out = match ((string)$step['target_app']) {
                'roster' => roster_worker_step_roster($pdo, $claim, $step, $snapshot, $ctx, $reconcile),
                'people' => roster_worker_step_person($pdo, $claim, $step, $snapshot, $ctx, $reconcile),
                'officers' => roster_worker_step_ledger($pdo, $claim, $step, $snapshot, $ctx, $reconcile),
                default => roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_FAILED, 'config', 'UNKNOWN_STEP', '不明なステップです。'),
            };
            if ($out !== null) {
                return $out;
            }
        }
        return roster_worker_finish($pdo, $claim, ROSTER_JOB_APPLIED, ['error_class' => null, 'error_code' => null, 'error_message' => null], ['steps' => count($steps)], [], true, false);
    } catch (RosterLeaseLost $e) {
        roster_log('warning', 'lease lost', ['job_id' => (int)$job['id']]);
        return ['job_id' => (int)$job['id'], 'status' => 'lease_lost'];
    } catch (Throwable $e) {
        return roster_worker_step_error($pdo, $claim, $ctx, $e);
    }
}

function roster_worker_step_error(PDO $pdo, array $claim, RosterStepContext $ctx, Throwable $e): array
{
    [$class, $code, $detail] = roster_worker_error_info($e, $ctx->phase, (int)$claim['job']['id']);
    $step = $ctx->step;
    if ($step !== null) {
        $detail += ['step' => (string)$step['step_key'], 'target_app_id' => $step['target_app_id']];
        try {
            roster_step_update($pdo, $claim, $step, [
                'status' => match ($class) {
                    'transient' => ROSTER_STEP_PENDING,
                    'unknown' => ROSTER_STEP_UNKNOWN,
                    'conflict' => ROSTER_STEP_CONFLICT,
                    default => ROSTER_STEP_FAILED,
                },
                'phase' => $class === 'transient' ? null : $ctx->phase,
                'error_class' => $class,
                'error_code' => $code,
                'error_message' => mb_substr($e->getMessage(), 0, 1000, 'UTF-8'),
            ]);
        } catch (RosterLeaseLost) {
            return ['job_id' => (int)$claim['job']['id'], 'status' => 'lease_lost'];
        }
    }
    $label = $step !== null ? roster_step_label($step) . '：' : '';
    return roster_worker_finish_error($pdo, $claim, $class, $code, $label . mb_substr($e->getMessage(), 0, 1000, 'UTF-8'), $detail + ['phase' => $ctx->phase]);
}

/** ID20 のステップ。第1段階と同じ反映・照合を使い、確認できた時点で ID20 の最終反映版を更新する。 */
function roster_worker_step_roster(PDO $pdo, array $claim, array $step, array $snapshot, RosterStepContext $ctx, bool $reconcile): ?array
{
    roster_step_update($pdo, $claim, $step, ['attempts+' => true, 'phase' => 'search']);
    $rows = (array)$snapshot['rows'];
    $out = roster_worker_roster_outcome($pdo, ['mode' => $reconcile ? 'reconcile' : 'apply'] + $claim, $rows, $ctx);
    if ($out['kind'] !== 'verified') {
        $status = (string)$out['status'];
        return roster_worker_step_stop($pdo, $claim, $step, $status, (string)($out['fields']['error_class'] ?? 'config'), (string)($out['fields']['error_code'] ?? 'ROSTER'), (string)($out['fields']['error_message'] ?? ''), $out['detail'] ?? []);
    }
    $record = $out['record'];
    roster_worker_update_job($pdo, $claim, ['phase' => 'verified', 'target_record_id' => $record['record_id'], 'after_revision' => $record['revision'], 'error_class' => null, 'error_code' => null, 'error_message' => null]);
    $writeKind = match ((string)($out['detail']['write'] ?? $out['detail']['result'] ?? '')) {
        'none_needed' => 'none',
        'add', 'add_confirmed' => 'add',
        'update', 'update_confirmed' => 'update',
        default => null,
    };
    roster_step_complete($pdo, $claim, $step, [
        'phase' => 'verified',
        'write_kind' => $writeKind,
        'target_record_id' => $record['record_id'],
        'after_revision' => $record['revision'],
    ], static function (PDO $pdo) use ($claim, $record, $out): void {
        // ID20 の最終反映版は ID20 の読戻しが確認できた時点で更新し、役員の反映とは区別する
        $pdo->prepare('UPDATE roster_cases SET last_applied_job_id = :job, last_applied_revision_number = :rev, last_applied_at = :now WHERE id = :id AND current_job_id = :job2')->execute([
            ':job' => (int)$claim['job']['id'],
            ':rev' => (int)$claim['acceptance']['revision_number'],
            ':now' => roster_now_sql(),
            ':id' => (int)$claim['case']['id'],
            ':job2' => (int)$claim['job']['id'],
        ]);
        roster_worker_save_links($pdo, (string)$claim['case']['group_code'], (int)$claim['job']['id'], (int)$record['record_id'], $out['links']);
    });
    return null;
}

/** 既存の登録者を参照してよいか（区分・氏名の矛盾がない）。問題があれば理由を返す。 */
function roster_officer_reference_issue(array $person, array $expected): ?string
{
    if (!in_array((string)$person['type'], roster_officer_type_options(), true)) {
        return '登録者の区分が対象外になっています。';
    }
    $expectedName = roster_officer_compact_name((string)$expected['family_name'] . (string)$expected['personal_name']);
    if ($expectedName !== '' && roster_officer_compact_name((string)$person['name']) !== $expectedName) {
        return '登録者の氏名が accept 時の申告と一致しません。';
    }
    return null;
}

/** 新規登録する人物が、承認した申告どおりに登録されているか */
function roster_officer_person_matches(array $person, array $expected): bool
{
    return roster_normalize_student_id($person['student_id']) === roster_normalize_student_id($expected['student_id'])
        && trim((string)$person['family_name']) === (string)$expected['family_name']
        && trim((string)$person['personal_name']) === (string)$expected['personal_name']
        && roster_officer_compact_name((string)$person['name']) === roster_officer_compact_name((string)$expected['family_name'] . (string)$expected['personal_name'])
        && mb_strtolower(trim((string)$person['mail']), 'UTF-8') === mb_strtolower((string)$expected['mail'], 'UTF-8')
        && (string)$person['depend'] === (string)$expected['depend']
        && (string)$person['type'] === (string)$expected['type']
        && (string)$person['org_depend_state'] === (string)$expected['org_depend_state'];
}

function roster_officer_snapshot_person(array $snapshot, string $stepKey): ?array
{
    foreach ($snapshot['officers']['people'] as $person) {
        if ((string)$person['step_key'] === $stepKey) {
            return $person;
        }
    }
    return null;
}

/** ID22 の人物ステップ。既存参照は比較元を再確認し、新規登録は学籍番号・メールで照合してから1件だけ追加する。 */
function roster_worker_step_person(PDO $pdo, array $claim, array $step, array $snapshot, RosterStepContext $ctx, bool $reconcile): ?array
{
    $plan = roster_officer_snapshot_person($snapshot, (string)$step['step_key']);
    if ($plan === null) {
        return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_FAILED, 'config', 'PLAN_MISSING', '承認計画にこの人物がありません。');
    }
    roster_step_update($pdo, $claim, $step, ['attempts+' => true, 'phase' => 'search']);
    $expected = $plan['expected'];
    $found = roster_kintone_find_people('student_id', [(string)$plan['student_id']]);
    if (count($found) > 1) {
        return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_CONFLICT, 'conflict', 'PEOPLE_MULTIPLE', '学籍番号が一致する登録者が複数あります。');
    }
    $verified = static function (array $person, string $writeKind) use ($pdo, $claim, $step): void {
        roster_step_complete($pdo, $claim, $step, [
            'phase' => 'verified',
            'write_kind' => $writeKind,
            'target_record_id' => $person['record_id'],
            'after_revision' => $person['revision'],
        ]);
    };

    if ((string)$plan['operation'] === 'reference') {
        $person = $found[0] ?? null;
        if ($person === null) {
            return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_CONFLICT, 'conflict', 'PEOPLE_MISSING', 'accept 時に参照した登録者が見つかりません。');
        }
        if ($person['record_id'] !== (int)$plan['record_id']) {
            return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_CONFLICT, 'conflict', 'PEOPLE_CHANGED', '学籍番号に対応する登録者のレコードが accept 時と異なります。');
        }
        $issue = roster_officer_reference_issue($person, $expected);
        if ($issue !== null) {
            return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_CONFLICT, 'conflict', 'PEOPLE_CHANGED', $issue);
        }
        $verified($person, 'none');
        return null;
    }

    if (count($found) === 1) {
        // 成否不明だった追加、または accept 後の別経路の登録。申告どおりなら同じ人物として確定し、追加を繰り返さない
        if (roster_officer_person_matches($found[0], $expected)) {
            $verified($found[0], $reconcile ? 'add' : 'none');
            return null;
        }
        return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_CONFLICT, 'conflict', 'PEOPLE_CREATED', '同じ学籍番号の登録者がありますが、内容が承認した申告と一致しません。別人の可能性があるため紐付けません。');
    }
    $mailOwners = roster_kintone_find_people('mail', [(string)$expected['mail']]);
    if ($mailOwners !== []) {
        return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_CONFLICT, 'conflict', 'PEOPLE_MAIL_TAKEN', '申告のメールアドレスを別の登録者が使っています。');
    }
    $ctx->phase = 'post_sent';
    roster_step_update($pdo, $claim, $step, ['phase' => 'post_sent', 'write_kind' => 'add', 'sent_at' => roster_now_sql()]);
    try {
        $res = roster_kintone_add_person($expected);
    } catch (RosterKintoneError $e) {
        if (!roster_kintone_is_duplicate_error($e)) {
            throw $e;
        }
        // 一意制約に当たった。学籍番号で照合し直し、申告どおりの人物だけを参照する
        $ctx->phase = 'search';
        $again = roster_kintone_find_people('student_id', [(string)$plan['student_id']]);
        if (count($again) === 1 && roster_officer_person_matches($again[0], $expected)) {
            $verified($again[0], 'add');
            return null;
        }
        return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_CONFLICT, 'conflict', 'PEOPLE_DUPLICATE', '登録者の学籍番号またはメールアドレスが別のレコードと重複しています。');
    }
    $ctx->phase = 'written';
    roster_step_update($pdo, $claim, $step, ['phase' => 'written', 'target_record_id' => $res['record_id'] ?: null, 'after_revision' => $res['revision'] ?: null]);
    $person = roster_kintone_get_person((int)$res['record_id']);
    if (!roster_officer_person_matches($person, $expected)) {
        return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_FAILED, 'verification', 'READBACK_MISMATCH', '追加しましたが、読み戻した内容が承認した申告と一致しません。');
    }
    $verified($person, 'add');
    return null;
}

/** ID21 に書き込む値（更新）。変更する枠・コピーの取り直しが必要な枠・変更時の登録日だけを送る。 */
function roster_officer_update_values(array $record, array $snapshot, array $peopleBySid): array
{
    $defs = array_column(roster_officer_slot_definitions(), null, 'key');
    $values = [];
    foreach ($snapshot['officers']['slots'] as $slot) {
        $def = $defs[$slot['key']];
        $planned = (string)$slot['planned_student_id'];
        switch ($slot['operation']) {
            case 'set':
            case 'replace':
                $values[$def['student_field']] = $planned;
                break;
            case 'clear':
                $values[$def['student_field']] = '';
                break;
            case 'keep':
                // ID22 だけを訂正した場合、ID21 のコピーは古いまま。ルックアップを取り直して最新にする
                $current = $record['slots'][$slot['key']];
                $person = $peopleBySid[$planned] ?? null;
                if (is_array($person) && !roster_officer_copy_matches($current, $person)) {
                    $values[$def['student_field']] = $planned;
                }
                break;
        }
    }
    $ledger = $snapshot['officers']['ledger'];
    if ($ledger['register_date_mode'] === 'accept_date' && (string)$record['register_date'] !== (string)$ledger['register_date']) {
        $values['register_date'] = (string)$ledger['register_date'];
    }
    return $values;
}

function roster_officer_create_values(array $snapshot): array
{
    $code = (string)$snapshot['group_code'];
    $values = [
        // ルックアップはコピー元のキーだけを送る（団体名・組織は kintone がコピーする）
        'group_id' => $code,
        'forms_current_group_key' => $code,
        'register_date' => (string)$snapshot['officers']['ledger']['register_date'],
    ];
    $defs = array_column(roster_officer_slot_definitions(), null, 'key');
    foreach ($snapshot['officers']['slots'] as $slot) {
        if ((string)$slot['planned_student_id'] !== '') {
            $values[$defs[$slot['key']]['student_field']] = (string)$slot['planned_student_id'];
        }
    }
    return $values;
}

/** ID21 のコピー（氏名・メール）が ID22 の現在値と一致するか */
function roster_officer_copy_matches(array $slot, array $person): bool
{
    return roster_officer_compact_name((string)$slot['name']) === roster_officer_compact_name((string)$person['name'])
        && mb_strtolower(trim((string)$slot['mail']), 'UTF-8') === mb_strtolower(trim((string)$person['mail']), 'UTF-8');
}

/**
 * 読み戻した ID21 が承認計画どおりか。対象レコード・団体キー・登録日・各枠の学籍番号と、
 * ID22 の現在値からコピーされた氏名・メール、空席の3項目を確認する。氏名や学籍番号はメッセージに含めない。
 */
function roster_officer_verify_ledger(array $record, array $snapshot, array $peopleBySid): array
{
    $code = (string)$snapshot['group_code'];
    $mismatches = [];
    if ((string)$record['group_key'] !== $code) {
        $mismatches[] = '団体キーが一致しません。';
    }
    if ((string)$record['group_id'] !== '' && (string)$record['group_id'] !== $code) {
        $mismatches[] = '団体識別記号が一致しません。';
    }
    if ((string)$record['register_date'] !== (string)$snapshot['officers']['ledger']['register_date']) {
        $mismatches[] = '登録日が一致しません。';
    }
    $defs = array_column(roster_officer_slot_definitions(), null, 'key');
    foreach ($snapshot['officers']['slots'] as $slot) {
        $label = $defs[$slot['key']]['label'];
        $actual = $record['slots'][$slot['key']];
        $planned = (string)$slot['planned_student_id'];
        if ($planned === '') {
            if ($actual['student_id'] !== '' || $actual['name'] !== '' || $actual['mail'] !== '') {
                $mismatches[] = $label . 'が空席になっていません（学籍番号・氏名・メール）。';
            }
            continue;
        }
        if (roster_normalize_student_id($actual['student_id']) !== $planned) {
            $mismatches[] = $label . 'の学籍番号が一致しません。';
            continue;
        }
        $person = $peopleBySid[$planned] ?? null;
        if (!is_array($person) || !roster_officer_copy_matches($actual, $person)) {
            $mismatches[] = $label . 'の氏名・メールが登録者の現在値と一致しません。';
        }
    }
    return $mismatches;
}

/** ID21 のステップ。ID7・ID22 のルックアップ元と ID21 の比較元を直前に確認し、確定したキーと期待リビジョンで書き込む。 */
function roster_worker_step_ledger(PDO $pdo, array $claim, array $step, array $snapshot, RosterStepContext $ctx, bool $reconcile): ?array
{
    $code = (string)$snapshot['group_code'];
    $ledger = $snapshot['officers']['ledger'];
    roster_step_update($pdo, $claim, $step, ['attempts+' => true, 'phase' => 'search']);

    $group = roster_kintone_find_group_master($code);
    if ($group === null || $group['record_id'] !== (int)$snapshot['officers']['group']['record_id']) {
        return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_CONFLICT, 'conflict', 'GROUP_CHANGED', '公認団体・組織（ID7）の団体レコードが accept 時と異なります。');
    }
    if (!in_array($group['status'], (array)roster_config('groups.allowed_statuses', []), true)) {
        return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_CONFLICT, 'conflict', 'GROUP_STATUS', '公認団体・組織（ID7）の活動状況が「' . $group['status'] . '」になっています。');
    }
    $hasClear = array_filter($snapshot['officers']['slots'], static fn(array $s): bool => $s['operation'] === 'clear') !== [];
    if ($hasClear && !roster_config('officers.clear_enabled', false) && !$reconcile) {
        return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_FAILED, 'config', 'CLEAR_DISABLED', '空席の自動解除が無効です。役員台帳で手動で解除し、比較し直してから accept してください。');
    }

    // ルックアップ元（ID22）の現在値。予定の担当者は全員が1件ずつ存在すること
    $planned = array_values(array_unique(array_filter(array_map(static fn(array $s): string => (string)$s['planned_student_id'], $snapshot['officers']['slots']))));
    $peopleBySid = [];
    foreach (roster_kintone_find_people('student_id', $planned) as $person) {
        $peopleBySid[roster_normalize_student_id($person['student_id'])][] = $person;
    }
    foreach ($planned as $sid) {
        if (count($peopleBySid[$sid] ?? []) !== 1) {
            return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_CONFLICT, 'conflict', 'PEOPLE_CHANGED', 'ルックアップ元の登録者（ID22）が1件に定まらない役員がいます。');
        }
        $peopleBySid[$sid] = $peopleBySid[$sid][0];
    }

    $found = roster_kintone_find_officer_records($code);
    if ((string)$ledger['operation'] === 'update') {
        if ($found['count'] !== 1 || $found['records'][0]['record_id'] !== (int)$ledger['record_id']) {
            return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_CONFLICT, 'conflict', 'LEDGER_RECORD', '役員台帳の現行レコードが accept 時と異なります（' . $found['count'] . '件）。');
        }
        $record = $found['records'][0];
        if ($reconcile && $step['before_revision'] !== null && $record['revision'] !== (int)$step['before_revision']) {
            // 送信後に更新されている。承認計画どおりなら反映済みとして確定する（再送しない）
            $mismatches = roster_officer_verify_ledger($record, $snapshot, $peopleBySid);
            if ($mismatches === []) {
                return roster_worker_ledger_verified($pdo, $claim, $step, $snapshot, $record, 'update');
            }
            return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_CONFLICT, 'conflict', 'RECONCILE_MISMATCH', '成否不明だった更新を照合しましたが、役員台帳が承認内容と一致しません（別の更新があった可能性）。', ['mismatches' => array_slice($mismatches, 0, 10)]);
        }
        if ($record['revision'] !== (int)$ledger['revision']) {
            return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_CONFLICT, 'conflict', 'REVISION_CHANGED', 'accept 後に役員台帳が更新されました（リビジョン ' . (int)$ledger['revision'] . ' → ' . $record['revision'] . '）。上書きせずに停止しました。');
        }
        if ($record['group_key'] !== $code) {
            return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_CONFLICT, 'conflict', 'GROUP_KEY', '役員台帳の団体キーが団体識別記号と一致しません。');
        }
        // 初回の上書き前に、現任の代表者を履歴の基準として記録する（団体ごとに一度だけ）
        roster_officer_history_baseline($pdo, $claim, $snapshot, (string)$snapshot['officers']['representative']['previous_student_id'], (string)$snapshot['officers']['representative']['previous_name'], $record['record_id'], false);
        $values = roster_officer_update_values($record, $snapshot, $peopleBySid);
        if ($values === []) {
            $mismatches = roster_officer_verify_ledger($record, $snapshot, $peopleBySid);
            if ($mismatches !== []) {
                return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_FAILED, 'verification', 'LEDGER_MISMATCH', '書き込む項目はありませんが、役員台帳が承認内容と一致しません：' . implode(' ', array_slice($mismatches, 0, 5)));
            }
            return roster_worker_ledger_verified($pdo, $claim, $step, $snapshot, $record, 'none');
        }
        $ctx->phase = 'put_sent';
        roster_step_update($pdo, $claim, $step, ['phase' => 'put_sent', 'write_kind' => 'update', 'target_record_id' => $record['record_id'], 'before_revision' => $record['revision'], 'sent_at' => roster_now_sql()]);
        $res = roster_kintone_update_officer_record($record['record_id'], $record['revision'], $values);
        $ctx->phase = 'written';
        roster_step_update($pdo, $claim, $step, ['phase' => 'written', 'after_revision' => $res['revision'] ?: null]);
        $after = roster_kintone_get_officer_record($record['record_id']);
        return roster_worker_ledger_readback($pdo, $claim, $step, $snapshot, $after, $peopleBySid, 'update');
    }

    // 新規作成
    if ($found['count'] === 1 && $reconcile) {
        $mismatches = roster_officer_verify_ledger($found['records'][0], $snapshot, $peopleBySid);
        if ($mismatches === []) {
            return roster_worker_ledger_verified($pdo, $claim, $step, $snapshot, $found['records'][0], 'add');
        }
        return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_CONFLICT, 'conflict', 'RECONCILE_MISMATCH', '成否不明だった新規作成を照合しましたが、役員台帳のレコードが承認内容と一致しません（手動登録の可能性）。', ['mismatches' => array_slice($mismatches, 0, 10)]);
    }
    if ($found['count'] > 0) {
        return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_CONFLICT, 'conflict', 'LEDGER_CREATED', 'accept 後に役員台帳へ同じ団体のレコードが作成されました（' . $found['count'] . '件）。差分を確認し直してください。');
    }
    $ctx->phase = 'post_sent';
    roster_step_update($pdo, $claim, $step, ['phase' => 'post_sent', 'write_kind' => 'add', 'sent_at' => roster_now_sql()]);
    try {
        $res = roster_kintone_add_officer_record(roster_officer_create_values($snapshot));
    } catch (RosterKintoneError $e) {
        if (!roster_kintone_is_duplicate_error($e)) {
            throw $e;
        }
        // 団体キーの一意制約に当たった。再取得して期待値と照合する（0件だったことだけで追加の安全性を判断しない）
        $ctx->phase = 'search';
        $again = roster_kintone_find_officer_records($code);
        if ($again['count'] === 1 && roster_officer_verify_ledger($again['records'][0], $snapshot, $peopleBySid) === []) {
            return roster_worker_ledger_verified($pdo, $claim, $step, $snapshot, $again['records'][0], 'add');
        }
        return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_CONFLICT, 'conflict', 'LEDGER_DUPLICATE', '同じ団体キーの役員台帳レコードが既にあり、内容が承認計画と一致しません。');
    }
    $ctx->phase = 'written';
    roster_step_update($pdo, $claim, $step, ['phase' => 'written', 'target_record_id' => $res['record_id'] ?: null, 'after_revision' => $res['revision'] ?: null]);
    $after = roster_kintone_get_officer_record((int)$res['record_id']);
    $count = roster_kintone_find_officer_records($code)['count'];
    if ($count !== 1) {
        return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_CONFLICT, 'conflict', 'LEDGER_MULTIPLE', '作成後に同じ団体のレコードが' . $count . '件あります（標準画面からの同時登録の可能性）。');
    }
    return roster_worker_ledger_readback($pdo, $claim, $step, $snapshot, $after, $peopleBySid, 'add');
}

function roster_worker_ledger_readback(PDO $pdo, array $claim, array $step, array $snapshot, array $after, array $peopleBySid, string $writeKind): ?array
{
    $mismatches = roster_officer_verify_ledger($after, $snapshot, $peopleBySid);
    if ($mismatches !== []) {
        return roster_worker_step_stop($pdo, $claim, $step, ROSTER_JOB_FAILED, 'verification', 'READBACK_MISMATCH', '書き込みましたが、読み戻した内容が承認内容と一致しません：' . implode(' ', array_slice($mismatches, 0, 5)), ['mismatches' => array_slice($mismatches, 0, 20)]);
    }
    return roster_worker_ledger_verified($pdo, $claim, $step, $snapshot, $after, $writeKind);
}

/** ID21 を確認済みにし、代表者の交代を同じトランザクションで履歴に確定する（同じ交代は一度だけ） */
function roster_worker_ledger_verified(PDO $pdo, array $claim, array $step, array $snapshot, array $record, string $writeKind): ?array
{
    roster_step_complete($pdo, $claim, $step, [
        'phase' => 'verified',
        'write_kind' => $writeKind,
        'target_record_id' => $record['record_id'],
        'after_revision' => $record['revision'],
    ], static function (PDO $pdo) use ($claim, $snapshot, $record): void {
        roster_officer_history_on_verified($pdo, $claim, $snapshot, $record);
    });
    return null;
}

/* ---------------- 代表者の履歴 ---------------- */

function roster_officer_history_insert(PDO $pdo, array $row): void
{
    $pdo->prepare(
        'INSERT IGNORE INTO roster_officer_history (tenant, group_code, event_key, event_type, previous_student_id, previous_name, new_student_id, new_name, appointed_on, applied_at, acceptance_id, job_id, revision_id, ledger_record_id, recorded_by) '
        . 'VALUES (:tenant, :group, :key, :type, :prev_sid, :prev_name, :new_sid, :new_name, NULL, :applied, :acc, :job, :rev, :record, :by)'
    )->execute([
        ':tenant' => $row['tenant'],
        ':group' => $row['group_code'],
        ':key' => $row['event_key'],
        ':type' => $row['event_type'],
        ':prev_sid' => $row['previous_student_id'] !== '' ? $row['previous_student_id'] : null,
        ':prev_name' => $row['previous_name'] !== '' ? mb_substr($row['previous_name'], 0, 190, 'UTF-8') : null,
        ':new_sid' => $row['new_student_id'] !== '' ? $row['new_student_id'] : null,
        ':new_name' => $row['new_name'] !== '' ? mb_substr($row['new_name'], 0, 190, 'UTF-8') : null,
        ':applied' => $row['applied_at'],
        ':acc' => $row['acceptance_id'],
        ':job' => $row['job_id'],
        ':rev' => $row['revision_id'],
        ':record' => $row['ledger_record_id'],
        ':by' => mb_substr((string)($row['recorded_by'] ?? ''), 0, 190, 'UTF-8') ?: null,
    ]);
}

/** 履歴の基準（団体ごとに一度だけ）。既存台帳は初回上書き前の現任代表者、新設団体は初回登録した代表者を起点にする。 */
function roster_officer_history_baseline(PDO $pdo, array $claim, array $snapshot, string $studentId, string $name, int $recordId, bool $applied): void
{
    if ($studentId === '') {
        return;
    }
    roster_officer_history_insert($pdo, [
        'tenant' => (string)$claim['tenant'],
        'group_code' => (string)$snapshot['group_code'],
        'event_key' => 'baseline:' . $claim['tenant'] . ':' . $snapshot['group_code'],
        'event_type' => 'baseline',
        'previous_student_id' => '',
        'previous_name' => '',
        'new_student_id' => $studentId,
        'new_name' => $name,
        'applied_at' => $applied ? roster_now_sql() : null,
        'acceptance_id' => (int)$claim['acceptance']['id'],
        'job_id' => (int)$claim['job']['id'],
        'revision_id' => (int)$claim['job']['revision_id'],
        'ledger_record_id' => $recordId,
        'recorded_by' => (string)($claim['acceptance']['accepted_by_name'] ?? ''),
    ]);
}

/** ID21 の読戻しで交代が確認できた時点の確定。誤記訂正や交代のない再提出は数えない（学籍番号の変化だけを交代とする）。 */
function roster_officer_history_on_verified(PDO $pdo, array $claim, array $snapshot, array $record): void
{
    $rep = $snapshot['officers']['representative'];
    if ((string)$snapshot['officers']['ledger']['operation'] === 'create') {
        roster_officer_history_baseline($pdo, $claim, $snapshot, (string)$rep['new_student_id'], (string)$rep['new_name'], (int)$record['record_id'], true);
        return;
    }
    if (empty($rep['changed'])) {
        return;
    }
    roster_officer_history_insert($pdo, [
        'tenant' => (string)$claim['tenant'],
        'group_code' => (string)$snapshot['group_code'],
        'event_key' => 'change:' . (int)$claim['acceptance']['id'],
        'event_type' => 'change',
        'previous_student_id' => (string)$rep['previous_student_id'],
        'previous_name' => (string)$rep['previous_name'],
        'new_student_id' => (string)$rep['new_student_id'],
        'new_name' => (string)$rep['new_name'],
        'applied_at' => roster_now_sql(),
        'acceptance_id' => (int)$claim['acceptance']['id'],
        'job_id' => (int)$claim['job']['id'],
        'revision_id' => (int)$claim['job']['revision_id'],
        'ledger_record_id' => (int)$record['record_id'],
        'recorded_by' => (string)($claim['acceptance']['accepted_by_name'] ?? ''),
    ]);
}

function roster_officer_history_for_group(PDO $pdo, string $tenant, string $groupCode): array
{
    $stmt = $pdo->prepare('SELECT * FROM roster_officer_history WHERE tenant = :t AND group_code = :g ORDER BY id ASC');
    $stmt->execute([':t' => $tenant, ':g' => $groupCode]);
    return $stmt->fetchAll() ?: [];
}
