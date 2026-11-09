<?php

declare(strict_types=1);

/**
 * ローカル確認用のデータを作る（ローカルDB専用。本番・共有DBでは実行しないこと）。
 *
 *   FITSC_CONFIG_FILE=/path/to/local_config.php php tests/support/roster_dev_seed.php <fake_kintone.json> <accounts_out.txt>
 *
 * - kintone管理サイト用のアカウント（admin / operator / viewer）と Forms 管理者を作成し、ランダムなパスワードを accounts_out.txt に書き出す
 * - 2026年度の部員名簿フォームを作成する
 * - FakeKintone に CA056 の既存名簿（職名付き・状態未設定の行を含む）を入れる
 */

if (PHP_SAPI !== 'cli' || count($argv) < 3) {
    fwrite(STDERR, "usage: php tests/support/roster_dev_seed.php <fake_kintone.json> <accounts_out.txt>\n");
    exit(1);
}

require_once dirname(__DIR__, 2) . '/apps/roster_core/bootstrap.php';
require_once dirname(__DIR__, 2) . '/apps/shared_accounts.php';
require_once __DIR__ . '/fake_kintone.php';

$fake = FakeKintone::persistent($argv[1]);
$fake->records = [];
$fake->nextRecordId = 1;
$fake->nextRowId = 1001;
$fake->seedRecord('CA056', [
    ['name' => '福岡 太郎', 'student_id' => 'BM23001', 'phone_number' => '090-1234-5678', 'role' => '代表者', 'role_name' => '主将', 'membership_status' => '在籍', 'uses_group_email' => ['使用']],
    ['name' => '工大 花子', 'student_id' => 'BM23002', 'role' => '会計長', 'phone_number' => '080-1111-2222'],
    ['name' => '博多 次郎', 'student_id' => 'BM22003', 'role' => '-'],
]);
// 第2段階の比較用: ID22 に2名、ID21 に CA056 の現行レコード
$fake->people = [
    ['id' => 1, 'revision' => 2, 'student_id' => 'BM23001', 'family_name' => '福岡', 'personal_name' => '太郎', 'mail' => 'registered@bene.fit.ac.jp', 'depend' => '情報工学科', 'type' => '大学 学生', 'org_depend_state' => '無'],
    ['id' => 2, 'revision' => 1, 'student_id' => 'BM23002', 'family_name' => '工大', 'personal_name' => '花子', 'mail' => 'hanako@bene.fit.ac.jp', 'depend' => '電気工学科', 'type' => '大学 学生', 'org_depend_state' => '無'],
];
$fake->officerRecords = [[
    'id' => 500, 'revision' => 4, 'group_id' => 'CA056', 'group_name' => '福工大謎解きサークル', 'group_org' => ['CA056'], 'register_date' => '2025-04-01',
    'slots' => ['representative_stundet_id' => 'BM23001', 'representative_name' => '福岡太郎', 'representative_mail' => 'registered@bene.fit.ac.jp', 'accountant_stundet_id' => 'BM21000', 'accountant_name' => '前任会計', 'accountant_mail' => 'old@bene.fit.ac.jp'],
]];
$fake->save();
roster_kintone_test_transport(fn(string $m, string $p, array $payload, int $t): array => $fake->handle($m, $p, $payload, $t), true);

$accounts = shared_accounts_db(kintone_load_config(), []);
shared_accounts_install_schema($accounts);
$lines = [];
foreach ([['roster_admin', 'kintone', 'admin', '管理 太郎'], ['roster_operator', 'kintone', 'operator', '担当 花子'], ['roster_viewer', 'kintone', 'viewer', '閲覧 次郎'], ['forms_admin', 'forms', 'admin', 'フォーム 管理']] as [$login, $app, $role, $name]) {
    $password = bin2hex(random_bytes(8));
    $accounts->prepare('INSERT INTO shared_accounts (login_id, password_hash, display_name, is_active) VALUES (:l, :h, :n, 1) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), display_name = VALUES(display_name), is_active = 1')->execute([
        ':l' => $login, ':h' => password_hash($password, PASSWORD_DEFAULT), ':n' => $name,
    ]);
    $id = (int)$accounts->query('SELECT id FROM shared_accounts WHERE login_id = ' . $accounts->quote($login))->fetchColumn();
    $accounts->prepare('DELETE FROM shared_account_app_roles WHERE account_id = :id')->execute([':id' => $id]);
    $accounts->prepare('INSERT INTO shared_account_app_roles (account_id, app_key, role_key) VALUES (:id, :app, :role)')->execute([':id' => $id, ':app' => $app, ':role' => $role]);
    $lines[] = $login . ' (' . $app . ':' . $role . ') ' . $password;
}
file_put_contents($argv[2], implode("\n", $lines) . "\n");

$pdo = roster_db();
$exists = (int)$pdo->query("SELECT COUNT(*) FROM managed_forms WHERE slug = 'roster-2026'")->fetchColumn();
if ($exists === 0) {
    $pdo->prepare('INSERT INTO managed_forms (slug, name, description, is_active, sort_order, settings_json) VALUES (:slug, :name, :d, 1, 0, :s)')->execute([
        ':slug' => 'roster-2026',
        ':name' => '2026年度 部員名簿',
        ':d' => "各団体の部員名簿を提出してください。\n顧問承諾書・クラブハウス部屋鍵保有者登録書は、これまでどおり書類で提出してください。",
        ':s' => json_encode(['form_type' => 'roster', 'roster_fiscal_year' => 2026, 'roster_phone_required_scope' => 'officers', 'roster_phone_required_manager' => false, 'enable_date_field' => false, 'allow_file_upload' => false], JSON_UNESCAPED_UNICODE),
    ]);
}
if ((int)$pdo->query("SELECT COUNT(*) FROM managed_forms WHERE slug = 'roster-2026-officers'")->fetchColumn() === 0) {
    $pdo->prepare('INSERT INTO managed_forms (slug, name, description, is_active, sort_order, settings_json) VALUES (:slug, :name, :d, 1, 1, :s)')->execute([
        ':slug' => 'roster-2026-officers',
        ':name' => '2026年度 部員名簿（役員情報あり）',
        ':d' => '第2段階の確認用。部員名簿に役員の割り当てを付けて提出します。',
        ':s' => json_encode(['form_type' => 'roster', 'roster_fiscal_year' => 2026, 'roster_phone_required_scope' => 'officers', 'roster_phone_required_manager' => false, 'roster_officer_sync' => true, 'enable_date_field' => false, 'allow_file_upload' => false], JSON_UNESCAPED_UNICODE),
    ]);
}
$dir = roster_directory_refresh($pdo);
echo 'seeded: groups=' . $dir['count'] . ' accounts=' . count($lines) . "\n";
