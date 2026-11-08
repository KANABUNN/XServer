<?php

declare(strict_types=1);

/* =====================================================================
   名簿の業務処理（下書き・提出・差し戻し・accept・取り消し・再試行）
   - ここでは kintone へ書き込まない。accept は反映ジョブを同じトランザクションで登録するだけ。
   - ロック順は必ず「roster_cases → roster_apply_jobs」（worker と同じ）にしてデッドロックを避ける。
   ===================================================================== */

function roster_form_row(PDO $pdo, int $formId, string $lock = ''): ?array
{
    $sql = 'SELECT id, name, is_active, settings_json FROM managed_forms WHERE id = :id LIMIT 1';
    if ($lock !== '') {
        $sql .= ' ' . $lock;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $formId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    $settings = roster_json_decode((string)($row['settings_json'] ?? ''), []);
    $settings = is_array($settings) ? $settings : [];
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'is_active' => (int)$row['is_active'] === 1,
        'settings' => $settings,
        'roster' => roster_form_settings($settings),
    ];
}

function roster_require_roster_form(?array $form): array
{
    if (!is_array($form) || !$form['roster']['is_roster']) {
        throw new RosterException('部員名簿フォームではありません。', 'not_roster', 404);
    }
    if ((int)$form['roster']['fiscal_year'] < 1) {
        throw new RosterException('フォームに対象年度が設定されていません。管理者へ連絡してください。', 'no_fiscal_year', 409);
    }
    return $form;
}

