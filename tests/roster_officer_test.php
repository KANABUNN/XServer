<?php

declare(strict_types=1);

/**
 * 部員名簿 第2段階（役員入力・提出原本の版管理・ID22/ID21 の比較と反映計画）の検証
 *
 * 実レコードは使わず、kintone は FakeKintone で置き換える。fitsc_forms の roster_* テーブルを空にするため、
 * ローカルの使い捨てDB以外では実行しないこと（接続先は tests/roster_workflow_test.php と同じ）。
 *
 *   php -d extension=pdo_mysql -d extension=mbstring tests/roster_officer_test.php
 */

$tmp = rtrim(sys_get_temp_dir(), '/\\') . '/roster_officer_test_' . getmypid();
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
    'roster' => ['worker' => ['lock_file' => $tmp . '/worker.lock']],
];
file_put_contents($tmp . '/config.php', '<?php return ' . var_export($config, true) . ';');
putenv('FITSC_CONFIG_FILE=' . $tmp . '/config.php');

require_once dirname(__DIR__) . '/apps/roster_core/bootstrap.php';
require_once dirname(__DIR__) . '/apps/kintone_core/kintone_sync_service.php';
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
function has_message(array $items, string $needle): bool
{
    foreach ($items as $item) {
        if (str_contains(is_array($item) ? (string)$item['message'] : (string)$item, $needle)) {
            return true;
        }
    }
    return false;
}
function member(string $sid, string $name, string $role = '-', string $status = '在籍', string $phone = '090-0000-0000'): array
{
    return ['key' => 'k' . $sid, 'kintone_row_id' => null, 'membership_status' => $status, 'name' => $name, 'student_id' => $sid, 'role' => $role, 'phone_number' => $phone, 'uses_group_email' => false, 'is_website_editor' => false];
}
function officers(array $slots, array $people = []): array
{
    return ['slots' => $slots, 'people' => $people];
}
const TEST_NAMES = ['BM001' => ['代表', '一郎'], 'BM002' => ['会計', '二郎'], 'BM003' => ['総務', '三郎'], 'BM004' => ['副代', '四郎'], 'BM006' => ['部員', '六郎'], 'BM005' => ['卒業', '五郎'], 'A1' => ['兼務', '太郎']];
function declared(string $sid, array $over = []): array
{
    [$family, $given] = TEST_NAMES[$sid] ?? ['新規', '太郎'];
    return $over + ['student_id' => $sid, 'family_name' => $family, 'personal_name' => $given, 'mail' => strtolower($sid) . '@bene.fit.ac.jp', 'depend' => '情報工学科', 'type' => '大学 学生', 'org_depend_state' => '無'];
}
function person(int $id, string $sid, string $family, string $given, string $type = '大学 学生'): array
{
    return ['id' => $id, 'revision' => 3, 'student_id' => $sid, 'family_name' => $family, 'personal_name' => $given, 'mail' => strtolower($sid) . '@bene.fit.ac.jp', 'depend' => '情報工学科', 'type' => $type, 'org_depend_state' => '無'];
}

$fake = new FakeKintone();
roster_kintone_test_transport(fn(string $m, string $p, array $payload, int $t): array => $fake->handle($m, $p, $payload, $t), true);

