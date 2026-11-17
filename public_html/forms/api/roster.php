<?php

declare(strict_types=1);

/**
 * 部員名簿フォーム（利用者側）API
 *
 * GET  ?action=directory&form_id=      団体一覧（識別記号・団体名のみ）
 * GET  ?action=state&form_id=          確認済み団体の名簿の状態・下書き・提出履歴
 * POST {action: request_code}          ID7 の団体メールへ確認コードを送る
 * POST {action: verify_code}           確認コードを照合し、この団体の名簿を扱えるようにする
 * POST {action: logout}                団体確認を終了する
 * POST {action: save_draft}            下書きをサーバーに保存する
 * POST {action: load_kintone}          kintone の現在の名簿を下書きへ読み込む
 * POST {action: edit_from_latest}      最新の提出内容から下書きを作る
 * POST {action: preview}               提出前の確認（不足項目・差分）
 * POST {action: submit}                提出（新しいリビジョン。kintone へは書き込まない）
 */

require_once __DIR__ . '/../../../apps/forms_core/bootstrap.php';
require_once __DIR__ . '/../../../apps/response_limit.php';
require_once __DIR__ . '/../../../apps/roster_core/bootstrap.php';

function roster_api_fail(string $message, int $status = 400, array $extra = []): never
{
    json_response(['ok' => false, 'message' => $message] + $extra, $status);
    exit;
}

function roster_api_form(int $formId, bool $requireOpen): array
{
    forms_bootstrap();
    $form = forms_load_form($formId, true);
    if (!is_array($form)) {
        roster_api_fail('対象フォームが見つかりません。', 404);
    }
    $roster = roster_form_settings((array)($form['settings'] ?? []));
    if (!$roster['is_roster']) {
        roster_api_fail('このフォームは部員名簿フォームではありません。', 404);
    }
    if ($requireOpen && !forms_is_publicly_available($form)) {
        $availability = forms_public_period_context($form);
        roster_api_fail(($availability['status'] ?? '') === 'scheduled' ? 'このフォームは提出期間前です。' : 'このフォームの受け付けは終了しました。', 403);
    }
    return ['id' => (int)$form['id'], 'name' => (string)$form['name'], 'settings' => (array)$form['settings'], 'roster' => $roster];
}

