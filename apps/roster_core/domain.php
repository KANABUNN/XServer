<?php

declare(strict_types=1);

/* =====================================================================
   名簿の項目・選択肢・検証・照合・差分（DB・kintone 通信を持たない純粋関数）
   ===================================================================== */

/** 審査状態 */
const ROSTER_REVIEW_DRAFT = 'draft';
const ROSTER_REVIEW_SUBMITTED = 'submitted';
const ROSTER_REVIEW_RETURNED = 'returned';
const ROSTER_REVIEW_ACCEPTED = 'accepted';
/** リビジョン単位の状態（上記に加えて） */
const ROSTER_REVISION_SUPERSEDED = 'superseded';

/** 反映状態（ジョブの状態） */
const ROSTER_APPLY_NONE = 'not_applicable';
const ROSTER_JOB_QUEUED = 'queued';
const ROSTER_JOB_RUNNING = 'running';
const ROSTER_JOB_APPLIED = 'applied';
const ROSTER_JOB_FAILED = 'failed';
const ROSTER_JOB_CONFLICT = 'conflict';
const ROSTER_JOB_UNKNOWN = 'unknown';
const ROSTER_JOB_CANCELLED = 'cancelled';

function roster_review_state_label(string $state): string
{
    return match ($state) {
        ROSTER_REVIEW_DRAFT => '下書き',
        ROSTER_REVIEW_SUBMITTED => '提出済み・確認中',
        ROSTER_REVIEW_RETURNED => '差し戻し',
        ROSTER_REVIEW_ACCEPTED => 'accept済み',
        ROSTER_REVISION_SUPERSEDED => '再提出により置換',
        default => $state,
    };
}

function roster_apply_state_label(string $state): string
{
    return match ($state) {
        ROSTER_APPLY_NONE => '未反映',
        ROSTER_JOB_QUEUED => '反映待ち',
        ROSTER_JOB_RUNNING => '反映中',
        ROSTER_JOB_APPLIED => '反映済み',
        ROSTER_JOB_FAILED => '反映失敗',
        ROSTER_JOB_CONFLICT => '競合・要確認',
        ROSTER_JOB_UNKNOWN => '結果確認待ち',
        ROSTER_JOB_CANCELLED => '取り消し・失効',
        default => $state,
    };
}

function roster_status_options(): array
{
    return ['入部', '在籍', '卒部', '退部'];
}

function roster_active_statuses(): array
{
    return ['入部', '在籍'];
}

/**
 * 役職。value は kintone ID20 list.role の選択肢、label は Excel の表記。
 * order は Excel のプルダウン順（「役職はプルダウンに表示されている順番で表記」）。
 */
function roster_role_definitions(): array
{
    return [
        ['value' => '代表者', 'label' => '部長・主将', 'order' => 1, 'officer' => true],
        ['value' => '会計長', 'label' => '会計長', 'order' => 2, 'officer' => true],
        ['value' => '総務', 'label' => '総務', 'order' => 3, 'officer' => true],
        ['value' => '副代表者', 'label' => '副部長・副主将', 'order' => 4, 'officer' => true],
        ['value' => '副会計長', 'label' => '副会計長', 'order' => 5, 'officer' => true],
        ['value' => 'マネージャー', 'label' => 'マネージャー', 'order' => 6, 'officer' => true],
        ['value' => '-', 'label' => '役職なし', 'order' => 99, 'officer' => false],
    ];
}

function roster_role_values(): array
{
    return array_column(roster_role_definitions(), 'value');
}

function roster_role_label(string $value): string
{
    foreach (roster_role_definitions() as $def) {
        if ($def['value'] === $value) {
            return $def['label'];
        }
    }
    return $value === '' ? '' : $value;
}

function roster_role_order(string $value): int
{
    foreach (roster_role_definitions() as $def) {
        if ($def['value'] === $value) {
            return (int)$def['order'];
        }
    }
    return 100;
}

/** Excel 表記・別名を kintone の選択肢へ変換する（不明な値は null） */
function roster_role_from_input(string $input): ?string
{
    $input = trim($input);
    if ($input === '' || in_array($input, ['役職なし', 'なし', '－', 'ー', '―'], true)) {
        return '-';
    }
    foreach (roster_role_definitions() as $def) {
        if ($input === $def['value'] || $input === $def['label']) {
            return $def['value'];
        }
    }
    return match ($input) {
        '部長', '主将', '代表' => '代表者',
        '副部長', '副主将', '副代表' => '副代表者',
        default => null,
    };
}

