<?php

declare(strict_types=1);

/**
 * 実際の kintone（テスト用アプリ）での反映確認。ローカルDB・テスト用アプリ専用。
 *
 *   FITSC_CONFIG_FILE=… FITSC_ROSTER_DEV_MAIL=1 php tests/support/roster_live_check.php --confirm-app-id=184 --group=CA056
 *
 * - 登録済みの APIトークン（member_roster / groups）で、フィールド確認 → 団体一覧取得 → 提出 → accept → 反映（新規）
 *   → 読込・変更して再提出 → accept → 反映（更新）→ kintone 直接編集後の accept は競合で止まること、を順に確認する。
 * - 部員はすべて架空のテストデータ（氏名「連携テスト …」、学籍番号 TEST…）。
 * - --confirm-app-id が設定上の部員名簿アプリIDと一致しないと実行しない（本番アプリへの誤実行防止）。
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$opts = getopt('', ['confirm-app-id:', 'group:']);
require_once dirname(__DIR__, 2) . '/apps/roster_core/bootstrap.php';

$ctx = roster_kintone_app_context('roster');
if ((int)($opts['confirm-app-id'] ?? 0) !== (int)$ctx['app_id']) {
    fwrite(STDERR, '設定上の部員名簿アプリIDは ' . $ctx['app_id'] . " です。--confirm-app-id で同じIDを指定してください。\n");
    exit(1);
}
$groupCode = roster_normalize_group_code((string)($opts['group'] ?? ''));
if ($groupCode === '') {
    fwrite(STDERR, "--group=団体識別記号 を指定してください。\n");
    exit(1);
}
$staff = ['id' => 0, 'login_id' => 'live_check', 'display_name' => '連携テスト（ローカル）'];
$step = static function (string $title): void {
    echo "\n## {$title}\n";
};
$show = static function (array $record): void {
    echo '  レコードID ' . $record['record_id'] . ' / リビジョン ' . $record['revision'] . ' / 団体 ' . $record['group_code'] . ' ' . $record['group_name'] . "\n";
    foreach ($record['rows'] as $r) {
        echo sprintf("   - 行%s %s %s 状態=%s 役職=%s 職名=%s 電話=%s メール=%s HP=%s\n",
            $r['kintone_row_id'], $r['name'], $r['student_id'], var_export($r['membership_status'], true), $r['role'], $r['role_name'], $r['phone_number'],
            var_export($r['uses_group_email'], true), var_export($r['is_website_editor'], true));
    }
};
$runJob = static function (int $jobId): array {
    $summary = roster_worker_run(['only_job_id' => $jobId, 'skip_directory' => true, 'worker_name' => 'live_check']);
    $p = $summary['processed'][0] ?? ['status' => $summary['status'] ?? '?', 'message' => $summary['error'] ?? ''];
    echo '  ジョブ #' . $jobId . ' → ' . $p['status'] . ((string)($p['message'] ?? '') !== '' ? ' / ' . $p['message'] : '') . "\n";
    return $p;
};

$step('1. フィールド確認（アプリ ' . $ctx['app_id'] . '）');
$pre = roster_kintone_preflight();
echo '  ok=' . var_export($pre['ok'], true) . "\n";
foreach (array_merge($pre['errors'], $pre['warnings']) as $m) {
    echo '  - ' . $m . "\n";
}

$step('2. 団体一覧（ID7）の取得');
$pdo = roster_db();
$dir = roster_directory_refresh($pdo);
$eligible = roster_directory_public_list($pdo);
$group = roster_directory_get($groupCode, $pdo);
echo '  取得 ' . $dir['count'] . '団体 / 提出対象 ' . count($eligible) . "団体\n";
echo '  ' . $groupCode . ': ' . ($group ? $group['group_name'] . '（' . $group['group_rank'] . '・' . $group['activity_status'] . '・メール ' . roster_mask_email((string)$group['group_email']) . '）' : '見つかりません') . "\n";
if (!$group || !roster_directory_is_eligible($group)) {
    exit(1);
}

$pdo->exec("INSERT INTO managed_forms (slug, name, is_active, settings_json) SELECT 'roster-live-check', '部員名簿（連携テスト）', 1, '{\"form_type\":\"roster\",\"roster_fiscal_year\":2026}' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM managed_forms WHERE slug = 'roster-live-check')");
$form = roster_form_row($pdo, (int)$pdo->query("SELECT id FROM managed_forms WHERE slug = 'roster-live-check'")->fetchColumn());
$version = static fn(): int => (int)(roster_case_find($pdo, $form['id'], 2026, $groupCode)['draft_version'] ?? 0);
$submit = static function (array $rows) use ($form, $group, $version): array {
    return roster_group_submit($form, $group, ['verification_id' => 0], $rows, $version(), '連携テスト 提出者', '127.0.0.1');
};
$accept = static function (int $caseId, int $revisionId) use ($staff, $groupCode): array {
    $found = roster_kintone_find_group_records($groupCode);
    return roster_staff_accept($caseId, $revisionId, roster_compare_token($found), $staff);
};

$step('3. 現在の kintone（アプリ ' . $ctx['app_id'] . '）');
$found = roster_kintone_find_group_records($groupCode);
echo '  ' . $groupCode . ' のレコード: ' . $found['count'] . "件\n";
foreach ($found['records'] as $r) {
    $show($r);
}

if ($found['count'] === 0) {
    $step('4. 初回の提出 → accept → 反映（新規登録・ルックアップ）');
    $s1 = $submit([
        ['key' => 'a', 'membership_status' => '在籍', 'name' => '連携テスト 一郎', 'student_id' => 'TEST001', 'role' => '部長・主将', 'phone_number' => '090-0000-0001', 'uses_group_email' => true, 'is_website_editor' => false],
        ['key' => 'b', 'membership_status' => '在籍', 'name' => '連携テスト 二郎', 'student_id' => 'TEST002', 'role' => '会計長', 'phone_number' => '090-0000-0002', 'uses_group_email' => false, 'is_website_editor' => true],
        ['key' => 'c', 'membership_status' => '入部', 'name' => '連携テスト 三郎', 'student_id' => 'TEST003', 'role' => '役職なし', 'phone_number' => '', 'uses_group_email' => false, 'is_website_editor' => false],
    ]);
    echo '  提出 第' . $s1['revision_number'] . "版\n";
    $a1 = $accept($s1['case_id'], $s1['revision_id']);
    echo '  accept → ジョブ #' . $a1['job_id'] . "\n";
    $p1 = $runJob($a1['job_id']);
    if ($p1['status'] !== ROSTER_JOB_APPLIED) {
        $job = roster_job_by_id($pdo, $a1['job_id']);
        echo '  分類=' . $job['error_class'] . ' コード=' . $job['error_code'] . "\n";
        exit(1);
    }
    $found = roster_kintone_find_group_records($groupCode);
    $show($found['records'][0]);
}

$step('5. kintone の名簿を読み込んで変更 → 再提出 → accept → 反映（更新）');
$case = roster_case_find($pdo, $form['id'], 2026, $groupCode);
roster_group_load_kintone($form, $groupCode, (string)$group['group_name'], (int)$case['draft_version']);
$rows = roster_group_state($form, $groupCode)['draft']['rows'];
$before = roster_kintone_find_group_records($groupCode)['records'][0];
foreach ($rows as &$r) {
    if ($r['student_id'] === 'TEST003') {
        $r['membership_status'] = '退部';
    }
    if ($r['student_id'] === 'TEST002') {
        $r['role'] = '副代表者';
    }
    if ($r['membership_status'] === '') {
        $r['membership_status'] = '在籍';
    }
}
unset($r);
$rows[] = ['key' => 'd', 'membership_status' => '入部', 'name' => '連携テスト 四郎', 'student_id' => 'TEST004', 'role' => '-', 'phone_number' => '', 'uses_group_email' => false, 'is_website_editor' => false];
$s2 = $submit($rows);
$a2 = $accept($s2['case_id'], $s2['revision_id']);
$p2 = $runJob($a2['job_id']);
$after = roster_kintone_find_group_records($groupCode)['records'][0];
$show($after);
$keptIds = array_intersect(array_column($before['rows'], 'kintone_row_id'), array_column($after['rows'], 'kintone_row_id'));
echo '  同じレコード: ' . var_export($before['record_id'] === $after['record_id'], true) . ' / 既存の行ID ' . count($keptIds) . '/' . count($before['rows']) . " 件を維持 / リビジョン {$before['revision']} → {$after['revision']}\n";

$step('6. accept 後に kintone を直接編集 → 反映は競合で停止（上書きしない）');
$s3 = $submit(array_map(static fn(array $r): array => $r['student_id'] === 'TEST004' ? ['phone_number' => '090-0000-0004'] + $r : $r, roster_json_decode((string)roster_revision_by_id($pdo, $s2['revision_id'])['payload_json'])['rows']));
$a3 = $accept($s3['case_id'], $s3['revision_id']);
$rec = roster_kintone_find_group_records($groupCode)['records'][0];
$table = [];
foreach ($rec['raw_rows'] as $raw) {
    $values = roster_kintone_copy_row_values($raw);
    if ($values['student_id']['value'] === 'TEST001') {
        $values['role_name'] = ['value' => '主将（kintone で直接入力）'];
    }
    $table[] = ['id' => $raw['id'], 'value' => $values];
}
roster_kintone_update_record($rec['record_id'], $rec['revision'], $table);
echo "  kintone 側で TEST001 の職名を直接変更（担当者の直接編集を想定）\n";
$p3 = $runJob($a3['job_id']);
$final = roster_kintone_find_group_records($groupCode)['records'][0];
$roleName = array_column($final['rows'], 'role_name', 'student_id')['TEST001'] ?? '';
echo '  直接編集の内容は保持: ' . var_export($roleName === '主将（kintone で直接入力）', true) . "\n";
roster_staff_revoke($s3['case_id'], $a3['job_id'], '連携テスト：競合の確認後に取り消し', $staff);
echo "  accept を取り消し（ジョブ失効）\n";

echo "\n完了\n";
