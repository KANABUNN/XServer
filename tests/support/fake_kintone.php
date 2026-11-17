<?php

declare(strict_types=1);

/**
 * kintone REST API の模擬（ID20 部員名簿・ID7 公認団体）。テストとローカル確認専用。
 * - テーブル更新: 行IDありは更新、なしは追加、送られなかった行は削除（kintone の仕様）
 * - 存在しないフィールドは無視（kintone の仕様。だから書込み前のフィールド検査が必須）
 * - 送られなかった行内の項目は空にする（仕様に明記がないため厳しい側で模擬）
 * - リビジョン不一致は 409 GAIA_CO02、ルックアップ参照先のトークンが無ければ 403
 */

final class FakeKintone
{
    public array $records = [];
    public int $nextRecordId = 1;
    public int $nextRowId = 1001;
    public array $calls = [];
    /** 第2段階: [method, app] の記録（ID21/ID22 への書込みが0回であることの確認に使う） */
    public array $appCalls = [];
    /** ID22 登録者 */
    public array $people = [];
    /** ID21 役員台帳 */
    public array $officerRecords = [];
    public bool $officerFieldsComplete = true;
    /** false は調査時の設定（総務1が必須・副代表者1が任意・一意キーなし）、true は業務ルールへの移行後 */
    public bool $officerFieldsMigrated = false;
    public int $writes = 0;
    public bool $newFieldsPresent = true;
    /** @var null|callable(string,string,array,FakeKintone):mixed */
    public $inject = null;
    public array $groups = [
        ['id' => 'CA056', 'name' => '福工大謎解きサークル', 'rank' => '同好会', 'status' => '活動中', 'mail' => 'nazo@example.jp'],
        ['id' => 'CA001', 'name' => 'アイスホッケー', 'rank' => '部', 'status' => '活動中', 'mail' => 'hockey@example.jp'],
        ['id' => 'OR001', 'name' => '学生自治会', 'rank' => '組織', 'status' => '活動中', 'mail' => 'org@example.jp'],
        ['id' => 'CA099', 'name' => '解散した部', 'rank' => '部', 'status' => '解散', 'mail' => 'gone@example.jp'],
    ];

    /** ローカル確認用: 状態を JSON ファイルに保存して、リクエストをまたいで使う */
    public ?string $statePath = null;

    public static function persistent(string $path): self
    {
        $fake = new self();
        $fake->statePath = $path;
        if (is_file($path)) {
            $state = json_decode((string)file_get_contents($path), true);
            if (is_array($state)) {
                foreach (['records', 'nextRecordId', 'nextRowId', 'newFieldsPresent', 'groups', 'people', 'officerRecords'] as $key) {
                    if (array_key_exists($key, $state)) {
                        $fake->$key = $state[$key];
                    }
                }
                $fake->records = array_column($fake->records, null, 'id');
            }
        }
        return $fake;
    }

