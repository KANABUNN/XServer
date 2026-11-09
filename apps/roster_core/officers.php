<?php

declare(strict_types=1);

/* =====================================================================
   第2段階: 役員枠の定義・入力の正規化・検証（DB・kintone 通信を持たない純粋関数）
   - 提出原本の rows は ID20 の形式のまま。役員情報は別キー officers に置く。
   - 公開側から ID22 の存在や内容を検索できる機能は設けない（照合は担当者画面で行う）。
   ===================================================================== */

/** 役員情報を含む提出原本の版。第1段階の原本には payload_version がなく、版1として読む。 */
const ROSTER_PAYLOAD_VERSION_OFFICERS = 2;

/**
 * ID21 の役職枠。フィールドコードの綴り stundet は既存のまま使う。
 * マネージャーは ID20 の役職だけで、ID21 に枠はない。
 * required は業務上の必須（代表者・副代表者1・会計長）。総務・副会計長・副代表者2は空席にできる。
 */
function roster_officer_slot_definitions(): array
{
    $slot = static function (string $key, string $label, string $role, bool $required, string $prefix): array {
        return [
            'key' => $key,
            'label' => $label,
            'role' => $role,
            'required' => $required,
            'student_field' => $prefix . '_stundet_id',
            'name_field' => $prefix . '_name',
            'mail_field' => $prefix . '_mail',
        ];
    };
    return [
        $slot('representative', '代表者', '代表者', true, 'representative'),
        $slot('deputy_representative_1', '副代表者1', '副代表者', true, 'deputy_representative_1'),
        $slot('deputy_representative_2', '副代表者2', '副代表者', false, 'deputy_representative_2'),
        $slot('accountant', '会計長', '会計長', true, 'accountant'),
        $slot('deputy_accountant_1', '副会計長1', '副会計長', false, 'deputy_accountant_1'),
        $slot('deputy_accountant_2', '副会計長2', '副会計長', false, 'deputy_accountant_2'),
        $slot('general_affairs_1', '総務1', '総務', false, 'general_affairs_1'),
        $slot('general_affairs_2', '総務2', '総務', false, 'general_affairs_2'),
        $slot('general_affairs_3', '総務3', '総務', false, 'general_affairs_3'),
    ];
}

function roster_officer_depend_options(): array
{
    return array_values(array_map('strval', (array)roster_config('officers.depend_options', [])));
}

function roster_officer_type_options(): array
{
    return array_values(array_map('strval', (array)roster_config('officers.student_types', [])));
}

function roster_officer_org_depend_options(): array
{
    return array_values(array_map('strval', (array)roster_config('officers.org_depend_states', [])));
}

function roster_officer_min_active_members(): int
{
    return max(0, (int)roster_config('officers.min_active_members', 5));
}

function roster_officer_empty(): array
{
    return ['slots' => array_fill_keys(array_column(roster_officer_slot_definitions(), 'key'), ''), 'people' => []];
}

function roster_payload_version(array $payload): int
{
    return max(1, (int)($payload['payload_version'] ?? 1));
}

/** キー順を固定する。ハッシュは同じ内容なら常に同じ値になる。 */
function roster_canonicalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }
    return array_map('roster_canonicalize', $value);
}

function roster_canonical_hash(array $data): string
{
    return hash('sha256', roster_json(roster_canonicalize($data)));
}

/** 版2の提出原本のハッシュ（役員情報を含む）。版1は roster_payload_hash()（部員行のみ）のまま。 */
function roster_payload_hash_v2(array $rows, array $officers): string
{
    return roster_canonical_hash([
        'payload_version' => ROSTER_PAYLOAD_VERSION_OFFICERS,
        'rows' => $rows,
        'officers' => $officers,
    ]);
}

/** 氏名の表示上の空白だけを除く（表記の推測はしない） */
function roster_officer_compact_name(string $name): string
{
    return preg_replace('/[\s\x{3000}]+/u', '', $name) ?? $name;
}