$pdo = roster_db();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['roster_apply_attempts', 'roster_apply_jobs', 'roster_acceptances', 'roster_reviews', 'roster_revisions', 'roster_row_links', 'roster_cases', 'roster_group_verifications', 'roster_group_directory', 'roster_worker_heartbeats'] as $table) {
    $pdo->exec('TRUNCATE TABLE ' . $table);
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("DELETE FROM managed_forms WHERE slug LIKE 'roster-officer-test-%'");
$makeForm = static function (string $suffix, array $settings) use ($pdo): array {
    $pdo->prepare('INSERT INTO managed_forms (slug, name, is_active, settings_json) VALUES (:s, :n, 1, :j)')->execute([
        ':s' => 'roster-officer-test-' . $suffix . '-' . getmypid(),
        ':n' => '役員連携テスト ' . $suffix,
        ':j' => json_encode(['form_type' => 'roster', 'roster_fiscal_year' => 2026] + $settings, JSON_UNESCAPED_UNICODE),
    ]);
    return roster_form_row($pdo, (int)$pdo->lastInsertId());
};
$form = $makeForm('on', ['roster_officer_sync' => true]);
$formOff = $makeForm('off', []);
roster_directory_refresh($pdo);
$staff = ['id' => 9001, 'login_id' => 'staff01', 'display_name' => '担当 花子'];
$submit = static function (array $form, array $rows, ?array $off, string $code = 'CA056') use ($pdo): array {
    $case = roster_case_find($pdo, $form['id'], 2026, $code);
    return roster_group_submit($form, roster_directory_get($code, $pdo), ['verification_id' => 0], $rows, (int)($case['draft_version'] ?? 0), '提出 者', '127.0.0.1', $off);
};
$planFor = static function (array $saved) use ($pdo): ?array {
    return roster_officer_build_plan(roster_case_by_id($pdo, $saved['case_id']), roster_revision_by_id($pdo, $saved['revision_id']));
};

$baseRows = [
    member('BM001', '代表 一郎', '代表者'),
    member('BM002', '会計 二郎', '会計長'),
    member('BM003', '総務 三郎', '総務'),
    member('BM004', '副代 四郎', '副代表者'),
    member('BM006', '部員 六郎'),
    member('BM005', '卒業 五郎', '副代表者', '卒部'),
];
$normalizedRows = roster_normalize_rows($baseRows);
$snapshot = roster_snapshot_rows($normalizedRows);
$goodSlots = ['representative' => 'BM001', 'deputy_representative_1' => 'BM004', 'accountant' => 'BM002', 'general_affairs_1' => 'BM003'];
$goodPeople = [declared('BM001'), declared('BM002'), declared('BM003'), declared('BM004')];
$good = officers($goodSlots, $goodPeople);
$validate = static fn(array $o, array $rows): array => roster_validate_officers(roster_normalize_officers($o), roster_normalize_rows($rows));
/** 提出済みの版の原本だけを書き換えて計画を作る（提出時の検証を通らない状態の確認用） */
$planWith = static function (array $saved, callable $mutate) use ($pdo): ?array {
    $revision = roster_revision_by_id($pdo, $saved['revision_id']);
    $payload = json_decode((string)$revision['payload_json'], true);
    $revision['payload_json'] = json_encode($mutate($payload), JSON_UNESCAPED_UNICODE);
    return roster_officer_build_plan(roster_case_by_id($pdo, $saved['case_id']), $revision);
};

section('役員枠の定義と正規化');
$defs = array_column(roster_officer_slot_definitions(), null, 'key');
check(count($defs) === 9, '役職枠は9枠');
check($defs['representative']['required'] && $defs['deputy_representative_1']['required'] && $defs['accountant']['required'], '代表者・副代表者1・会計長は必須');
check(!$defs['general_affairs_1']['required'] && !$defs['deputy_representative_2']['required'] && !$defs['deputy_accountant_1']['required'], '総務・副代表者2・副会計長は空席にできる');
check($defs['representative']['student_field'] === 'representative_stundet_id' && $defs['general_affairs_1']['student_field'] === 'general_affairs_1_stundet_id', 'ID21 のフィールドコードは stundet の綴りのまま');
check(!in_array('マネージャー', array_column($defs, 'role'), true), 'マネージャーに ID21 の枠はない');
$norm = roster_normalize_officers(['slots' => ['representative' => 'ｂｍ００１', 'hacked' => 'X'], 'people' => [declared('BM001'), declared('BM001', ['family_name' => '二重']), declared('BM999')]]);
check($norm['slots']['representative'] === 'BM001' && !isset($norm['slots']['hacked']), '学籍番号を既存の規則で正規化し、未定義の枠は捨てる', $norm['slots']);
check(count($norm['people']) === 1 && $norm['people'][0]['family_name'] === '代表', '人物の申告は枠に割り当てられた人の分を1人1件だけ残す', $norm['people']);

section('提出時の検証：人数と必須の役職');
$v = $validate($good, $baseRows);
check($v['errors'] === [], '必須3役職がそろい入部・在籍が5人なら通る', $v);
$four = $baseRows;
$four[4] = member('BM006', '部員 六郎', '-', '退部');
$v = $validate($good, $four);
check(has_message($v['errors'], '5人以上必要') && has_message($v['errors'], '現在4人'), '入部・在籍が4人で卒部・退部が1人なら人数不足を表示', $v['errors']);
foreach (['representative' => '代表者', 'deputy_representative_1' => '副代表者1', 'accountant' => '会計長'] as $key => $label) {
    $o = $good;
    $o['slots'][$key] = '';
    $v = $validate($o, $baseRows);
    check(has_message($v['errors'], $label . 'が空席です'), $label . 'の空席は提出時にエラー', $v['errors']);
}
$o = $good;
$o['slots']['general_affairs_1'] = '';
$v = $validate($o, $baseRows);
check($v['errors'] === [], '総務1の空席を受け付ける', $v['errors']);

section('提出時の検証：兼務と候補');
$allOfficers = [member('BM001', '代表 一郎', '代表者'), member('BM004', '副代 四郎', '副代表者'), member('BM003', '総務 三郎', '総務'), member('BM002', '会計 二郎', '副会計長'), member('BM006', '部員 六郎', 'マネージャー')];
$o = officers(['representative' => 'BM001', 'accountant' => 'BM001', 'deputy_representative_1' => 'BM004', 'general_affairs_1' => 'BM003', 'deputy_accountant_1' => 'BM002', 'general_affairs_2' => 'BM006'], [declared('BM001'), declared('BM004'), declared('BM003'), declared('BM002'), declared('BM006')]);
$v = $validate($o, $allOfficers);
check($v['errors'] === [], '5人全員が幹部で、代表者が会計長も兼務でき、マネージャーも枠に入れられる', $v['errors']);
$a = roster_officer_assignments(roster_normalize_officers($o));
check(count($a['BM001']) === 2 && count(roster_normalize_officers($o)['people']) === 5, '兼務する人の申告は1人分で、両方の枠を参照する');
$o2 = $o;
$o2['slots']['accountant'] = 'BM002';
$o2['slots']['deputy_accountant_1'] = '';
$rows2 = $allOfficers;
$rows2[3] = member('BM002', '会計 二郎', '-');
$v = $validate($o2, $rows2);
check(has_message($v['errors'], '担当する役職（会計長）から選んで'), '枠に入った人の名簿の役職は、実際に担当する役職から選ぶ（役職なしは不可）', $v['errors']);
check(($v['errors'][0]['key'] ?? null) === 'kBM002', '名簿の役職のエラーは該当の行に結び付ける', $v['errors']);
$o3 = $good;
$o3['slots']['general_affairs_2'] = 'BM003';
$v = $validate($o3, $baseRows);
check(has_message($v['errors'], '「総務」の複数の枠'), '同じ役職の番号違いに同じ人を重ねるとエラー', $v['errors']);
$o4 = $good;
$o4['slots']['general_affairs_1'] = 'NOPE';
$o4['slots']['deputy_accountant_1'] = 'BM005';
$v = $validate($o4, $baseRows);
check(has_message($v['errors'], '名簿にない') && has_message($v['errors'], '入部・在籍の部員を指定'), '名簿にない人・卒部者の指定はエラー', $v['errors']);
$over = array_merge($baseRows, [member('X1', '総務 四', '総務'), member('X2', '総務 五', '総務'), member('X3', '総務 六', '総務')]);
$v = $validate($good, $over);
check(has_message($v['warnings'], '「総務」の部員が4人') && has_message($v['warnings'], '枠は3人分'), '定員超過は役職と人数を示す（自動で切り捨てない）', $v['warnings']);

section('提出時の検証：人物の申告（ID22 に必要な情報）');
$withPerson = static fn(array $over): array => officers($goodSlots, [declared('BM001', $over), declared('BM002'), declared('BM003'), declared('BM004')]);
$v = $validate($withPerson(['org_depend_state' => '']), $baseRows);
check(has_message($v['errors'], '不足') && has_message($v['errors'], '執行・議会組織'), '執行・議会組織への所属の空欄は「無」で補完せず不足にする', $v['errors']);
$v = $validate(officers($goodSlots, [declared('BM002'), declared('BM003'), declared('BM004')]), $baseRows);
check(has_message($v['errors'], '代表 一郎：役員の情報が不足'), '役員の申告がなければ不足を表示', $v['errors']);
$v = $validate($withPerson(['mail' => 'bad', 'depend' => '架空学科', 'type' => '教職員']), $baseRows);
check(has_message($v['errors'], 'メールアドレスの形式') && has_message($v['errors'], '所属を選択肢') && has_message($v['errors'], '学生区分を選択肢'), 'メール形式・選択肢外の所属と区分はエラー', $v['errors']);
$v = $validate($withPerson(['org_depend_state' => '不明']), $baseRows);
check(has_message($v['errors'], '「有」「無」から'), '執行・議会組織への所属は「有／無」だけ', $v['errors']);
$v = $validate($withPerson(['family_name' => '別人']), $baseRows);
check(has_message($v['errors'], '姓・名が名簿の氏名と一致しません'), '姓・名は名簿の氏名と一致させる（機械分割はしない）', $v['errors']);
$v = $validate($withPerson(['mail' => 'bm002@bene.fit.ac.jp']), $baseRows);
check(has_message($v['errors'], '同じメールアドレスが複数の役員'), '役員どうしのメール重複はエラー', $v['errors']);
$v = $validate($withPerson(['mail' => 'someone@example.com']), $baseRows);
check($v['errors'] === [] && has_message($v['warnings'], '大学のメールアドレスではない'), '大学以外のドメインは警告', $v);

section('提出原本の版管理');
$normalizedGood = roster_normalize_officers($good);
$h1 = roster_payload_hash_v2($snapshot, $normalizedGood);
$reordered = $normalizedGood;
$reordered['slots'] = array_reverse($reordered['slots'], true);
check($h1 === roster_payload_hash_v2($snapshot, $reordered), 'キーの順序が違っても同じ内容なら同じハッシュ');
$changed = $normalizedGood;
$changed['slots']['representative'] = 'BM002';
check($h1 !== roster_payload_hash_v2($snapshot, $changed), '役員情報が変わるとハッシュも変わる');

$fake->appCalls = [];
$fake->writes = 0;
$draft = roster_group_save_draft($form, 'CA056', '謎解き', $baseRows, 0, $good);
$state = roster_group_state($form, 'CA056');
check($state['settings']['officer_sync'] === true && $state['draft']['officers']['slots']['representative'] === 'BM001' && count($state['draft']['officers']['people']) === 4, '役員情報を下書きに保存して再開できる', $state['draft']['officers']);
$preview = roster_group_preview($form, 'CA056', $baseRows, $good);
check($preview['validation']['errors'] === [] && $preview['officers'][0]['name'] === '代表 一郎', '提出前の確認に役員の割り当てを表示', $preview['officers'][0]);
$s1 = $submit($form, $baseRows, $good);
$rev1 = roster_revision_by_id($pdo, $s1['revision_id']);
$payload1 = json_decode((string)$rev1['payload_json'], true);
check(($payload1['payload_version'] ?? null) === 2 && isset($payload1['officers']['slots']) && count($payload1['rows']) === 6 && !isset($payload1['rows'][0]['officers']), '対象フォームの提出は版2。rows は ID20 の形式のまま役員情報を別キーに保存', array_keys($payload1));
check($rev1['payload_hash'] === roster_payload_hash_v2($payload1['rows'], $payload1['officers']), '提出原本のハッシュに役員情報を含める');
$latest = roster_group_state($form, 'CA056');
check(($latest['latest']['officers'][0]['student_id'] ?? '') === 'BM001' && $latest['draft']['exists'] === false, '団体の画面に提出済みの役員割当てを表示できる');
roster_group_edit_from_latest($form, 'CA056', (int)roster_case_find($pdo, $form['id'], 2026, 'CA056')['draft_version']);
$again = roster_group_state($form, 'CA056');
check($again['draft']['officers']['slots']['general_affairs_1'] === 'BM003' && count($again['draft']['officers']['people']) === 4, '修正して再提出するときに役員の割り当てを引き継ぐ');
$caseVersion = (int)roster_case_find($pdo, $form['id'], 2026, 'CA056')['draft_version'];
$bad = $good;
$bad['slots']['accountant'] = '';
try {
    roster_group_submit($form, roster_directory_get('CA056', $pdo), ['verification_id' => 0], $baseRows, $caseVersion, '提出 者', '', $bad);
    check(false, '必須の役職が空席の提出は拒否');
} catch (RosterException $e) {
    check($e->reason === 'validation' && has_message($e->detail['validation']['errors'], '会計長が空席'), '必須の役職が空席の提出は拒否', $e->getMessage());
}
$shuffled = array_reverse($baseRows);
$v = roster_validate_officers($normalizedGood, roster_normalize_rows($shuffled));
check($v['errors'] === [], '名簿行を並べ替えても割当ては学籍番号で保たれる', $v['errors']);
$concurrentRows = $baseRows;
$concurrentRows[0] = member('BM001', '代表 一郎', '会計長');
$noLeaderWarning = roster_validate_submission(roster_normalize_rows($concurrentRows), roster_normalize_officers(officers(['representative' => 'BM001', 'accountant' => 'BM001', 'deputy_representative_1' => 'BM004', 'general_affairs_1' => 'BM003'], [declared('BM001'), declared('BM004'), declared('BM003')])), ['phone_required_scope' => 'officers']);
check($noLeaderWarning['errors'] === [] && !has_message($noLeaderWarning['warnings'], '部長・主将」がいません'), '兼務で主役職を会計長にしても「部長・主将がいない」と警告しない', $noLeaderWarning);

section('第1段階との互換性');
$sOff = $submit($formOff, $baseRows, $good, 'CA001');
$revOff = roster_revision_by_id($pdo, $sOff['revision_id']);
$payloadOff = json_decode((string)$revOff['payload_json'], true);
check(!isset($payloadOff['payload_version']) && !isset($payloadOff['officers']), '対象外フォームは第1段階と同じ形（役員情報は保存しない）', array_keys($payloadOff));
check($revOff['payload_hash'] === roster_payload_hash($payloadOff['rows']), '対象外フォームのハッシュは従来の部員行のみ');
check($planFor($sOff) === null, '版1の提出には反映計画を作らない（全枠空席に変換しない）');
$stateOff = roster_group_state($formOff, 'CA001');
check($stateOff['settings']['officer_sync'] === false && ($stateOff['latest']['officers'] ?? null) === null, '対象外フォームの画面には役員欄を出さない');
check($fake->officerWrites() === 0 && $fake->writes === 0, '下書き・提出・再開で3アプリへの書込みは0回', $fake->appCalls);

section('反映計画：ID22 の照合');
$fake->people = [person(1, 'BM001', '代表', '一郎'), person(2, 'BM002', '会計', '二郎')];
$plan = $planFor($s1);
$byStep = array_column($plan['people'], null, 'student_id');
check($plan['ok'] === true && $byStep['BM001']['operation'] === 'reference' && $byStep['BM001']['differences'] === [], '学籍番号が1件一致し矛盾がなければ既存の登録者を参照（空白の違いは氏名の差にしない）', $byStep['BM001']);
check($byStep['BM003']['operation'] === 'create' && $byStep['BM003']['kintone'] === null, '未登録で申告がそろっていれば新規登録の候補', $byStep['BM003']);
check(str_starts_with($byStep['BM001']['step_key'], 'person_'), 'ステップは学籍番号ではなく person_N で識別', $byStep['BM001']['step_key']);
$fake->people[] = ['id' => 3, 'revision' => 1, 'student_id' => 'ZZ999', 'family_name' => '別', 'personal_name' => '人', 'mail' => 'bm003@bene.fit.ac.jp', 'depend' => '情報工学科', 'type' => '大学 学生', 'org_depend_state' => '無'];
$plan = $planFor($s1);
$byStep = array_column($plan['people'], null, 'student_id');
check($byStep['BM003']['operation'] === 'review' && has_message($plan['blockers'], 'メールアドレスが別の学籍番号'), '未登録者のメールが別の学籍番号と重複すれば競合で保留', $byStep['BM003']);
$fake->people[2]['mail'] = 'bm001@bene.fit.ac.jp';
$plan = $planFor($s1);
$byStep = array_column($plan['people'], null, 'student_id');
check($byStep['BM001']['operation'] === 'review' && has_message($plan['blockers'], 'メールアドレスが別の学籍番号'), '既存の人でも申告のメールが別人と重複すれば競合で保留', $byStep['BM001']['notes']);
array_pop($fake->people);
$fake->people[0]['type'] = '教職員';
$plan = $planFor($s1);
check(array_column($plan['people'], null, 'student_id')['BM001']['operation'] === 'review' && has_message($plan['blockers'], '区分が対象外'), '既存の区分が対象外なら競合で保留');
$fake->people[0]['type'] = '大学 学生';
$fake->people[0]['family_name'] = '別姓';
$plan = $planFor($s1);
$first = array_column($plan['people'], null, 'student_id')['BM001'];
check($first['operation'] === 'review' && $first['differences'][0]['label'] === '氏名' && has_message($plan['blockers'], '氏名が登録者と一致しない'), '氏名が実質的に不一致なら競合で保留（推測で紐付けない）', $first);
$fake->people[0]['family_name'] = '代表';
$fake->people[] = person(4, 'BM001', '代表', '一郎');
$plan = $planFor($s1);
check(array_column($plan['people'], null, 'student_id')['BM001']['operation'] === 'review' && has_message($plan['blockers'], '複数ある'), '同じ学籍番号が複数件なら競合で保留');
array_pop($fake->people);
$fake->people[0]['depend'] = '電気工学科';
$fake->people[0]['org_depend_state'] = '有';
$plan = $planFor($s1);
$first = array_column($plan['people'], null, 'student_id')['BM001'];
$labels = array_column($first['differences'], 'label');
check($first['operation'] === 'reference' && in_array('所属', $labels, true) && in_array('執行・議会組織への所属', $labels, true) && has_message($first['notes'], '連携では更新しない'), '既存人物との所属などの差は差分に出すが、参照のまま（自動で上書きしない）', $first);
check($plan['ready'] === true, '差分だけなら役員反映を止めない', $plan['blockers']);
$fake->people[0]['depend'] = '情報工学科';
$fake->people[0]['org_depend_state'] = '無';
$plan = $planWith($s1, static function (array $payload): array {
    $payload['officers']['people'] = array_values(array_filter($payload['officers']['people'], static fn(array $p): bool => $p['student_id'] !== 'BM003'));
    return $payload;
});
$third = array_column($plan['people'], null, 'student_id')['BM003'];
check($third['operation'] === 'needs_info' && count($third['missing']) === 6, '未登録で申告がなければ情報不足（作成しない）', $third);
$suggestion = roster_officer_return_suggestion($plan);
check(str_contains($suggestion, '総務 三郎') && str_contains($suggestion, '大学メールアドレス') && !str_contains($suggestion, '代表 一郎'), '差し戻し理由の下書きは不足している項目だけを示す', $suggestion);
$plan = $planWith($s1, static function (array $payload): array {
    $payload['officers']['slots']['accountant'] = 'BM001';
    return $payload;
});
$holder = array_column($plan['people'], null, 'student_id')['BM001'];
check(count($plan['people']) === 3 && $holder['holds'] === ['代表者', '会計長'], '兼務する人の ID22 の照合は1人分で、担当の枠をすべて示す', $holder['holds']);
check(in_array('兼務', array_column($plan['slots'], null, 'key')['accountant']['flags'], true), '枠の比較に兼務を表示', array_column($plan['slots'], null, 'key')['accountant']);

section('反映計画：ID21 の現行1件と登録日');
$plan = $planFor($s1);
check($plan['ledger']['operation'] === 'create' && $plan['ledger']['record_count'] === 0 && $plan['ledger']['register_date_mode'] === 'accept_date', '現行レコードがなければ新規作成で、登録日は accept 日', $plan['ledger']);
$ledgerSlots = ['representative_stundet_id' => 'BM001', 'representative_name' => '代表一郎', 'deputy_representative_1_stundet_id' => 'BM004', 'accountant_stundet_id' => 'BM002', 'general_affairs_1_stundet_id' => 'BM003'];
$fake->officerRecords = [['id' => 500, 'revision' => 7, 'group_id' => 'CA056', 'group_name' => '福工大謎解きサークル', 'group_org' => ['CA056'], 'register_date' => '2025-04-01', 'group_key' => 'CA056', 'slots' => $ledgerSlots]];
$plan = $planFor($s1);
check($plan['ledger']['operation'] === 'update' && $plan['ledger']['record_id'] === 500 && $plan['ledger']['revision'] === 7, '1件なら更新対象としてレコードIDとリビジョンを比較元にする', $plan['ledger']);
check($plan['ledger']['register_date_mode'] === 'keep' && $plan['ledger']['composition_changed'] === false, '幹部構成が同じなら登録日を維持', $plan['ledger']);
$fake->officerRecords[0]['slots']['representative_name'] = '表記訂正';
check($planFor($s1)['ledger']['register_date_mode'] === 'keep', '同じ幹部の氏名の訂正だけなら登録日を維持');
$fake->officerRecords[0]['slots'] = ['representative_stundet_id' => 'BM001', 'deputy_representative_1_stundet_id' => 'BM004', 'deputy_representative_2_stundet_id' => '', 'accountant_stundet_id' => 'OLD01', 'accountant_name' => '旧会計', 'general_affairs_1_stundet_id' => 'BM003', 'general_affairs_2_stundet_id' => 'BM006', 'general_affairs_2_name' => '部員六郎', 'deputy_accountant_1_stundet_id' => 'BM005', 'deputy_accountant_2_stundet_id' => 'GONE1'];
$plan = $planFor($s1);
$slots = array_column($plan['slots'], null, 'key');
check($slots['representative']['operation'] === 'keep', '同じ人物は維持');
check($slots['accountant']['operation'] === 'replace' && $slots['accountant']['current_student_id'] === 'OLD01' && in_array('現在の役員は今回の名簿に見当たりません', $slots['accountant']['flags'], true), '別の人物なら交代（前任が名簿にいないことも示す）', $slots['accountant']);
check($slots['deputy_representative_2']['operation'] === 'none', '双方空席は変更なし');
check($slots['general_affairs_2']['operation'] === 'clear' && $slots['general_affairs_2']['planned_student_id'] === '', '空席で提出された枠は既存の担当者を解除', $slots['general_affairs_2']);
check($slots['deputy_accountant_1']['operation'] === 'clear' && in_array('現在の役員は卒部・退部です', $slots['deputy_accountant_1']['flags'], true), '卒部・退部した現任をそのまま維持せず解除として示す', $slots['deputy_accountant_1']);
check($plan['ledger']['register_date_mode'] === 'accept_date' && $plan['ledger']['composition_changed'] === true, '就任・交代・解除があれば登録日は accept 日', $plan['ledger']);
check($plan['ready'] === true && $plan['write_enabled'] === false, '解除や交代があっても準備は完了にできる（書込みは既定で無効）', $plan['blockers']);
$fake->officerRecords[0]['slots'] = $ledgerSlots;
$swapRows = array_merge($baseRows, [member('BM007', '総務 七郎', '総務')]);
$swapOfficers = $good;
$swapOfficers['slots']['general_affairs_1'] = 'BM007';
$swapOfficers['slots']['general_affairs_2'] = 'BM003';
$swapOfficers['people'][] = ['student_id' => 'BM007', 'family_name' => '総務', 'personal_name' => '七郎', 'mail' => 'bm007@bene.fit.ac.jp', 'depend' => '情報工学科', 'type' => '大学 学生', 'org_depend_state' => '無'];
$s4 = $submit($form, $swapRows, $swapOfficers, 'CA001');
$fake->officerRecords[] = ['id' => 600, 'revision' => 2, 'group_id' => 'CA001', 'group_name' => 'x', 'register_date' => '2024-04-01', 'group_key' => 'CA001', 'slots' => ['representative_stundet_id' => 'BM001', 'deputy_representative_1_stundet_id' => 'BM004', 'accountant_stundet_id' => 'BM002', 'general_affairs_1_stundet_id' => 'BM003', 'general_affairs_2_stundet_id' => 'BM007']];
$plan = $planFor($s4);
$slots = array_column($plan['slots'], null, 'key');
check($plan['ledger']['register_date_mode'] === 'keep' && $slots['general_affairs_1']['operation'] === 'keep' && $slots['general_affairs_1']['planned_student_id'] === 'BM003' && $slots['general_affairs_2']['planned_student_id'] === 'BM007', '同じ役職内の番号の入替えは変更にせず、既存の枠位置を再利用', $slots['general_affairs_1']);
check(in_array('既存の枠位置に合わせました', $slots['general_affairs_1']['flags'], true), '提出と別の番号に合わせたことを表示', $slots['general_affairs_1']);
array_pop($fake->officerRecords);
$current = ['deputy_representative_1' => 'A', 'deputy_representative_2' => 'B'];
$target = roster_officer_target_slots(['slots' => ['deputy_representative_1' => 'B', 'deputy_representative_2' => '']], $current);
check($target['deputy_representative_1'] === 'B' && $target['deputy_representative_2'] === '', '既存の枠を再利用しても必須の枠は空けない', $target);
$target = roster_officer_target_slots(['slots' => ['general_affairs_2' => 'X']], []);
check($target['general_affairs_2'] === 'X' && $target['general_affairs_1'] === '', '総務1を空席にした提出はその枠位置のまま', $target);
$fp1 = $planFor($s1)['fingerprint'];
check($planFor($s1)['fingerprint'] === $fp1, '同じ比較元なら計画の指紋は同じ');
$fake->officerRecords[0]['revision'] = 8;
check($planFor($s1)['fingerprint'] !== $fp1, '比較後に役員台帳が更新されると計画の指紋が変わる');
$fake->people[1]['revision'] = 9;
$fp2 = $planFor($s1)['fingerprint'];
$fake->people[1]['revision'] = 3;
check($planFor($s1)['fingerprint'] !== $fp2, '比較後に登録者が更新されても計画の指紋が変わる');
$fake->officerRecords[0]['group_key'] = '';
check(has_message($planFor($s1)['warnings'], 'forms_current_group_key'), '現行レコードに団体キーがなければ移行が必要と示す');
$fake->officerRecords[0]['group_key'] = 'CA999';
check(has_message($planFor($s1)['blockers'], '団体キーが団体識別記号と一致しません'), '団体キーの不一致は止める');
$fake->officerRecords[0]['group_key'] = 'CA056';
$fake->officerRecords[] = ['id' => 501, 'revision' => 1, 'group_id' => 'CA056', 'group_name' => 'x', 'register_date' => '2024-04-01', 'slots' => []];
$plan = $planFor($s1);
check($plan['ledger']['operation'] === 'review' && $plan['ledger']['record_count'] === 2 && $plan['ledger']['register_date_mode'] === 'undetermined' && has_message($plan['blockers'], '2件あります'), '複数件なら最初の1件を選ばず止める', $plan['ledger']);
array_pop($fake->officerRecords);
$fake->officerRecords[0]['group_name'] = '旧団体名';
check(has_message($planFor($s1)['warnings'], '団体名が'), '役員台帳の団体名が ID7 と違えば差分として示す');
$fake->officerRecords[0]['group_name'] = '福工大謎解きサークル';
$plan = $planWith($s1, static function (array $payload): array {
    $payload['officers']['slots']['accountant'] = '';
    $payload['officers']['slots']['general_affairs_1'] = 'BM005';
    return $payload;
});
check(has_message($plan['blockers'], '会計長が空席') && has_message($plan['blockers'], '総務1：入部・在籍'), '必須の役職と候補の在籍状態は計画の作成時にも検証', $plan['blockers']);
check(!has_message($plan['blockers'], '卒業'), '担当者画面の解消項目に氏名を出さない', $plan['blockers']);
$capacityPlan = $planWith($s1, static function (array $payload): array {
    foreach (['X1', 'X2', 'X3'] as $sid) {
        $payload['rows'][] = roster_snapshot_rows(roster_normalize_rows([member($sid, $sid, '総務')]))[0];
    }
    return $payload;
});
check(has_message($capacityPlan['warnings'], '「総務」の部員が4人'), '定員超過は担当者の画面にも表示', $capacityPlan['warnings']);
check(is_array($plan['group']) && $plan['group']['record_id'] > 0, 'ID7 の現在値（レコードID・リビジョン）を比較元にする', $plan['group']);
$saved = $fake->inject;
$fake->inject = static fn($m, $p, $payload) => (int)($payload['app'] ?? 0) === 21 ? 'connect_fail' : null;
$planDown = $planFor($s1);
$fake->inject = $saved;
check($planDown['ok'] === false && str_contains($planDown['error'], '比較できません'), 'kintone に接続できなければ確認済みとして扱わない', $planDown);

section('接続の確認（ID22 / ID21）');
$pre = roster_kintone_officer_preflight();
check($pre['ok'] === true && has_message($pre['write_blockers'], 'forms_current_group_key'), '調査時の設定でも比較はできるが、一意キーがなければ書込みの前提を満たさない', $pre);
check(has_message($pre['write_blockers'], '「general_affairs_1_stundet_id」の必須指定を解除') && has_message($pre['write_blockers'], '「general_affairs_1_mail」の必須指定を解除'), '総務1が必須の旧設定を書込みの前提未達として検出', $pre['write_blockers']);
check(has_message($pre['write_blockers'], '「deputy_representative_1_stundet_id」を必須に'), '副代表者1が任意の旧設定を検出', $pre['write_blockers']);
$fake->officerFieldsMigrated = true;
$pre = roster_kintone_officer_preflight();
$fake->officerFieldsMigrated = false;
check($pre['ok'] === true && $pre['write_blockers'] === [], '業務ルールへ移行後は書込みの前提を満たす', $pre['write_blockers']);
$fake->officerFieldsComplete = false;
$pre = roster_kintone_officer_preflight();
$fake->officerFieldsComplete = true;
check($pre['ok'] === false && has_message($pre['errors'], 'org_depend_state') && has_message($pre['errors'], '重複を禁止') && has_message($pre['errors'], 'accountant_stundet_id'), 'フィールド不足・一意制約なしを検出', $pre['errors']);

section('書込みをしないこと');
$fake->appCalls = [];
$fake->writes = 0;
$fake->inject = null;
$case1 = roster_case_by_id($pdo, $s1['case_id']);
$found = roster_kintone_find_group_records('CA056');
$peopleBefore = count($fake->people);
$a = roster_staff_accept($s1['case_id'], (int)$case1['latest_revision_id'], roster_compare_token($found), $staff);
$planAfterAccept = roster_officer_build_plan(roster_case_by_id($pdo, $s1['case_id']), roster_revision_by_id($pdo, $s1['revision_id']));
$summary = roster_worker_run(['only_job_id' => $a['job_id'], 'skip_directory' => true, 'worker_name' => 'test']);
check(($summary['processed'][0]['status'] ?? '') === 'applied' && count($fake->records) === 1, '版2の提出を accept すると、ID20 だけが反映される', $summary);
check($fake->officerWrites() === 0, 'accept・反映でも ID21 / ID22 への書込みは0回', $fake->appCalls);
check($fake->officerRecords[0]['revision'] === 8 && count($fake->people) === $peopleBefore, 'ID21 / ID22 の内容は変わらない');
$snap = json_decode((string)roster_acceptance_by_id($pdo, $a['acceptance_id'])['snapshot_json'], true);
check(!isset($snap['officers']), 'この段階の accept スナップショットは ID20 の部員行だけ（役員は次の PR で固定）', array_keys($snap));

section('汎用同期のガード');
$org = kintone_pdo('org');
$org->exec("DELETE FROM kintone_apps WHERE app_key LIKE 'guard_%' OR app_key IN ('roster_people', 'roster_officers')");
$org->exec("INSERT INTO kintone_apps (app_key, display_name, kintone_subdomain, kintone_app_id, app_role) VALUES ('roster_people', 'ID22', 'fit-sc', 22, 'manual'), ('roster_officers', 'ID21', 'fit-sc', 21, 'manual'), ('guard_alias', '別名', 'fit-sc', 22, 'pull'), ('guard_other', '別アプリ', 'fit-sc', 99, 'pull')");
$blocked = static function (string $key) use ($org): bool {
    $stmt = $org->prepare('SELECT * FROM kintone_apps WHERE app_key = :k');
    $stmt->execute([':k' => $key]);
    try {
        kintone_sync_assert_not_roster_app($stmt->fetch());
        return false;
    } catch (KintoneSyncException) {
        return true;
    }
};
check($blocked('roster_people') && $blocked('roster_officers'), 'ID22 / ID21 は汎用同期から操作できない');
check($blocked('guard_alias'), '同じアプリIDを別名で登録しても汎用同期から操作できない');
check(!$blocked('guard_other'), '別のアプリは止めない');
$org->exec("DELETE FROM kintone_apps WHERE app_key LIKE 'guard_%' OR app_key IN ('roster_people', 'roster_officers')");

echo "\n" . $GLOBALS['passes'] . ' passed, ' . $GLOBALS['failures'] . " failed\n";
exit($GLOBALS['failures'] > 0 ? 1 : 0);