/** フォーム設定（managed_forms.settings_json）から名簿の設定を取り出す */
function roster_form_settings(array $settings): array
{
    $scope = (string)($settings['roster_phone_required_scope'] ?? 'officers');
    if (!in_array($scope, ['officers', 'all', 'none'], true)) {
        $scope = 'officers';
    }
    $year = (int)($settings['roster_fiscal_year'] ?? 0);
    return [
        'is_roster' => (string)($settings['form_type'] ?? 'standard') === 'roster',
        'fiscal_year' => ($year >= 2000 && $year <= 2100) ? $year : 0,
        'phone_required_scope' => $scope,
        'phone_required_manager' => !empty($settings['roster_phone_required_manager']),
        'officer_sync' => !empty($settings['roster_officer_sync']),
    ];
}

/** 電話番号が必須の部員か（在籍・入部の部員だけを対象にする） */
function roster_phone_required_for(array $row, array $rosterSettings): bool
{
    if (!in_array((string)($row['membership_status'] ?? ''), roster_active_statuses(), true)) {
        return false;
    }
    $scope = (string)($rosterSettings['phone_required_scope'] ?? 'officers');
    if ($scope === 'none') {
        return false;
    }
    if ($scope === 'all') {
        return true;
    }
    $role = (string)($row['role'] ?? '-');
    if ($role === '' || $role === '-') {
        return false;
    }
    if ($role === 'マネージャー') {
        return !empty($rosterSettings['phone_required_manager']);
    }
    return true;
}

function roster_clean_text(mixed $value, int $max): string
{
    $text = is_scalar($value) ? (string)$value : '';
    $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '';
    $text = trim(preg_replace('/[ \t]+/u', ' ', $text) ?? '');
    $text = trim($text, " \u{3000}");
    return mb_substr($text, 0, $max, 'UTF-8');
}

function roster_normalize_student_id(mixed $value): string
{
    $text = is_scalar($value) ? (string)$value : '';
    $text = mb_convert_kana($text, 'as', 'UTF-8');
    $text = strtoupper(preg_replace('/\s+/u', '', $text) ?? '');
    return mb_substr($text, 0, 40, 'UTF-8');
}

function roster_normalize_phone(mixed $value): string
{
    $text = is_scalar($value) ? (string)$value : '';
    $text = mb_convert_kana($text, 'as', 'UTF-8');
    $text = str_replace(['ー', '－', '―', '‐', '−', '–', '—'], '-', $text);
    $text = preg_replace('/\s+/u', '', $text) ?? '';
    return mb_substr($text, 0, 30, 'UTF-8');
}

function roster_bool(mixed $value): bool
{
    return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
}

function roster_nullable_bool(mixed $value): ?bool
{
    if ($value === null || $value === '') {
        return null;
    }
    return roster_bool($value);
}

/**
 * 画面から送られた行を、保存用の形へ正規化する（不足項目はそのまま残す）。
 * kintone_row_id は、サーバー側で保持している比較元（$allowedRowIds）に含まれるものだけを残す。
 */
function roster_normalize_rows(mixed $rawRows, array $allowedRowIds = []): array
{
    if (!is_array($rawRows)) {
        throw new RosterException('名簿の形式が不正です。');
    }
    $maxRows = (int)roster_config('rules.max_rows', 500);
    if (count($rawRows) > $maxRows) {
        throw new RosterException('名簿は' . $maxRows . '行までです。');
    }
    $nameMax = (int)roster_config('rules.name_max_length', 100);
    $allowed = array_fill_keys(array_map('strval', $allowedRowIds), true);
    $rows = [];
    $seenKeys = [];
    foreach (array_values($rawRows) as $i => $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $key = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($raw['key'] ?? '')) ?? '';
        if ($key === '' || isset($seenKeys[$key])) {
            $key = 'r' . ($i + 1) . '_' . bin2hex(random_bytes(3));
        }
        $seenKeys[$key] = true;
        $rowId = trim((string)($raw['kintone_row_id'] ?? ''));
        $role = roster_role_from_input((string)($raw['role'] ?? ''));
        $status = trim((string)($raw['membership_status'] ?? ''));
        $rows[] = [
            'key' => mb_substr($key, 0, 40),
            'kintone_row_id' => ($rowId !== '' && ctype_digit($rowId) && isset($allowed[$rowId])) ? $rowId : null,
            'membership_status' => in_array($status, roster_status_options(), true) ? $status : '',
            'name' => roster_clean_text($raw['name'] ?? '', $nameMax),
            'student_id' => roster_normalize_student_id($raw['student_id'] ?? ''),
            'role' => $role ?? (string)($raw['role'] ?? ''),
            'phone_number' => roster_normalize_phone($raw['phone_number'] ?? ''),
            'uses_group_email' => roster_bool($raw['uses_group_email'] ?? false),
            'is_website_editor' => roster_bool($raw['is_website_editor'] ?? false),
        ];
    }
    return $rows;
}

