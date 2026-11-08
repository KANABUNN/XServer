<?php

declare(strict_types=1);

/**
 * 部員名簿の HTTP 経由の確認（ローカルの PHP 組み込みサーバー + FakeKintone）
 * 指示書16章 3「閲覧者・別団体・未認証利用者が accept や再試行 API を直接呼んでも拒否される」と、
 * Forms → kintone管理サイト → 反映までの一連の流れを、実際の HTTP リクエストで確認する。
 *
 *   php tests/support/roster_dev_seed.php ... を実行し、tests/support/roster_dev_router.php でサーバーを起動したうえで:
 *   php -d extension=curl tests/roster_http_test.php http://127.0.0.1:8090 <accounts.txt> <kintone_storage_dir>
 */

[$script, $base, $accountsFile, $storageDir] = $argv + [null, null, null, null];
if (!$base || !$accountsFile || !$storageDir) {
    fwrite(STDERR, "usage: php tests/roster_http_test.php <base_url> <accounts.txt> <kintone_storage_dir>\n");
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
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => false]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
        }
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $raw = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $head = substr($raw, 0, $headerSize);
        $text = substr($raw, $headerSize);
        preg_match('/^Location:\s*(.+)$/mi', $head, $m);
        return ['status' => $status, 'body' => $text, 'json' => json_decode($text, true), 'location' => trim($m[1] ?? '')];
    }
    public function json(string $path, array $payload): array
    {
        return $this->request('POST', $path, json_encode($payload, JSON_UNESCAPED_UNICODE), ['Content-Type: application/json']);
    }
    public function csrfFrom(string $path, string $pattern): string
    {
        $page = $this->request('GET', $path);
        preg_match($pattern, $page['body'], $m);
        return html_entity_decode($m[1] ?? '');
    }
    public function kintoneLogin(string $login, string $password): bool
    {
        $csrf = $this->csrfFrom('/kintone/login.php', '/name="_csrf" value="([^"]+)"/');
        $r = $this->request('POST', '/kintone/login.php', ['_csrf' => $csrf, 'login_id' => $login, 'password' => $password, 'return_to' => 'index.php']);
        return $r['status'] === 302 && !str_contains($r['location'], 'login.php');
    }
}

function latest_code(string $storageDir): string
{
    $log = (string)@file_get_contents($storageDir . '/roster_dev_mail.log');
    preg_match_all('/確認コード：(\d{6})/u', $log, $m);
    return (string)end($m[1]);
}

$formsPage = (new Client($base))->request('GET', '/forms/api/public_forms_with_closed.php');
$rosterForm = null;
foreach ((array)($formsPage['json']['forms'] ?? []) as $f) {
    if (($f['settings']['form_type'] ?? '') === 'roster') {
        $rosterForm = $f;
    }
}
ok(is_array($rosterForm), '公開フォーム一覧に部員名簿フォームが出る');
$formId = (int)$rosterForm['id'];

