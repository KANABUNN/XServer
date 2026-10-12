<?php
require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
require_once __DIR__ . '/../../../apps/response_limit.php';

function forms_period_unavailable_public_message(array $availability): string
{
    $status = strtolower((string)($availability['status'] ?? ''));
    if ($status === 'scheduled') {
        return 'このフォームは提出期間前です。';
    }
    if ($status === 'closed') {
        return "このフォームの受け付けは終了しました。\n提出物がある際はメールにて連絡してください。\nsogokanri@bene.fit.ac.jp";
    }
    return (string)($availability['note'] ?? '現在このフォームは受付できません。');
}

function forms_period_unavailable_message_from_exception(Throwable $e): string
{
    $message = $e->getMessage();
    if (str_contains($message, '受付開始前')) {
        return 'このフォームは提出期間前です。';
    }
    if (str_contains($message, '公開期間は終了') || str_contains($message, '受付終了')) {
        return "このフォームの受け付けは終了しました。\n提出物がある際はメールにて連絡してください。\nsogokanri@bene.fit.ac.jp";
    }
    return $message !== '' ? $message : '現在このフォームは受付できません。';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['ok' => false, 'message' => 'POST のみ許可されています。'], 405);
}

// post_max_size を超過すると PHP は $_POST / $_FILES を空にするため、
// CSRF エラーに落とさず容量超過として返す。
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
    json_response([
        'ok' => false,
        'message' => 'アップロード容量がPHPの上限を超えている可能性があります。添付ファイルのサイズを小さくして再送信してください。',
    ], 413);
}

try {
    rate_limit_or_throw(
        get_client_ip(),
        __DIR__ . '/../../../apps/rate_limit_forms_submit.json',
        5,
        300
    );
} catch (Throwable $e) {
    if ($e->getMessage() === '短時間に送信が多すぎます。時間をおいて再試行してください。') {
        json_response(['ok' => false, 'message' => $e->getMessage()], 429);
    }
    error_log('[forms submit rate_limit] ' . (string)$e);
    json_response(['ok' => false, 'message' => '現在送信を受け付けられません。時間をおいて再試行してください。'], 500);
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    json_response([
        'ok' => false,
        'message' => 'セッションの有効期限が切れた可能性があります。入力内容は下書きに残したまま、ページを再読み込みしてから再送信してください。',
        'csrf_expired' => true,
        'reload_required' => true,
    ], 419);
}

try {
    forms_bootstrap();

    $formId = (int)($_POST['form_id'] ?? 0);
    $form = forms_load_form($formId, true);
    if (!is_array($form)) {
        json_response(['ok' => false, 'message' => '対象フォームが見つかりません。'], 404);
        exit;
    }
    if (!forms_is_publicly_available($form)) {
        $availability = forms_public_period_context($form);
        json_response(['ok' => false, 'message' => forms_period_unavailable_public_message($availability)], 403);
    }

    $validation = forms_validate_submission($form, $_POST, $_FILES);
    $validationErrors = (array)($validation['errors'] ?? []);
    if ($validationErrors !== []) {
        $validationMessages = $validation['error_messages'] ?? null;
        $messages = is_array($validationMessages) ? $validationMessages : array_values($validationErrors);
        json_response([
            'ok' => false,
            'message' => implode("\n", $messages),
            'errors' => $validationErrors,
        ], 422);
    }

    $validationData = is_array($validation['data'] ?? null) ? $validation['data'] : [];
    $saved = [];
    try {
        $saved = forms_save_submission($form, $validationData);
    } catch (InvalidArgumentException $e) {
        error_log('[forms submit save invalid] ' . (string)$e);
        $status = str_contains($e->getMessage(), '見つかりません') ? 404 : 422;
        json_response(['ok' => false, 'message' => $status === 404 ? '対象フォームが見つかりません。' : '送信内容を確認してください。'], $status);
    } catch (DomainException $e) {
        error_log('[forms submit save closed] ' . (string)$e);
        json_response(['ok' => false, 'message' => forms_period_unavailable_message_from_exception($e)], 403);
    } catch (Throwable $e) {
        error_log('[forms submit save] ' . (string)$e);
        json_response(['ok' => false, 'message' => '送信の保存に失敗しました。時間をおいて再試行してください。'], 500);
    }

    $savedStatus = (string)($saved['status'] ?? '');
    json_response([
        'ok' => true,
        'status' => $savedStatus,
        'message' => $savedStatus === 'updated'
            ? '同じメールアドレスと団体名の組み合わせのため、履歴を残して最新データへ更新しました。'
            : ((string)($form['settings']['completion_message'] ?? '送信を受け付けました。')),
        'submission_id' => (int)($saved['submission_id'] ?? 0),
        'revision_number' => (int)($saved['revision_number'] ?? 0),
    ]);
} catch (Throwable $e) {
    error_log('[forms submit] ' . (string)$e);
    json_response(['ok' => false, 'message' => '送信処理中にエラーが発生しました。時間をおいて再試行してください。'], 500);
}