/** 提出前の完全な検証。errors が空なら提出できる。 */
function roster_validate_rows(array $rows, array $rosterSettings): array
{
    $errors = [];
    $warnings = [];
    $add = static function (array &$bucket, ?int $index, ?string $field, string $message) use ($rows): void {
        $bucket[] = [
            'row' => $index === null ? null : $index + 1,
            'key' => $index === null ? null : ($rows[$index]['key'] ?? null),
            'field' => $field,
            'message' => $message,
        ];
    };
    $minRows = max(0, (int)roster_config('rules.min_rows', 1));
    if (count($rows) < $minRows) {
        $add($errors, null, null, '部員を' . $minRows . '人以上入力してください。');
    }
    $nameMax = (int)roster_config('rules.name_max_length', 100);
    $studentIds = [];
    $roleValues = roster_role_values();
    foreach ($rows as $i => $row) {
        $label = ($i + 1) . '行目';
        if ((string)$row['membership_status'] === '') {
            $add($errors, $i, 'membership_status', $label . '：状態（入部・在籍・卒部・退部）を選択してください。');
        }
        if ((string)$row['name'] === '') {
            $add($errors, $i, 'name', $label . '：氏名を入力してください。');
        } elseif (mb_strlen((string)$row['name'], 'UTF-8') > $nameMax) {
            $add($errors, $i, 'name', $label . '：氏名は' . $nameMax . '文字以内で入力してください。');
        }
        $sid = (string)$row['student_id'];
        if ($sid === '') {
            $add($errors, $i, 'student_id', $label . '：学籍番号を入力してください。');
        } elseif (preg_match('/\A[A-Z0-9-]{1,32}\z/', $sid) !== 1) {
            $add($errors, $i, 'student_id', $label . '：学籍番号は半角英数字（ハイフン可）で入力してください。');
        } else {
            $studentIds[$sid][] = $i;
        }
        if (!in_array((string)$row['role'], $roleValues, true)) {
            $add($errors, $i, 'role', $label . '：役職を選択肢から選んでください。');
        }
        $phone = (string)$row['phone_number'];
        if ($phone !== '') {
            $digits = preg_replace('/\D/', '', $phone) ?? '';
            if (preg_match('/\A\+?[0-9()-]+\z/', $phone) !== 1 || strlen($digits) < 6 || strlen($digits) > 15) {
                $add($errors, $i, 'phone_number', $label . '：電話番号の形式を確認してください（数字とハイフン）。');
            }
        } elseif (roster_phone_required_for($row, $rosterSettings)) {
            $add($errors, $i, 'phone_number', $label . '：この部員は電話番号が必須です（' . roster_role_label((string)$row['role']) . '）。');
        }
    }
    foreach ($studentIds as $sid => $indexes) {
        if (count($indexes) > 1) {
            foreach ($indexes as $i) {
                $add($errors, $i, 'student_id', ($i + 1) . '行目：学籍番号 ' . $sid . ' が名簿内で重複しています。');
            }
        }
    }
    $activeLeaders = array_filter($rows, static fn(array $r): bool => $r['role'] === '代表者' && in_array($r['membership_status'], roster_active_statuses(), true));
    if ($rows !== [] && $activeLeaders === []) {
        $add($warnings, null, 'role', '在籍・入部の部員に「部長・主将」がいません。');
    }
    return ['errors' => $errors, 'warnings' => $warnings];
}

function roster_count_rows(array $rows): array
{
    $counts = ['total' => count($rows), 'active' => 0, 'inactive' => 0, 'unset' => 0];
    foreach (roster_status_options() as $status) {
        $counts['by_status'][$status] = 0;
    }
    foreach ($rows as $row) {
        $status = (string)($row['membership_status'] ?? '');
        if (isset($counts['by_status'][$status])) {
            $counts['by_status'][$status]++;
        }
        if (in_array($status, roster_active_statuses(), true)) {
            $counts['active']++;
        } elseif ($status === '') {
            $counts['unset']++;
        } else {
            $counts['inactive']++;
        }
    }
    return $counts;
}

