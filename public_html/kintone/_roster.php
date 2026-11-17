<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
require_once dirname(__DIR__, 2) . '/apps/roster_core/bootstrap.php';

function kt_roster_badge(string $label, string $tone, string $title = ''): string
{
    return '<span class="badge badge--dot' . ($tone !== '' ? ' badge--' . $tone : '') . '"' . ($title !== '' ? ' title="' . kintone_h($title) . '"' : '') . '>' . kintone_h($label) . '</span>';
}

function kt_roster_review_badge(string $state): string
{
    $tone = match ($state) {
        ROSTER_REVIEW_SUBMITTED => 'info',
        ROSTER_REVIEW_RETURNED => 'warning',
        ROSTER_REVIEW_ACCEPTED => 'accent',
        default => '',
    };
    return kt_roster_badge(roster_review_state_label($state), $tone, $state);
}

function kt_roster_apply_badge(string $state): string
{
    $tone = match ($state) {
        ROSTER_JOB_QUEUED, ROSTER_JOB_RUNNING => 'info',
        ROSTER_JOB_APPLIED => 'success',
        ROSTER_JOB_FAILED => 'danger',
        ROSTER_JOB_CONFLICT, ROSTER_JOB_UNKNOWN => 'warning',
        default => '',
    };
    return kt_roster_badge(roster_apply_state_label($state), $tone, $state);
}

function kt_roster_actor(array $user): array
{
    return [
        'id' => (int)($user['id'] ?? 0),
        'login_id' => (string)($user['login_id'] ?? ''),
        'display_name' => (string)($user['display_name'] ?? $user['login_id'] ?? ''),
    ];
}

function kt_roster_bool_cell(?bool $value, string $on): string
{
    if ($value === null) {
        return '<span class="text-subtle" title="kintone に項目がないか未設定">未設定</span>';
    }
    return $value ? kintone_h($on) : '<span class="text-subtle">—</span>';
}

/** 提出内容（読み取り専用） */
function kt_roster_rows_table(array $rows): string
{
    if ($rows === []) {
        return '<div class="empty"><span>部員はいません。</span></div>';
    }
    $html = '<div class="table-wrap is-stacked kt-table-flat"><table class="table table--compact"><thead><tr><th>No.</th><th>状態</th><th>氏名</th><th>学籍番号</th><th>役職</th><th>電話番号</th><th>団体メール</th><th>HP更新</th><th>kintone行</th></tr></thead><tbody>';
    foreach (array_values($rows) as $i => $r) {
        $html .= '<tr>'
            . '<td class="num">' . ($i + 1) . '</td>'
            . '<td>' . kintone_h((string)$r['membership_status']) . '</td>'
            . '<td class="allow-select">' . kintone_h((string)$r['name']) . '</td>'
            . '<td class="mono allow-select">' . kintone_h((string)$r['student_id']) . '</td>'
            . '<td>' . kintone_h(roster_role_label((string)$r['role'])) . '</td>'
            . '<td class="mono allow-select">' . kintone_h((string)$r['phone_number']) . '</td>'
            . '<td>' . kt_roster_bool_cell((bool)$r['uses_group_email'], '使用') . '</td>'
            . '<td>' . kt_roster_bool_cell((bool)$r['is_website_editor'], '担当') . '</td>'
            . '<td class="mono text-xs text-subtle">' . kintone_h((string)($r['kintone_row_id'] ?? '') ?: '新規') . '</td>'
            . '</tr>';
    }
    return $html . '</tbody></table></div>';
}

function kt_roster_kind_badge(string $kind): string
{
    return match ($kind) {
        'added' => kt_roster_badge('追加', 'success'),
        'changed' => kt_roster_badge('変更', 'info'),
        'unchanged' => kt_roster_badge('変更なし', ''),
        'ambiguous' => kt_roster_badge('対応不明', 'danger'),
        default => kt_roster_badge($kind, ''),
    };
}