    public function save(): void
    {
        if ($this->statePath === null) {
            return;
        }
        file_put_contents($this->statePath, json_encode([
            'records' => array_values($this->records),
            'nextRecordId' => $this->nextRecordId,
            'nextRowId' => $this->nextRowId,
            'newFieldsPresent' => $this->newFieldsPresent,
            'groups' => $this->groups,
            'people' => $this->people,
            'officerRecords' => $this->officerRecords,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    public function handle(string $method, string $path, array $payload, int $tokens): array
    {
        $this->calls[] = $method . ' ' . $path;
        $this->appCalls[] = [$method, (int)($payload['app'] ?? 0)];
        if ($this->inject !== null) {
            $r = ($this->inject)($method, $path, $payload, $this);
            if (is_array($r)) {
                return $r;
            }
            if ($r === 'apply_then_timeout') {
                $this->dispatch($method, $path, $payload, $tokens);
                throw new RosterKintoneError('timeout', 0, '', [], true, 28);
            }
            if ($r === 'timeout_not_applied') {
                throw new RosterKintoneError('timeout', 0, '', [], true, 28);
            }
            if ($r === 'connect_fail') {
                throw new RosterKintoneError('connect failed', 0, '', [], true, 7);
            }
        }
        return $this->dispatch($method, $path, $payload, $tokens);
    }

    private function dispatch(string $method, string $path, array $payload, int $tokens): array
    {
        if ($method === 'GET' && $path === '/k/v1/records.json' && (int)$payload['app'] === 22) {
            return ['status' => 200, 'body' => ['records' => $this->queryPeople((string)$payload['query'])]];
        }
        if ($method === 'GET' && $path === '/k/v1/records.json' && (int)$payload['app'] === 21) {
            return ['status' => 200, 'body' => ['records' => $this->queryOfficers((string)$payload['query'])]];
        }
        if ($method === 'GET' && $path === '/k/v1/app/form/fields.json' && in_array((int)$payload['app'], [21, 22], true)) {
            return ['status' => 200, 'body' => ['properties' => (int)$payload['app'] === 22 ? $this->peopleFields() : $this->officerFields()]];
        }
        if ($method === 'GET' && $path === '/k/v1/records.json' && (int)$payload['app'] === 7 && preg_match('/\bid = "([^"]+)"/', (string)$payload['query'], $match) === 1) {
            $out = [];
            foreach ($this->groups as $i => $g) {
                if ($g['id'] === $match[1]) {
                    $out[] = [
                        '$id' => ['value' => (string)($i + 1)], '$revision' => ['value' => (string)($g['revision'] ?? 1)],
                        'id' => ['value' => $g['id']], 'name' => ['value' => $g['name']], 'status' => ['value' => $g['status']],
                        'org' => ['value' => array_map(static fn(string $c): array => ['code' => $c, 'name' => $c], $g['org'] ?? [$g['id']])],
                    ];
                }
            }
            return ['status' => 200, 'body' => ['records' => $out]];
        }
        if ($method === 'GET' && $path === '/k/v1/records.json' && (int)$payload['app'] === 7) {
            $records = array_map(fn(array $g, int $i): array => [
                '$id' => ['value' => (string)($i + 1)], '$revision' => ['value' => '1'],
                'id' => ['value' => $g['id']], 'name' => ['value' => $g['name']], 'rank' => ['value' => $g['rank']],
                'status' => ['value' => $g['status']], 'mail' => ['value' => $g['mail']],
            ], $this->groups, array_keys($this->groups));
            return ['status' => 200, 'body' => ['records' => str_contains((string)$payload['query'], 'offset 0') ? $records : []]];
        }
        if ($method === 'GET' && $path === '/k/v1/records.json') {
            preg_match('/group_id = "([^"]+)"/', (string)$payload['query'], $m);
            $out = [];
            foreach ($this->records as $r) {
                if ($r['group_id'] === ($m[1] ?? '')) {
                    $out[] = $this->export($r);
                }
            }
            return ['status' => 200, 'body' => ['records' => $out]];
        }
        if ($method === 'GET' && $path === '/k/v1/record.json') {
            $r = $this->records[(int)$payload['id']] ?? null;
            return $r ? ['status' => 200, 'body' => ['record' => $this->export($r)]] : ['status' => 404, 'body' => ['code' => 'GAIA_RE01', 'message' => 'not found']];
        }
        if ($method === 'GET' && $path === '/k/v1/app/form/fields.json') {
            $inner = [
                'name' => ['type' => 'SINGLE_LINE_TEXT', 'code' => 'name'],
                'student_id' => ['type' => 'SINGLE_LINE_TEXT', 'code' => 'student_id'],
                'phone_number' => ['type' => 'SINGLE_LINE_TEXT', 'code' => 'phone_number'],
                'role' => ['type' => 'DROP_DOWN', 'code' => 'role', 'options' => array_fill_keys(['-', '代表者', '副代表者', '会計長', '副会計長', '総務', 'マネージャー'], [])],
                'role_name' => ['type' => 'SINGLE_LINE_TEXT', 'code' => 'role_name'],
            ];
            if ($this->newFieldsPresent) {
                $inner['membership_status'] = ['type' => 'DROP_DOWN', 'code' => 'membership_status', 'required' => false, 'defaultValue' => '', 'options' => array_fill_keys(['入部', '在籍', '卒部', '退部'], [])];
                $inner['uses_group_email'] = ['type' => 'CHECK_BOX', 'code' => 'uses_group_email', 'defaultValue' => [], 'options' => ['使用' => []]];
                $inner['is_website_editor'] = ['type' => 'CHECK_BOX', 'code' => 'is_website_editor', 'defaultValue' => [], 'options' => ['担当' => []]];
            }
            return ['status' => 200, 'body' => ['properties' => [
                'group_id' => ['type' => 'SINGLE_LINE_TEXT', 'code' => 'group_id', 'lookup' => ['relatedApp' => ['app' => '7']]],
                'list' => ['type' => 'SUBTABLE', 'code' => 'list', 'fields' => $inner],
            ]]];
        }
        if ($method === 'POST' && $path === '/k/v1/record.json') {
            $this->writes++;
            if ($tokens < 2) {
                return ['status' => 403, 'body' => ['code' => 'GAIA_NO01', 'message' => 'lookup app token missing']];
            }
            $groupId = (string)($payload['record']['group_id']['value'] ?? '');
            $group = array_values(array_filter($this->groups, fn($g) => $g['id'] === $groupId))[0] ?? null;
            if ($group === null) {
                return ['status' => 400, 'body' => ['code' => 'GAIA_LO04', 'message' => 'lookup failed']];
            }
            $id = $this->nextRecordId++;
            $this->records[$id] = ['id' => $id, 'revision' => 1, 'group_id' => $groupId, 'group_name' => $group['name'], 'list' => $this->applyTable([], $payload['record']['list']['value'] ?? [])];
            return ['status' => 200, 'body' => ['id' => (string)$id, 'revision' => '1']];
        }
        if ($method === 'PUT' && $path === '/k/v1/record.json') {
            $this->writes++;
            $id = (int)$payload['id'];
            if (!isset($this->records[$id])) {
                return ['status' => 404, 'body' => ['code' => 'GAIA_RE01', 'message' => 'not found']];
            }
            $r = &$this->records[$id];
            if (isset($payload['revision']) && (int)$payload['revision'] !== -1 && (int)$payload['revision'] !== $r['revision']) {
                return ['status' => 409, 'body' => ['code' => 'GAIA_CO02', 'message' => 'revision mismatch']];
            }
            if (isset($payload['record']['list'])) {
                $r['list'] = $this->applyTable($r['list'], $payload['record']['list']['value']);
            }
            $r['revision']++;
            return ['status' => 200, 'body' => ['revision' => (string)$r['revision']]];
        }
        return ['status' => 400, 'body' => ['code' => 'CB_IL02', 'message' => 'unsupported ' . $method . ' ' . $path]];
    }

    private function queryPeople(string $query): array
    {
        if (preg_match('/^(student_id|mail) in \((.*)\) limit/', $query, $m) !== 1) {
            return [];
        }
        preg_match_all('/"([^"]*)"/', $m[2], $values);
        $out = [];
        foreach ($this->people as $person) {
            if (in_array((string)$person[$m[1]], $values[1], true)) {
                $row = ['$id' => ['value' => (string)$person['id']], '$revision' => ['value' => (string)$person['revision']]];
                foreach (['student_id', 'family_name', 'personal_name', 'mail', 'depend', 'type', 'org_depend_state'] as $code) {
                    $row[$code] = ['value' => (string)($person[$code] ?? '')];
                }
                $row['name'] = ['value' => $person['family_name'] . $person['personal_name']];
                $out[] = $row;
            }
        }
        return $out;
    }

    private function queryOfficers(string $query): array
    {
        preg_match('/group_id = "([^"]+)"/', $query, $m);
        $out = [];
        foreach ($this->officerRecords as $record) {
            if ($record['group_id'] !== ($m[1] ?? '')) {
                continue;
            }
            $row = [
                '$id' => ['value' => (string)$record['id']],
                '$revision' => ['value' => (string)$record['revision']],
                'group_id' => ['value' => $record['group_id']],
                'group_name' => ['value' => $record['group_name']],
                'group_org' => ['value' => array_map(static fn(string $c): array => ['code' => $c, 'name' => $c], $record['group_org'] ?? [$record['group_id']])],
                'register_date' => ['value' => $record['register_date'] ?? ''],
            ];
            if (isset($record['group_key'])) {
                $row['forms_current_group_key'] = ['value' => $record['group_key']];
            }
            foreach ($record['slots'] ?? [] as $code => $value) {
                $row[$code] = ['value' => $value];
            }
            $out[] = $row;
        }
        return array_reverse($out);
    }

    private function peopleFields(): array
    {
        $fields = [
            'student_id' => ['type' => 'SINGLE_LINE_TEXT', 'unique' => true],
            'family_name' => ['type' => 'SINGLE_LINE_TEXT'],
            'personal_name' => ['type' => 'SINGLE_LINE_TEXT'],
            'name' => ['type' => 'SINGLE_LINE_TEXT'],
            'mail' => ['type' => 'LINK', 'unique' => true],
            'depend' => ['type' => 'DROP_DOWN', 'options' => array_fill_keys(['知能機械工学科', '生命環境化学科', '電子情報工学科', '電気工学科', '情報工学科', '情報通信工学科', '情報システム工学科', 'システムマネジメント学科', '情報マネジメント学科', '社会環境学科', '短期大学部', '大学院', '教養力育成センター', '職員', 'その他'], [])],
            'type' => ['type' => 'DROP_DOWN', 'options' => array_fill_keys(['大学 学生', '短期大学部 学生', '大学院 学生', '教職員', 'システムユーザ'], [])],
            'org_depend_state' => ['type' => 'RADIO_BUTTON', 'options' => array_fill_keys(['有', '無'], [])],
        ];
        if (!$this->officerFieldsComplete) {
            unset($fields['org_depend_state']);
            $fields['mail'] = ['type' => 'LINK', 'unique' => false];
        }
        return $fields;
    }

    private function officerFields(): array
    {
        $fields = [
            'group_id' => ['type' => 'SINGLE_LINE_TEXT', 'lookup' => null],
            'group_name' => ['type' => 'SINGLE_LINE_TEXT'],
            'group_org' => ['type' => 'ORGANIZATION_SELECT'],
            'register_date' => ['type' => 'DATE'],
        ];
        foreach (['representative', 'deputy_representative_1', 'deputy_representative_2', 'accountant', 'deputy_accountant_1', 'deputy_accountant_2', 'general_affairs_1', 'general_affairs_2', 'general_affairs_3'] as $prefix) {
            $required = $this->officerFieldsMigrated
                ? in_array($prefix, ['representative', 'deputy_representative_1', 'accountant'], true)
                : in_array($prefix, ['representative', 'accountant', 'general_affairs_1'], true);
            $fields[$prefix . '_stundet_id'] = ['type' => 'SINGLE_LINE_TEXT', 'lookup' => null, 'required' => $required];
            $fields[$prefix . '_name'] = ['type' => 'SINGLE_LINE_TEXT', 'required' => $required];
            $fields[$prefix . '_mail'] = ['type' => 'LINK', 'required' => $required];
        }
        if ($this->officerFieldsMigrated) {
            $fields['forms_current_group_key'] = ['type' => 'SINGLE_LINE_TEXT', 'required' => true, 'unique' => true];
        }
        if (!$this->officerFieldsComplete) {
            unset($fields['accountant_stundet_id']);
        }
        return $fields;
    }

    /** ID21 / ID22 への書込み回数（第2段階の最初のPRでは常に0回） */
    public function officerWrites(): int
    {
        return count(array_filter($this->appCalls, static fn(array $c): bool => $c[0] !== 'GET' && in_array($c[1], [21, 22], true)));
    }

    /** kintone のテーブル更新: 行IDありは更新、なしは追加、送られなかった行は削除。存在しないフィールドは無視。 */
    private function applyTable(array $currentRows, array $incoming): array
    {
        $known = ['name', 'student_id', 'phone_number', 'role', 'role_name'];
        if ($this->newFieldsPresent) {
            array_push($known, 'membership_status', 'uses_group_email', 'is_website_editor');
        }
        $byId = [];
        foreach ($currentRows as $row) {
            $byId[$row['id']] = $row;
        }
        $out = [];
        foreach ($incoming as $row) {
            $id = isset($row['id']) ? (string)$row['id'] : (string)$this->nextRowId++;
            $values = [];
            foreach ($known as $code) {
                // 送られなかった項目は空にする（仕様に明記がないため、厳しい側で模擬する）
                $values[$code] = array_key_exists($code, $row['value'] ?? []) ? $row['value'][$code]['value'] : (in_array($code, ['uses_group_email', 'is_website_editor'], true) ? [] : '');
            }
            $out[] = ['id' => $id, 'values' => $values];
        }
        return $out;
    }

    private function export(array $r): array
    {
        $types = ['name' => 'SINGLE_LINE_TEXT', 'student_id' => 'SINGLE_LINE_TEXT', 'phone_number' => 'SINGLE_LINE_TEXT', 'role' => 'DROP_DOWN', 'role_name' => 'SINGLE_LINE_TEXT', 'membership_status' => 'DROP_DOWN', 'uses_group_email' => 'CHECK_BOX', 'is_website_editor' => 'CHECK_BOX'];
        $rows = [];
        foreach ($r['list'] as $row) {
            $value = [];
            foreach ($row['values'] as $code => $v) {
                if (!$this->newFieldsPresent && in_array($code, ['membership_status', 'uses_group_email', 'is_website_editor'], true)) {
                    continue;
                }
                $value[$code] = ['type' => $types[$code], 'value' => $v];
            }
            $rows[] = ['id' => $row['id'], 'value' => $value];
        }
        return [
            '$id' => ['type' => '__ID__', 'value' => (string)$r['id']],
            '$revision' => ['type' => '__REVISION__', 'value' => (string)$r['revision']],
            'group_id' => ['type' => 'SINGLE_LINE_TEXT', 'value' => $r['group_id']],
            'group_name' => ['type' => 'SINGLE_LINE_TEXT', 'value' => $r['group_name']],
            'list' => ['type' => 'SUBTABLE', 'value' => $rows],
        ];
    }

    /** kintone 標準画面からの直接編集を模擬する */
    public function directEdit(int $recordId, callable $fn): void
    {
        $fn($this->records[$recordId]);
        $this->records[$recordId]['revision']++;
    }

    public function seedRecord(string $groupId, array $rows): int
    {
        $id = $this->nextRecordId++;
        $list = [];
        foreach ($rows as $values) {
            $list[] = ['id' => (string)$this->nextRowId++, 'values' => $values + ['name' => '', 'student_id' => '', 'phone_number' => '', 'role' => '-', 'role_name' => '', 'membership_status' => '', 'uses_group_email' => [], 'is_website_editor' => []]];
        }
        $group = array_values(array_filter($this->groups, fn($g) => $g['id'] === $groupId))[0];
        $this->records[$id] = ['id' => $id, 'revision' => 5, 'group_id' => $groupId, 'group_name' => $group['name'], 'list' => $list];
        return $id;
    }

    public function rows(int $recordId): array
    {
        return $this->records[$recordId]['list'];
    }
}