/** 役職順（在籍・入部を先に、役職順、元の順）に並べ替える */
function roster_sort_rows_by_role(array $rows): array
{
    $indexed = [];
    foreach (array_values($rows) as $i => $row) {
        $indexed[] = [$i, $row];
    }
    usort($indexed, static function (array $a, array $b): int {
        $activeA = in_array($a[1]['membership_status'] ?? '', roster_active_statuses(), true) ? 0 : 1;
        $activeB = in_array($b[1]['membership_status'] ?? '', roster_active_statuses(), true) ? 0 : 1;
        return [$activeA, roster_role_order((string)($a[1]['role'] ?? '-')), $a[0]] <=> [$activeB, roster_role_order((string)($b[1]['role'] ?? '-')), $b[0]];
    });
    return array_map(static fn(array $pair): array => $pair[1], $indexed);
}

/** 承認・反映の対象として保存する内容（行順・値）。ハッシュで同一性を確認する。 */
function roster_snapshot_rows(array $rows): array
{
    return array_map(static fn(array $row): array => [
        'kintone_row_id' => $row['kintone_row_id'] ?? null,
        'membership_status' => (string)$row['membership_status'],
        'name' => (string)$row['name'],
        'student_id' => (string)$row['student_id'],
        'role' => (string)$row['role'],
        'phone_number' => (string)$row['phone_number'],
        'uses_group_email' => (bool)$row['uses_group_email'],
        'is_website_editor' => (bool)$row['is_website_editor'],
    ], array_values($rows));
}

function roster_payload_hash(array $payload): string
{
    return hash('sha256', roster_json($payload));
}

/** 比較する項目（kintone に反映する項目） */
function roster_managed_fields(): array
{
    return ['membership_status', 'name', 'student_id', 'role', 'phone_number', 'uses_group_email', 'is_website_editor'];
}

function roster_field_label(string $field): string
{
    return match ($field) {
        'membership_status' => '状態',
        'name' => '氏名',
        'student_id' => '学籍番号',
        'role' => '役職',
        'phone_number' => '電話番号',
        'uses_group_email' => '団体メール使用者',
        'is_website_editor' => 'HP更新担当者',
        'role_name' => '職名',
        default => $field,
    };
}

/** 比較用に値を正規化する（kintone 側の未設定 null は '' / false と同じに扱わない場合は raw を使う） */
function roster_compare_value(string $field, mixed $value): string
{
    if ($field === 'uses_group_email' || $field === 'is_website_editor') {
        if ($value === null) {
            return '';
        }
        return $value ? '1' : '0';
    }
    return $value === null ? '' : (string)$value;
}

function roster_display_value(string $field, mixed $value): string
{
    if ($field === 'uses_group_email' || $field === 'is_website_editor') {
        if ($value === null) {
            return '（未設定）';
        }
        return $value ? ($field === 'uses_group_email' ? '使用' : '担当') : '—';
    }
    if ($field === 'role') {
        return roster_role_label((string)$value);
    }
    if ($value === null) {
        return '（未設定）';
    }
    return (string)$value === '' ? '—' : (string)$value;
}

/**
 * 提出行と kintone の行を対応付ける。
 * 1. kintone 行ID が一致する行
 * 2. 行IDのない提出行は、未対応の kintone 行のうち学籍番号が一致する1行（複数なら曖昧）
 */
function roster_match_rows(array $rows, array $kintoneRows): array
{
    $byId = [];
    $bySid = [];
    foreach ($kintoneRows as $k) {
        $id = (string)($k['kintone_row_id'] ?? '');
        if ($id === '') {
            continue;
        }
        $byId[$id] = $k;
        $sid = roster_normalize_student_id($k['student_id'] ?? '');
        if ($sid !== '') {
            $bySid[$sid][] = $id;
        }
    }
    $pairs = array_fill(0, count($rows), null);
    $used = [];
    $ambiguous = [];
    foreach (array_values($rows) as $i => $row) {
        $id = (string)($row['kintone_row_id'] ?? '');
        if ($id !== '' && isset($byId[$id]) && !isset($used[$id])) {
            $pairs[$i] = $id;
            $used[$id] = true;
        }
    }
    foreach (array_values($rows) as $i => $row) {
        if ($pairs[$i] !== null) {
            continue;
        }
        $sid = (string)($row['student_id'] ?? '');
        $candidates = array_values(array_filter($bySid[$sid] ?? [], static fn(string $id): bool => !isset($used[$id])));
        if (count($candidates) === 1) {
            $pairs[$i] = $candidates[0];
            $used[$candidates[0]] = true;
        } elseif (count($candidates) > 1) {
            $ambiguous[] = $i;
        }
    }
    $unmatched = [];
    foreach ($kintoneRows as $k) {
        $id = (string)($k['kintone_row_id'] ?? '');
        if ($id !== '' && !isset($used[$id])) {
            $unmatched[] = $id;
        }
    }
    return ['pairs' => $pairs, 'unmatched' => $unmatched, 'ambiguous' => $ambiguous, 'by_id' => $byId];
}

