<?php

declare(strict_types=1);

/**
 * 第2段階（役員入力・比較・書込み対象の指定・accept・反映）の HTTP 経由の確認。
 * tests/support/roster_dev_seed.php でデータを作り、tests/support/roster_dev_router.php でサーバーを起動したうえで実行する。
 * サーバーのローカル設定で roster.officers.write_enabled を true にしておくこと（書込み対象を指定した団体だけが書き込まれる）。
 *
 *   php -d extension=curl tests/roster_officer_http_test.php http://127.0.0.1:8090 <accounts.txt> <kintone_storage_dir> <fake_kintone.json>
 */

[$script, $base, $accountsFile, $storageDir, $fakeState] = $argv + [null, null, null, null, null];
if (!$base || !$accountsFile || !$storageDir || !$fakeState) {
    fwrite(STDERR, "usage: php tests/roster_officer_http_test.php <base_url> <accounts.txt> <kintone_storage_dir> <fake_kintone.json>\n");
    exit(1);
}
$passwords = [];
foreach (file($accountsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    [$login, , $password] = explode(' ', $line);
    $passwords[$login] = $password;
}
$fails = 0;
$passes = 0;
function ok(bool $cond, string $label, mixed $detail = null): void
{
    global $fails, $passes;
    $cond ? $passes++ : $fails++;
    echo ($cond ? '  ok  - ' : '  NG  - ') . $label . ($cond || $detail === null ? '' : ' :: ' . (is_scalar($detail) ? (string)$detail : json_encode($detail, JSON_UNESCAPED_UNICODE))) . "\n";
}

final class Client
{
    private string $jar;
    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'rcj');
    }
    public function request(string $method, string $path, array|string|null $body = null, array $headers = []): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
        }
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $raw = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $text = substr($raw, $headerSize);
        preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $headerSize), $m);
        return ['status' => $status, 'body' => $text, 'json' => json_decode($text, true), 'location' => trim($m[1] ?? '')];
    }
    public function json(string $path, array $payload): array
    {
        return $this->request('POST', $path, json_encode($payload, JSON_UNESCAPED_UNICODE), ['Content-Type: application/json']);
    }
    public function login(string $login, string $password): bool
    {
        preg_match('/name="_csrf" value="([^"]+)"/', $this->request('GET', '/kintone/login.php')['body'], $m);
        $r = $this->request('POST', '/kintone/login.php', ['_csrf' => html_entity_decode($m[1] ?? ''), 'login_id' => $login, 'password' => $password, 'return_to' => 'index.php']);
        return $r['status'] === 302 && !str_contains($r['location'], 'login.php');
    }
}

function latest_code(string $storageDir): string
{
    preg_match_all('/確認コード：(\d{6})/u', (string)@file_get_contents($storageDir . '/roster_dev_mail.log'), $m);
    return (string)end($m[1]);
}

$before = json_decode((string)file_get_contents($fakeState), true);
$forms = (new Client($base))->request('GET', '/forms/api/public_forms_with_closed.php')['json']['forms'] ?? [];
$form = array_values(array_filter($forms, static fn($f) => ($f['slug'] ?? '') === 'roster-2026-officers'))[0] ?? null;
ok(is_array($form) && !empty($form['settings']['roster_officer_sync']), '役員連携の対象フォームが公開一覧にある');
$formId = (int)$form['id'];

