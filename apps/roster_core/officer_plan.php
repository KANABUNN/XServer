<?php

declare(strict_types=1);

/* =====================================================================
   第2段階: 反映計画のプレビュー（ID7 / ID22 / ID21 の読取りと比較だけを行う）
   - この処理から kintone へは書き込まない。計画の固定と実行は accept・ワーカー側の役割。
   - 計画の指紋は、担当者が見た比較元・操作を accept 直前に照合するためのもの。
   ===================================================================== */

function roster_officer_person_operation_label(string $operation): string
{
    return match ($operation) {
        'reference' => '既存の登録者を参照',
        'create' => '新規登録',
        'needs_info' => '情報不足（差し戻し）',
        'review' => '競合（役員反映を保留）',
        default => $operation,
    };
}

function roster_officer_slot_operation_label(string $operation): string
{
    return match ($operation) {
        'none' => '空席のまま',
        'keep' => '維持',
        'set' => '新しく設定',
        'replace' => '交代',
        'clear' => '解除（空席にする）',
        default => $operation,
    };
}

/**
 * 役職ごとに、提出された担当者を ID21 のどの枠に入れるかを決める。
 * 同じ役職内の番号の並べ替えは構成の変更にしないため、既存の枠位置を優先して再利用する。
 */
function roster_officer_target_slots(array $officers, array $current): array
{
    $byRole = [];
    foreach (roster_officer_slot_definitions() as $def) {
        $byRole[$def['role']][] = $def;
    }
    $target = [];
    foreach ($byRole as $defs) {
        $submitted = array_map(static fn(array $d): string => (string)($officers['slots'][$d['key']] ?? ''), $defs);
        $remaining = array_values(array_unique(array_filter($submitted, static fn(string $s): bool => $s !== '')));
        $assigned = array_fill_keys(array_column($defs, 'key'), '');
        $take = static function (string $key, string $sid) use (&$assigned, &$remaining): void {
            $pos = array_search($sid, $remaining, true);
            if ($sid !== '' && $pos !== false && $assigned[$key] === '') {
                $assigned[$key] = $sid;
                array_splice($remaining, (int)$pos, 1);
            }
        };
        foreach ($defs as $def) {
            $take($def['key'], (string)($current[$def['key']] ?? ''));
        }
        foreach ($defs as $i => $def) {
            $take($def['key'], $submitted[$i]);
        }
        foreach ($defs as $def) {
            if ($assigned[$def['key']] === '' && $remaining !== []) {
                $assigned[$def['key']] = array_shift($remaining);
            }
        }
        // 既存の枠を優先した結果、必須の枠が空いたら任意の枠から移す
        foreach ($defs as $def) {
            if (!$def['required'] || $assigned[$def['key']] !== '') {
                continue;
            }
            foreach (array_reverse($defs) as $other) {
                if (!$other['required'] && $assigned[$other['key']] !== '') {
                    $assigned[$def['key']] = $assigned[$other['key']];
                    $assigned[$other['key']] = '';
                    break;
                }
            }
        }
        $target += $assigned;
    }
    return $target;
}

/** 幹部構成（役職種別ごとの担当者の集合）が変わるか。氏名・メールの訂正や同じ役職内の番号の入替えは含まない。 */
function roster_officer_composition_changed(array $officers, array $current): bool
{
    $sets = [[], []];
    foreach (roster_officer_slot_definitions() as $def) {
        foreach ([(string)($officers['slots'][$def['key']] ?? ''), (string)($current[$def['key']] ?? '')] as $i => $sid) {
            if ($sid !== '') {
                $sets[$i][$def['role']][$sid] = true;
            }
        }
    }
    foreach ($sets as &$set) {
        foreach ($set as &$sids) {
            ksort($sids, SORT_STRING);
        }
        unset($sids);
        ksort($set, SORT_STRING);
    }
    unset($set);
    return $sets[0] !== $sets[1];
}