/**
 * 画面から送られた役員情報を保存用の形へ正規化する。
 * 枠は定義済みのものだけ、人物の申告は枠に割り当てられた人のものを1人1件だけ残す。空の枠は空席を表す。
 */
function roster_normalize_officers(mixed $raw): array
{
    $raw = is_array($raw) ? $raw : [];
    $slotsIn = is_array($raw['slots'] ?? null) ? $raw['slots'] : [];
    $slots = [];
    foreach (roster_officer_slot_definitions() as $def) {
        $slots[$def['key']] = roster_normalize_student_id($slotsIn[$def['key']] ?? '');
    }
    $assigned = array_fill_keys(array_values(array_filter($slots, static fn(string $s): bool => $s !== '')), true);
    $people = [];
    $seen = [];
    foreach ((is_array($raw['people'] ?? null) ? $raw['people'] : []) as $person) {
        if (!is_array($person)) {
            continue;
        }
        $sid = roster_normalize_student_id($person['student_id'] ?? '');
        if ($sid === '' || !isset($assigned[$sid]) || isset($seen[$sid])) {
            continue;
        }
        $seen[$sid] = true;
        $people[] = [
            'student_id' => $sid,
            'family_name' => roster_clean_text($person['family_name'] ?? '', 50),
            'personal_name' => roster_clean_text($person['personal_name'] ?? '', 50),
            'mail' => mb_strtolower(roster_clean_text($person['mail'] ?? '', 200), 'UTF-8'),
            'depend' => roster_clean_text($person['depend'] ?? '', 50),
            'type' => roster_clean_text($person['type'] ?? '', 50),
            'org_depend_state' => roster_clean_text($person['org_depend_state'] ?? '', 10),
        ];
    }
    return ['slots' => $slots, 'people' => $people];
}

function roster_officer_person_fields(): array
{
    return [
        'family_name' => '姓',
        'personal_name' => '名',
        'mail' => '大学メールアドレス',
        'depend' => '所属（学科等）',
        'type' => '学生区分',
        'org_depend_state' => '執行・議会組織への所属',
    ];
}

/** 申告された人物情報の不足項目（表示名の一覧） */
function roster_officer_missing_fields(?array $person): array
{
    $missing = [];
    foreach (roster_officer_person_fields() as $field => $label) {
        if (trim((string)($person[$field] ?? '')) === '') {
            $missing[] = $label;
        }
    }
    return $missing;
}

/** 学籍番号ごとの担当枠（枠定義の順）。兼務の判定と表示に使う。 */
function roster_officer_assignments(array $officers): array
{
    $assignments = [];
    foreach (roster_officer_slot_definitions() as $def) {
        $sid = (string)($officers['slots'][$def['key']] ?? '');
        if ($sid !== '') {
            $assignments[$sid][] = $def;
        }
    }
    return $assignments;
}

/** 正規化した学籍番号で重複のない「入部」「在籍」の部員数 */
function roster_officer_active_member_count(array $rows): int
{
    $active = roster_active_statuses();
    $sids = [];
    foreach ($rows as $row) {
        $sid = (string)($row['student_id'] ?? '');
        if ($sid !== '' && in_array((string)($row['membership_status'] ?? ''), $active, true)) {
            $sids[$sid] = true;
        }
    }
    return count($sids);
}

/**
 * 枠の割当ての検証。提出時と反映計画の作成時の両方で使う。
 * 担当者画面（viewer を含む）にも出すため、メッセージに氏名を含めない。
 */