function roster_api_require_group(int $formId): array
{
    $session = roster_session_group($_SESSION, $formId);
    if ($session === null) {
        unset($_SESSION['roster_auth'][$formId]);
        roster_api_fail('団体の確認が必要です（確認の有効期限が切れた可能性があります）。もう一度団体確認を行ってください。', 401, ['auth_required' => true]);
    }
    return $session;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$body = $method === 'POST' ? request_json() : [];
$action = (string)($method === 'POST' ? ($body['action'] ?? '') : ($_GET['action'] ?? ''));
$formId = (int)($method === 'POST' ? ($body['form_id'] ?? 0) : ($_GET['form_id'] ?? 0));

try {
    rate_limit_or_throw(get_client_ip(), __DIR__ . '/../../../apps/rate_limit_forms_roster.json', $method === 'POST' ? 60 : 120, 60);
} catch (Throwable $e) {
    roster_api_fail('短時間に操作が多すぎます。少し時間をおいて再度お試しください。', 429);
}

if ($method === 'POST' && !verify_csrf((string)($body['csrf_token'] ?? ''))) {
    roster_api_fail('セッションの有効期限が切れた可能性があります。ページを再読み込みしてください（下書きはサーバーに保存されています）。', 419, ['reload_required' => true]);
}
if (!roster_config('enabled', true)) {
    roster_api_fail('部員名簿の受付は現在停止しています。', 503);
}

try {
    switch ($method . ' ' . $action) {
        case 'GET directory':
            $form = roster_api_form($formId, true);
            json_response(['ok' => true, 'groups' => roster_directory_public_list()]);
            break;

        case 'POST request_code':
            $form = roster_api_form($formId, true);
            $result = roster_verification_request($form, (string)($body['group_code'] ?? ''), session_id(), get_client_ip());
            $_SESSION['roster_pending'][$formId] = (int)$result['verification_id'];
            json_response([
                'ok' => true,
                'message' => '確認コードを団体メール（' . $result['email_masked'] . '）へ送信しました。',
                'email_masked' => $result['email_masked'],
                'group_code' => $result['group_code'],
                'group_name' => $result['group_name'],
                'expires_at' => $result['expires_at'],
            ]);
            break;

        case 'POST verify_code':
            $form = roster_api_form($formId, true);
            $pendingId = (int)($_SESSION['roster_pending'][$formId] ?? 0);
            if ($pendingId < 1) {
                roster_api_fail('先に確認コードを送信してください。', 409);
            }
            $verified = roster_verification_confirm($form, $pendingId, (string)($body['code'] ?? ''), session_id());
            session_regenerate_id(true);
            unset($_SESSION['roster_pending'][$formId]);
            $_SESSION['roster_auth'][$formId] = [
                'group_code' => $verified['group_code'],
                'verification_id' => $verified['verification_id'],
                'email_masked' => $verified['email_masked'],
                'verified_at' => time(),
                'expires_at' => time() + max(10, (int)roster_config('verification.session_ttl_minutes', 240)) * 60,
            ];
            json_response(['ok' => true, 'message' => '団体を確認しました。', 'group_code' => $verified['group_code'], 'group_name' => $verified['group_name']]);
            break;

        case 'POST logout':
            unset($_SESSION['roster_auth'][$formId], $_SESSION['roster_pending'][$formId]);
            json_response(['ok' => true]);
            break;

        case 'GET state':
            $form = roster_api_form($formId, false);
            $session = roster_session_group($_SESSION, $formId);
            if ($session === null) {
                json_response(['ok' => true, 'authenticated' => false, 'fiscal_year' => $form['roster']['fiscal_year']]);
            }
            $state = roster_group_state($form, (string)$session['group']['group_code']);
            json_response([
                'ok' => true,
                'authenticated' => true,
                'group' => [
                    'code' => (string)$session['group']['group_code'],
                    'name' => (string)$session['group']['group_name'],
                    'rank' => (string)$session['group']['group_rank'],
                    'email_masked' => (string)($session['auth']['email_masked'] ?? ''),
                    'auth_expires_at' => date('Y-m-d H:i', (int)$session['auth']['expires_at']),
                ],
                'options' => [
                    'statuses' => roster_status_options(),
                    'active_statuses' => roster_active_statuses(),
                    'roles' => roster_role_definitions(),
                    'officer_slots' => array_map(static fn(array $d): array => ['key' => $d['key'], 'label' => $d['label'], 'role' => $d['role'], 'required' => $d['required']], roster_officer_slot_definitions()),
                    'depend_options' => roster_officer_depend_options(),
                    'student_types' => roster_officer_type_options(),
                    'org_depend_states' => roster_officer_org_depend_options(),
                    'officer_min_active_members' => roster_officer_min_active_members(),
                ],
            ] + $state);
            break;

        case 'POST save_draft':
            $form = roster_api_form($formId, true);
            $session = roster_api_require_group($formId);
            $result = roster_group_save_draft($form, (string)$session['group']['group_code'], (string)$session['group']['group_name'], $body['rows'] ?? null, (int)($body['draft_version'] ?? -1), $body['officers'] ?? null);
            json_response(['ok' => true, 'draft_version' => $result['draft_version'], 'saved_at' => $result['draft_updated_at']]);
            break;

        case 'POST load_kintone':
            $form = roster_api_form($formId, true);
            $session = roster_api_require_group($formId);
            $result = roster_group_load_kintone($form, (string)$session['group']['group_code'], (string)$session['group']['group_name'], (int)($body['draft_version'] ?? -1));
            json_response(['ok' => true] + $result);
            break;

        case 'POST edit_from_latest':
            $form = roster_api_form($formId, true);
            $session = roster_api_require_group($formId);
            $result = roster_group_edit_from_latest($form, (string)$session['group']['group_code'], (int)($body['draft_version'] ?? -1));
            json_response(['ok' => true] + $result);
            break;

        case 'POST preview':
            $form = roster_api_form($formId, true);
            $session = roster_api_require_group($formId);
            json_response(['ok' => true] + roster_group_preview($form, (string)$session['group']['group_code'], $body['rows'] ?? null, $body['officers'] ?? null));
            break;

        case 'POST submit':
            $form = roster_api_form($formId, true);
            $session = roster_api_require_group($formId);
            $result = roster_group_submit($form, $session['group'], $session['auth'], $body['rows'] ?? null, (int)($body['draft_version'] ?? -1), (string)($body['submitter_name'] ?? ''), get_client_ip(), $body['officers'] ?? null);
            json_response([
                'ok' => true,
                'message' => (string)($form['settings']['completion_message'] ?? '') !== '' && (string)$form['settings']['completion_message'] !== '送信を受け付けました。'
                    ? (string)$form['settings']['completion_message']
                    : '部員名簿を提出しました。総合管理事務局が内容を確認します。',
                'revision_number' => $result['revision_number'],
                'draft_version' => $result['draft_version'],
                'counts' => $result['counts'],
                'superseded_job' => $result['superseded_job'],
            ]);
            break;

        default:
            roster_api_fail('不明な操作です。', 400);
    }
} catch (RosterException $e) {
    json_response(['ok' => false, 'message' => $e->getMessage(), 'reason' => $e->reason] + $e->detail, $e->httpStatus);
} catch (Throwable $e) {
    $ref = 'RST-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
    error_log('[forms roster api][' . $ref . '][' . $action . '] ' . get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    json_response(['ok' => false, 'message' => '処理中にエラーが発生しました。時間をおいて再度お試しください（参照ID: ' . $ref . '）。'], 500);
}