echo "\n# 団体（Forms）\n";
$group = new Client($base);
preg_match('/name="csrf-token" content="([^"]+)"/', $group->request('GET', '/forms/')['body'], $m);
$csrf = html_entity_decode($m[1] ?? '');
$slots = ['representative' => 'BM23001', 'deputy_representative_1' => 'BM22003', 'accountant' => 'BM23002', 'general_affairs_1' => 'BM26001'];
$r = $group->json('/forms/api/roster.php', ['action' => 'preview', 'form_id' => $formId, 'rows' => [], 'officers' => ['slots' => $slots], 'csrf_token' => $csrf]);
ok($r['status'] === 401, '団体確認前は役員情報を扱えない', $r['status']);
$group->json('/forms/api/roster.php', ['action' => 'request_code', 'form_id' => $formId, 'group_code' => 'CA056', 'csrf_token' => $csrf]);
$group->json('/forms/api/roster.php', ['action' => 'verify_code', 'form_id' => $formId, 'code' => latest_code($storageDir), 'csrf_token' => $csrf]);
$state = $group->request('GET', '/forms/api/roster.php?action=state&form_id=' . $formId)['json'];
ok(($state['settings']['officer_sync'] ?? false) === true && count($state['options']['officer_slots'] ?? []) === 9, '団体の画面に役員枠の定義が渡る');
ok(!str_contains(json_encode($state, JSON_UNESCAPED_UNICODE), 'registered@bene.fit.ac.jp'), '団体の画面に ID22 の登録内容を返さない');
$rows = [
    ['key' => 'a', 'membership_status' => '在籍', 'name' => '福岡 太郎', 'student_id' => 'BM23001', 'role' => '部長・主将', 'phone_number' => '090-1234-5678'],
    ['key' => 'b', 'membership_status' => '在籍', 'name' => '工大 花子', 'student_id' => 'BM23002', 'role' => '会計長', 'phone_number' => '080-1111-2222'],
    ['key' => 'c', 'membership_status' => '在籍', 'name' => '新入 一郎', 'student_id' => 'BM26001', 'role' => '総務', 'phone_number' => '080-3333-4444'],
    ['key' => 'd', 'membership_status' => '在籍', 'name' => '博多 次郎', 'student_id' => 'BM22003', 'role' => '副部長', 'phone_number' => '080-5555-6666'],
    ['key' => 'e', 'membership_status' => '入部', 'name' => '平 部員', 'student_id' => 'BM26002', 'role' => '', 'phone_number' => ''],
];
$person = static fn(string $sid, string $family, string $given, string $mail, string $depend = '情報工学科'): array => ['student_id' => $sid, 'family_name' => $family, 'personal_name' => $given, 'mail' => $mail, 'depend' => $depend, 'type' => '大学 学生', 'org_depend_state' => '無'];
$officers = ['slots' => $slots, 'people' => [
    $person('BM23001', '福岡', '太郎', 'registered@bene.fit.ac.jp'),
    $person('BM23002', '工大', '花子', 'hanako@bene.fit.ac.jp', '電気工学科'),
    $person('BM26001', '新入', '一郎', 'bm26001@bene.fit.ac.jp'),
    $person('BM22003', '博多', '次郎', 'bm22003@bene.fit.ac.jp'),
]];
$short = $group->json('/forms/api/roster.php', ['action' => 'preview', 'form_id' => $formId, 'rows' => array_slice($rows, 0, 4), 'officers' => $officers, 'csrf_token' => $csrf])['json'];
ok(str_contains(json_encode($short['validation']['errors'] ?? [], JSON_UNESCAPED_UNICODE), '5人以上必要'), '入部・在籍が5人未満なら人数不足を表示', $short['validation'] ?? $short);
$vacant = $officers;
$vacant['slots']['deputy_representative_1'] = '';
$noDeputy = $group->json('/forms/api/roster.php', ['action' => 'preview', 'form_id' => $formId, 'rows' => $rows, 'officers' => $vacant, 'csrf_token' => $csrf])['json'];
ok(str_contains(json_encode($noDeputy['validation']['errors'] ?? [], JSON_UNESCAPED_UNICODE), '副代表者1が空席'), '必須の役職の空席を表示', $noDeputy['validation'] ?? $noDeputy);
$preview = $group->json('/forms/api/roster.php', ['action' => 'preview', 'form_id' => $formId, 'rows' => $rows, 'officers' => $officers, 'csrf_token' => $csrf])['json'];
ok(($preview['validation']['errors'] ?? null) === [] && ($preview['officers'][0]['name'] ?? '') === '福岡 太郎', '提出前の確認で役員の割り当てを検証・表示', $preview['validation'] ?? $preview);
$r = $group->json('/forms/api/roster.php', ['action' => 'submit', 'form_id' => $formId, 'rows' => $rows, 'officers' => $officers, 'draft_version' => (int)$state['draft']['draft_version'], 'submitter_name' => '福岡 太郎', 'csrf_token' => $csrf]);
ok(($r['json']['ok'] ?? false) === true, '役員情報を付けて提出できる', $r['json']);