/** 人物ごとの照合結果（既存参照 / 新規登録 / 情報不足 / 競合）を決める。既存の登録者は書き換えない。 */
function roster_officer_classify_people(array $officers, array $rowsBySid, array &$blockers, array &$warnings): array
{
    $assignments = roster_officer_assignments($officers);
    $order = array_map('strval', array_keys($assignments));
    $found = [];
    foreach (roster_kintone_find_people('student_id', $order) as $person) {
        $found[roster_normalize_student_id($person['student_id'])][] = $person;
    }
    $declared = array_column($officers['people'] ?? [], null, 'student_id');
    $mails = array_values(array_filter(array_map(static fn(array $p): string => (string)$p['mail'], $declared)));
    $byMail = [];
    foreach (roster_kintone_find_people('mail', $mails) as $person) {
        $byMail[mb_strtolower($person['mail'], 'UTF-8')][] = $person;
    }
    $studentTypes = roster_officer_type_options();
    $mailDomains = array_map('strval', (array)roster_config('officers.person_mail_domains', []));
    $people = [];
    foreach ($order as $i => $sid) {
        $stepKey = 'person_' . ($i + 1);
        $row = $rowsBySid[$sid] ?? [];
        $input = $declared[$sid] ?? null;
        $matches = $found[$sid] ?? [];
        $rosterName = (string)($row['name'] ?? '');
        $entry = [
            'step_key' => $stepKey,
            'student_id' => $sid,
            'roster_name' => $rosterName,
            'holds' => array_column($assignments[$sid], 'label'),
            'primary_role' => roster_role_label((string)($row['role'] ?? '')),
            'operation' => 'review',
            'kintone' => null,
            'declared' => $input,
            'missing' => [],
            'differences' => [],
            'notes' => [],
        ];
        $mailTaken = $input !== null && array_filter(
            $byMail[mb_strtolower((string)$input['mail'], 'UTF-8')] ?? [],
            static fn(array $p): bool => roster_normalize_student_id($p['student_id']) !== $sid
        ) !== [];
        $conflict = static function (string $note, string $blocker) use (&$entry, &$blockers, $stepKey): void {
            $entry['notes'][] = $note;
            $blockers[] = $stepKey . '：' . $blocker;
        };
        if (count($matches) > 1) {
            $conflict('学籍番号が一致する登録者が複数あります。', '学籍番号が一致する登録者が複数あるため、役員反映を保留します。');
        } elseif (count($matches) === 1) {
            $person = $matches[0];
            $entry['kintone'] = $person;
            $hasConflict = false;
            if (!in_array($person['type'], $studentTypes, true)) {
                $conflict('登録者の区分が対象外です（学生以外）。', '登録者の区分が対象外のため、役員反映を保留します。');
                $hasConflict = true;
            }
            $declaredName = $input === null ? $rosterName : (string)$input['family_name'] . (string)$input['personal_name'];
            $kintoneName = roster_officer_compact_name($person['name']);
            if ($kintoneName !== roster_officer_compact_name($rosterName) || $kintoneName !== roster_officer_compact_name($declaredName)) {
                $entry['differences'][] = ['label' => '氏名', 'kintone' => $person['name'], 'submitted' => $rosterName];
                $conflict('名簿・申告の氏名と登録者の氏名が一致しません。', '氏名が登録者と一致しないため、役員反映を保留します。');
                $hasConflict = true;
            }
            if ($mailTaken) {
                $conflict('申告のメールアドレスは別の学籍番号の登録者が使っています。', 'メールアドレスが別の学籍番号の登録者と重複しているため、役員反映を保留します。');
                $hasConflict = true;
            }
            if (!$hasConflict) {
                $entry['operation'] = 'reference';
            }
            if ($input !== null) {
                $compare = ['family_name' => '姓', 'personal_name' => '名', 'mail' => 'メールアドレス', 'depend' => '所属', 'type' => '学生区分', 'org_depend_state' => '執行・議会組織への所属'];
                $differs = false;
                foreach ($compare as $field => $label) {
                    $submitted = (string)$input[$field];
                    if ($field === 'mail' ? mb_strtolower($submitted, 'UTF-8') !== mb_strtolower((string)$person[$field], 'UTF-8') : $submitted !== (string)$person[$field]) {
                        $entry['differences'][] = ['label' => $label, 'kintone' => (string)$person[$field], 'submitted' => $submitted];
                        $differs = true;
                    }
                }
                if ($differs) {
                    $entry['notes'][] = '申告と登録者の内容が異なります。連携では更新しないため、訂正が必要なら登録者アプリで行い、再度比較してください。';
                }
            }
        } else {
            $missing = roster_officer_missing_fields($input);
            if ($missing !== []) {
                $entry['operation'] = 'needs_info';
                $entry['missing'] = $missing;
                $blockers[] = $stepKey . '：登録者に見当たらず、新規登録に必要な情報が不足しています。';
            } elseif ($mailTaken) {
                $conflict('申告のメールアドレスは別の学籍番号の登録者が使っています。', 'メールアドレスが別の学籍番号の登録者と重複しているため、役員反映を保留します。');
            } else {
                $entry['operation'] = 'create';
                $domain = substr(strrchr((string)$input['mail'], '@') ?: '', 1);
                if ($mailDomains !== [] && !in_array($domain, $mailDomains, true)) {
                    $warnings[] = $stepKey . '：大学のメールアドレスではない可能性があります。';
                }
            }
        }
        $people[] = $entry;
    }
    return $people;
}

