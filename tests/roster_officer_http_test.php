<?php

declare(strict_types=1);

/**
 * 第2段階（役員入力・比較プレビュー）の HTTP 経由の確認。
 * tests/support/roster_dev_seed.php でデータを作り、tests/support/roster_dev_router.php でサーバーを起動したうえで実行する。
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

echo "\n{$passes} passed, {$fails} failed\n";
exit($fails > 0 ? 1 : 0);
