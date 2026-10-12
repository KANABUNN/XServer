<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
require_once __DIR__ . '/../../../apps/response_limit.php';

function public_forms_with_closed_rank(array $form): int
{
    $availability = is_array($form['availability'] ?? null) ? $form['availability'] : [];
    $status = strtolower((string)($availability['status'] ?? ''));
    $isOpen = (bool)($availability['is_open'] ?? false);

    if ($isOpen || in_array($status, ['open', 'always_open', 'available', 'active'], true)) {
        return 0;
    }
    if ($status === 'scheduled') {
        return 1;
    }
    if ($status === 'closed') {
        return 2;
    }
    return 3;
}

function public_forms_with_closed_summary(array $forms): array
{
    $summary = [
        'total' => count($forms),
        'open' => 0,
        'scheduled' => 0,
        'closed' => 0,
        'other' => 0,
    ];

    foreach ($forms as $form) {
        $rank = public_forms_with_closed_rank($form);
        if ($rank === 0) {
            $summary['open']++;
        } elseif ($rank === 1) {
            $summary['scheduled']++;
        } elseif ($rank === 2) {
            $summary['closed']++;
        } else {
            $summary['other']++;
        }
    }

    return $summary;
}

try {
    rate_limit_or_throw(
        get_client_ip(),
        __DIR__ . '/../../../apps/rate_limit_forms_public_forms.json',
        120,
        60
    );
} catch (Throwable $e) {
    if ($e->getMessage() === '短時間に送信が多すぎます。時間をおいて再試行してください。') {
        json_response(['ok' => false, 'message' => '短時間にアクセスが多すぎます。時間をおいて再試行してください。'], 429);
    }
    error_log('[forms public_forms_with_closed rate_limit] ' . (string)$e);
    json_response(['ok' => false, 'message' => '現在フォーム一覧を取得できません。時間をおいて再試行してください。'], 500);
}

try {
    forms_bootstrap();

    $visibleForms = [];
    foreach (forms_fetch_forms(true) as $form) {
        $form['fields'] = array_values(array_filter($form['fields'], static function (array $field): bool {
            return !empty($field['is_enabled']);
        }));
        $visibleForms[] = $form;
    }

    usort($visibleForms, static function (array $a, array $b): int {
        $rankCompare = public_forms_with_closed_rank($a) <=> public_forms_with_closed_rank($b);
        if ($rankCompare !== 0) {
            return $rankCompare;
        }

        $sortCompare = ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0));
        if ($sortCompare !== 0) {
            return $sortCompare;
        }

        return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    });

    json_response([
        'ok' => true,
        'forms' => $visibleForms,
        'summary' => public_forms_with_closed_summary($visibleForms),
    ]);
} catch (Throwable $e) {
    error_log('[forms public_forms_with_closed] ' . (string)$e);
    json_response(['ok' => false, 'message' => 'フォーム一覧の取得に失敗しました。時間をおいて再試行してください。'], 500);
}