/**
 * 反映計画のプレビュー。版2の提出だけが対象で、版1の提出には null を返す（役員情報のない版を全枠空席として扱わない）。
 * kintone に接続できない場合は ok=false（比較できないものを確認済みとして扱わない）。
 */
function roster_officer_build_plan(array $case, array $revision): ?array
{
    $payload = roster_json_decode((string)$revision['payload_json'], []);
    if (!is_array($payload) || roster_payload_version($payload) < ROSTER_PAYLOAD_VERSION_OFFICERS) {
        return null;
    }
    $officers = roster_normalize_officers($payload['officers'] ?? null);
    $rows = (array)($payload['rows'] ?? []);
    $rowsBySid = array_column($rows, null, 'student_id');
    $active = roster_active_statuses();
    $groupCode = (string)$case['group_code'];
    $blockers = roster_officer_slot_issues($officers, $rows);
    $warnings = roster_officer_capacity_issues($rows);
    try {
        $group = roster_kintone_find_group_master($groupCode);
        $ledger = roster_kintone_find_officer_records($groupCode);
        $people = roster_officer_classify_people($officers, $rowsBySid, $blockers, $warnings);
        $appIds = [
            'groups' => roster_kintone_app_context('group')['app_id'],
            'roster' => roster_kintone_app_context('roster')['app_id'],
            'people' => roster_kintone_app_context('people')['app_id'],
            'officers' => roster_kintone_app_context('officers')['app_id'],
        ];
    } catch (RosterKintoneError $e) {
        roster_log('warning', 'officer plan failed', ['case_id' => (int)$case['id']] + $e->summary());
        return ['ok' => false, 'error' => 'kintone の登録者・役員台帳と比較できません（' . mb_substr($e->getMessage(), 0, 200, 'UTF-8') . '）。'];
    }

    if ($group === null) {
        $blockers[] = '公認団体・組織（ID7）にこの団体が見つかりません。';
    } elseif (!in_array($group['status'], (array)roster_config('groups.allowed_statuses', []), true)) {
        $warnings[] = '公認団体・組織（ID7）の活動状況が「' . $group['status'] . '」です。';
    }

    $record = $ledger['count'] === 1 ? $ledger['records'][0] : null;
    $operation = match (true) {
        $ledger['count'] === 0 => 'create',
        $ledger['count'] === 1 => 'update',
        default => 'review',
    };
    if ($operation === 'review') {
        $blockers[] = '役員台帳に同じ団体のレコードが' . $ledger['count'] . '件あります（現行は団体ごとに1件）。整理するまで反映できません。';
    }
    if ($record !== null) {
        if ($record['group_key'] === '') {
            $warnings[] = '役員台帳の現行レコードに団体キー（forms_current_group_key）が入っていません。移行で補完するまで書込みは有効にできません。';
        } elseif ($record['group_key'] !== $groupCode) {
            $blockers[] = '役員台帳の団体キーが団体識別記号と一致しません。';
        }
    }
    if ($record !== null && $group !== null) {
        if ($record['group_name'] !== $group['name']) {
            $warnings[] = '役員台帳の団体名が公認団体・組織（ID7）の現在の団体名と異なります。';
        }
        if ($record['group_org'] !== $group['org']) {
            $warnings[] = '役員台帳の組織が公認団体・組織（ID7）の現在の組織と異なります。';
        }
    }

    $currentSids = [];
    foreach (roster_officer_slot_definitions() as $def) {
        $currentSids[$def['key']] = roster_normalize_student_id($record['slots'][$def['key']]['student_id'] ?? '');
    }
    $target = roster_officer_target_slots($officers, $currentSids);
    $compositionChanged = roster_officer_composition_changed($officers, $currentSids);
    $registerDateMode = match ($operation) {
        'create' => 'accept_date',
        'update' => $compositionChanged ? 'accept_date' : 'keep',
        default => 'undetermined',
    };

    $assignments = roster_officer_assignments($officers);
    $personBySid = array_column($people, null, 'student_id');
    $slots = [];
    foreach (roster_officer_slot_definitions() as $def) {
        $planned = $target[$def['key']];
        $current = $currentSids[$def['key']];
        $op = match (true) {
            $planned === '' && $current === '' => 'none',
            $planned === '' => 'clear',
            $current === '' => 'set',
            $planned === $current => 'keep',
            default => 'replace',
        };
        $flags = [];
        if (in_array($op, ['clear', 'replace'], true)) {
            $status = (string)($rowsBySid[$current]['membership_status'] ?? '');
            if ($status === '') {
                $flags[] = '現在の役員は今回の名簿に見当たりません';
            } elseif (!in_array($status, $active, true)) {
                $flags[] = '現在の役員は卒部・退部です';
            }
        }
        if ($planned !== '' && $planned !== (string)($officers['slots'][$def['key']] ?? '')) {
            $flags[] = '既存の枠位置に合わせました';
        }
        if ($planned !== '' && count(array_unique(array_column($assignments[$planned] ?? [], 'role'))) > 1) {
            $flags[] = '兼務';
        }
        $slots[] = [
            'key' => $def['key'],
            'label' => $def['label'],
            'required' => $def['required'],
            'operation' => $op,
            'current_student_id' => $current,
            'current_name' => (string)($record['slots'][$def['key']]['name'] ?? ''),
            'planned_student_id' => $planned,
            'planned_name' => $planned !== '' ? (string)($rowsBySid[$planned]['name'] ?? '') : '',
            'planned_step' => $planned !== '' ? (string)($personBySid[$planned]['step_key'] ?? '') : '',
            'flags' => $flags,
        ];
    }

    $fingerprint = roster_canonical_hash([
        'case_id' => (int)$case['id'],
        'revision_id' => (int)$revision['id'],
        'payload_hash' => (string)$revision['payload_hash'],
        'app_ids' => $appIds,
        'group' => $group === null ? null : ['record_id' => $group['record_id'], 'revision' => $group['revision']],
        'people' => array_map(static fn(array $p): array => [
            'step' => $p['step_key'],
            'operation' => $p['operation'],
            'record_id' => $p['kintone']['record_id'] ?? null,
            'revision' => $p['kintone']['revision'] ?? null,
        ], $people),
        'ledger' => ['operation' => $operation, 'record_id' => $record['record_id'] ?? null, 'revision' => $record['revision'] ?? null, 'register_date_mode' => $registerDateMode],
        'slots' => array_map(static fn(array $s): array => [$s['key'], $s['operation'], $s['planned_student_id']], $slots),
    ]);

    return [
        'ok' => true,
        'group' => $group,
        'people' => $people,
        'ledger' => [
            'operation' => $operation,
            'record_count' => $ledger['count'],
            'record_id' => $record['record_id'] ?? null,
            'revision' => $record['revision'] ?? null,
            'register_date' => $record['register_date'] ?? '',
            'register_date_mode' => $registerDateMode,
            'composition_changed' => $compositionChanged,
            'record_ids' => array_column($ledger['records'], 'record_id'),
        ],
        'slots' => $slots,
        'blockers' => $blockers,
        'warnings' => $warnings,
        'ready' => $blockers === [],
        'fingerprint' => $fingerprint,
        'write_enabled' => (bool)roster_config('officers.write_enabled', false),
    ];
}

/** 不足項目を示す差し戻し理由の下書き（当該団体の入力項目だけで、登録者の内容は含めない） */
function roster_officer_return_suggestion(array $plan): string
{
    $lines = [];
    foreach ($plan['people'] ?? [] as $person) {
        if ($person['operation'] === 'needs_info') {
            $lines[] = $person['roster_name'] . 'さん（' . $person['student_id'] . '）：役員の登録に次の情報が必要です。' . implode('・', $person['missing']) . ' を入力して再提出してください。';
        }
    }
    return implode("\n", $lines);
}
