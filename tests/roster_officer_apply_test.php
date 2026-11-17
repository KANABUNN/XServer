<?php

declare(strict_types=1);

/**
 * 部員名簿 第2段階（承認計画の固定と、ID20 → ID22 → ID21 のステップ実行）の検証
 *
 * 実レコードは使わず、kintone は FakeKintone で置き換える。fitsc_forms の roster_* テーブルを空にするため、
 * ローカルの使い捨てDB以外では実行しないこと（接続先は tests/roster_workflow_test.php と同じ）。
 *
 *   php -d extension=pdo_mysql -d extension=mbstring tests/roster_officer_apply_test.php
 */

$tmp = rtrim(sys_get_temp_dir(), '/\\') . '/roster_officer_apply_test_' . getmypid();
@mkdir($tmp, 0700, true);
$db = [
    'driver' => 'mysql',
    'host' => getenv('ROSTER_TEST_DB_HOST') ?: '127.0.0.1',
    'port' => (int)(getenv('ROSTER_TEST_DB_PORT') ?: 33306),
    'charset' => 'utf8mb4',
    'user' => getenv('ROSTER_TEST_DB_USER') ?: 'root',
    'password' => getenv('ROSTER_TEST_DB_PASS') ?: 'roster-test-root',
];
$config = [
    'timezone' => 'Asia/Tokyo',
    'db_connections' => [
        'forms' => $db + ['dbname' => 'fitsc_forms'],
        'org' => $db + ['dbname' => 'fitsc_org'],
        'account' => $db + ['dbname' => 'fitsc_account'],
    ],
    'kintone' => ['storage_root' => $tmp . '/kintone_storage'],
    'roster' => [
        'worker' => ['lock_file' => $tmp . '/worker.lock'],
        // このテストでは書込みと空席の自動解除を有効にする（既定はどちらも無効）
        'officers' => ['write_enabled' => true, 'clear_enabled' => true],
    ],
];
file_put_contents($tmp . '/config.php', '<?php return ' . var_export($config, true) . ';');
putenv('FITSC_CONFIG_FILE=' . $tmp . '/config.php');

require_once dirname(__DIR__) . '/apps/roster_core/bootstrap.php';
require_once __DIR__ . '/support/fake_kintone.php';

$GLOBALS['failures'] = 0;
$GLOBALS['passes'] = 0;
function check(bool $condition, string $label, mixed $detail = null): void
{
    $GLOBALS[$condition ? 'passes' : 'failures']++;
    echo ($condition ? '  ok  - ' : '  NG  - ') . $label . ($condition || $detail === null ? '' : ' :: ' . (is_scalar($detail) ? (string)$detail : json_encode($detail, JSON_UNESCAPED_UNICODE))) . "\n";
}
function section(string $title): void
{
    echo "\n# {$title}\n";
}
function member(string $sid, string $name, string $role = '-', string $status = '在籍'): array
{
    return ['key' => 'k' . $sid, 'kintone_row_id' => null, 'membership_status' => $status, 'name' => $name, 'student_id' => $sid, 'role' => $role, 'phone_number' => '090-0000-0000', 'uses_group_email' => false, 'is_website_editor' => false];
}
function declared(string $sid, string $family, string $given, array $over = []): array
{
    return $over + ['student_id' => $sid, 'family_name' => $family, 'personal_name' => $given, 'mail' => strtolower($sid) . '@bene.fit.ac.jp', 'depend' => '情報工学科', 'type' => '大学 学生', 'org_depend_state' => '無'];
}
function person(int $id, string $sid, string $family, string $given, array $over = []): array
{
    return $over + ['id' => $id, 'revision' => 3, 'student_id' => $sid, 'family_name' => $family, 'personal_name' => $given, 'mail' => strtolower($sid) . '@bene.fit.ac.jp', 'depend' => '情報工学科', 'type' => '大学 学生', 'org_depend_state' => '無'];
}
/** 名簿の行と、役員の申告（姓・名は名簿の氏名を空白で分けたもの）をまとめて作る */
function roster_input(array $members, array $slots): array
{
    $rows = [];
    $people = [];
    foreach ($members as [$sid, $name, $role, $status]) {
        $rows[] = member($sid, $name, $role, $status);
        if (in_array($sid, $slots, true)) {
            [$family, $given] = explode(' ', $name);
            $people[] = declared($sid, $family, $given);
        }
    }
    return [$rows, ['slots' => $slots, 'people' => $people]];
}
function ledger_slots(FakeKintone $fake, array $map): array
{
    $slots = [];
    foreach ($map as $prefix => $sid) {
        $p = array_values(array_filter($fake->people, static fn(array $x): bool => $x['student_id'] === $sid))[0] ?? null;
        $slots[$prefix . '_stundet_id'] = $sid;
        $slots[$prefix . '_name'] = $p ? $p['family_name'] . $p['personal_name'] : '';
        $slots[$prefix . '_mail'] = $p ? $p['mail'] : '';
    }
    return $slots;
}
function clock(string $at): void
{
    roster_test_clock(new DateTimeImmutable($at, new DateTimeZone('Asia/Tokyo')), true);
}

