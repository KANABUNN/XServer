<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
require_once __DIR__ . '/../../../apps/response_limit.php';

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
    error_log('[forms public_forms rate_limit] ' . (string)$e);
    json_response(['ok' => false, 'message' => '現在フォーム一覧を取得できません。時間をおいて再試行してください。'], 500);
}

try {
    forms_bootstrap();
    json_response([
        'ok' => true,
        'forms' => forms_public_forms_payload(),
    ]);
} catch (Throwable $e) {
    error_log('[forms public_forms] ' . (string)$e);
    json_response(['ok' => false, 'message' => 'フォーム一覧の取得に失敗しました。時間をおいて再試行してください。'], 500);
}