/** kintone 現在値との差分表 */
function kt_roster_diff_table(array $diff, bool $showUnchanged = false): string
{
    $entries = array_values(array_filter($diff['entries'], static fn(array $e): bool => $showUnchanged || $e['kind'] !== 'unchanged'));
    $html = '';
    if ($entries === []) {
        $html .= '<p class="small-note">kintone の現在の名簿から変更はありません。</p>';
    } else {
        $html .= '<div class="table-wrap is-stacked kt-table-flat"><table class="table table--compact"><thead><tr><th>No.</th><th>区分</th><th>氏名・学籍番号</th><th>状態・役職</th><th>変更内容（kintone 現在値 → 提出）</th></tr></thead><tbody>';
        foreach ($entries as $e) {
            $flags = '';
            if (!empty($e['student_id_corrected'])) {
                $flags .= ' ' . kt_roster_badge('学籍番号の訂正', 'warning');
            }
            if (!empty($e['kintone_changed'])) {
                $flags .= ' ' . kt_roster_badge('団体の読込後に kintone で変更あり', 'danger');
            }
            if ($e['kind'] === 'ambiguous') {
                $changes = '<span class="text-danger">学籍番号が一致する kintone の行が複数あるため、どの行を更新するか決められません。</span>';
            } elseif ($e['kind'] === 'added') {
                $changes = '<span class="text-subtle">新しい行として追加</span>';
            } else {
                $changes = '';
                foreach ($e['changes'] as $c) {
                    $changes .= '<div' . ($c['kintone_changed'] ? ' class="text-danger" title="団体が名簿を読み込んだ後に kintone 側で変更された項目です。accept すると提出内容で上書きされます。"' : '') . '>'
                        . kintone_h($c['label']) . '：<span class="kt-old"><span>' . kintone_h($c['from']) . '</span></span> → <span class="kt-new">' . kintone_h($c['to']) . '</span></div>';
                }
                if ($changes === '') {
                    $changes = '<span class="text-subtle">—</span>';
                }
            }
            $html .= '<tr><td class="num">' . (int)$e['no'] . '</td>'
                . '<td><span class="kt-badges">' . kt_roster_kind_badge($e['kind']) . $flags . '</span></td>'
                . '<td><span class="cell-main allow-select">' . kintone_h($e['name']) . '</span><span class="cell-sub mono allow-select">' . kintone_h($e['student_id']) . '</span></td>'
                . '<td>' . kintone_h($e['status']) . '<span class="cell-sub">' . kintone_h($e['role_label']) . ($e['role_name'] !== '' ? '（職名: ' . kintone_h($e['role_name']) . '・保持）' : '') . '</span></td>'
                . '<td>' . $changes . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
    if ($diff['kept'] !== []) {
        $html .= '<details class="kt-roster-kept"><summary>提出に含まれない kintone の既存行（' . count($diff['kept']) . '行・削除せずに残します）</summary><ul>';
        foreach ($diff['kept'] as $k) {
            $html .= '<li class="allow-select">' . kintone_h($k['name']) . ' <span class="mono">' . kintone_h($k['student_id']) . '</span> ' . kintone_h($k['status'] !== '' ? $k['status'] : '状態未設定') . ' ・ ' . kintone_h($k['role_label']) . '</li>';
        }
        $html .= '</ul></details>';
    }
    return $html;
}

/** 第2段階の比較（読み取りのみ）。詳細な人物属性は operator 以上にだけ表示する。 */
function kt_roster_officer_plan_html(array $plan, bool $canSeePersonal): string
{
    if (empty($plan['ok'])) {
        return '<div class="alert alert--danger">' . fitsc_icon('alert-circle') . '<div class="alert__body">' . kintone_h((string)$plan['error']) . '</div></div>';
    }
    $list = static function (array $messages): string {
        $html = '<ul class="kt-plain-list">';
        foreach ($messages as $message) {
            $html .= '<li>' . kintone_h((string)$message) . '</li>';
        }
        return $html . '</ul>';
    };
    $html = '';
    if ($plan['blockers'] !== []) {
        $html .= '<div class="alert alert--danger">' . fitsc_icon('lock') . '<div class="alert__body"><strong>解消が必要な項目</strong>' . $list($plan['blockers']) . '</div></div>';
    } else {
        $html .= '<div class="alert alert--success">' . fitsc_icon('check-circle') . '<div class="alert__body">登録者・役員台帳との比較で、解消が必要な項目はありません。</div></div>';
    }
    if ($plan['warnings'] !== []) {
        $html .= '<div class="alert alert--warning">' . fitsc_icon('alert-triangle') . '<div class="alert__body">' . $list($plan['warnings']) . '</div></div>';
    }
    $ledger = $plan['ledger'];
    $ledgerLabel = match ($ledger['operation']) {
        'create' => '新規作成（現行レコードなし）',
        'update' => '更新（レコード ID ' . (int)$ledger['record_id'] . ' ・ リビジョン ' . (int)$ledger['revision'] . ' ・ 登録日 ' . ((string)$ledger['register_date'] ?: '—') . '）',
        default => '要確認（' . (int)$ledger['record_count'] . '件：ID ' . implode(', ', array_map('intval', $ledger['record_ids'])) . '）',
    };
    $dateLabel = match ($ledger['register_date_mode']) {
        'accept_date' => '変更時はaccept日を使用（' . ($ledger['operation'] === 'create' ? '初回登録' : '今回は幹部構成に変更あり') . '）',
        'keep' => '幹部構成に変更がないため既存の日付を維持',
        default => '現行レコードを確定するまで判定しません',
    };
    $html .= '<p class="small-note">' . fitsc_icon('layers', 'ico--sm') . '役員台帳（ID21）：' . kintone_h($ledgerLabel) . '<br>' . fitsc_icon('calendar', 'ico--sm') . '登録日：' . kintone_h($dateLabel) . '</p>';

    $html .= '<div class="table-wrap is-stacked kt-table-flat"><table class="table table--compact"><thead><tr><th>役員枠</th><th>現在の役員台帳</th><th>提出</th><th>操作</th><th>確認事項</th></tr></thead><tbody>';
    foreach ($plan['slots'] as $slot) {
        $person = static function (string $sid, string $name, string $step = '') use ($canSeePersonal): string {
            if ($sid === '') {
                return '<span class="text-subtle">—</span>';
            }
            if (!$canSeePersonal) {
                return $step !== '' ? kintone_h($step) : '登録あり';
            }
            return '<span class="cell-main allow-select">' . kintone_h($name) . '</span><span class="cell-sub mono allow-select">' . kintone_h($sid) . '</span>';
        };
        $tone = match ($slot['operation']) {
            'replace', 'set' => 'info',
            'clear' => 'warning',
            default => '',
        };
        $html .= '<tr><td>' . kintone_h($slot['label']) . ($slot['required'] ? ' <span class="req">必須</span>' : '') . '</td>'
            . '<td>' . $person($slot['current_student_id'], $slot['current_name']) . '</td>'
            . '<td>' . $person($slot['planned_student_id'], $slot['planned_name'], $slot['planned_step']) . '</td>'
            . '<td>' . kt_roster_badge(roster_officer_slot_operation_label($slot['operation']), $tone) . '</td>'
            . '<td>' . ($slot['flags'] !== [] ? '<span class="text-warning">' . kintone_h(implode('・', $slot['flags'])) . '</span>' : '<span class="text-subtle">—</span>') . '</td></tr>';
    }
    $html .= '</tbody></table></div>';

    if ($plan['people'] !== []) {
        $html .= '<div class="table-wrap is-stacked kt-table-flat"><table class="table table--compact"><thead><tr><th>番号</th><th>部員</th><th>担当（兼務）</th><th>登録者（ID22）との照合</th><th>登録者の内容</th><th>確認事項</th></tr></thead><tbody>';
        foreach ($plan['people'] as $p) {
            $tone = match ($p['operation']) {
                'reference' => 'success',
                'create' => 'info',
                'needs_info' => 'warning',
                default => 'danger',
            };
            $notes = '';
            if ($canSeePersonal) {
                foreach ($p['differences'] as $d) {
                    $notes .= '<div>' . kintone_h($d['label']) . '：<span class="text-subtle">' . kintone_h($d['kintone']) . '</span> ／ 申告・名簿 <strong>' . kintone_h($d['submitted']) . '</strong></div>';
                }
            } elseif ($p['differences'] !== []) {
                $notes .= '<div>相違あり（' . count($p['differences']) . '項目）</div>';
            }
            if ($p['missing'] !== []) {
                $notes .= '<div class="text-warning">不足：' . kintone_h(implode('・', $p['missing'])) . '</div>';
            }
            foreach ($p['notes'] as $note) {
                $notes .= '<div>' . kintone_h($note) . '</div>';
            }
            $k = $p['kintone'];
            $kintoneCell = '<span class="text-subtle">未登録</span>';
            if (is_array($k)) {
                $kintoneCell = $canSeePersonal
                    ? '<span class="cell-main allow-select">' . kintone_h($k['name']) . '</span><span class="cell-sub allow-select">' . kintone_h($k['mail']) . ' ・ ' . kintone_h($k['depend']) . ' ・ ' . kintone_h($k['type']) . ' ・ 執行・議会組織 ' . kintone_h($k['org_depend_state'] !== '' ? $k['org_depend_state'] : '—') . '</span>'
                    : '登録あり';
            }
            $html .= '<tr><td class="mono text-xs">' . kintone_h($p['step_key']) . '</td>'
                . '<td>' . ($canSeePersonal ? '<span class="cell-main allow-select">' . kintone_h($p['roster_name']) . '</span><span class="cell-sub mono allow-select">' . kintone_h($p['student_id']) . '</span>' : '<span class="text-subtle">（担当者のみ表示）</span>') . '</td>'
                . '<td>' . kintone_h(implode('・', $p['holds'])) . ($canSeePersonal ? '<span class="cell-sub">名簿の役職：' . kintone_h($p['primary_role']) . '</span>' : '') . '</td>'
                . '<td>' . kt_roster_badge(roster_officer_person_operation_label($p['operation']), $tone) . '</td>'
                . '<td>' . $kintoneCell . '</td>'
                . '<td>' . ($notes !== '' ? $notes : '<span class="text-subtle">—</span>') . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
    $html .= '<p class="small-note mono">計画の指紋 ' . kintone_h(substr((string)$plan['fingerprint'], 0, 16)) . '</p>';
    return $html;
}

function kt_roster_flash_redirect(string $location, string $type, string $message): never
{
    kintone_set_flash($type, $message);
    header('Location: ' . $location, true, 302);
    exit;
}
