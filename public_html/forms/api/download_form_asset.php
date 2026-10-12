<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
require_once __DIR__ . '/../../../apps/forms_distribution_multi.php';
require_once __DIR__ . '/../../../apps/response_limit.php';

function forms_asset_error(string $message, int $status = 404): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

try {
    rate_limit_or_throw(
        get_client_ip(),
        __DIR__ . '/../../../apps/rate_limit_forms_download_asset.json',
        30,
        300
    );
} catch (Throwable $e) {
    if ($e->getMessage() === '短時間に送信が多すぎます。時間をおいて再試行してください。') {
        forms_asset_error('短時間にダウンロードが多すぎます。時間をおいて再試行してください。', 429);
    }
    error_log('[forms download_form_asset rate_limit] ' . (string)$e);
    forms_asset_error('現在配布ファイルを取得できません。時間をおいて再試行してください。', 500);
}

try {
    forms_bootstrap();

    $formId = (int)($_GET['form_id'] ?? 0);
    $fileId = trim((string)($_GET['file_id'] ?? ''));
    if ($formId < 1) {
        forms_asset_error('配布ファイルが見つかりません。', 404);
    }

    $form = forms_load_form($formId, true);
    if (!$form || !forms_is_publicly_available($form)) {
        forms_asset_error('配布ファイルが見つかりません。', 404);
    }

    $settings = $form['settings'] ?? [];
    if (empty($settings['distribution_enabled']) || forms_distmulti_files_from_form($form) === []) {
        forms_asset_error('配布ファイルが設定されていません。', 404);
    }

    forms_distmulti_output_file_by_id($form, $fileId !== '' ? $fileId : null);
} catch (Throwable $e) {
    error_log('[forms download_form_asset] ' . (string)$e);
    forms_asset_error('配布ファイルが見つかりません。', 404);
}