function roster_case_find(PDO $pdo, int $formId, int $fiscalYear, string $groupCode, bool $forUpdate = false): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM roster_cases WHERE form_id = :form_id AND fiscal_year = :year AND group_code = :code LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([':form_id' => $formId, ':year' => $fiscalYear, ':code' => $groupCode]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function roster_case_by_id(PDO $pdo, int $caseId, bool $forUpdate = false): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM roster_cases WHERE id = :id LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([':id' => $caseId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** トランザクション内で呼ぶ。案件が無ければ作成し、行ロックを取って返す。 */
function roster_case_lock_or_create(PDO $pdo, int $formId, int $fiscalYear, string $groupCode, string $groupName): array
{
    $pdo->prepare('INSERT IGNORE INTO roster_cases (form_id, fiscal_year, group_code, group_name) VALUES (:form_id, :year, :code, :name)')->execute([
        ':form_id' => $formId,
        ':year' => $fiscalYear,
        ':code' => $groupCode,
        ':name' => mb_substr($groupName, 0, 255, 'UTF-8'),
    ]);
    $case = roster_case_find($pdo, $formId, $fiscalYear, $groupCode, true);
    if (!is_array($case)) {
        throw new RuntimeException('名簿の案件を作成できませんでした。');
    }
    return $case;
}

function roster_revision_by_id(PDO $pdo, int $revisionId, bool $forUpdate = false): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM roster_revisions WHERE id = :id LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([':id' => $revisionId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function roster_job_by_id(PDO $pdo, int $jobId, bool $forUpdate = false): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM roster_apply_jobs WHERE id = :id LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([':id' => $jobId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function roster_acceptance_by_id(PDO $pdo, int $acceptanceId, bool $forUpdate = false): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM roster_acceptances WHERE id = :id LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([':id' => $acceptanceId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function roster_insert_review(PDO $pdo, int $caseId, ?int $revisionId, string $action, ?string $reason, string $actorType, array $actor = []): void
{
    $pdo->prepare(
        'INSERT INTO roster_reviews (case_id, revision_id, action, reason, actor_type, actor_account_id, actor_login_id, actor_name, created_at) '
        . 'VALUES (:case_id, :revision_id, :action, :reason, :actor_type, :account_id, :login_id, :name, :now)'
    )->execute([
        ':case_id' => $caseId,
        ':revision_id' => $revisionId,
        ':action' => $action,
        ':reason' => $reason !== null && $reason !== '' ? $reason : null,
        ':actor_type' => $actorType,
        ':account_id' => (int)($actor['id'] ?? 0) ?: null,
        ':login_id' => (string)($actor['login_id'] ?? '') !== '' ? mb_substr((string)$actor['login_id'], 0, 100) : null,
        ':name' => mb_substr((string)($actor['display_name'] ?? $actor['name'] ?? ''), 0, 190, 'UTF-8') ?: null,
        ':now' => roster_now_sql(),
    ]);
}

/** 未実行・失敗・競合のジョブを失効させ、accept も取り消す（同じトランザクション内で呼ぶ） */
function roster_invalidate_job(PDO $pdo, array $job, string $reason, array $actor = []): void
{
    $now = roster_now_sql();
    $pdo->prepare(
        'UPDATE roster_apply_jobs SET status = :cancelled, invalidated_at = :now, invalidated_reason = :reason, lease_token = NULL, lease_expires_at = NULL, finished_at = COALESCE(finished_at, :now2) '
        . 'WHERE id = :id AND status IN (:queued, :failed, :conflict)'
    )->execute([
        ':cancelled' => ROSTER_JOB_CANCELLED,
        ':now' => $now,
        ':now2' => $now,
        ':reason' => mb_substr($reason, 0, 64),
        ':id' => (int)$job['id'],
        ':queued' => ROSTER_JOB_QUEUED,
        ':failed' => ROSTER_JOB_FAILED,
        ':conflict' => ROSTER_JOB_CONFLICT,
    ]);
    $pdo->prepare('UPDATE roster_acceptances SET revoked_at = COALESCE(revoked_at, :now), revoked_by_name = COALESCE(revoked_by_name, :by), revoke_reason = COALESCE(revoke_reason, :reason), active_revision_id = NULL WHERE id = :id')->execute([
        ':now' => $now,
        ':by' => mb_substr((string)($actor['display_name'] ?? $actor['name'] ?? 'system'), 0, 190, 'UTF-8'),
        ':reason' => mb_substr($reason, 0, 255, 'UTF-8'),
        ':id' => (int)$job['acceptance_id'],
    ]);
}

function roster_draft_rows(array $case): array
{
    $draft = roster_json_decode((string)($case['draft_json'] ?? ''), null);
    return is_array($draft) && is_array($draft['rows'] ?? null) ? $draft['rows'] : [];
}

function roster_draft_base(array $case): ?array
{
    $base = roster_json_decode((string)($case['draft_base_json'] ?? ''), null);
    return is_array($base) ? $base : null;
}

function roster_base_row_ids(?array $base): array
{
    if (!is_array($base)) {
        return [];
    }
    return array_values(array_filter(array_map(static fn($r): string => (string)($r['kintone_row_id'] ?? ''), (array)($base['rows'] ?? []))));
}

function roster_assert_version(array $case, int $expectedVersion): void
{
    if ((int)$case['draft_version'] !== $expectedVersion) {
        throw new RosterException('別の画面または別の担当者がこの名簿を更新しました。画面を再読み込みしてから操作してください。', 'stale', 409, [
            'draft_version' => (int)$case['draft_version'],
        ]);
    }
}

/* ---------------- 団体（Forms）側 ---------------- */

function roster_group_save_draft(array $form, string $groupCode, string $groupName, mixed $rawRows, int $expectedVersion): array
{
    $form = roster_require_roster_form($form);
    $pdo = roster_db();
    $pdo->beginTransaction();
    try {
        $case = roster_case_lock_or_create($pdo, $form['id'], $form['roster']['fiscal_year'], $groupCode, $groupName);
        roster_assert_version($case, $expectedVersion);
        $rows = roster_normalize_rows($rawRows, roster_base_row_ids(roster_draft_base($case)));
        $version = (int)$case['draft_version'] + 1;
        $now = roster_now_sql();
        $pdo->prepare('UPDATE roster_cases SET draft_json = :draft, draft_version = :version, draft_updated_at = :now, group_name = :name WHERE id = :id')->execute([
            ':draft' => roster_json(['rows' => $rows]),
            ':version' => $version,
            ':now' => $now,
            ':name' => mb_substr($groupName, 0, 255, 'UTF-8'),
            ':id' => (int)$case['id'],
        ]);
        $pdo->commit();
        return ['draft_version' => $version, 'draft_updated_at' => $now, 'rows' => $rows];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** kintone の現在の名簿を下書きへ読み込む（比較元としてサーバー側に保存する） */
function roster_group_load_kintone(array $form, string $groupCode, string $groupName, int $expectedVersion): array
{
    $form = roster_require_roster_form($form);
    try {
        $found = roster_kintone_find_group_records($groupCode);
    } catch (RosterKintoneError $e) {
        roster_log('warning', 'load_kintone failed', ['group' => $groupCode] + $e->summary());
        throw new RosterException('kintoneに接続できないため、現在の名簿を読み込めませんでした。時間をおいて再度お試しいただくか、そのまま入力してください。', 'kintone_unavailable', 503);
    }
    if ($found['count'] > 1) {
        throw new RosterException('kintone上にこの団体の名簿が複数あるため読み込めません。総合管理事務局へご連絡ください。', 'kintone_multiple', 409);
    }
    $record = $found['records'][0] ?? null;
    $base = [
        'loaded' => true,
        'record_id' => $record['record_id'] ?? null,
        'revision' => $record['revision'] ?? null,
        'fetched_at' => roster_now_sql(),
        'rows' => $record['rows'] ?? [],
    ];
    $rows = [];
    foreach ($base['rows'] as $i => $k) {
        $role = roster_role_from_input((string)$k['role']);
        $rows[] = [
            'key' => 'k' . $k['kintone_row_id'],
            'kintone_row_id' => (string)$k['kintone_row_id'],
            // 未設定の状態は団体に選んでもらう（勝手に「在籍」にしない）
            'membership_status' => in_array((string)$k['membership_status'], roster_status_options(), true) ? (string)$k['membership_status'] : '',
            'name' => (string)$k['name'],
            'student_id' => roster_normalize_student_id($k['student_id']),
            'role' => $role ?? (string)$k['role'],
            'phone_number' => roster_normalize_phone($k['phone_number']),
            'uses_group_email' => (bool)$k['uses_group_email'],
            'is_website_editor' => (bool)$k['is_website_editor'],
        ];
    }
    $pdo = roster_db();
    $pdo->beginTransaction();
    try {
        $case = roster_case_lock_or_create($pdo, $form['id'], $form['roster']['fiscal_year'], $groupCode, $groupName);
        roster_assert_version($case, $expectedVersion);
        $version = (int)$case['draft_version'] + 1;
        $now = roster_now_sql();
        $pdo->prepare('UPDATE roster_cases SET draft_json = :draft, draft_base_json = :base, draft_version = :version, draft_updated_at = :now WHERE id = :id')->execute([
            ':draft' => roster_json(['rows' => $rows]),
            ':base' => roster_json($base),
            ':version' => $version,
            ':now' => $now,
            ':id' => (int)$case['id'],
        ]);
        $pdo->commit();
        return ['draft_version' => $version, 'rows' => $rows, 'base' => roster_base_summary($base)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** 最新の提出内容から下書きを作り直す（修正して再提出する） */
function roster_group_edit_from_latest(array $form, string $groupCode, int $expectedVersion): array
{
    $form = roster_require_roster_form($form);
    $pdo = roster_db();
    $pdo->beginTransaction();
    try {
        $case = roster_case_find($pdo, $form['id'], $form['roster']['fiscal_year'], $groupCode, true);
        if (!is_array($case) || (int)$case['latest_revision_id'] < 1) {
            throw new RosterException('提出済みの名簿がありません。', 'no_revision', 404);
        }
        roster_assert_version($case, $expectedVersion);
        $revision = roster_revision_by_id($pdo, (int)$case['latest_revision_id']);
        $payload = roster_json_decode((string)$revision['payload_json'], []);
        $rows = [];
        foreach ((array)($payload['rows'] ?? []) as $i => $r) {
            $rows[] = ['key' => 'v' . $revision['revision_number'] . '_' . ($i + 1)] + $r;
        }
        $version = (int)$case['draft_version'] + 1;
        $pdo->prepare('UPDATE roster_cases SET draft_json = :draft, draft_base_json = :base, draft_version = :version, draft_updated_at = :now WHERE id = :id')->execute([
            ':draft' => roster_json(['rows' => $rows]),
            ':base' => $revision['base_json'],
            ':version' => $version,
            ':now' => roster_now_sql(),
            ':id' => (int)$case['id'],
        ]);
        $pdo->commit();
        return ['draft_version' => $version, 'rows' => $rows];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function roster_base_summary(?array $base): ?array
{
    if (!is_array($base)) {
        return null;
    }
    return [
        'loaded' => (bool)($base['loaded'] ?? false),
        'record_id' => $base['record_id'] ?? null,
        'revision' => $base['revision'] ?? null,
        'fetched_at' => (string)($base['fetched_at'] ?? ''),
        'row_count' => count((array)($base['rows'] ?? [])),
    ];
}

/** 提出前の確認（検証結果と、読み込んだ kintone 名簿との差分） */
function roster_group_preview(array $form, string $groupCode, mixed $rawRows): array
{
    $form = roster_require_roster_form($form);
    $pdo = roster_db();
    $case = roster_case_find($pdo, $form['id'], $form['roster']['fiscal_year'], $groupCode);
    $base = is_array($case) ? roster_draft_base($case) : null;
    $rows = roster_normalize_rows($rawRows, roster_base_row_ids($base));
    $validation = roster_validate_rows($rows, $form['roster']);
    $diff = roster_compute_diff($rows, (array)($base['rows'] ?? []), null);
    return [
        'rows' => $rows,
        'validation' => $validation,
        'diff' => roster_public_diff($diff),
        'counts' => roster_count_rows($rows),
        'base' => roster_base_summary($base),
    ];
}

/** 画面表示用に差分を簡略化する */
function roster_public_diff(array $diff): array
{
    $entries = [];
    foreach ($diff['entries'] as $e) {
        $changes = [];
        foreach ($e['changes'] as $field => $c) {
            $changes[] = [
                'field' => $field,
                'label' => roster_field_label($field),
                'from' => roster_display_value($field, $c['from']),
                'to' => roster_display_value($field, $c['to']),
                'kintone_changed' => (bool)$c['kintone_changed'],
            ];
        }
        $entries[] = [
            'index' => $e['index'],
            'no' => $e['index'] + 1,
            'kind' => $e['kind'],
            'name' => (string)$e['row']['name'],
            'student_id' => (string)$e['row']['student_id'],
            'status' => (string)$e['row']['membership_status'],
            'role_label' => roster_role_label((string)$e['row']['role']),
            'role_name' => (string)($e['role_name'] ?? ''),
            'kintone_row_id' => $e['kintone_row_id'],
            'changes' => $changes,
            'student_id_corrected' => (bool)($e['student_id_corrected'] ?? false),
            'kintone_changed' => (bool)($e['kintone_changed'] ?? false),
        ];
    }
    $kept = array_map(static fn(array $k): array => [
        'kintone_row_id' => (string)$k['kintone_row_id'],
        'name' => (string)$k['name'],
        'student_id' => (string)$k['student_id'],
        'status' => (string)($k['membership_status'] ?? ''),
        'role_label' => roster_role_label((string)$k['role']),
    ], $diff['kept']);
    return ['entries' => $entries, 'kept' => $kept, 'summary' => $diff['summary']];
}

/**
 * 提出（新しいリビジョン）。kintone へは書き込まない。
 * 反映中・結果確認待ちの間は提出を受け付けず、内容は下書きとして保存する。
 */
function roster_group_submit(array $form, array $group, array $auth, mixed $rawRows, int $expectedVersion, string $submitterName, string $ipAddress = ''): array
{
    $form = roster_require_roster_form($form);
    $submitterName = roster_clean_text($submitterName, 100);
    if ($submitterName === '') {
        throw new RosterException('提出者の氏名を入力してください。', 'invalid', 422, ['field' => 'submitter_name']);
    }
    $groupCode = (string)$group['group_code'];
    $groupName = (string)$group['group_name'];
    $pdo = roster_db();
    $pdo->beginTransaction();
    try {
        $formRow = roster_require_roster_form(roster_form_row($pdo, $form['id'], 'LOCK IN SHARE MODE'));
        if ($formRow['roster']['fiscal_year'] !== $form['roster']['fiscal_year']) {
            throw new RosterException('フォームの対象年度が変更されました。画面を再読み込みしてください。', 'stale', 409);
        }
        $case = roster_case_lock_or_create($pdo, $form['id'], $form['roster']['fiscal_year'], $groupCode, $groupName);
        roster_assert_version($case, $expectedVersion);
        $base = roster_draft_base($case);
        $rows = roster_normalize_rows($rawRows, roster_base_row_ids($base));
        $validation = roster_validate_rows($rows, $formRow['roster']);
        if ($validation['errors'] !== []) {
            throw new RosterException('入力内容に不足・誤りがあります。', 'validation', 422, ['validation' => $validation]);
        }

        $job = (int)$case['current_job_id'] > 0 ? roster_job_by_id($pdo, (int)$case['current_job_id'], true) : null;
        if (is_array($job) && in_array((string)$job['status'], [ROSTER_JOB_RUNNING, ROSTER_JOB_UNKNOWN], true)) {
            // 古い内容と新しい内容の適用順が逆転しないよう、反映処理が終わるまで提出を受け付けない。
            $version = (int)$case['draft_version'] + 1;
            $pdo->prepare('UPDATE roster_cases SET draft_json = :draft, draft_version = :version, draft_updated_at = :now WHERE id = :id')->execute([
                ':draft' => roster_json(['rows' => $rows]),
                ':version' => $version,
                ':now' => roster_now_sql(),
                ':id' => (int)$case['id'],
            ]);
            $pdo->commit();
            throw new RosterException('前回承認された名簿をkintoneへ反映している最中のため、今は提出できません。入力内容は下書きに保存しました。しばらくしてから再度提出してください。', 'busy', 409, ['draft_version' => $version]);
        }
        if (is_array($job) && in_array((string)$job['status'], [ROSTER_JOB_QUEUED, ROSTER_JOB_FAILED, ROSTER_JOB_CONFLICT], true)) {
            roster_invalidate_job($pdo, $job, 'superseded_by_new_revision');
        }
        if ((int)$case['latest_revision_id'] > 0) {
            $prev = roster_revision_by_id($pdo, (int)$case['latest_revision_id'], true);
            $prevApplied = is_array($job) && (string)$job['status'] === ROSTER_JOB_APPLIED && (int)$job['revision_id'] === (int)$prev['id'];
            if (is_array($prev) && !$prevApplied && in_array((string)$prev['review_state'], [ROSTER_REVIEW_SUBMITTED, ROSTER_REVIEW_RETURNED, ROSTER_REVIEW_ACCEPTED], true)) {
                $pdo->prepare('UPDATE roster_revisions SET review_state = :state WHERE id = :id')->execute([':state' => ROSTER_REVISION_SUPERSEDED, ':id' => (int)$prev['id']]);
            }
        }

        $snapshot = roster_snapshot_rows($rows);
        $counts = roster_count_rows($snapshot);
        $revisionNumber = (int)$case['latest_revision_number'] + 1;
        $now = roster_now_sql();
        $pdo->prepare(
            'INSERT INTO roster_revisions (case_id, form_id, fiscal_year, group_code, revision_number, review_state, submitter_name, verified_email, verification_id, payload_json, payload_hash, base_json, row_count, active_count, inactive_count, submitted_at, submitted_ip) '
            . 'VALUES (:case_id, :form_id, :year, :code, :number, :state, :submitter, :email, :verification_id, :payload, :hash, :base, :rows, :active, :inactive, :now, :ip)'
        )->execute([
            ':case_id' => (int)$case['id'],
            ':form_id' => $form['id'],
            ':year' => $form['roster']['fiscal_year'],
            ':code' => $groupCode,
            ':number' => $revisionNumber,
            ':state' => ROSTER_REVIEW_SUBMITTED,
            ':submitter' => $submitterName,
            ':email' => mb_substr((string)($group['group_email'] ?? ''), 0, 255),
            ':verification_id' => (int)($auth['verification_id'] ?? 0) ?: null,
            ':payload' => roster_json([
                'rows' => $snapshot,
                'group_name' => $groupName,
                'form_settings' => [
                    'phone_required_scope' => $formRow['roster']['phone_required_scope'],
                    'phone_required_manager' => $formRow['roster']['phone_required_manager'],
                ],
                'warnings' => $validation['warnings'],
            ]),
            ':hash' => roster_payload_hash($snapshot),
            ':base' => $base !== null ? roster_json($base) : null,
            ':rows' => $counts['total'],
            ':active' => $counts['active'],
            ':inactive' => $counts['inactive'],
            ':now' => $now,
            ':ip' => $ipAddress !== '' ? hash('sha256', $ipAddress) : null,
        ]);
        $revisionId = (int)$pdo->lastInsertId();
        $version = (int)$case['draft_version'] + 1;
        $pdo->prepare(
            'UPDATE roster_cases SET review_state = :review, apply_state = :apply, latest_revision_id = :rev_id, latest_revision_number = :rev_no, '
            . 'current_acceptance_id = NULL, current_job_id = NULL, draft_json = NULL, draft_base_json = NULL, draft_version = :version, draft_updated_at = NULL, '
            . 'group_name = :name, last_submitted_at = :now WHERE id = :id'
        )->execute([
            ':review' => ROSTER_REVIEW_SUBMITTED,
            ':apply' => ROSTER_APPLY_NONE,
            ':rev_id' => $revisionId,
            ':rev_no' => $revisionNumber,
            ':version' => $version,
            ':name' => mb_substr($groupName, 0, 255, 'UTF-8'),
            ':now' => $now,
            ':id' => (int)$case['id'],
        ]);
        roster_insert_review($pdo, (int)$case['id'], $revisionId, 'submit', null, 'group', ['display_name' => $submitterName]);
        $pdo->commit();
        return [
            'case_id' => (int)$case['id'],
            'revision_id' => $revisionId,
            'revision_number' => $revisionNumber,
            'draft_version' => $version,
            'counts' => $counts,
            'superseded_job' => is_array($job) && in_array((string)$job['status'], [ROSTER_JOB_QUEUED, ROSTER_JOB_FAILED, ROSTER_JOB_CONFLICT], true),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** 団体向けの状態（自団体の分だけ） */
function roster_group_state(array $form, string $groupCode): array
{
    $form = roster_require_roster_form($form);
    $pdo = roster_db();
    $case = roster_case_find($pdo, $form['id'], $form['roster']['fiscal_year'], $groupCode);
    $state = [
        'fiscal_year' => $form['roster']['fiscal_year'],
        'settings' => [
            'phone_required_scope' => $form['roster']['phone_required_scope'],
            'phone_required_manager' => $form['roster']['phone_required_manager'],
            'max_rows' => (int)roster_config('rules.max_rows', 500),
        ],
        'case' => null,
        'draft' => null,
        'latest' => null,
        'history' => [],
    ];
    if (!is_array($case)) {
        $state['draft'] = ['rows' => [], 'draft_version' => 0, 'base' => null, 'exists' => false];
        return $state;
    }
    $job = (int)$case['current_job_id'] > 0 ? roster_job_by_id($pdo, (int)$case['current_job_id']) : null;
    $state['case'] = [
        'review_state' => (string)$case['review_state'],
        'review_label' => roster_review_state_label((string)$case['review_state']),
        'apply_state' => (string)$case['apply_state'],
        'apply_label' => roster_apply_state_label((string)$case['apply_state']),
        'latest_revision_number' => (int)$case['latest_revision_number'],
        'last_applied_revision_number' => $case['last_applied_revision_number'] !== null ? (int)$case['last_applied_revision_number'] : null,
        'last_applied_at' => (string)($case['last_applied_at'] ?? ''),
        'submit_blocked' => is_array($job) && in_array((string)$job['status'], [ROSTER_JOB_RUNNING, ROSTER_JOB_UNKNOWN], true),
        'pending_acceptance' => is_array($job) && (string)$job['status'] === ROSTER_JOB_QUEUED,
    ];
    $draftExists = $case['draft_json'] !== null;
    $state['draft'] = [
        'rows' => roster_draft_rows($case),
        'draft_version' => (int)$case['draft_version'],
        'updated_at' => (string)($case['draft_updated_at'] ?? ''),
        'base' => roster_base_summary(roster_draft_base($case)),
        'exists' => $draftExists,
    ];
    $revisions = $pdo->prepare('SELECT id, revision_number, review_state, submitter_name, row_count, active_count, inactive_count, submitted_at, payload_json FROM roster_revisions WHERE case_id = :id ORDER BY revision_number DESC LIMIT 30');
    $revisions->execute([':id' => (int)$case['id']]);
    $reviewStmt = $pdo->prepare('SELECT revision_id, action, reason, created_at FROM roster_reviews WHERE case_id = :id AND action IN ("return", "accept", "revoke") ORDER BY id ASC');
    $reviewStmt->execute([':id' => (int)$case['id']]);
    $reviewsByRevision = [];
    foreach ($reviewStmt->fetchAll() ?: [] as $r) {
        $reviewsByRevision[(int)$r['revision_id']][] = [
            'action' => (string)$r['action'],
            'reason' => (string)($r['reason'] ?? ''),
            'at' => (string)$r['created_at'],
        ];
    }
    foreach ($revisions->fetchAll() ?: [] as $i => $rev) {
        $item = [
            'revision_number' => (int)$rev['revision_number'],
            'review_state' => (string)$rev['review_state'],
            'review_label' => roster_review_state_label((string)$rev['review_state']),
            'submitter_name' => (string)$rev['submitter_name'],
            'row_count' => (int)$rev['row_count'],
            'active_count' => (int)$rev['active_count'],
            'inactive_count' => (int)$rev['inactive_count'],
            'submitted_at' => (string)$rev['submitted_at'],
            'reviews' => array_values(array_filter($reviewsByRevision[(int)$rev['id']] ?? [], static fn(array $r): bool => $r['action'] === 'return')),
        ];
        if ($i === 0) {
            $payload = roster_json_decode((string)$rev['payload_json'], []);
            $state['latest'] = $item + ['rows' => (array)($payload['rows'] ?? [])];
        }
        $state['history'][] = $item;
    }
    return $state;
}

/* ---------------- 担当者（kintone管理サイト）側 ---------------- */

/** kintone の現在値を取得し、指定リビジョンとの差分を返す。接続できなければ ok=false。 */
function roster_staff_live_compare(array $case, array $revision): array
{
    $payload = roster_json_decode((string)$revision['payload_json'], []);
    $rows = (array)($payload['rows'] ?? []);
    $base = roster_json_decode((string)($revision['base_json'] ?? ''), null);
    try {
        $found = roster_kintone_find_group_records((string)$case['group_code']);
    } catch (Throwable $e) {
        $summary = $e instanceof RosterKintoneError ? $e->summary() : [];
        roster_log('warning', 'live compare failed', ['case_id' => (int)$case['id']] + $summary);
        return ['ok' => false, 'error' => 'kintoneに接続できないため、現在の名簿と比較できません（' . mb_substr($e->getMessage(), 0, 200, 'UTF-8') . '）。', 'count' => null];
    }
    $record = $found['count'] === 1 ? $found['records'][0] : null;
    $diff = roster_compute_diff($rows, $record['rows'] ?? [], is_array($base) ? (array)($base['rows'] ?? []) : null);
    $baseRevision = is_array($base) ? ($base['revision'] ?? null) : null;
    return [
        'ok' => true,
        'count' => $found['count'],
        'record_id' => $record['record_id'] ?? null,
        'revision' => $record['revision'] ?? null,
        'record_ids' => array_map(static fn(array $r): int => (int)$r['record_id'], $found['records']),
        'diff' => roster_public_diff($diff),
        'compare_token' => roster_compare_token($found),
        'base_loaded' => is_array($base) && !empty($base['loaded']),
        'base_record_id' => is_array($base) ? ($base['record_id'] ?? null) : null,
        'base_revision' => $baseRevision,
        'base_outdated' => is_array($base) && !empty($base['loaded']) && $record !== null && (int)$baseRevision !== (int)$record['revision'],
        'record' => $record,
    ];
}

function roster_compare_token(array $found): string
{
    if ($found['count'] === 0) {
        return 'none';
    }
    if ($found['count'] > 1) {
        return 'multiple';
    }
    $r = $found['records'][0];
    return $r['record_id'] . ':' . $r['revision'];
}

function roster_staff_return(int $caseId, int $revisionId, string $reason, array $actor): array
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new RosterException('差し戻し理由を入力してください。');
    }
    if (mb_strlen($reason, 'UTF-8') > 2000) {
        throw new RosterException('差し戻し理由は2000文字以内で入力してください。');
    }
    $pdo = roster_db();
    $pdo->beginTransaction();
    try {
        $case = roster_case_by_id($pdo, $caseId, true);
        if (!is_array($case)) {
            throw new RosterException('名簿が見つかりません。', 'not_found', 404);
        }
        if ((int)$case['latest_revision_id'] !== $revisionId) {
            throw new RosterException('差し戻せるのは最新の提出だけです。画面を再読み込みしてください。', 'stale', 409);
        }
        $revision = roster_revision_by_id($pdo, $revisionId, true);
        if (!is_array($revision) || (string)$revision['review_state'] !== ROSTER_REVIEW_SUBMITTED) {
            throw new RosterException('この提出は確認中ではないため差し戻せません（accept済みの場合は先に取り消してください）。', 'state', 409);
        }
        $pdo->prepare('UPDATE roster_revisions SET review_state = :state WHERE id = :id')->execute([':state' => ROSTER_REVIEW_RETURNED, ':id' => $revisionId]);
        $pdo->prepare('UPDATE roster_cases SET review_state = :state WHERE id = :id')->execute([':state' => ROSTER_REVIEW_RETURNED, ':id' => $caseId]);
        roster_insert_review($pdo, $caseId, $revisionId, 'return', $reason, 'staff', $actor);
        $pdo->commit();
        return ['case_id' => $caseId, 'revision_id' => $revisionId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * accept。kintone の現在値を先に取得し（トランザクション外）、担当者が見た比較元と一致する場合だけ、
 * accept の保存と反映ジョブの登録を1つのトランザクションで行う。連打・再送は同じ accept・ジョブへ集約する。
 */
function roster_staff_accept(int $caseId, int $revisionId, string $postedCompareToken, array $actor): array
{
    $pdo = roster_db();
    $case = roster_case_by_id($pdo, $caseId);
    if (!is_array($case)) {
        throw new RosterException('名簿が見つかりません。', 'not_found', 404);
    }
    $revision = roster_revision_by_id($pdo, $revisionId);
    if (!is_array($revision) || (int)$revision['case_id'] !== $caseId) {
        throw new RosterException('提出リビジョンが見つかりません。', 'not_found', 404);
    }
    // 既に同じリビジョンを accept 済みなら、そのまま返す（二重 accept 防止）
    $existing = roster_active_acceptance_for_revision($pdo, $revisionId);
    if (is_array($existing)) {
        $job = roster_job_for_acceptance($pdo, (int)$existing['id']);
        return ['acceptance_id' => (int)$existing['id'], 'job_id' => (int)($job['id'] ?? 0), 'already' => true];
    }
    try {
        $found = roster_kintone_find_group_records((string)$case['group_code']);
    } catch (Throwable $e) {
        throw new RosterException('kintoneに接続できないため、比較元を確認できません。acceptは保留されました（' . mb_substr($e->getMessage(), 0, 200, 'UTF-8') . '）。', 'kintone_unavailable', 503);
    }
    if ($found['count'] > 1) {
        throw new RosterException('kintone上に同じ団体識別番号のレコードが' . $found['count'] . '件あるため accept できません。kintone側で1件に整理してから再確認してください。', 'kintone_multiple', 409);
    }
    $token = roster_compare_token($found);
    if ($postedCompareToken !== $token) {
        throw new RosterException('差分を表示した後に kintone の名簿が更新されました。画面を再読み込みして差分を確認し直してください。', 'stale', 409);
    }
    $record = $found['records'][0] ?? null;
    $payload = roster_json_decode((string)$revision['payload_json'], []);
    $rows = (array)($payload['rows'] ?? []);
    $match = roster_match_rows($rows, $record['rows'] ?? []);
    if ($match['ambiguous'] !== []) {
        throw new RosterException('学籍番号が一致する既存行が kintone 上に複数あるため accept できません。kintone側で重複を整理してください。', 'ambiguous', 409);
    }

    $pdo->beginTransaction();
    try {
        $case = roster_case_by_id($pdo, $caseId, true);
        if ((int)$case['latest_revision_id'] !== $revisionId) {
            throw new RosterException('acceptできるのは最新の提出だけです（新しい提出があります）。画面を再読み込みしてください。', 'stale', 409);
        }
        $revision = roster_revision_by_id($pdo, $revisionId, true);
        $existing = roster_active_acceptance_for_revision($pdo, $revisionId);
        if (is_array($existing)) {
            $job = roster_job_for_acceptance($pdo, (int)$existing['id']);
            $pdo->commit();
            return ['acceptance_id' => (int)$existing['id'], 'job_id' => (int)($job['id'] ?? 0), 'already' => true];
        }
        if ((string)$revision['review_state'] !== ROSTER_REVIEW_SUBMITTED) {
            throw new RosterException('この提出は確認中ではないため accept できません（' . roster_review_state_label((string)$revision['review_state']) . '）。', 'state', 409);
        }
        $form = roster_require_roster_form(roster_form_row($pdo, (int)$case['form_id'], 'LOCK IN SHARE MODE'));
        if ((int)$form['roster']['fiscal_year'] !== (int)$case['fiscal_year']) {
            throw new RosterException('過年度（' . (int)$case['fiscal_year'] . '年度）の提出は現行名簿へ反映できません。', 'past_year', 409);
        }
        $currentJob = (int)$case['current_job_id'] > 0 ? roster_job_by_id($pdo, (int)$case['current_job_id'], true) : null;
        if (is_array($currentJob) && in_array((string)$currentJob['status'], [ROSTER_JOB_QUEUED, ROSTER_JOB_RUNNING, ROSTER_JOB_UNKNOWN], true)) {
            throw new RosterException('この団体には処理中の反映ジョブがあります。完了してから accept してください。', 'busy', 409);
        }
        $now = roster_now_sql();
        $pdo->prepare(
            'INSERT INTO roster_acceptances (case_id, revision_id, revision_number, active_revision_id, snapshot_json, snapshot_hash, compared_record_count, compared_record_id, compared_revision, compared_rows_json, compared_at, accepted_by_account_id, accepted_by_login_id, accepted_by_name, accepted_at) '
            . 'VALUES (:case_id, :revision_id, :revision_number, :active, :snapshot, :hash, :count, :record_id, :revision, :rows, :now, :account_id, :login_id, :name, :now2)'
        )->execute([
            ':case_id' => $caseId,
            ':revision_id' => $revisionId,
            ':revision_number' => (int)$revision['revision_number'],
            ':active' => $revisionId,
            ':snapshot' => roster_json(['rows' => $rows, 'group_code' => (string)$case['group_code']]),
            ':hash' => roster_payload_hash($rows),
            ':count' => $found['count'],
            ':record_id' => $record['record_id'] ?? null,
            ':revision' => $record['revision'] ?? null,
            ':rows' => $record !== null ? roster_json($record['rows']) : null,
            ':now' => $now,
            ':account_id' => (int)($actor['id'] ?? 0) ?: null,
            ':login_id' => (string)($actor['login_id'] ?? '') ?: null,
            ':name' => mb_substr((string)($actor['display_name'] ?? ''), 0, 190, 'UTF-8') ?: null,
            ':now2' => $now,
        ]);
        $acceptanceId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO roster_apply_jobs (case_id, acceptance_id, revision_id, status, next_run_at) VALUES (:case_id, :acceptance_id, :revision_id, :status, :now)')->execute([
            ':case_id' => $caseId,
            ':acceptance_id' => $acceptanceId,
            ':revision_id' => $revisionId,
            ':status' => ROSTER_JOB_QUEUED,
            ':now' => $now,
        ]);
        $jobId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE roster_revisions SET review_state = :state WHERE id = :id')->execute([':state' => ROSTER_REVIEW_ACCEPTED, ':id' => $revisionId]);
        $pdo->prepare('UPDATE roster_cases SET review_state = :review, apply_state = :apply, current_acceptance_id = :acc, current_job_id = :job WHERE id = :id')->execute([
            ':review' => ROSTER_REVIEW_ACCEPTED,
            ':apply' => ROSTER_JOB_QUEUED,
            ':acc' => $acceptanceId,
            ':job' => $jobId,
            ':id' => $caseId,
        ]);
        roster_insert_review($pdo, $caseId, $revisionId, 'accept', null, 'staff', $actor);
        $pdo->commit();
        return ['acceptance_id' => $acceptanceId, 'job_id' => $jobId, 'already' => false];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // 同時 accept で一意制約に当たった場合は、先に保存された accept を返す
        $existing = (string)$e->getCode() === '23000' ? roster_active_acceptance_for_revision($pdo, $revisionId) : null;
        if (is_array($existing)) {
            $job = roster_job_for_acceptance($pdo, (int)$existing['id']);
            return ['acceptance_id' => (int)$existing['id'], 'job_id' => (int)($job['id'] ?? 0), 'already' => true];
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function roster_active_acceptance_for_revision(PDO $pdo, int $revisionId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM roster_acceptances WHERE active_revision_id = :id LIMIT 1');
    $stmt->execute([':id' => $revisionId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function roster_job_for_acceptance(PDO $pdo, int $acceptanceId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM roster_apply_jobs WHERE acceptance_id = :id LIMIT 1');
    $stmt->execute([':id' => $acceptanceId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** accept の取り消し（反映待ち・失敗・競合のときだけ）。ジョブの失効と同じトランザクションで行う。 */
function roster_staff_revoke(int $caseId, int $jobId, string $reason, array $actor): array
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new RosterException('取り消しの理由を入力してください。');
    }
    $pdo = roster_db();
    $pdo->beginTransaction();
    try {
        $case = roster_case_by_id($pdo, $caseId, true);
        if (!is_array($case) || (int)$case['current_job_id'] !== $jobId) {
            throw new RosterException('対象の反映ジョブが現在のものではありません。画面を再読み込みしてください。', 'stale', 409);
        }
        $job = roster_job_by_id($pdo, $jobId, true);
        if (!is_array($job) || !in_array((string)$job['status'], [ROSTER_JOB_QUEUED, ROSTER_JOB_FAILED, ROSTER_JOB_CONFLICT], true)) {
            throw new RosterException('反映中・反映済み・結果確認待ちの accept は取り消せません。', 'state', 409);
        }
        roster_invalidate_job($pdo, $job, 'revoked_by_staff', $actor);
        $pdo->prepare('UPDATE roster_revisions SET review_state = :state WHERE id = :id AND review_state = :accepted')->execute([
            ':state' => ROSTER_REVIEW_SUBMITTED,
            ':id' => (int)$job['revision_id'],
            ':accepted' => ROSTER_REVIEW_ACCEPTED,
        ]);
        $pdo->prepare('UPDATE roster_cases SET review_state = :review, apply_state = :apply, current_acceptance_id = NULL, current_job_id = NULL WHERE id = :id')->execute([
            ':review' => ROSTER_REVIEW_SUBMITTED,
            ':apply' => ROSTER_APPLY_NONE,
            ':id' => $caseId,
        ]);
        roster_insert_review($pdo, $caseId, (int)$job['revision_id'], 'revoke', $reason, 'staff', $actor);
        $pdo->commit();
        return ['case_id' => $caseId, 'job_id' => $jobId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** 失敗したジョブの再試行、結果確認待ちの照合を次回の定期実行に回す。accept の有効性は worker が実行時に再確認する。 */
function roster_staff_retry(int $caseId, int $jobId, array $actor): array
{
    $pdo = roster_db();
    $pdo->beginTransaction();
    try {
        $case = roster_case_by_id($pdo, $caseId, true);
        if (!is_array($case) || (int)$case['current_job_id'] !== $jobId) {
            throw new RosterException('対象の反映ジョブが現在のものではありません。', 'stale', 409);
        }
        $job = roster_job_by_id($pdo, $jobId, true);
        $status = (string)($job['status'] ?? '');
        if ($status === ROSTER_JOB_FAILED) {
            $pdo->prepare('UPDATE roster_apply_jobs SET status = :queued, next_run_at = :now, attempts = 0, error_class = NULL, error_code = NULL, error_message = NULL, finished_at = NULL WHERE id = :id AND status = :failed')->execute([
                ':queued' => ROSTER_JOB_QUEUED,
                ':now' => roster_now_sql(),
                ':id' => $jobId,
                ':failed' => ROSTER_JOB_FAILED,
            ]);
            $pdo->prepare('UPDATE roster_cases SET apply_state = :apply WHERE id = :id')->execute([':apply' => ROSTER_JOB_QUEUED, ':id' => $caseId]);
            $action = 'retry';
        } elseif ($status === ROSTER_JOB_UNKNOWN) {
            $pdo->prepare('UPDATE roster_apply_jobs SET next_run_at = :now WHERE id = :id AND status = :unknown')->execute([':now' => roster_now_sql(), ':id' => $jobId, ':unknown' => ROSTER_JOB_UNKNOWN]);
            $action = 'reconcile';
        } else {
            throw new RosterException('再試行できるのは「反映失敗」「結果確認待ち」のジョブだけです。競合は差分を確認し直して accept し直してください。', 'state', 409);
        }
        roster_insert_review($pdo, $caseId, (int)$job['revision_id'], $action, null, 'staff', $actor);
        $pdo->commit();
        return ['job_id' => $jobId, 'action' => $action];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function roster_staff_counts(?int $fiscalYear = null): array
{
    $pdo = roster_db();
    $where = $fiscalYear ? 'WHERE fiscal_year = :year' : '';
    $stmt = $pdo->prepare(
        'SELECT SUM(review_state = "submitted") AS submitted, SUM(review_state = "returned") AS returned, '
        . 'SUM(apply_state = "queued") AS queued, SUM(apply_state = "running") AS running, SUM(apply_state = "failed") AS failed, '
        . 'SUM(apply_state = "conflict") AS conflict, SUM(apply_state = "unknown") AS unknown, SUM(apply_state = "applied") AS applied, '
        . 'SUM(review_state = "draft") AS draft, COUNT(*) AS total FROM roster_cases ' . $where
    );
    $stmt->execute($fiscalYear ? [':year' => $fiscalYear] : []);
    $row = $stmt->fetch() ?: [];
    return array_map('intval', array_map(static fn($v) => $v ?? 0, $row));
}

function roster_staff_list(array $filters): array
{
    $pdo = roster_db();
    $where = ['1 = 1'];
    $params = [];
    if ((int)($filters['fiscal_year'] ?? 0) > 0) {
        $where[] = 'c.fiscal_year = :year';
        $params[':year'] = (int)$filters['fiscal_year'];
    }
    if ((int)($filters['form_id'] ?? 0) > 0) {
        $where[] = 'c.form_id = :form_id';
        $params[':form_id'] = (int)$filters['form_id'];
    }
    $state = (string)($filters['state'] ?? '');
    if (in_array($state, ['submitted', 'returned', 'draft'], true)) {
        $where[] = 'c.review_state = :state';
        $params[':state'] = $state;
    } elseif (in_array($state, ['queued', 'running', 'failed', 'conflict', 'unknown', 'applied'], true)) {
        $where[] = 'c.apply_state = :state';
        $params[':state'] = $state;
    } elseif ($state === 'attention') {
        $where[] = '(c.review_state = "submitted" OR c.apply_state IN ("failed", "conflict", "unknown"))';
    }
    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(c.group_code LIKE :q1 OR c.group_name LIKE :q2)';
        $params[':q1'] = '%' . $q . '%';
        $params[':q2'] = '%' . $q . '%';
    }
    $stmt = $pdo->prepare(
        'SELECT c.id, c.form_id, c.fiscal_year, c.group_code, c.group_name, c.review_state, c.apply_state, c.latest_revision_number, c.last_submitted_at, c.last_applied_at, c.updated_at, c.draft_json IS NOT NULL AS has_draft, f.name AS form_name '
        . 'FROM roster_cases c LEFT JOIN managed_forms f ON f.id = c.form_id WHERE ' . implode(' AND ', $where)
        . ' ORDER BY FIELD(c.apply_state, "conflict", "unknown", "failed") DESC, FIELD(c.review_state, "submitted") DESC, c.updated_at DESC LIMIT 500'
    );
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function roster_staff_detail(int $caseId): ?array
{
    $pdo = roster_db();
    $case = roster_case_by_id($pdo, $caseId);
    if (!is_array($case)) {
        return null;
    }
    $form = roster_form_row($pdo, (int)$case['form_id']);
    $q = static function (string $sql) use ($pdo, $caseId): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $caseId]);
        return $stmt->fetchAll() ?: [];
    };
    $jobs = $q('SELECT * FROM roster_apply_jobs WHERE case_id = :id ORDER BY id DESC LIMIT 50');
    $attempts = [];
    if ($jobs !== []) {
        $ids = implode(',', array_map(static fn(array $j): int => (int)$j['id'], $jobs));
        $attempts = $pdo->query('SELECT * FROM roster_apply_attempts WHERE job_id IN (' . $ids . ') ORDER BY id DESC LIMIT 200')->fetchAll() ?: [];
    }
    return [
        'case' => $case,
        'form' => $form,
        'directory' => roster_directory_get((string)$case['group_code'], $pdo),
        'revisions' => $q('SELECT * FROM roster_revisions WHERE case_id = :id ORDER BY revision_number DESC'),
        'reviews' => $q('SELECT * FROM roster_reviews WHERE case_id = :id ORDER BY id DESC'),
        'acceptances' => $q('SELECT id, revision_id, revision_number, compared_record_count, compared_record_id, compared_revision, compared_at, accepted_by_name, accepted_at, revoked_at, revoked_by_name, revoke_reason FROM roster_acceptances WHERE case_id = :id ORDER BY id DESC'),
        'jobs' => $jobs,
        'attempts' => $attempts,
    ];
}
