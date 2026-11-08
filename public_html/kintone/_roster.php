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

function kt_roster_flash_redirect(string $location, string $type, string $message): never
{
    kintone_set_flash($type, $message);
    header('Location: ' . $location, true, 302);
    exit;
}