echo "\n# 担当者（kintone管理サイト）\n";
$caseId = (int)(new PDO('mysql:host=127.0.0.1;port=33306;dbname=fitsc_forms;charset=utf8mb4', 'root', 'roster-test-root'))->query("SELECT id FROM roster_cases WHERE group_code = 'CA056' AND form_id = " . $formId)->fetchColumn();
$operator = new Client($base);
ok($operator->login('roster_operator', $passwords['roster_operator']), '担当者でログイン');
$detail = $operator->request('GET', '/kintone/roster_detail.php?case_id=' . $caseId)['body'];
ok(str_contains($detail, '登録者・役員台帳との比較') && str_contains($detail, '書込み無効'), '提出詳細に比較プレビューを表示（書込み無効と明示）');
ok(str_contains($detail, '既存の登録者を参照') && str_contains($detail, '新規登録') && str_contains($detail, 'registered@bene.fit.ac.jp'), 'operator には照合結果と人物の属性を表示');
ok(str_contains($detail, '交代') && str_contains($detail, '維持'), 'ID21 の枠ごとの操作を表示');
ok(str_contains($detail, '変更時はaccept日を使用'), '登録日の扱いを表示');
$viewer = new Client($base);
ok($viewer->login('roster_viewer', $passwords['roster_viewer']), '閲覧者でログイン');
$viewerDetail = $viewer->request('GET', '/kintone/roster_detail.php?case_id=' . $caseId)['body'];
ok(str_contains($viewerDetail, '既存の登録者を参照') && !str_contains($viewerDetail, 'registered@bene.fit.ac.jp'), 'viewer には判定だけを表示し、登録者の属性は出さない');
ok(str_contains($viewerDetail, '担当者のみ表示'), 'viewer の照合表では部員を伏せる');
$settings = $operator->request('GET', '/kintone/roster_settings.php?check=1')['body'];
ok(str_contains($settings, '第2段階：ID22 / ID21 のフィールド設定') && str_contains($settings, 'forms_current_group_key'), '接続確認に ID22 / ID21 の検査結果を表示');
ok(str_contains($settings, '書込みを有効にする前に必要な設定') && str_contains($settings, 'general_affairs_1_stundet_id'), '総務1が必須の旧設定を書込みの前提として表示');

$after = json_decode((string)file_get_contents($fakeState), true);
ok($before['people'] === $after['people'] && $before['officerRecords'] === $after['officerRecords'], '提出・比較では ID21 / ID22 が変わらない');