echo "\n# 団体（Forms）\n";
$group = new Client($base);
$csrf = $group->csrfFrom('/forms/', '/name="csrf-token" content="([^"]+)"/');
$r = $group->json('/forms/api/roster.php', ['action' => 'save_draft', 'form_id' => $formId, 'rows' => [], 'draft_version' => 0]);
ok($r['status'] === 419, 'CSRF トークンなしの名簿 API は拒否', $r['status']);
$r = $group->json('/forms/api/roster.php', ['action' => 'save_draft', 'form_id' => $formId, 'rows' => [], 'draft_version' => 0, 'csrf_token' => $csrf]);
ok($r['status'] === 401 && !empty($r['json']['auth_required']), '団体確認前の下書き保存は拒否（未認証）', $r['status']);
$r = $group->request('GET', '/forms/api/roster.php?action=state&form_id=' . $formId);
ok(($r['json']['authenticated'] ?? null) === false && !isset($r['json']['draft']), '団体確認前は名簿を表示しない');
$r = $group->json('/forms/api/submit.php', ['form_id' => $formId]);
$r = $group->request('POST', '/forms/api/submit.php', ['form_id' => $formId, 'csrf_token' => $csrf, 'email' => 'x@example.jp', 'organization_name' => '福工大謎解きサークル']);
ok($r['status'] === 409, '一般の提出 API からは名簿フォームへ提出できない', $r['status']);
$r = $group->json('/forms/api/roster.php', ['action' => 'request_code', 'form_id' => $formId, 'group_code' => 'CA056', 'csrf_token' => $csrf]);
ok(($r['json']['ok'] ?? false) && str_contains((string)$r['json']['email_masked'], '@example.jp'), '団体識別記号を選ぶと ID7 の団体メールへ確認コードを送る', $r['json']);
$r = $group->json('/forms/api/roster.php', ['action' => 'verify_code', 'form_id' => $formId, 'code' => latest_code($storageDir), 'csrf_token' => $csrf]);
ok(($r['json']['ok'] ?? false) === true, '確認コードで団体を確認', $r['json']);
$state = $group->request('GET', '/forms/api/roster.php?action=state&form_id=' . $formId)['json'];
ok(($state['group']['code'] ?? '') === 'CA056', '確認した団体（CA056）の名簿だけを扱える');
$r = $group->json('/forms/api/roster.php', ['action' => 'load_kintone', 'form_id' => $formId, 'draft_version' => (int)$state['draft']['draft_version'], 'csrf_token' => $csrf]);
ok(($r['json']['ok'] ?? false) && count($r['json']['rows']) === 3, 'kintone の現在名簿を読み込む', $r['json']['message'] ?? null);
$rows = $r['json']['rows'];
$rows[1]['membership_status'] = '在籍';
$rows[2]['membership_status'] = '卒部';
$rows[] = ['key' => 'n1', 'kintone_row_id' => '99999', 'membership_status' => '入部', 'name' => '新入 一郎', 'student_id' => 'bm26001', 'role' => '役職なし', 'phone_number' => '', 'uses_group_email' => false, 'is_website_editor' => true];
$r = $group->json('/forms/api/roster.php', ['action' => 'save_draft', 'form_id' => $formId, 'rows' => $rows, 'draft_version' => (int)$r['json']['draft_version'], 'csrf_token' => $csrf]);
ok(($r['json']['ok'] ?? false) === true, '下書きをサーバーに保存', $r['json']);
$version = (int)$r['json']['draft_version'];
$preview = $group->json('/forms/api/roster.php', ['action' => 'preview', 'form_id' => $formId, 'rows' => $rows, 'csrf_token' => $csrf])['json'];
ok(($preview['validation']['errors'] ?? null) === [] && ($preview['diff']['summary']['added'] ?? 0) === 1 && ($preview['diff']['summary']['status_changed'] ?? 0) === 2, '提出前の確認で差分（追加1・状態変更2）を表示', $preview['diff']['summary'] ?? $preview);
ok(array_key_exists('kintone_row_id', $preview['rows'][3] ?? []) && $preview['rows'][3]['kintone_row_id'] === null, '画面から送られた未知の kintone 行IDは捨てる（他団体の行を指定できない）', $preview['rows'][3] ?? null);
$r = $group->json('/forms/api/roster.php', ['action' => 'submit', 'form_id' => $formId, 'rows' => $rows, 'draft_version' => $version, 'submitter_name' => '福岡 太郎', 'group_code' => 'CA001', 'csrf_token' => $csrf]);
ok(($r['json']['ok'] ?? false) && ($r['json']['revision_number'] ?? 0) === 1, '提出（第1版）', $r['json']);
$state = $group->request('GET', '/forms/api/roster.php?action=state&form_id=' . $formId)['json'];
ok(($state['group']['code'] ?? '') === 'CA056' && ($state['case']['review_state'] ?? '') === 'submitted', '画面から別団体の識別記号を送っても、確認済みの団体（CA056）として扱う');
$other = new Client($base);
$otherCsrf = $other->csrfFrom('/forms/', '/name="csrf-token" content="([^"]+)"/');
$other->json('/forms/api/roster.php', ['action' => 'request_code', 'form_id' => $formId, 'group_code' => 'CA001', 'csrf_token' => $otherCsrf]);
$other->json('/forms/api/roster.php', ['action' => 'verify_code', 'form_id' => $formId, 'code' => latest_code($storageDir), 'csrf_token' => $otherCsrf]);
$otherState = $other->request('GET', '/forms/api/roster.php?action=state&form_id=' . $formId)['json'];
ok(($otherState['group']['code'] ?? '') === 'CA001' && $otherState['case'] === null && $otherState['latest'] === null, '別団体（CA001）として確認したセッションには CA056 の名簿は見えない', $otherState['case'] ?? null);

