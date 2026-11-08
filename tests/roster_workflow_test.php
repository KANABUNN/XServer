<?php

declare(strict_types=1);

/**
 * 部員名簿（提出 → accept → kintone ID20 反映）の必須検証（指示書16章）
 *
 * 実レコードは使わない。kintone は FakeKintone（メモリ上の模擬）で置き換え、DB はローカルの MariaDB を使う。
 *
 *   docker run -d --name roster-test-db --tmpfs /var/lib/mysql -p 127.0.0.1:33306:3306 -e MARIADB_ROOT_PASSWORD=roster-test-root mariadb:10.5.22
 *   docker exec -i roster-test-db mysql -uroot -proster-test-root < localhost.sql   # 構造ダンプ（fitsc_forms.managed_forms 等）
 *   php -d extension=pdo_mysql -d extension=mbstring tests/roster_workflow_test.php
 *
 * 環境変数 ROSTER_TEST_DB_HOST / ROSTER_TEST_DB_PORT / ROSTER_TEST_DB_USER / ROSTER_TEST_DB_PASS で接続先を変えられる。
 * テストは fitsc_forms の roster_* テーブルを毎回空にするため、本番・共有のDBでは絶対に実行しないこと。
 */

$tmp = rtrim(sys_get_temp_dir(), '/\\') . '/roster_test_' . getmypid();
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
    'db' => $db + ['dbname' => 'fitsc_book', 'pass' => $db['password']],
    'forms_db' => $db + ['dbname' => 'fitsc_forms', 'pass' => $db['password']],
    'kintone' => ['storage_root' => $tmp . '/kintone_storage'],
    'roster' => [
        'worker' => ['lock_file' => $tmp . '/worker.lock', 'max_attempts' => 3, 'retry_backoff_seconds' => [60]],
        'verification' => ['mail_transport' => 'log'],
    ],
];
file_put_contents($tmp . '/config.php', '<?php return ' . var_export($config, true) . ';');
putenv('FITSC_CONFIG_FILE=' . $tmp . '/config.php');
putenv('FITSC_ROSTER_DEV_MAIL=1');

require_once dirname(__DIR__) . '/apps/roster_core/bootstrap.php';

/* ---------------- テスト補助 ---------------- */
$GLOBALS['failures'] = 0;
$GLOBALS['passes'] = 0;
function check(bool $condition, string $label, mixed $detail = null): void
{
    if ($condition) {
        $GLOBALS['passes']++;
        echo "  ok  - {$label}\n";
        return;
    }
    $GLOBALS['failures']++;
    echo "  NG  - {$label}" . ($detail !== null ? ' :: ' . (is_scalar($detail) ? (string)$detail : json_encode($detail, JSON_UNESCAPED_UNICODE)) : '') . "\n";
}
function section(string $title): void
{
    echo "\n# {$title}\n";
}
function expect_roster_exception(callable $fn, string $reason, string $label): void
{
    try {
        $fn();
        check(false, $label, '例外が発生しませんでした');
    } catch (RosterException $e) {
        check($e->reason === $reason, $label, $e->reason . ' / ' . $e->getMessage());
    }
}

require_once __DIR__ . '/support/fake_kintone.php';

$fake = new FakeKintone();
roster_kintone_test_transport(fn(string $m, string $p, array $payload, int $t): array => $fake->handle($m, $p, $payload, $t), true);