function roster_officer_slot_issues(array $officers, array $rows): array
{
    $bySid = array_column($rows, null, 'student_id');
    $active = roster_active_statuses();
    $issues = [];
    $seenInRole = [];
    foreach (roster_officer_slot_definitions() as $def) {
        $sid = (string)($officers['slots'][$def['key']] ?? '');
        if ($sid === '') {
            if ($def['required']) {
                $issues[] = $def['label'] . 'が空席です（必須の役職です）。';
            }
            continue;
        }
        $row = $bySid[$sid] ?? null;
        if ($row === null) {
            $issues[] = $def['label'] . '：名簿にない部員が指定されています。';
            continue;
        }
        if (!in_array((string)$row['membership_status'], $active, true)) {
            $issues[] = $def['label'] . '：入部・在籍の部員を指定してください。';
        }
        if (isset($seenInRole[$def['role']][$sid])) {
            $issues[] = '同じ部員を「' . roster_role_label($def['role']) . '」の複数の枠に割り当てることはできません。';
        }
        $seenInRole[$def['role']][$sid] = true;
    }
    return $issues;
}

/** 役職ごとの定員超過（役職と人数を示す。自動で切り捨てない）。氏名は含めない。 */
function roster_officer_capacity_issues(array $rows): array
{
    $capacity = [];
    foreach (roster_officer_slot_definitions() as $def) {
        $capacity[$def['role']] = ($capacity[$def['role']] ?? 0) + 1;
    }
    $active = roster_active_statuses();
    $count = [];
    foreach ($rows as $row) {
        if (in_array((string)$row['membership_status'], $active, true)) {
            $count[(string)$row['role']] = ($count[(string)$row['role']] ?? 0) + 1;
        }
    }
    $issues = [];
    foreach ($capacity as $role => $limit) {
        if (($count[$role] ?? 0) > $limit) {
            $issues[] = '役職「' . roster_role_label($role) . '」の部員が' . $count[$role] . '人います（役員台帳の枠は' . $limit . '人分です）。枠に入らない人は役員台帳に反映されません。';
        }
    }
    return $issues;
}

/**
 * 提出時の検証。人数・必須の役職・候補の在籍状態・人物の申告はエラーにする。
 * 兼務は異なる役職の枠にだけ認め、部員数は1人として数える。
 */
