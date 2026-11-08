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
                foreach (['records', 'nextRecordId', 'nextRowId', 'newFieldsPresent', 'groups'] as $key) {
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
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    public function handle(string $method, string $path, array $payload, int $tokens): array
    {
        $this->calls[] = $method . ' ' . $path;
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