/* ---------------- DB の準備 ---------------- */
$pdo = roster_db();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['roster_apply_attempts', 'roster_apply_jobs', 'roster_acceptances', 'roster_reviews', 'roster_revisions', 'roster_row_links', 'roster_cases', 'roster_group_verifications', 'roster_group_directory', 'roster_worker_heartbeats'] as $table) {
    $pdo->exec('TRUNCATE TABLE ' . $table);
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("DELETE FROM managed_forms WHERE slug LIKE 'roster-test-%'");
$pdo->prepare('INSERT INTO managed_forms (slug, name, is_active, settings_json) VALUES (:slug, :name, 1, :settings)')->execute([
    ':slug' => 'roster-test-' . getmypid(),
    ':name' => '2026年度 部員名簿（テスト）',
    ':settings' => json_encode(['form_type' => 'roster', 'roster_fiscal_year' => 2026, 'roster_phone_required_scope' => 'officers', 'roster_phone_required_manager' => false], JSON_UNESCAPED_UNICODE),
]);
$formId = (int)$pdo->lastInsertId();
$form = roster_form_row($pdo, $formId);
$staff = ['id' => 9001, 'login_id' => 'staff01', 'display_name' => '担当 花子'];

function job_of(PDO $pdo, int $caseId): ?array
{
    $case = roster_case_by_id($pdo, $caseId);
    return (int)$case['current_job_id'] > 0 ? roster_job_by_id($pdo, (int)$case['current_job_id']) : null;
}
function make_rows(array $specs): array
{
    $rows = [];
    foreach ($specs as $i => $s) {
        $rows[] = $s + ['key' => 'k' . $i, 'kintone_row_id' => null, 'membership_status' => '在籍', 'role' => '-', 'phone_number' => '', 'uses_group_email' => false, 'is_website_editor' => false];
    }
    return $rows;
}
function submit_rows(array $form, array $rows, string $groupCode = 'CA056'): array
{
    $pdo = roster_db();
    $case = roster_case_find($pdo, $form['id'], 2026, $groupCode);
    $group = roster_directory_get($groupCode, $pdo);
    return roster_group_submit($form, $group, ['verification_id' => 0], $rows, (int)($case['draft_version'] ?? 0), '福岡 太郎', '127.0.0.1');
}
function accept_latest(array $form, int $caseId, array $actor): array
{
    $pdo = roster_db();
    $case = roster_case_by_id($pdo, $caseId);
    $found = roster_kintone_find_group_records((string)$case['group_code']);
    return roster_staff_accept($caseId, (int)$case['latest_revision_id'], roster_compare_token($found), $actor);
}
function run_worker(): array
{
    return roster_worker_run(['skip_directory' => true, 'worker_name' => 'test']);
}
function make_due(PDO $pdo, int $jobId): void
{
    $pdo->prepare('UPDATE roster_apply_jobs SET next_run_at = :t WHERE id = :id')->execute([':t' => roster_now_sql(-1), ':id' => $jobId]);
}

/* ================================================================== */
section('団体一覧と団体確認');
$dir = roster_directory_refresh($pdo);
check($dir['count'] === 4, 'ID7 から団体一覧を取得', $dir);
$public = roster_directory_public_list($pdo);
check(count($public) === 3 && !in_array('CA099', array_column($public, 'code'), true), '解散した団体は対象外・組織は対象（3団体）', array_column($public, 'code'));
check(!array_key_exists('mail', $public[0]) && !array_key_exists('group_email', $public[0]), '利用者向け一覧にメールアドレスを含めない');
$req = roster_verification_request($form, 'ca056', 'session-A', '192.0.2.1');
check($req['email_masked'] !== 'nazo@example.jp' && str_contains($req['email_masked'], '@example.jp'), '確認コードは ID7 の団体メールへ送る（画面にはマスク表示）', $req['email_masked']);
$mailLog = (string)file_get_contents(kintone_storage_root() . '/roster_dev_mail.log');
preg_match('/確認コード：(\d{6})/u', $mailLog, $mm);
expect_roster_exception(fn() => roster_verification_confirm($form, $req['verification_id'], $mm[1] ?? '', 'session-B'), 'expired', '別のセッションでは確認コードを使えない');
expect_roster_exception(fn() => roster_verification_confirm($form, $req['verification_id'], '000000' === ($mm[1] ?? '') ? '111111' : '000000', 'session-A'), 'wrong_code', '誤ったコードは拒否');
$ok = roster_verification_confirm($form, $req['verification_id'], $mm[1] ?? '', 'session-A');
check($ok['group_code'] === 'CA056', '正しいコードで団体を確認');
expect_roster_exception(fn() => roster_verification_confirm($form, $req['verification_id'], $mm[1] ?? '', 'session-A'), 'expired', '確認コードは1回しか使えない');
expect_roster_exception(fn() => roster_verification_request($form, 'CA099', 'session-A', '192.0.2.1'), 'group_not_found', '対象外の団体には送らない');

/* ================================================================== */
section('1. 下書き保存・提出・再提出・差し戻しでは kintone へ書き込まない / 15. 変換');
$fake->calls = [];
$fake->writes = 0;
$draft = roster_group_save_draft($form, 'CA056', '福工大謎解きサークル', make_rows([
    ['name' => '福岡 太郎', 'student_id' => 'ｂｍ２３００１', 'role' => '部長・主将', 'phone_number' => '０９０－１２３４－５６７８', 'uses_group_email' => true],
]), 0);
check($draft['rows'][0]['student_id'] === 'BM23001' && $draft['rows'][0]['role'] === '代表者' && $draft['rows'][0]['phone_number'] === '090-1234-5678', '学籍番号・電話番号を文字列のまま正規化し、先頭0を保持・役職を kintone の選択肢へ変換', $draft['rows'][0]);
expect_roster_exception(fn() => roster_group_save_draft($form, 'CA056', 'x', [], 0), 'stale', '古い版の下書き保存は拒否（別画面の上書き防止）');
$rowsV1 = make_rows([
    ['name' => '福岡 太郎', 'student_id' => 'BM23001', 'role' => '代表者', 'phone_number' => '090-1234-5678', 'uses_group_email' => true],
    ['name' => '工大 花子', 'student_id' => 'BM23002', 'role' => 'マネージャー', 'membership_status' => '入部', 'is_website_editor' => true],
    ['name' => '博多 次郎', 'student_id' => 'BM22003', 'role' => '-', 'membership_status' => '卒部'],
]);
$v = roster_validate_rows(roster_normalize_rows($rowsV1), $form['roster']);
check($v['errors'] === [], 'マネージャーは電話番号必須の対象外（フォーム設定の既定）', $v['errors']);
$formWithManager = $form;
$formWithManager['roster']['phone_required_manager'] = true;
$v2 = roster_validate_rows(roster_normalize_rows($rowsV1), $formWithManager['roster']);
check(count($v2['errors']) === 1 && $v2['errors'][0]['field'] === 'phone_number', 'フォーム設定でマネージャーも必須に切り替えられる', $v2['errors']);
$bad = roster_validate_rows(roster_normalize_rows(make_rows([['name' => 'A', 'student_id' => 'X1', 'role' => '代表者', 'membership_status' => ''], ['name' => 'B', 'student_id' => 'X1']])), $form['roster']);
check(count(array_filter($bad['errors'], fn($e) => $e['field'] === 'student_id')) === 2 && count(array_filter($bad['errors'], fn($e) => $e['field'] === 'membership_status')) === 1, '状態未設定・団体内の学籍番号重複・役員の電話番号未入力をサーバー側で検出', $bad['errors']);
$s1 = submit_rows($form, $rowsV1);
$caseId = $s1['case_id'];
check($s1['revision_number'] === 1, '提出で第1版を作成');
roster_staff_return($caseId, $s1['revision_id'], '博多さんの学籍番号を確認してください。', $staff);
$s2 = submit_rows($form, $rowsV1);
check($s2['revision_number'] === 2, '再提出で第2版を作成');
check($fake->writes === 0 && !array_filter($fake->calls, fn($c) => !str_starts_with($c, 'GET')), '下書き・提出・差し戻し・再提出で kintone への書込みは0回', $fake->calls);

section('4. 差し戻し理由と過去の提出が残り、再提出は新しい未承認リビジョンになる');
$state = roster_group_state($form, 'CA056');
$rev1 = array_values(array_filter($state['history'], fn($h) => $h['revision_number'] === 1))[0];
check(($rev1['reviews'][0]['reason'] ?? '') === '博多さんの学籍番号を確認してください。' && $rev1['review_state'] === 'superseded', '第1版の差し戻し理由が残る', $rev1);
check($state['case']['review_state'] === 'submitted' && $state['latest']['revision_number'] === 2, '第2版は確認中（未承認）', $state['case']);
check(count($state['latest']['rows']) === 3, '提出内容が保存されている');

/* ================================================================== */
section('2. accept でジョブが1件だけ作られ、自動実行の対象になる / 7. 連打・再送');
$a1 = accept_latest($form, $caseId, $staff);
$a2 = accept_latest($form, $caseId, $staff);
$jobs = $pdo->query('SELECT COUNT(*) FROM roster_apply_jobs WHERE case_id = ' . $caseId)->fetchColumn();
check(!$a1['already'] && $a2['already'] && $a1['job_id'] === $a2['job_id'] && (int)$jobs === 1, 'accept の連打・再送は同じ accept・ジョブに集約', [$a1, $a2, $jobs]);
$job = job_of($pdo, $caseId);
check($job['status'] === 'queued' && $job['next_run_at'] <= roster_now_sql(), 'ジョブは反映待ちで、次回の定期実行の対象', $job);
expect_roster_exception(fn() => roster_staff_return($caseId, (int)$job['revision_id'], '理由', $staff), 'state', 'accept 済みの版は通常の差し戻しにできない（取り消しと区別）');

section('5. 承認後に内容が変わっても、最新値を承認済みとして読まない');
$case = roster_case_by_id($pdo, $caseId);
roster_group_save_draft($form, 'CA056', '福工大謎解きサークル', make_rows([['name' => '下書き 変更', 'student_id' => 'ZZ999', 'role' => '代表者', 'phone_number' => '0900']]), (int)$case['draft_version']);
// 提出原本の行を不正に書き換えても、反映は accept 時のスナップショットだけを使う
$pdo->prepare('UPDATE roster_revisions SET payload_json = :p WHERE id = :id')->execute([':p' => json_encode(['rows' => []]), ':id' => (int)$job['revision_id']]);

section('8. 初回は追加（7.cron重複でも二重適用しない）');
$lockFp = fopen(roster_worker_lock_file(), 'c');
flock($lockFp, LOCK_EX);
$locked = run_worker();
check($locked['status'] === 'locked', '別のワーカーが実行中なら何もしない（cron 重複）');
flock($lockFp, LOCK_UN);
fclose($lockFp);
$claimA = roster_worker_claim($pdo, null, 'A');
$claimB = roster_worker_claim($pdo, null, 'B');
check($claimA !== null && $claimB === null, '同じジョブを2つのワーカーが同時に取得できない', [$claimA['job']['id'] ?? null, $claimB]);
$resultA = roster_worker_execute($pdo, $claimA);
check($resultA['status'] === 'applied', '初回の accept で kintone にレコードを追加して反映済み', $resultA);
check(count($fake->records) === 1 && $fake->writes === 1, 'POST は1回だけ', [$fake->writes, count($fake->records)]);
$recordId = array_key_first($fake->records);
$rowsK = $fake->rows($recordId);
check(array_column($rowsK, 'values')[0]['student_id'] === 'BM23001' && count($rowsK) === 3, '承認スナップショット（3名）が反映され、下書き・書き換え後の内容は使われない', array_column(array_column($rowsK, 'values'), 'name'));
check($rowsK[0]['values']['uses_group_email'] === ['使用'] && $rowsK[1]['values']['is_website_editor'] === ['担当'] && $rowsK[2]['values']['membership_status'] === '卒部' && $rowsK[0]['values']['role'] === '代表者', '状態・担当チェック・役職を kintone の形式（配列・選択肢）で保存', $rowsK);
$links = (int)$pdo->query("SELECT COUNT(*) FROM roster_row_links WHERE group_code = 'CA056'")->fetchColumn();
check($links === 3, '学籍番号と kintone 行IDの対応を保存', $links);
$case = roster_case_by_id($pdo, $caseId);
check($case['apply_state'] === 'applied' && (int)$case['last_applied_revision_number'] === 2, '案件の反映状態が反映済み（第2版）', $case['apply_state']);

section('8/10/12. 2回目以降は同じレコードを更新し、行ID・職名・提出外の行を保持（削除しない）');
$fake->directEdit($recordId, function (array &$r): void {
    $r['list'][0]['values']['role_name'] = '主将';
    $r['list'][] = ['id' => '1999', 'values' => ['name' => '手入力 三郎', 'student_id' => 'BM21009', 'phone_number' => '', 'role' => '-', 'role_name' => '', 'membership_status' => '', 'uses_group_email' => [], 'is_website_editor' => []]];
});
$rowIdTaro = $fake->rows($recordId)[0]['id'];
$case = roster_case_by_id($pdo, $caseId);
roster_group_load_kintone($form, 'CA056', '福工大謎解きサークル', (int)$case['draft_version']);
$st = roster_group_state($form, 'CA056');
check(count($st['draft']['rows']) === 4 && $st['draft']['rows'][3]['membership_status'] === '', 'kintone の現在名簿を読み込み、状態未設定の行は未設定のまま（在籍に確定しない）', $st['draft']['rows'][3]);
$rowsV3 = $st['draft']['rows'];
$rowsV3[1]['membership_status'] = '在籍';
$rowsV3[2]['membership_status'] = '退部';
unset($rowsV3[3]); // 提出から手入力の行を外す → kintone では削除されないこと
$rowsV3[] = ['key' => 'new1', 'kintone_row_id' => null, 'membership_status' => '入部', 'name' => '新入 四郎', 'student_id' => 'BM26010', 'role' => '-', 'phone_number' => '', 'uses_group_email' => false, 'is_website_editor' => false];
$s3 = submit_rows($form, array_values($rowsV3));
$a3 = accept_latest($form, $caseId, $staff);
$fake->writes = 0;
$r3 = run_worker();
check(($r3['processed'][0]['status'] ?? '') === 'applied' && count($fake->records) === 1 && $fake->writes === 1, '2回目は同じレコードを PUT で更新（重複作成しない）', $r3);
$rowsK = $fake->rows($recordId);
$byId = array_column($rowsK, null, 'id');
check(isset($byId[$rowIdTaro]) && $byId[$rowIdTaro]['values']['role_name'] === '主将', '既存の行IDと職名（role_name）を保持', $byId[$rowIdTaro] ?? null);
check(isset($byId['1999']) && $byId['1999']['values']['name'] === '手入力 三郎', '提出に含まれない既存行を削除しない', array_keys($byId));
check(count($rowsK) === 5 && in_array('BM26010', array_column(array_column($rowsK, 'values'), 'student_id'), true), '新しい部員を行として追加', count($rowsK));
$statusBySid = array_column(array_map(fn($r) => $r['values'], $rowsK), 'membership_status', 'student_id');
check($statusBySid['BM22003'] === '退部' && $statusBySid['BM23002'] === '在籍', '卒部・退部は状態の変更として反映（行は残る）', $statusBySid);

section('11. kintone の直接編集によるリビジョン競合を検出し、上書きしない');
$s4 = submit_rows($form, array_values(array_map(fn($r) => array_merge($r, ['phone_number' => $r['student_id'] === 'BM23001' ? '090-0000-0000' : $r['phone_number']]), roster_json_decode((string)roster_revision_by_id($pdo, $s3['revision_id'])['payload_json'])['rows'])));
$a4 = accept_latest($form, $caseId, $staff);
$fake->directEdit($recordId, function (array &$r): void { $r['list'][0]['values']['phone_number'] = '080-9999-9999'; });
$fake->writes = 0;
$r4 = run_worker();
check(($r4['processed'][0]['status'] ?? '') === 'conflict' && $fake->writes === 0, 'accept 後に kintone が変わったら競合で停止し、PUT しない', $r4);
check($fake->rows($recordId)[0]['values']['phone_number'] === '080-9999-9999', 'kintone の直接編集は保持される');
expect_roster_exception(fn() => roster_staff_retry($caseId, $a4['job_id'], $staff), 'state', '競合は単純な再試行ではなく再確認（取り消し→再accept）が必要');
roster_staff_revoke($caseId, $a4['job_id'], '差分を確認し直すため', $staff);
check(job_of($pdo, $caseId) === null && roster_revision_by_id($pdo, $s4['revision_id'])['review_state'] === 'submitted', '取り消しで提出は確認中に戻る');
$a4b = accept_latest($form, $caseId, $staff);
$r4b = run_worker();
check(($r4b['processed'][0]['status'] ?? '') === 'applied', '再確認して accept し直すと反映できる', $r4b);

/* ================================================================== */
section('6. 新しい提出・取り消しで失効した旧ジョブは、worker・再試行・手動経路でも実行されない');
$s5 = submit_rows($form, roster_json_decode((string)roster_revision_by_id($pdo, $s4['revision_id'])['payload_json'])['rows']);
$a5 = accept_latest($form, $caseId, $staff);
$s6 = submit_rows($form, roster_json_decode((string)roster_revision_by_id($pdo, $s4['revision_id'])['payload_json'])['rows']);
$old = roster_job_by_id($pdo, $a5['job_id']);
check($old['status'] === 'cancelled' && $old['invalidated_reason'] === 'superseded_by_new_revision', '新しい提出で accept 済み・未実行のジョブを失効', $old);
check(roster_case_by_id($pdo, $caseId)['review_state'] === 'submitted', '新しい提出は承認待ちになる（以前の accept を引き継がない）');
$fake->writes = 0;
$none = roster_worker_run(['only_job_id' => $a5['job_id'], 'skip_directory' => true]);
$none2 = run_worker();
check(($none['processed'] ?? []) === [] && ($none2['processed'] ?? []) === [] && $fake->writes === 0, '失効したジョブは手動（今すぐ実行）でも cron でも実行されない', [$none, $none2]);
expect_roster_exception(fn() => roster_staff_retry($caseId, $a5['job_id'], $staff), 'stale', '失効したジョブは再試行 API でも実行できない');
// 取り消しと同じトランザクションで失効
$a6 = accept_latest($form, $caseId, $staff);
roster_staff_revoke($caseId, $a6['job_id'], '確認のため取り消し', $staff);
check(roster_job_by_id($pdo, $a6['job_id'])['status'] === 'cancelled' && roster_acceptance_by_id($pdo, $a6['acceptance_id'])['revoked_at'] !== null, '取り消しでジョブ失効と accept の取消が同時に行われる');
// 年度切替後は過年度の accept を反映しない
$a6b = accept_latest($form, $caseId, $staff);
$pdo->prepare('UPDATE managed_forms SET settings_json = JSON_SET(settings_json, "$.roster_fiscal_year", 2027) WHERE id = :id')->execute([':id' => $formId]);
$r6 = run_worker();
check(($r6['processed'] ?? []) === [] && roster_job_by_id($pdo, $a6b['job_id'])['invalidated_reason'] === 'past_fiscal_year' && $fake->writes === 0, '対象年度を変えると過年度の accept は実行時に失効（現行名簿へ適用しない）', roster_job_by_id($pdo, $a6b['job_id']));
$pdo->prepare('UPDATE managed_forms SET settings_json = JSON_SET(settings_json, "$.roster_fiscal_year", 2026) WHERE id = :id')->execute([':id' => $formId]);
// スナップショットの改ざん
$s7 = submit_rows($form, roster_json_decode((string)roster_revision_by_id($pdo, $s4['revision_id'])['payload_json'])['rows']);
$a7 = accept_latest($form, $caseId, $staff);
$pdo->prepare('UPDATE roster_acceptances SET snapshot_json = :s WHERE id = :id')->execute([':s' => json_encode(['rows' => []]), ':id' => $a7['acceptance_id']]);
$r7 = run_worker();
check(roster_job_by_id($pdo, $a7['job_id'])['invalidated_reason'] === 'snapshot_hash_mismatch' && $fake->writes === 0, '承認スナップショットが改ざんされたら実行しない');

section('9. 対象団体レコードが複数あれば競合で停止し、最初の1件を選ばない');
$s8 = submit_rows($form, roster_json_decode((string)roster_revision_by_id($pdo, $s4['revision_id'])['payload_json'])['rows']);
$a8 = accept_latest($form, $caseId, $staff);
$dupId = $fake->seedRecord('CA056', [['name' => '重複', 'student_id' => 'DUP1']]);
$r8 = run_worker();
check(($r8['processed'][0]['status'] ?? '') === 'conflict' && $fake->writes === 0, '同じ団体のレコードが2件あると競合で停止', $r8);
roster_staff_revoke($caseId, $a8['job_id'], '重複整理', $staff);
expect_roster_exception(fn() => accept_latest($form, $caseId, $staff), 'kintone_multiple', '重複がある間は accept 自体もできない');
unset($fake->records[$dupId]);

section('13. 追加 API の成否不明を単純に再 POST せず、更新の成否不明も照合する');
// 追加：kintone では登録済みだが応答が届かない
$s9 = submit_rows($form, make_rows([['name' => '別団体 代表', 'student_id' => 'BH25001', 'role' => '代表者', 'phone_number' => '092-000-0000']]), 'CA001');
$caseB = $s9['case_id'];
$a9 = accept_latest($form, $caseB, $staff);
$fake->writes = 0;
$fake->inject = fn($m, $p) => $m === 'POST' ? 'apply_then_timeout' : null;
$r9 = run_worker();
$fake->inject = null;
check(($r9['processed'][0]['status'] ?? '') === 'unknown', '追加の応答が届かなければ「結果確認待ち」', $r9);
check(job_of($pdo, $caseB)['next_run_at'] > roster_now_sql(), '照合は時間をおいて行う（すぐに再送しない）');
make_due($pdo, $a9['job_id']);
$r9b = run_worker();
$recordsB = array_filter($fake->records, fn($r) => $r['group_id'] === 'CA001');
check(($r9b['processed'][0]['status'] ?? '') === 'applied' && count($recordsB) === 1 && $fake->writes === 1, '照合で登録済みを確認し、再 POST せずに反映済みにする', [$r9b, count($recordsB), $fake->writes]);
// 追加：送信後に応答なし・実際は未登録 → 照合で未登録を確認してから登録する
$s10 = submit_rows($form, make_rows([['name' => '組織 代表', 'student_id' => 'OR25001', 'role' => '代表者', 'phone_number' => '092-111-1111']]), 'OR001');
$caseC = $s10['case_id'];
$a10 = accept_latest($form, $caseC, $staff);
$fake->writes = 0;
$fake->inject = fn($m, $p) => $m === 'POST' ? 'timeout_not_applied' : null;
run_worker();
$fake->inject = null;
make_due($pdo, $a10['job_id']);
$r10 = run_worker();
$recordsC = array_filter($fake->records, fn($r) => $r['group_id'] === 'OR001');
check(($r10['processed'][0]['status'] ?? '') === 'applied' && count($recordsC) === 1, '照合で未登録を確認してから1回だけ登録（重複なし）', [$r10, count($recordsC)]);
// 更新：適用されたが応答なし → 照合で内容一致を確認
$payloadB = roster_json_decode((string)roster_revision_by_id($pdo, $s9['revision_id'])['payload_json'])['rows'];
$case = roster_case_by_id($pdo, $caseB);
roster_group_load_kintone($form, 'CA001', 'アイスホッケー', (int)$case['draft_version']);
$rowsB = roster_group_state($form, 'CA001')['draft']['rows'];
$rowsB[0]['phone_number'] = '092-222-2222';
$s11 = submit_rows($form, $rowsB, 'CA001');
$a11 = accept_latest($form, $caseB, $staff);
$fake->writes = 0;
$fake->inject = fn($m, $p) => $m === 'PUT' ? 'apply_then_timeout' : null;
$r11 = run_worker();
$fake->inject = null;
make_due($pdo, $a11['job_id']);
$r11b = run_worker();
check(($r11['processed'][0]['status'] ?? '') === 'unknown' && ($r11b['processed'][0]['status'] ?? '') === 'applied' && $fake->writes === 1, '更新の成否不明は照合し、内容一致を確認して反映済みにする（PUT は1回）', [$r11, $r11b, $fake->writes]);
check(roster_job_by_id($pdo, $a11['job_id'])['phase'] === 'verified', '反映後の読み戻し照合まで完了している');
// 結果確認待ちの間は新しい提出を受け付けず、下書きを保持
$rowsB[0]['phone_number'] = '092-333-3333';
$s12 = submit_rows($form, $rowsB, 'CA001');
$a12 = accept_latest($form, $caseB, $staff);
$fake->inject = fn($m, $p) => $m === 'PUT' ? 'timeout_not_applied' : null;
run_worker();
$fake->inject = null;
$rowsB[0]['phone_number'] = '092-444-4444';
try {
    submit_rows($form, $rowsB, 'CA001');
    check(false, '結果確認待ちの間の提出は拒否');
} catch (RosterException $e) {
    $draftAfter = roster_group_state($form, 'CA001')['draft'];
    check($e->reason === 'busy' && ($draftAfter['rows'][0]['phone_number'] ?? '') === '092-444-4444', '反映処理中・結果確認待ちの提出は受け付けず、内容は下書きに保存', [$e->reason, $draftAfter['rows'][0]['phone_number'] ?? null]);
}
make_due($pdo, $a12['job_id']);
$r12 = run_worker();
check(($r12['processed'][0]['status'] ?? '') === 'applied', '照合で未適用を確認した更新は、同じ期待リビジョンで再送して反映', $r12);

section('ワーカー停止時の期限付きロック復旧');
$s13 = submit_rows($form, $rowsB, 'CA001');
$a13 = accept_latest($form, $caseB, $staff);
$claim = roster_worker_claim($pdo, null, 'crashed');
$recordB = array_values(array_filter($fake->records, fn($r) => $r['group_id'] === 'CA001'))[0];
// 送信直前（put_sent を記録した直後）にワーカーが止まった状態を再現する
$pdo->prepare('UPDATE roster_apply_jobs SET phase = "put_sent", write_kind = "update", before_revision = :rev, target_record_id = :rid, lease_expires_at = :t WHERE id = :id')->execute([
    ':rev' => $recordB['revision'], ':rid' => $recordB['id'], ':t' => roster_now_sql(-5), ':id' => $a13['job_id'],
]);
check(roster_worker_recover_leases($pdo) === 1 && roster_job_by_id($pdo, $a13['job_id'])['status'] === 'unknown', '書込み送信後に止まったジョブは未実行へ戻さず「結果確認待ち」にする');
make_due($pdo, $a13['job_id']);
$r13 = run_worker();
check(($r13['processed'][0]['status'] ?? '') === 'applied', '照合してから再処理して反映', $r13);
$s14 = submit_rows($form, $rowsB, 'CA001');
$a14 = accept_latest($form, $caseB, $staff);
roster_worker_claim($pdo, null, 'crashed2');
$pdo->prepare('UPDATE roster_apply_jobs SET phase = "search", lease_expires_at = :t WHERE id = :id')->execute([':t' => roster_now_sql(-5), ':id' => $a14['job_id']]);
roster_worker_recover_leases($pdo);
check(roster_job_by_id($pdo, $a14['job_id'])['status'] === 'queued', '書込み前に止まったジョブは反映待ちへ戻す');
run_worker();

/* ================================================================== */
section('14. 不足フィールド・権限エラー・通信障害を区別し、成功していないものを反映済みにしない');
$rowsC = roster_json_decode((string)roster_revision_by_id($pdo, $s10['revision_id'])['payload_json'])['rows'];
$rowsC[0]['phone_number'] = '092-555-5555';
$s15 = submit_rows($form, $rowsC, 'OR001');
$a15 = accept_latest($form, $caseC, $staff);
$fake->newFieldsPresent = false;
$fake->writes = 0;
$r15 = run_worker();
$j15 = roster_job_by_id($pdo, $a15['job_id']);
check(($r15['processed'][0]['status'] ?? '') === 'failed' && $j15['error_class'] === 'config' && $fake->writes === 0, 'フィールド未追加は設定エラーで停止（項目を捨てて成功にしない）', $j15['error_message']);
check(roster_acceptance_by_id($pdo, $a15['acceptance_id'])['revoked_at'] === null, 'accept 済みの内容は保持');
$fake->newFieldsPresent = true;
roster_staff_retry($caseC, $a15['job_id'], $staff);
$r15b = run_worker();
check(($r15b['processed'][0]['status'] ?? '') === 'applied', 'フィールドを追加して再試行すると反映できる', $r15b);
$rowsC[0]['phone_number'] = '092-666-6666';
$s16 = submit_rows($form, $rowsC, 'OR001');
$a16 = accept_latest($form, $caseC, $staff);
$fake->inject = fn($m, $p) => $m === 'PUT' ? ['status' => 403, 'body' => ['code' => 'CB_NO02', 'message' => 'no permission']] : null;
$r16 = run_worker();
$fake->inject = null;
check(($r16['processed'][0]['status'] ?? '') === 'failed' && roster_job_by_id($pdo, $a16['job_id'])['error_class'] === 'permission', '権限エラーは「権限エラー」で失敗（自動再試行しない）', roster_job_by_id($pdo, $a16['job_id'])['error_class']);
roster_staff_retry($caseC, $a16['job_id'], $staff);
$fake->inject = fn($m, $p) => $p === '/k/v1/records.json' ? 'connect_fail' : null;
$r16b = run_worker();
$fake->inject = null;
$j16 = roster_job_by_id($pdo, $a16['job_id']);
check(($r16b['processed'][0]['status'] ?? '') === 'queued' && $j16['error_class'] === 'transient' && $j16['next_run_at'] > roster_now_sql(), '通信障害は一時的な障害として時間をおいて自動再試行', $j16);
$fake->inject = fn($m, $p) => $m === 'PUT' ? ['status' => 400, 'body' => ['code' => 'CB_VA01', 'message' => 'invalid', 'errors' => ['record.list.value[0].value.role.value' => ['messages' => ['選択肢にありません']]]]] : null;
make_due($pdo, $a16['job_id']);
$r16c = run_worker();
$fake->inject = null;
check(($r16c['processed'][0]['status'] ?? '') === 'failed' && roster_job_by_id($pdo, $a16['job_id'])['error_class'] === 'validation', '入力値エラー（選択肢不一致等）は失敗として停止', roster_job_by_id($pdo, $a16['job_id'])['error_message']);
check(roster_case_by_id($pdo, $caseC)['apply_state'] === 'failed', '失敗は反映済みとして扱わない');

/* ================================================================== */
section('15. 一般フォームの添付提出・再提出・履歴は従来どおり');
// apps/forms_module.php の一般提出（メール＋団体名で再提出を判定）を名簿機能と同じDBで確認する
$GLOBALS['config'] = ['db' => $db + ['pass' => $db['password'], 'dbname' => 'fitsc_forms'], 'forms_db' => $db + ['dbname' => 'fitsc_forms', 'pass' => $db['password']]];
require_once dirname(__DIR__) . '/apps/forms_module.php';
$standard = forms_save_form(['name' => '一般テスト', 'slug' => 'roster-test-standard-' . getmypid(), 'is_active' => true, 'allow_file_upload' => false], []);
check(($standard['settings']['form_type'] ?? '') === 'standard', '一般フォームの種別は standard のまま');
$normalized = ['email' => 'a@example.jp', 'normalized_email' => 'a@example.jp', 'organization_name' => '団体A', 'normalized_organization' => '団体a', 'submitted_date' => null, 'payload' => ['note' => '1回目'], 'upload_files' => []];
$g1 = forms_save_submission($standard, $normalized);
$g2 = forms_save_submission($standard, ['payload' => ['note' => '2回目']] + $normalized);
check($g1['status'] === 'created' && $g2['status'] === 'updated' && $g2['revision_number'] === 2 && $g1['submission_id'] === $g2['submission_id'], '一般フォームの再提出は従来どおり同じ回答に履歴を追加', [$g1, $g2]);
check(count(forms_fetch_entry_history($g1['submission_id'])['revisions'] ?? []) === 2, '一般フォームの履歴を取得できる');
try {
    forms_delete_form($formId);
    check(false, '名簿の提出履歴があるフォームは削除できない');
} catch (InvalidArgumentException $e) {
    check(true, '名簿の提出履歴があるフォームは削除できない');
}
$pdo->prepare('DELETE FROM managed_forms WHERE id = :id')->execute([':id' => (int)$standard['id']]);

echo "\n" . $GLOBALS['passes'] . ' passed, ' . $GLOBALS['failures'] . " failed\n";
exit($GLOBALS['failures'] > 0 ? 1 : 0);