echo "\n# 役員の書込み（admin が対象を指定 → accept → 反映）\n";
// ID21 の移行（団体キーの補完・必須設定の変更）が済んだ状態を模擬する
$state = json_decode((string)file_get_contents($fakeState), true);
$state['officerFieldsMigrated'] = true;
foreach ($state['officerRecords'] as &$rec) {
    if ($rec['group_id'] === 'CA056') {
        $rec['group_key'] = 'CA056';
    }
}
unset($rec);
file_put_contents($fakeState, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$csrfOf = static fn(string $html): string => html_entity_decode(preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '');
$operator->request('POST', '/kintone/api/roster_action.php', ['_csrf' => $csrfOf($settings), 'action' => 'officer_target_set', 'group_code' => 'CA056', 'form_id' => $formId]);
ok(str_contains($operator->request('GET', '/kintone/roster_settings.php')['body'], 'admin 権限が必要'), 'operator は書込み対象を変更できない');
$admin = new Client($base);
ok($admin->login('roster_admin', $passwords['roster_admin']), '管理者でログイン');
$adminSettings = $admin->request('GET', '/kintone/roster_settings.php')['body'];
$admin->request('POST', '/kintone/api/roster_action.php', ['_csrf' => $csrfOf($adminSettings), 'action' => 'officer_target_set', 'group_code' => 'CA056', 'form_id' => $formId]);
$adminSettings = $admin->request('GET', '/kintone/roster_settings.php')['body'];
ok(str_contains($adminSettings, '役員の書込み対象に指定しました') && str_contains($adminSettings, '<span class="cell-sub mono">CA056</span>'), 'admin が団体・フォーム・年度を書込み対象に指定できる');
$detail = $operator->request('GET', '/kintone/roster_detail.php?case_id=' . $caseId)['body'];
preg_match('/name="officer_fingerprint" value="([0-9a-f]*)"/', $detail, $fp);
preg_match('/name="compare_token" value="([^"]*)"/', $detail, $ct);
preg_match('/name="action" value="accept"><input type="hidden" name="case_id" value="\d+"><input type="hidden" name="revision_id" value="(\d+)"/', $detail, $rv);
ok(str_contains($detail, '書込み対象') && strlen($fp[1] ?? '') === 64 && ($rv[1] ?? '') !== '', '書込み対象の団体では、accept の画面に計画の指紋を渡す');
$operator->request('POST', '/kintone/api/roster_action.php', ['_csrf' => $csrfOf($detail), 'action' => 'accept', 'case_id' => $caseId, 'revision_id' => $rv[1] ?? 0, 'compare_token' => html_entity_decode($ct[1] ?? ''), 'officer_fingerprint' => str_repeat('0', 64)]);
ok(str_contains($operator->request('GET', '/kintone/roster_detail.php?case_id=' . $caseId)['body'], '登録者・役員台帳の内容が変わりました'), '画面と異なる指紋の accept は拒否');
$detail = $operator->request('GET', '/kintone/roster_detail.php?case_id=' . $caseId)['body'];
$operator->request('POST', '/kintone/api/roster_action.php', ['_csrf' => $csrfOf($detail), 'action' => 'accept', 'case_id' => $caseId, 'revision_id' => $rv[1] ?? 0, 'compare_token' => html_entity_decode($ct[1] ?? ''), 'officer_fingerprint' => $fp[1] ?? '']);
$jobId = (int)(new PDO('mysql:host=127.0.0.1;port=33306;dbname=fitsc_forms;charset=utf8mb4', 'root', 'roster-test-root'))->query('SELECT current_job_id FROM roster_cases WHERE id = ' . $caseId)->fetchColumn();
$detail = $operator->request('GET', '/kintone/roster_detail.php?case_id=' . $caseId)['body'];
ok($jobId > 0 && str_contains($detail, '役員（登録者・役員台帳）も計画どおりに反映します') && str_contains($detail, '役員台帳（ID21）'), 'accept で計画を固定し、ステップ（ID20 → ID22 → ID21）を表示', $jobId);
$operator->request('POST', '/kintone/api/roster_action.php', ['_csrf' => $csrfOf($detail), 'action' => 'run_now', 'case_id' => $caseId, 'job_id' => $jobId]);
$detail = $operator->request('GET', '/kintone/roster_detail.php?case_id=' . $caseId)['body'];
ok(str_contains($detail, '処理しました：反映済み') && substr_count($detail, '確認済み') >= 6, '今すぐ処理で、全ステップを読み戻して確認済み');
$final = json_decode((string)file_get_contents($fakeState), true);
$ledger = array_values(array_filter($final['officerRecords'], static fn(array $r): bool => $r['group_id'] === 'CA056'))[0];
ok(count($final['people']) === count($before['people']) + 2 && $ledger['slots']['accountant_stundet_id'] === 'BM23002' && $ledger['slots']['general_affairs_1_name'] === '新入一郎', 'ID22 に未登録の2名を登録し、ID21 の会計長・総務1・副代表者1を更新', $ledger['slots']);
$viewerDetail = $viewer->request('GET', '/kintone/roster_detail.php?case_id=' . $caseId)['body'];
ok(str_contains($viewerDetail, '登録者（ID22）person_') && !str_contains($viewerDetail, 'bm26001@bene.fit.ac.jp'), 'viewer にもステップ別の結果を表示し、人物の属性は出さない');

echo "\n{$passes} passed, {$fails} failed\n";
exit($fails > 0 ? 1 : 0);