function roster_validate_officers(array $officers, array $rows): array
{
    $errors = [];
    $warnings = [];
    $add = static function (array &$bucket, string $field, string $message): void {
        $bucket[] = ['row' => null, 'key' => null, 'field' => $field, 'message' => $message];
    };
    $bySid = [];
    $indexBySid = [];
    foreach (array_values($rows) as $i => $row) {
        $bySid[(string)($row['student_id'] ?? '')] = $row;
        $indexBySid[(string)($row['student_id'] ?? '')] = $i;
    }
    $minimum = roster_officer_min_active_members();
    $activeCount = roster_officer_active_member_count($rows);
    if ($activeCount < $minimum) {
        $add($errors, 'officer', '入部・在籍の部員が' . $minimum . '人以上必要です（現在' . $activeCount . '人。卒部・退部は数えません）。');
    }
    foreach (roster_officer_slot_issues($officers, $rows) as $issue) {
        $add($errors, 'officer', $issue);
    }

    $assignments = roster_officer_assignments($officers);
    // ID20 の役職は単一選択なので、実際に担当する役職から選んだ主役職を保存する（マネージャーは名簿側の役職）
    foreach ($assignments as $sid => $defs) {
        $row = $bySid[$sid] ?? null;
        if ($row === null) {
            continue;
        }
        $held = array_values(array_unique(array_column($defs, 'role')));
        if (!in_array((string)$row['role'], $held, true) && (string)$row['role'] !== 'マネージャー') {
            $errors[] = [
                'row' => $indexBySid[$sid] + 1,
                'key' => $row['key'] ?? null,
                'field' => 'role',
                'message' => ($indexBySid[$sid] + 1) . '行目：役職は、担当する役職（' . implode('・', array_map('roster_role_label', $held)) . '）から選んでください。',
            ];
        }
    }

    foreach (roster_officer_capacity_issues($rows) as $issue) {
        $add($warnings, 'officer', $issue);
    }
    $slotRoles = array_fill_keys(array_column(roster_officer_slot_definitions(), 'role'), true);
    $active = roster_active_statuses();
    $unassignedRoles = [];
    foreach ($rows as $row) {
        $role = (string)$row['role'];
        if (!isset($slotRoles[$role]) || !in_array((string)$row['membership_status'], $active, true)) {
            continue;
        }
        if (!in_array($role, array_column($assignments[(string)$row['student_id']] ?? [], 'role'), true)) {
            $unassignedRoles[$role] = true;
        }
    }
    foreach (array_keys($unassignedRoles) as $role) {
        $add($warnings, 'officer', '名簿の役職が「' . roster_role_label($role) . '」の部員のうち、その役職の枠に割り当てられていない人がいます。');
    }

    $peopleBySid = array_column($officers['people'], null, 'student_id');
    $departments = roster_officer_depend_options();
    $types = roster_officer_type_options();
    $orgStates = roster_officer_org_depend_options();
    $mailDomains = array_map('strval', (array)roster_config('officers.person_mail_domains', []));
    $mailOwners = [];
    foreach ($assignments as $sid => $defs) {
        $row = $bySid[$sid] ?? null;
        if ($row === null) {
            continue;
        }
        $name = (string)$row['name'];
        $person = $peopleBySid[$sid] ?? null;
        $missing = roster_officer_missing_fields($person);
        if ($missing !== []) {
            $add($errors, 'officer_person', $name . '：役員の情報が不足しています（' . implode('・', $missing) . '）。');
            continue;
        }
        if (roster_officer_compact_name((string)$person['family_name'] . (string)$person['personal_name']) !== roster_officer_compact_name($name)) {
            $add($errors, 'officer_person', $name . '：姓・名が名簿の氏名と一致しません。');
        }
        $mail = (string)$person['mail'];
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            $add($errors, 'officer_person', $name . '：メールアドレスの形式を確認してください。');
        } else {
            $mailOwners[$mail][] = $name;
            $domain = substr(strrchr($mail, '@') ?: '', 1);
            if ($mailDomains !== [] && !in_array($domain, $mailDomains, true)) {
                $add($warnings, 'officer_person', $name . '：大学のメールアドレスではない可能性があります。');
            }
        }
        if (!in_array((string)$person['depend'], $departments, true)) {
            $add($errors, 'officer_person', $name . '：所属を選択肢から選んでください。');
        }
        if (!in_array((string)$person['type'], $types, true)) {
            $add($errors, 'officer_person', $name . '：学生区分を選択肢から選んでください。');
        }
        if (!in_array((string)$person['org_depend_state'], $orgStates, true)) {
            $add($errors, 'officer_person', $name . '：執行・議会組織への所属を「有」「無」から選んでください。');
        }
    }
    foreach ($mailOwners as $names) {
        if (count($names) > 1) {
            $add($errors, 'officer_person', '同じメールアドレスが複数の役員に入力されています（' . implode('・', $names) . '）。');
        }
    }
    return ['errors' => $errors, 'warnings' => $warnings];
}

/** 画面表示用（枠の順に、指定された部員の氏名と兼務を添える）。空の枠は空席。 */
function roster_public_officers(array $officers, array $rows): array
{
    $nameBySid = array_column($rows, 'name', 'student_id');
    $assignments = roster_officer_assignments($officers);
    $out = [];
    foreach (roster_officer_slot_definitions() as $def) {
        $sid = (string)($officers['slots'][$def['key']] ?? '');
        $out[] = [
            'slot' => $def['key'],
            'label' => $def['label'],
            'required' => $def['required'],
            'student_id' => $sid,
            'name' => $sid !== '' ? (string)($nameBySid[$sid] ?? '') : '',
            'concurrent' => $sid === '' ? [] : array_values(array_column(
                array_filter($assignments[$sid], static fn(array $d): bool => $d['key'] !== $def['key']),
                'label'
            )),
        ];
    }
    return $out;
}