echo "\n# 担当者（kintone管理サイト）\n";
$caseId = (int)(new PDO('mysql:host=127.0.0.1;port=33306;dbname=fitsc_forms;charset=utf8mb4', 'root', 'roster-test-root'))->query("SELECT id FROM roster_cases WHERE group_code = 'CA056' AND form_id = " . $formId)->fetchColumn();
$anon = new Client($base);
$r = $anon->request('POST', '/kintone/api/roster_action.php', ['action' => 'accept', 'case_id' => $caseId, 'revision_id' => 1, 'compare_token' => 'x']);
ok($r['status'] === 302 && str_contains($r['location'], 'login.php'), '未ログインで accept API を呼ぶとログインへ戻され、処理されない', [$r['status'], $r['location']]);
$viewer = new Client($base);
ok($viewer->kintoneLogin('roster_viewer', $passwords['roster_viewer']), '閲覧者でログイン');
$page = $viewer->request('GET', '/kintone/roster_detail.php?case_id=' . $caseId);
ok($page['status'] === 200 && !str_contains($page['body'], 'name="action" value="accept"'), '閲覧者には accept の操作を表示しない');
preg_match('/name="_csrf" value="([^"]+)"/', $viewer->request('GET', '/kintone/roster_settings.php')['body'], $vm);
$viewerCsrf = $vm[1] ?? '';
foreach (['accept', 'return', 'retry', 'revoke', 'run_now'] as $action) {
    $r = $viewer->request('POST', '/kintone/api/roster_action.php', ['_csrf' => $viewerCsrf, 'action' => $action, 'case_id' => $caseId, 'revision_id' => 1, 'job_id' => 1, 'reason' => 'x', 'compare_token' => 'none']);
    ok($r['status'] === 403, '閲覧者が ' . $action . ' API を直接呼んでも拒否（403）', $r['status']);
}
$operator = new Client($base);
ok($operator->kintoneLogin('roster_operator', $passwords['roster_operator']), '担当者（operator）でログイン');
$detail = $operator->request('GET', '/kintone/roster_detail.php?case_id=' . $caseId)['body'];
preg_match('/name="compare_token" value="([^"]*)"/', $detail, $tm);
preg_match('/name="revision_id" value="(\d+)"/', $detail, $rm);
preg_match('/name="_csrf" value="([^"]+)"/', $detail, $cm);
ok(($tm[1] ?? '') !== '' && str_contains($detail, '主将'), '詳細画面に kintone 現在値との差分と比較元（職名の保持を含む）を表示', $tm[1] ?? null);
$r = $operator->request('POST', '/kintone/api/roster_action.php', ['action' => 'accept', 'case_id' => $caseId, 'revision_id' => $rm[1] ?? 0, 'compare_token' => $tm[1] ?? '', 'confirm_overwrite' => 1]);
ok($r['status'] === 403, 'CSRF トークンなしの accept は拒否', $r['status']);
$r = $operator->request('POST', '/kintone/api/roster_action.php', ['_csrf' => $cm[1] ?? '', 'action' => 'accept', 'case_id' => $caseId, 'revision_id' => $rm[1] ?? 0, 'compare_token' => 'stale-token', 'confirm_overwrite' => 1]);
$after = $operator->request('GET', $r['location'] ? '/kintone/' . ltrim(str_replace('../', '', $r['location']), '/') : '/kintone/roster.php')['body'];
ok(str_contains($after, '差分を表示した後に kintone の名簿が更新されました'), '表示時と比較元が違う accept は拒否（差分の再確認を求める）');
$r = $operator->request('POST', '/kintone/api/roster_action.php', ['_csrf' => $cm[1] ?? '', 'action' => 'accept', 'case_id' => $caseId, 'revision_id' => $rm[1] ?? 0, 'compare_token' => $tm[1] ?? '', 'confirm_overwrite' => 1]);
$after = $operator->request('GET', '/kintone/roster_detail.php?case_id=' . $caseId)['body'];
ok(str_contains($after, 'accept しました') && str_contains($after, '反映待ち'), 'operator の accept で反映待ちになる');
preg_match('/name="action" value="run_now"><input type="hidden" name="case_id" value="\d+"><input type="hidden" name="job_id" value="(\d+)"/', $after, $jm);
$r = $operator->request('POST', '/kintone/api/roster_action.php', ['_csrf' => $cm[1] ?? '', 'action' => 'run_now', 'case_id' => $caseId, 'job_id' => $jm[1] ?? 0]);
$after = $operator->request('GET', '/kintone/roster_detail.php?case_id=' . $caseId)['body'];
ok(str_contains($after, '処理しました：反映済み'), '「今すぐ処理」でも定期実行と同じ処理で反映済みになる', mb_substr(strip_tags($after), 0, 0));
$fake = json_decode((string)file_get_contents(getenv('FITSC_ROSTER_FAKE_STATE') ?: ($storageDir . '/../fake_kintone.json')), true);
$rec = array_values(array_filter($fake['records'], fn($x) => $x['group_id'] === 'CA056'))[0] ?? null;
$names = array_map(fn($row) => $row['values']['name'] . ':' . $row['values']['membership_status'] . ':' . $row['values']['role_name'], $rec['list'] ?? []);
ok(count($fake['records']) === 1 && in_array('福岡 太郎:在籍:主将', $names, true) && in_array('博多 次郎:卒部:', $names, true) && in_array('新入 一郎:入部:', $names, true), 'kintone（模擬）の同じレコードが更新され、職名を保持', $names);
$groupState = $group->request('GET', '/forms/api/roster.php?action=state&form_id=' . $formId)['json'];
ok(($groupState['case']['apply_state'] ?? '') === 'applied', '団体の画面にも反映済みと表示される', $groupState['case'] ?? null);

echo "\n{$passes} passed, {$fails} failed\n";
exit($fails > 0 ? 1 : 0);