/**
 * 差分。$kintoneRows は現在の kintone、$baseRows は団体が読み込んだ時点の kintone（あれば）。
 * kintone 側で読込後に変わった項目には kintone_changed を立てる（上書きになる箇所の確認用）。
 */
function roster_compute_diff(array $rows, array $kintoneRows, ?array $baseRows = null): array
{
    $match = roster_match_rows($rows, $kintoneRows);
    $baseById = [];
    foreach ($baseRows ?? [] as $b) {
        if ((string)($b['kintone_row_id'] ?? '') !== '') {
            $baseById[(string)$b['kintone_row_id']] = $b;
        }
    }
    $entries = [];
    $summary = ['added' => 0, 'changed' => 0, 'unchanged' => 0, 'status_changed' => 0, 'student_id_corrected' => 0, 'kept' => 0, 'ambiguous' => 0, 'kintone_changed' => 0];
    foreach (array_values($rows) as $i => $row) {
        $pairId = $match['pairs'][$i];
        if (in_array($i, $match['ambiguous'], true)) {
            $entries[] = ['index' => $i, 'kind' => 'ambiguous', 'kintone_row_id' => null, 'changes' => [], 'row' => $row];
            $summary['ambiguous']++;
            continue;
        }
        if ($pairId === null) {
            $entries[] = ['index' => $i, 'kind' => 'added', 'kintone_row_id' => null, 'changes' => [], 'row' => $row];
            $summary['added']++;
            continue;
        }
        $k = $match['by_id'][$pairId];
        $base = $baseById[$pairId] ?? null;
        $changes = [];
        $kintoneChangedAny = false;
        foreach (roster_managed_fields() as $field) {
            $to = roster_compare_value($field, $row[$field] ?? null);
            $from = roster_compare_value($field, $k[$field] ?? null);
            if ($to === $from) {
                continue;
            }
            $kintoneChanged = $base !== null && roster_compare_value($field, $base[$field] ?? null) !== $from;
            $kintoneChangedAny = $kintoneChangedAny || $kintoneChanged;
            $changes[$field] = [
                'from' => $k[$field] ?? null,
                'to' => $row[$field] ?? null,
                'kintone_changed' => $kintoneChanged,
            ];
        }
        $kind = $changes === [] ? 'unchanged' : 'changed';
        $entries[] = [
            'index' => $i,
            'kind' => $kind,
            'kintone_row_id' => $pairId,
            'changes' => $changes,
            'row' => $row,
            'role_name' => (string)($k['role_name'] ?? ''),
            'student_id_corrected' => isset($changes['student_id']),
            'kintone_changed' => $kintoneChangedAny,
        ];
        $summary[$kind]++;
        if (isset($changes['membership_status'])) {
            $summary['status_changed']++;
        }
        if (isset($changes['student_id'])) {
            $summary['student_id_corrected']++;
        }
        if ($kintoneChangedAny) {
            $summary['kintone_changed']++;
        }
    }
    $kept = [];
    foreach ($match['unmatched'] as $id) {
        $k = $match['by_id'][$id];
        $kept[] = $k;
        $summary['kept']++;
    }
    return ['entries' => $entries, 'kept' => $kept, 'summary' => $summary];
}

function roster_mask_email(string $email): string
{
    $email = trim($email);
    if (!str_contains($email, '@')) {
        return $email === '' ? '' : '***';
    }
    [$local, $domain] = explode('@', $email, 2);
    $visible = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));
    return $visible . str_repeat('*', max(3, mb_strlen($local) - mb_strlen($visible))) . '@' . $domain;
}

function roster_normalize_group_code(string $code): string
{
    $code = strtoupper(trim(mb_convert_kana($code, 'as', 'UTF-8')));
    return preg_match('/\A[A-Z0-9_-]{1,32}\z/', $code) === 1 ? $code : '';
}