$fake = new FakeKintone();
$fake->officerFieldsMigrated = true;
roster_kintone_test_transport(fn(string $m, string $p, array $payload, int $t): array => $fake->handle($m, $p, $payload, $t), true);

$pdo = roster_db();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['roster_apply_steps', 'roster_group_apply_guards', 'roster_officer_history', 'roster_apply_attempts', 'roster_apply_jobs', 'roster_acceptances', 'roster_reviews', 'roster_revisions', 'roster_row_links', 'roster_cases', 'roster_group_verifications', 'roster_group_directory', 'roster_worker_heartbeats'] as $table) {
    $pdo->exec('TRUNCATE TABLE ' . $table);
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("DELETE FROM managed_forms WHERE slug LIKE 'roster-apply-test-%'");
$makeForm = static function (string $suffix, array $settings) use ($pdo): array {
    $pdo->prepare('INSERT INTO managed_forms (slug, name, is_active, settings_json) VALUES (:s, :n, 1, :j)')->execute([
        ':s' => 'roster-apply-test-' . $suffix . '-' . getmypid(),
        ':n' => '役員反映テスト ' . $suffix,
        ':j' => json_encode(['form_type' => 'roster', 'roster_fiscal_year' => 2026] + $settings, JSON_UNESCAPED_UNICODE),
    ]);
    return roster_form_row($pdo, (int)$pdo->lastInsertId());
};
$form = $makeForm('on', ['roster_officer_sync' => true]);
$formOff = $makeForm('off', []);
roster_directory_refresh($pdo);
$staff = ['id' => 9001, 'login_id' => 'staff01', 'display_name' => '担当 花子'];
$admin = ['id' => 9002, 'login_id' => 'admin01', 'display_name' => '管理 太郎'];
$tenant = roster_kintone_tenant();

$submit = static function (array $form, array $rows, ?array $off, string $code) use ($pdo): array {
    $case = roster_case_find($pdo, $form['id'], 2026, $code);
    return roster_group_submit($form, roster_directory_get($code, $pdo), ['verification_id' => 0], $rows, (int)($case['draft_version'] ?? 0), '提出 者', '127.0.0.1', $off);
};
$planFor = static function (array $saved) use ($pdo): ?array {
    return roster_officer_build_plan(roster_case_by_id($pdo, $saved['case_id']), roster_revision_by_id($pdo, $saved['revision_id']));
};
$accept = static function (array $saved, ?string $fingerprint = null) use ($pdo, $staff, $planFor): array {
    $case = roster_case_by_id($pdo, $saved['case_id']);
    $found = roster_kintone_find_group_records((string)$case['group_code']);
    $fingerprint ??= (string)($planFor($saved)['fingerprint'] ?? '');
    return roster_staff_accept($saved['case_id'], $saved['revision_id'], roster_compare_token($found), $staff, $fingerprint);
};
$run = static function (int $jobId): ?array {
    return roster_worker_run(['only_job_id' => $jobId, 'skip_directory' => true, 'worker_name' => 'test'])['processed'][0] ?? null;
};
$steps = static fn(int $jobId): array => array_column(roster_steps_for_job($pdo, $jobId), null, 'step_key');
$history = static fn(string $code): array => roster_officer_history_for_group($pdo, $tenant, $code);
$events = static fn(string $code, string $type): array => array_values(array_filter($history($code), static fn(array $h): bool => $h['event_type'] === $type));
$ledgerOf = static fn(string $code): array => array_values(array_filter($fake->officerRecords, static fn(array $r): bool => $r['group_id'] === $code));
$peopleWith = static fn(string $sid): array => array_values(array_filter($fake->people, static fn(array $p): bool => $p['student_id'] === $sid));
$job = static fn(int $id): array => roster_job_by_id($pdo, $id);
$caseOf = static fn(array $saved): array => roster_case_by_id($pdo, $saved['case_id']);

// CA056 の現状: ID22 に4名、ID21 に現行1件（団体キー移行済み）
$fake->people = [person(1, 'BM001', '代表', '一郎'), person(2, 'BM002', '会計', '二郎'), person(3, 'BM003', '総務', '三郎'), person(4, 'BM004', '副代', '四郎')];
$fake->officerRecords = [[
    'id' => 500, 'revision' => 7, 'group_id' => 'CA056', 'group_name' => '福工大謎解きサークル', 'group_org' => ['CA056'], 'register_date' => '2025-04-01', 'group_key' => 'CA056',
    'slots' => ledger_slots($fake, ['representative' => 'BM001', 'deputy_representative_1' => 'BM004', 'accountant' => 'BM002', 'general_affairs_1' => 'BM003']),
]];
$sameMembers = [['BM001', '代表 一郎', '代表者', '在籍'], ['BM004', '副代 四郎', '副代表者', '在籍'], ['BM002', '会計 二郎', '会計長', '在籍'], ['BM003', '総務 三郎', '総務', '在籍'], ['BM006', '部員 六郎', '-', '在籍']];
$sameSlots = ['representative' => 'BM001', 'deputy_representative_1' => 'BM004', 'accountant' => 'BM002', 'general_affairs_1' => 'BM003'];

section('書込み対象でない団体（第1段階との互換）');
[$rows, $off] = roster_input($sameMembers, $sameSlots);
$s0 = $submit($form, $rows, $off, 'CA056');
$plan0 = $planFor($s0);
check($plan0['gate']['enabled'] === false && str_contains($plan0['gate']['reason'], '書込み対象に指定されていません'), '団体を指定するまでは書込み対象にならない', $plan0['gate']);
$a0 = $accept($s0, '');
$snap0 = json_decode((string)roster_acceptance_by_id($pdo, $a0['acceptance_id'])['snapshot_json'], true);
check(!isset($snap0['snapshot_version']) && empty($a0['officers']) && roster_steps_for_job($pdo, $a0['job_id']) === [], '版2の提出でも書込み対象外なら、ID20 だけの承認（第1段階と同じ形）', array_keys($snap0));
$fake->appCalls = [];
$r = $run($a0['job_id']);
check(($r['status'] ?? '') === 'applied' && $fake->officerWrites() === 0 && count($fake->records) === 1, 'ID20 だけが反映され、ID21・ID22 への書込みは0回', $r);

section('書込み対象の指定（admin）');
try {
    roster_officer_target_set('CA056', $formOff['id'], $admin);
    check(false, '役員情報を受け付けないフォームは指定できない');
} catch (RosterException $e) {
    check(str_contains($e->getMessage(), '役員情報を受け付ける'), '役員情報を受け付けないフォームは指定できない', $e->getMessage());
}
$t = roster_officer_target_set('CA056', $form['id'], $admin);
$guard = roster_guard_get($pdo, $tenant, 'CA056');
check($t['fiscal_year'] === 2026 && (int)$guard['officer_form_id'] === $form['id'] && (int)$guard['generation'] === 1, '団体・フォーム・年度を指定し、設定の世代を進める', $guard);
check($planFor($s0)['gate']['enabled'] === true, '指定後は書込み対象になる');

section('幹部交代なし：登録日と代表者履歴を保持');
clock('2026-02-01 10:00:00');
$s1 = $submit($form, $rows, $off, 'CA056');
$plan1 = $planFor($s1);
check($plan1['writable'] === true && $plan1['ledger']['register_date_mode'] === 'keep' && $plan1['representative']['changed'] === false, '構成が同じなら書込み可能な計画で、登録日は維持', ['wb' => $plan1['write_blockers'], 'b' => $plan1['blockers']]);
$e = null;
try {
    $accept($s1, str_repeat('0', 64));
} catch (RosterException $e) {
}
check($e instanceof RosterException && $e->reason === 'stale', '比較後に計画が変わった（指紋が違う）accept は拒否', $e?->getMessage());
$a1 = $accept($s1);
$snap1 = json_decode((string)roster_acceptance_by_id($pdo, $a1['acceptance_id'])['snapshot_json'], true);
check(($snap1['snapshot_version'] ?? 0) === 2 && $snap1['officers']['ledger']['register_date'] === '2025-04-01' && $snap1['target']['guard_generation'] === 1, 'accept で計画（対象・比較元・登録日・設定の世代）を版2のスナップショットに固定', $snap1['officers']['ledger']);
check(roster_acceptance_by_id($pdo, $a1['acceptance_id'])['snapshot_hash'] === roster_officer_snapshot_hash($snap1), '承認スナップショットのハッシュは計画全体から計算');
check(array_keys($steps($a1['job_id'])) === ['roster', 'person_1', 'person_2', 'person_3', 'person_4', 'ledger'], 'ステップの初期行を accept と同時に作る（ID20 → 人物 → ID21）', array_keys($steps($a1['job_id'])));
$fake->appCalls = [];
$r = $run($a1['job_id']);
$st = $steps($a1['job_id']);
check(($r['status'] ?? '') === 'applied', '全ステップを確認して反映済み', $r);
check($st['person_1']['write_kind'] === 'none' && $st['ledger']['write_kind'] === 'none' && $fake->officerWrites() === 0, '既存参照と変更のない台帳は書き込まずに照合済み', array_column($st, 'write_kind', 'step_key'));
check($ledgerOf('CA056')[0]['register_date'] === '2025-04-01' && $ledgerOf('CA056')[0]['revision'] === 7, 'ID21 の登録日とリビジョンは変わらない');
check(count($events('CA056', 'baseline')) === 1 && $events('CA056', 'change') === [] && $events('CA056', 'baseline')[0]['new_student_id'] === 'BM001', '初回の書込み前に現任代表者を履歴の基準にし、交代は記録しない', $history('CA056'));

section('幹部の変更：日付をまたいで反映・再試行しても accept 日を使う');
$changeMembers = [['BM005', '新代 五郎', '代表者', '入部'], ['BM001', '代表 一郎', '副代表者', '在籍'], ['BM002', '会計 二郎', '会計長', '在籍'], ['BM003', '総務 三郎', '総務', '在籍'], ['BM006', '部員 六郎', '-', '在籍'], ['BM004', '副代 四郎', '副代表者', '卒部']];
[$rows2, $off2] = roster_input($changeMembers, ['representative' => 'BM005', 'deputy_representative_1' => 'BM001', 'accountant' => 'BM002', 'general_affairs_1' => 'BM003']);
clock('2026-02-10 23:30:00');
$s2 = $submit($form, $rows2, $off2, 'CA056');
$plan2 = $planFor($s2);
check($plan2['writable'] === true && $plan2['ledger']['register_date_mode'] === 'accept_date' && $plan2['representative']['changed'] === true, '代表者の交代を含む計画（登録日は accept 日）', ['wb' => $plan2['write_blockers'], 'b' => $plan2['blockers']]);
clock('2026-02-10 23:59:30');
$a2 = $accept($s2);
$snap2 = json_decode((string)roster_acceptance_by_id($pdo, $a2['acceptance_id'])['snapshot_json'], true);
check($snap2['accepted_date'] === '2026-02-10' && $snap2['officers']['ledger']['register_date'] === '2026-02-10', 'accept した時点の日本日付を登録日として固定', $snap2['accepted_date']);
clock('2026-02-11 00:05:00');
$fake->inject = static fn($m, $p, $payload) => ($m === 'PUT' && (int)($payload['app'] ?? 0) === 21) ? 'apply_then_timeout' : null;
$r = $run($a2['job_id']);
$fake->inject = null;
$st = $steps($a2['job_id']);
check(($r['status'] ?? '') === 'unknown' && $st['ledger']['status'] === 'unknown' && $st['roster']['status'] === 'verified', '台帳の送信後に通信が切れたら、そのステップだけ結果確認待ち（ID20 は確認済みのまま）', array_column($st, 'status', 'step_key'));
check(count($peopleWith('BM005')) === 1 && $st[array_search('BM005', array_column($snap2['officers']['people'], 'student_id', 'step_key'), true)]['status'] === 'verified', '未登録の新代表者を ID22 に1件登録して読み戻した');
check($events('CA056', 'change') === [], '交代の確定前（読戻し前）は履歴に記録しない');
clock('2026-02-11 00:20:00');
$fake->appCalls = [];
$r = $run($a2['job_id']);
$ledger = $ledgerOf('CA056')[0];
check(($r['status'] ?? '') === 'applied' && $fake->officerWrites() === 0, '照合で反映済みと確認でき、同じ書込みを繰り返さない', $r);
check($ledger['register_date'] === '2026-02-10' && $ledger['slots']['representative_stundet_id'] === 'BM005' && $ledger['slots']['representative_name'] === '新代五郎', '日付をまたいでも登録日は accept 日（2月10日）。代表者は交代', $ledger);
$changes = $events('CA056', 'change');
check(count($changes) === 1 && $changes[0]['previous_student_id'] === 'BM001' && $changes[0]['new_student_id'] === 'BM005' && $changes[0]['appointed_on'] === null, '交代を一度だけ履歴に記録（前任・後任、就任日は不明なら空欄）', $changes);
check(count($ledgerOf('CA056')) === 1 && count($peopleWith('BM005')) === 1, 'ID21 は1件のまま、ID22 の新規登録も1件のまま');
check((int)$caseOf($s2)['last_applied_revision_number'] === (int)$s2['revision_number'], 'ID20 の最終反映版は ID20 の読戻し時に更新');

section('代表者交代後、履歴確定前に処理が中断した場合');
$fake->people[] = person(6, 'BM006', '部員', '六郎');
[$rows3, $off3] = roster_input(
    [['BM006', '部員 六郎', '代表者', '在籍'], ['BM001', '代表 一郎', '副代表者', '在籍'], ['BM002', '会計 二郎', '会計長', '在籍'], ['BM003', '総務 三郎', '総務', '在籍'], ['BM005', '新代 五郎', '-', '在籍']],
    ['representative' => 'BM006', 'deputy_representative_1' => 'BM001', 'accountant' => 'BM002', 'general_affairs_1' => 'BM003']
);
clock('2026-03-01 09:00:00');
$s3 = $submit($form, $rows3, $off3, 'CA056');
$a3 = $accept($s3);
// ID21 の書込み後、読戻しの前にプロセスが止まった（リースを失ったまま）ことを再現する
$fake->inject = static function ($m, $p, $payload) {
    if ($m === 'GET' && $p === '/k/v1/record.json' && (int)($payload['app'] ?? 0) === 21) {
        throw new RosterLeaseLost('simulated stop');
    }
    return null;
};
$r = $run($a3['job_id']);
$fake->inject = null;
check(($r['status'] ?? '') === 'lease_lost' && $job($a3['job_id'])['status'] === 'running' && $steps($a3['job_id'])['ledger']['phase'] === 'written', '書込み後に停止すると、ジョブは実行中・台帳ステップは送信済みのまま残る', $r);
check($ledgerOf('CA056')[0]['slots']['representative_stundet_id'] === 'BM006' && count($events('CA056', 'change')) === 1, 'kintone では交代済みだが、履歴はまだ確定していない');
$pdo->prepare('UPDATE roster_apply_jobs SET lease_expires_at = :past WHERE id = :id')->execute([':past' => roster_now_sql(-60), ':id' => $a3['job_id']]);
$recovered = roster_worker_recover_leases($pdo);
check($recovered === 1 && $job($a3['job_id'])['status'] === 'unknown' && $steps($a3['job_id'])['ledger']['status'] === 'unknown', '期限切れのリースを復旧すると、送信済みのステップは結果確認待ちになる');
$fake->appCalls = [];
$r = $run($a3['job_id']);
$changes = $events('CA056', 'change');
check(($r['status'] ?? '') === 'applied' && $fake->officerWrites() === 0, '保存済みの計画から照合を再開し、書込みは繰り返さない', $r);
check(count($changes) === 2 && $changes[1]['previous_student_id'] === 'BM005' && $changes[1]['new_student_id'] === 'BM006', '同じ交代を重複登録せず、一度だけ確定する', $changes);
$pdo->prepare('UPDATE roster_apply_jobs SET status = "unknown", next_run_at = :now WHERE id = :id')->execute([':now' => roster_now_sql(), ':id' => $a3['job_id']]);
$r = $run($a3['job_id']);
check(count($events('CA056', 'change')) === 2, '確定後に照合をもう一度実行しても、履歴は増えない', $events('CA056', 'change'));

section('総務の空席と、任意枠の退任者の解除');
[$rows4, $off4] = roster_input(
    [['BM006', '部員 六郎', '代表者', '在籍'], ['BM001', '代表 一郎', '副代表者', '在籍'], ['BM002', '会計 二郎', '会計長', '在籍'], ['BM003', '総務 三郎', '-', '退部'], ['BM005', '新代 五郎', '-', '在籍'], ['BM007', '平 七郎', '-', '入部']],
    ['representative' => 'BM006', 'deputy_representative_1' => 'BM001', 'accountant' => 'BM002']
);
$s4 = $submit($form, $rows4, $off4, 'CA056');
$plan4 = $planFor($s4);
$ga1 = array_column($plan4['slots'], null, 'key')['general_affairs_1'];
check($plan4['writable'] === true && $ga1['operation'] === 'clear', '総務1を空席として受け付け、退部した担当者の解除を計画する', $ga1);
check($plan4['ledger']['register_date_mode'] === 'accept_date', '解除も幹部構成の変更として登録日を更新する');
clock('2026-03-05 12:00:00');
$a4 = $accept($s4);
$r = $run($a4['job_id']);
$ledger = $ledgerOf('CA056')[0];
check(($r['status'] ?? '') === 'applied' && $ledger['slots']['general_affairs_1_stundet_id'] === '' && $ledger['slots']['general_affairs_1_name'] === '' && $ledger['slots']['general_affairs_1_mail'] === '', '総務1の学籍番号・氏名・メールの3項目が空になったことを読み戻して確認', $ledger['slots']);
check($ledger['register_date'] === '2026-03-05', '解除を含む変更の登録日は accept 日');

section('総務1が必須の旧設定では書き込まない');
$fake->officerFieldsMigrated = false;
[$rows5, $off5] = roster_input(
    [['BM006', '部員 六郎', '代表者', '在籍'], ['BM001', '代表 一郎', '副代表者', '在籍'], ['BM002', '会計 二郎', '会計長', '在籍'], ['BM005', '新代 五郎', '総務', '在籍'], ['BM007', '平 七郎', '-', '入部']],
    ['representative' => 'BM006', 'deputy_representative_1' => 'BM001', 'accountant' => 'BM002', 'general_affairs_2' => 'BM005']
);
$s5 = $submit($form, $rows5, $off5, 'CA056');
$plan5 = $planFor($s5);
check($plan5['writable'] === false && (bool)array_filter($plan5['write_blockers'], static fn($m) => str_contains($m, 'general_affairs_1_stundet_id')), '旧設定（総務1が必須・一意キーなし）は書き込む前に解消が必要', $plan5['write_blockers']);
$e = null;
try {
    $accept($s5);
} catch (RosterException $e) {
}
check($e instanceof RosterException && $e->reason === 'officer_blocked', '移行前の設定では役員を含む accept をしない', $e?->getMessage());
$fake->officerFieldsMigrated = true;

section('ID22 の照合：未登録者・氏名不一致・メールの衝突');
$fake->people[1]['family_name'] = '別人';
$plan5 = $planFor($s5);
check($plan5['writable'] === false && (bool)array_filter($plan5['blockers'], static fn($m) => str_contains($m, '氏名が登録者と一致しない')), '既存の登録者と氏名が一致しなければ役員反映を保留', $plan5['blockers']);
$fake->people[1]['family_name'] = '会計';
$fake->people[] = person(8, 'ZZ999', '他', '人', ['mail' => 'bm007@bene.fit.ac.jp']);
[$rows6, $off6] = roster_input(
    [['BM006', '部員 六郎', '代表者', '在籍'], ['BM001', '代表 一郎', '副代表者', '在籍'], ['BM002', '会計 二郎', '会計長', '在籍'], ['BM007', '平 七郎', '総務', '入部'], ['BM005', '新代 五郎', '-', '在籍']],
    ['representative' => 'BM006', 'deputy_representative_1' => 'BM001', 'accountant' => 'BM002', 'general_affairs_1' => 'BM007']
);
$s6 = $submit($form, $rows6, $off6, 'CA056');
$plan6 = $planFor($s6);
check($plan6['writable'] === false && (bool)array_filter($plan6['blockers'], static fn($m) => str_contains($m, 'メールアドレスが別の学籍番号')), '未登録者のメールが他人の登録と衝突すれば保留（推測で紐付けない）', $plan6['blockers']);
array_pop($fake->people);
$plan6 = $planFor($s6);
$a6 = $accept($s6);
// accept 後、ワーカーが動く前に別経路で同じ人物が申告どおりに登録された → 一意制約に当たっても同じ人物として確定する
$fake->inject = static function ($m, $p, $payload, $f) {
    if ($m === 'POST' && (int)($payload['app'] ?? 0) === 22 && ($payload['record']['student_id']['value'] ?? '') === 'BM007') {
        $f->people[] = person(9, 'BM007', '平', '七郎');
    }
    return null;
};
$r = $run($a6['job_id']);
$fake->inject = null;
$st = $steps($a6['job_id']);
$personStep = array_values(array_filter($st, static fn($s) => (string)$s['target_app'] === 'people' && (int)$s['target_record_id'] === 9));
check(($r['status'] ?? '') === 'applied' && count($peopleWith('BM007')) === 1 && $personStep !== [], '一意制約に当たったら学籍番号で照合し直し、申告どおりの人物だけを参照する', ['r' => $r, 'st' => array_column($st, 'status', 'step_key')]);

section('一部反映：ID20 の確認後に ID22 が変わった場合');
[$rows7, $off7] = roster_input(
    [['BM006', '部員 六郎', '代表者', '在籍'], ['BM001', '代表 一郎', '副代表者', '在籍'], ['BM002', '会計 二郎', '会計長', '在籍'], ['BM007', '平 七郎', '総務', '在籍'], ['BM005', '新代 五郎', '-', '在籍'], ['BM008', '入部 八郎', '-', '入部']],
    ['representative' => 'BM006', 'deputy_representative_1' => 'BM001', 'accountant' => 'BM002', 'general_affairs_1' => 'BM007']
);
clock('2026-04-01 10:00:00');
$s7 = $submit($form, $rows7, $off7, 'CA056');
$a7 = $accept($s7);
$fake->people[0]['family_name'] = '改姓';  // accept 後に BM001 の氏名が登録者アプリで変わった
$r = $run($a7['job_id']);
$st = $steps($a7['job_id']);
$case7 = $caseOf($s7);
check(($r['status'] ?? '') === 'conflict' && $st['roster']['status'] === 'verified' && $st['ledger']['status'] === 'pending', 'ID20 は確認済み、ID22 の参照先の変化で役員の反映は競合として停止', array_column($st, 'status', 'step_key'));
check((int)$case7['last_applied_revision_number'] === (int)$s7['revision_number'] && $case7['apply_state'] === 'conflict', '名簿の反映済み版と、役員の未完了（競合）を区別して記録', [$case7['last_applied_revision_number'], $case7['apply_state']]);
$fake->people[0]['family_name'] = '代表';  // 担当者が登録者アプリで確認・訂正した
roster_staff_revoke($s7['case_id'], $a7['job_id'], '登録者の確認後に再承認', $staff);
$a7b = $accept($s7);
$r = $run($a7b['job_id']);
$st = $steps($a7b['job_id']);
check(($r['status'] ?? '') === 'applied', '取り消して最新の差分で再承認すると、残りを反映できる', ['r' => $r, 'w' => array_column($st, 'write_kind', 'step_key')]);
check($st['roster']['write_kind'] === 'none' && (int)$job($a7b['job_id'])['before_revision'] === (int)$job($a7b['job_id'])['after_revision'], '再承認では ID20 は変更なし（書き込まずに照合済み）', $st['roster']);

section('5人全員が幹部、代表者が会計長も兼務（新設団体）');
roster_officer_target_set('CA001', $form['id'], $admin);
$fake->groups[1]['revision'] = 1;
[$rows8, $off8] = roster_input(
    [['X1', '兼務 太郎', '会計長', '在籍'], ['X2', '副部 次郎', '副代表者', '在籍'], ['X3', '総務 三子', '総務', '在籍'], ['X4', '副会 四子', '副会計長', '在籍'], ['X5', '支援 五子', 'マネージャー', '入部']],
    ['representative' => 'X1', 'accountant' => 'X1', 'deputy_representative_1' => 'X2', 'general_affairs_1' => 'X3', 'deputy_accountant_1' => 'X4', 'general_affairs_2' => 'X5']
);
clock('2026-04-10 15:00:00');
$s8 = $submit($form, $rows8, $off8, 'CA001');
$plan8 = $planFor($s8);
check($plan8['writable'] === true && $plan8['ledger']['operation'] === 'create' && count($plan8['people']) === 5, '兼務する人の照合は1人分。台帳は新規作成', ['wb' => $plan8['write_blockers'], 'b' => $plan8['blockers']]);
$a8 = $accept($s8);
// 新規作成の送信後に通信が切れても、団体キーで照合して1件のまま確定する
$fake->inject = static fn($m, $p, $payload) => ($m === 'POST' && (int)($payload['app'] ?? 0) === 21) ? 'apply_then_timeout' : null;
$r = $run($a8['job_id']);
$fake->inject = null;
check(($r['status'] ?? '') === 'unknown' && count($ledgerOf('CA001')) === 1, '新規作成の成否不明は結果確認待ち', $r);
$r = $run($a8['job_id']);
$created = $ledgerOf('CA001');
check(($r['status'] ?? '') === 'applied' && count($created) === 1 && $created[0]['group_key'] === 'CA001' && $created[0]['register_date'] === '2026-04-10', '照合で確定し、ID21 は1件・団体キーと登録日（accept 日）付き', $created);
check($created[0]['slots']['representative_stundet_id'] === 'X1' && $created[0]['slots']['accountant_stundet_id'] === 'X1' && $created[0]['slots']['accountant_name'] === '兼務太郎', 'ID21 の異なる枠に同じ人物を設定', $created[0]['slots']);
check(count($peopleWith('X1')) === 1 && count($peopleWith('X5')) === 1, '兼務する人の ID22 は1件');
$base = $events('CA001', 'baseline');
check(count($base) === 1 && $base[0]['new_student_id'] === 'X1' && $events('CA001', 'change') === [], '新設団体は初回登録した代表者を履歴の起点にする', $history('CA001'));

section('同じ団体の並行追加と、別フォームとの競合');
roster_officer_target_set('OR001', $form['id'], $admin);
[$rows9, $off9] = roster_input(
    [['Y1', '自治 一郎', '代表者', '在籍'], ['Y2', '自治 二郎', '副代表者', '在籍'], ['Y3', '自治 三郎', '会計長', '在籍'], ['Y4', '自治 四郎', '-', '在籍'], ['Y5', '自治 五郎', '-', '在籍']],
    ['representative' => 'Y1', 'deputy_representative_1' => 'Y2', 'accountant' => 'Y3']
);
$s9 = $submit($form, $rows9, $off9, 'OR001');
$a9 = $accept($s9);
$sOther = $submit($formOff, $rows9, null, 'OR001');
$e = null;
try {
    $accept($sOther, '');
} catch (RosterException $e) {
}
check($e instanceof RosterException && $e->reason === 'busy' && str_contains($e->getMessage(), '別の名簿'), '同じ団体の別フォームの accept は、未完了の反映がある間は拒否', $e?->getMessage());
// accept 後、標準画面から同じ団体の台帳が作られた
$fake->people[] = person(20, 'Y1', '自治', '一郎');
$fake->officerRecords[] = ['id' => 900, 'revision' => 1, 'group_id' => 'OR001', 'group_name' => '学生自治会', 'register_date' => '2026-04-01', 'group_key' => 'OR001', 'slots' => ledger_slots($fake, ['representative' => 'Y1'])];
array_pop($fake->people);
$r = $run($a9['job_id']);
$st = $steps($a9['job_id']);
check(($r['status'] ?? '') === 'conflict' && $st['ledger']['error_code'] === 'LEDGER_CREATED' && count($ledgerOf('OR001')) === 1, '計画にない台帳が作られていれば追加せずに競合（ID21 は1件のまま）', ['r' => $r, 'st' => $st['ledger']['error_code']]);

section('書込み対象の設定を変えたら、変更前の accept は書き込まない');
[$rows10, $off10] = roster_input([['BM006', '部員 六郎', '代表者', '在籍'], ['BM001', '代表 一郎', '副代表者', '在籍'], ['BM002', '会計 二郎', '会計長', '在籍'], ['BM007', '平 七郎', '総務', '在籍'], ['BM005', '新代 五郎', '-', '在籍']],
    ['representative' => 'BM006', 'deputy_representative_1' => 'BM001', 'accountant' => 'BM002', 'general_affairs_1' => 'BM007']);
$s10 = $submit($form, $rows10, $off10, 'CA056');
$a10 = $accept($s10);
roster_officer_target_set('CA056', $form['id'], $admin);  // 世代が進む
$writesBefore = $fake->writes;
$r = $run($a10['job_id']);
check(($r['status'] ?? '') === 'failed' && $steps($a10['job_id'])['roster']['error_code'] === 'OFFICER_WRITE_BLOCKED' && $fake->writes === $writesBefore, '設定の世代が変わった accept は、ID20 も含めて書き込まずに停止', ['r' => $r, 'w' => $fake->writes - $writesBefore]);

section('記録に個人情報を残さない');
$texts = implode("\n", array_merge(
    array_map('strval', $pdo->query('SELECT CONCAT_WS(" ", step_key, error_code, error_message) FROM roster_apply_steps')->fetchAll(PDO::FETCH_COLUMN) ?: []),
    array_map('strval', $pdo->query('SELECT CONCAT_WS(" ", step_key, message, detail_json) FROM roster_apply_attempts')->fetchAll(PDO::FETCH_COLUMN) ?: [])
));
$leaks = array_values(array_filter(['BM001', 'BM005', 'BM006', 'BM007', 'X1', 'Y1', '代表 一郎', '新代', '兼務 太郎', '@bene.fit.ac.jp'], static fn(string $needle): bool => str_contains($texts, $needle)));
check($leaks === [], 'ステップ・試行の記録に学籍番号・氏名・メールを含めない', $leaks);
roster_test_clock(null, true);

echo "\n" . $GLOBALS['passes'] . ' passed, ' . $GLOBALS['failures'] . " failed\n";
exit($GLOBALS['failures'] > 0 ? 1 : 0);
