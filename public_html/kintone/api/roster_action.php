<?php

declare(strict_types=1);

/**
 * 部員名簿の審査・反映の操作（POST のみ・operator 以上・CSRF 必須）
 * action: return / accept / revoke / retry / run_now / refresh_groups
 * 権限・対象リビジョン・状態はすべてサーバー側（apps/roster_core）で確認する。ボタンの表示有無には依存しない。
 */

require_once dirname(__DIR__) . '/_roster.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'POST only';
    exit;
}
$user = kintone_auth_require_operator_access();
kintone_auth_require_csrf();
$actor = kt_roster_actor($user);
$action = (string)($_POST['action'] ?? '');
$caseId = (int)($_POST['case_id'] ?? 0);
$back = $caseId > 0 ? '../roster_detail.php?case_id=' . $caseId : '../roster.php';
$ref = kintone_reference_id('RST');

try {
    switch ($action) {
        case 'return':
            $revisionId = (int)($_POST['revision_id'] ?? 0);
            roster_staff_return($caseId, $revisionId, (string)($_POST['reason'] ?? ''), $actor);
            kintone_write_audit_log('kintone.roster.return', 'roster_case', (string)$caseId, ['revision_id' => $revisionId], $user);
            kt_roster_flash_redirect($back, 'success', '差し戻しました。団体の提出画面に理由が表示されます。');

        case 'accept':
            $revisionId = (int)($_POST['revision_id'] ?? 0);
            $result = roster_staff_accept($caseId, $revisionId, (string)($_POST['compare_token'] ?? ''), $actor);
            kintone_write_audit_log('kintone.roster.accept', 'roster_case', (string)$caseId, [
                'revision_id' => $revisionId,
                'acceptance_id' => $result['acceptance_id'],
                'job_id' => $result['job_id'],
                'already' => $result['already'],
            ], $user);
            kt_roster_flash_redirect($back, 'success', $result['already']
                ? 'この版はすでに accept 済みです（ジョブ #' . $result['job_id'] . '）。'
                : 'accept しました。反映ジョブ #' . $result['job_id'] . ' を登録しました。次回の定期実行で kintone へ反映されます。');

        case 'revoke':
            $jobId = (int)($_POST['job_id'] ?? 0);
            roster_staff_revoke($caseId, $jobId, (string)($_POST['reason'] ?? ''), $actor);
            kintone_write_audit_log('kintone.roster.revoke', 'roster_case', (string)$caseId, ['job_id' => $jobId], $user);
            kt_roster_flash_redirect($back, 'success', 'accept を取り消しました。反映ジョブは失効し、提出は確認中に戻りました。');

        case 'retry':
            $jobId = (int)($_POST['job_id'] ?? 0);
            $result = roster_staff_retry($caseId, $jobId, $actor);
            kintone_write_audit_log('kintone.roster.' . $result['action'], 'roster_case', (string)$caseId, ['job_id' => $jobId], $user);
            kt_roster_flash_redirect($back, 'success', $result['action'] === 'retry' ? '再試行を登録しました。次回の定期実行で反映します。' : '次回の定期実行で kintone を照合します。');

        case 'run_now':
            $jobId = (int)($_POST['job_id'] ?? 0);
            $case = roster_case_by_id(roster_db(), $caseId);
            if (!is_array($case) || (int)$case['current_job_id'] !== $jobId) {
                throw new RosterException('対象の反映ジョブが現在のものではありません。', 'stale', 409);
            }
            // 定期実行と同じ処理（ロック・accept の有効性確認を含む）で、このジョブだけを処理する
            $summary = roster_worker_run(['only_job_id' => $jobId, 'skip_directory' => true, 'worker_name' => 'web:' . ($actor['login_id'] ?: 'operator')]);
            kintone_write_audit_log('kintone.roster.run_now', 'roster_case', (string)$caseId, ['job_id' => $jobId, 'status' => $summary['status']], $user);
            if (($summary['status'] ?? '') === 'locked') {
                kt_roster_flash_redirect($back, 'warn', '定期実行が処理中です。しばらくしてから画面を更新してください。');
            }
            $processed = $summary['processed'][0] ?? null;
            if (!is_array($processed)) {
                kt_roster_flash_redirect($back, 'warn', 'このジョブは処理されませんでした（取り消し・失効、または対象外になった可能性があります）。');
            }
            $label = roster_apply_state_label((string)$processed['status']);
            kt_roster_flash_redirect($back, (string)$processed['status'] === ROSTER_JOB_APPLIED ? 'success' : 'warn', '処理しました：' . $label . ((string)($processed['message'] ?? '') !== '' ? '（' . mb_substr((string)$processed['message'], 0, 200, 'UTF-8') . '）' : ''));

        case 'refresh_groups':
            $result = roster_directory_refresh();
            kintone_write_audit_log('kintone.roster.refresh_groups', 'roster_group_directory', null, ['count' => $result['count']], $user);
            kt_roster_flash_redirect('../roster_settings.php', 'success', '団体一覧を更新しました（' . $result['count'] . '団体）。');

        default:
            kt_roster_flash_redirect($back, 'error', '不明な操作です。');
    }
} catch (RosterException $e) {
    kt_roster_flash_redirect($action === 'refresh_groups' ? '../roster_settings.php' : $back, $e->httpStatus >= 500 ? 'error' : 'warn', $e->getMessage());
} catch (RosterKintoneError $e) {
    error_log('[kintone roster action][' . $ref . '][' . $action . '] ' . $e->getMessage());
    kt_roster_flash_redirect($action === 'refresh_groups' ? '../roster_settings.php' : $back, 'error', 'kintone との通信に失敗しました：' . mb_substr($e->getMessage(), 0, 200, 'UTF-8') . '（参照ID: ' . $ref . '）');
} catch (Throwable $e) {
    error_log('[kintone roster action][' . $ref . '][' . $action . '] ' . get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    kt_roster_flash_redirect($action === 'refresh_groups' ? '../roster_settings.php' : $back, 'error', '処理に失敗しました（参照ID: ' . $ref . '）。');
}
