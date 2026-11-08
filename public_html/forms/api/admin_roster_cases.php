<?php

declare(strict_types=1);

/**
 * Forms 管理画面: 部員名簿フォームの提出一覧（表示のみ）。
 * 差し戻し・accept・反映の操作は kintone管理サイト（承認の正本）で行う。ここでは状態を二重管理しない。
 */

require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
require_once __DIR__ . '/../../../apps/roster_core/bootstrap.php';
forms_bootstrap();
api_require_admin();

$formId = (int)($_GET['form_id'] ?? 0);
$form = $formId > 0 ? forms_load_form($formId) : null;
if (!is_array($form)) {
    json_response(['ok' => false, 'message' => 'フォームが見つかりません。'], 404);
}
$roster = roster_form_settings((array)($form['settings'] ?? []));
if (!$roster['is_roster']) {
    json_response(['ok' => false, 'message' => '部員名簿フォームではありません。'], 422);
}

try {
    $cases = roster_staff_list(['form_id' => $formId]);
    $base = trim((string)roster_config('kintone_site_base_url', ''));
    $items = [];
    $counts = ['total' => 0, 'submitted' => 0, 'returned' => 0, 'accepted' => 0, 'attention' => 0];
    foreach ($cases as $case) {
        $counts['total']++;
        if ((string)$case['review_state'] === ROSTER_REVIEW_SUBMITTED) {
            $counts['submitted']++;
        } elseif ((string)$case['review_state'] === ROSTER_REVIEW_RETURNED) {
            $counts['returned']++;
        } elseif ((string)$case['review_state'] === ROSTER_REVIEW_ACCEPTED) {
            $counts['accepted']++;
        }
        if (in_array((string)$case['apply_state'], [ROSTER_JOB_FAILED, ROSTER_JOB_CONFLICT, ROSTER_JOB_UNKNOWN], true)) {
            $counts['attention']++;
        }
        $items[] = [
            'case_id' => (int)$case['id'],
            'fiscal_year' => (int)$case['fiscal_year'],
            'group_code' => (string)$case['group_code'],
            'group_name' => (string)$case['group_name'],
            'review_state' => (string)$case['review_state'],
            'review_label' => roster_review_state_label((string)$case['review_state']),
            'apply_state' => (string)$case['apply_state'],
            'apply_label' => roster_apply_state_label((string)$case['apply_state']),
            'revision_number' => (int)$case['latest_revision_number'],
            'last_submitted_at' => (string)($case['last_submitted_at'] ?? ''),
            'last_applied_at' => (string)($case['last_applied_at'] ?? ''),
            'has_draft' => (bool)$case['has_draft'],
            'detail_url' => $base !== '' ? rtrim($base, '/') . '/roster_detail.php?case_id=' . (int)$case['id'] : '',
        ];
    }
    json_response([
        'ok' => true,
        'fiscal_year' => $roster['fiscal_year'],
        'cases' => $items,
        'counts' => $counts,
        'kintone_site_base_url' => $base,
    ]);
} catch (Throwable $e) {
    error_log('[forms admin_roster_cases] ' . $e->getMessage());
    json_response(['ok' => false, 'message' => '名簿の提出一覧を取得できませんでした。'], 500);
}
