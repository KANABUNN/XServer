<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/kintone_core/kintone_rest_client.php';

/* =====================================================================
   kintone アダプター（ID20 部員名簿・ID7 公認団体）
   - 既存の資格情報管理（kintone_credentials / kintone_apps）を使う。
   - 名簿の書込みは自動再送しない（既存 kintone_rest_request は 5xx で POST も再送するため使わない）。
   - 存在しないフィールドコードは kintone が黙って無視するため、書込み前に必ずフィールド検査を行い、
     書込み後は読み戻して照合する。
   ===================================================================== */

final class RosterKintoneError extends RuntimeException
{
    public function __construct(
        string $message,
        public int $httpStatus = 0,
        public string $kintoneCode = '',
        public array $errors = [],
        public bool $transport = false,
        public int $transportErrno = 0
    ) {
        parent::__construct($message, $httpStatus);
    }

    /** 送信前に失敗したことが確実な通信エラー（名前解決・接続失敗） */
    public function surelyNotSent(): bool
    {
        return $this->transport && in_array($this->transportErrno, [6, 7, 35, 60], true);
    }

    public function summary(): array
    {
        $fields = [];
        foreach ($this->errors as $path => $info) {
            $messages = is_array($info['messages'] ?? null) ? $info['messages'] : [];
            $fields[] = mb_substr((string)$path . ': ' . implode(' / ', array_map('strval', $messages)), 0, 300, 'UTF-8');
            if (count($fields) >= 10) {
                break;
            }
        }
        return array_filter([
            'http_status' => $this->httpStatus ?: null,
            'code' => $this->kintoneCode ?: null,
            'transport_errno' => $this->transportErrno ?: null,
            'fields' => $fields ?: null,
        ], static fn($v) => $v !== null);
    }
}

/** テスト用: HTTP 送信を差し替える。fn(string $method, string $path, array $payload, int $tokenCount): array{status:int, body:array} */
function roster_kintone_test_transport(?callable $transport = null, bool $set = false): ?callable
{
    static $current = null;
    if ($set) {
        $current = $transport;
    }
    return $current;
}

/** 'roster'（ID20）/ 'group'（ID7）/ 'people'（ID22）/ 'officers'（ID21）の接続情報 */
function roster_kintone_app_context(string $which): array
{
    $appKey = (string)roster_config(match ($which) {
        'group' => 'kintone.group_app_key',
        'people' => 'kintone.people_app_key',
        'officers' => 'kintone.officer_app_key',
        default => 'kintone.roster_app_key',
    });
    if (roster_kintone_test_transport() !== null) {
        return [
            'app_key' => $appKey,
            'app_id' => (int)roster_config('kintone.test_app_ids.' . $which, ['group' => 7, 'people' => 22, 'officers' => 21][$which] ?? 20),
            'base_url' => 'https://roster-test.invalid',
            'token' => 'test-token-' . $which,
        ];
    }
    try {
        $credentials = kintone_credentials_for($appKey);
    } catch (Throwable $e) {
        throw new RosterKintoneError('kintone接続情報（app_key: ' . $appKey . '）が登録されていません。kintone管理サイトのアプリ設定で登録してください。', 0, 'ROSTER_NO_CREDENTIALS');
    }
    $appId = (int)($credentials['kintone_app_id'] ?? 0);
    if ($appId < 1) {
        throw new RosterKintoneError('kintoneアプリID（app_key: ' . $appKey . '）が未設定です。', 0, 'ROSTER_NO_APP_ID');
    }
    try {
        $token = kintone_api_token_from_credentials($credentials);
    } catch (Throwable $e) {
        throw new RosterKintoneError('kintone APIトークン（app_key: ' . $appKey . '）を読み出せません。', 0, 'ROSTER_NO_TOKEN');
    }
    return [
        'app_key' => $appKey,
        'app_id' => $appId,
        'base_url' => kintone_api_base_url($credentials),
        'token' => $token,
    ];
}

/** 再送なしの1回だけの kintone REST 呼び出し */
function roster_kintone_request(string $method, string $path, array $payload, array $contexts): array
{
    $method = strtoupper($method);
    $tokens = array_values(array_unique(array_filter(array_map(static fn(array $c): string => (string)$c['token'], $contexts))));
    $transport = roster_kintone_test_transport();
    if ($transport !== null) {
        $result = $transport($method, $path, $payload, count($tokens));
        $status = (int)($result['status'] ?? 200);
        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        if ($status < 200 || $status >= 300) {
            throw new RosterKintoneError(
                'kintone APIがエラーを返しました。HTTP ' . $status . ' / ' . (string)($body['code'] ?? '') . ' ' . (string)($body['message'] ?? ''),
                $status,
                (string)($body['code'] ?? ''),
                is_array($body['errors'] ?? null) ? $body['errors'] : []
            );
        }
        return $body;
    }
    if (!function_exists('curl_init')) {
        throw new RosterKintoneError('PHP の curl 拡張が無効なため kintone へ接続できません。', 0, 'ROSTER_NO_CURL');
    }
    $baseUrl = (string)($contexts[0]['base_url'] ?? '');
    $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    if ($method === 'GET' && $payload !== []) {
        $url .= '?' . http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
    }
    $headers = [
        'X-Cybozu-API-Token: ' . implode(',', $tokens),
        'Accept: application/json',
    ];
    if ($method !== 'GET') {
        $headers[] = 'Content-Type: application/json';
    }
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RosterKintoneError('kintone接続の初期化に失敗しました。', 0, '', [], true, 2);
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => max(5, (int)roster_config('kintone.timeout_seconds', 30)),
        CURLOPT_CONNECTTIMEOUT => max(3, (int)roster_config('kintone.connect_timeout_seconds', 10)),
    ]);
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, roster_json($payload));
    }
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if (!is_string($raw)) {
        throw new RosterKintoneError('kintone API通信に失敗しました（' . $errno . ': ' . $error . '）。', 0, '', [], true, $errno);
    }
    $body = json_decode($raw, true);
    $body = is_array($body) ? $body : [];
    if ($status < 200 || $status >= 300) {
        throw new RosterKintoneError(
            'kintone APIがエラーを返しました。HTTP ' . $status . ' / ' . (string)($body['code'] ?? '') . ' ' . mb_substr((string)($body['message'] ?? ''), 0, 300, 'UTF-8'),
            $status,
            (string)($body['code'] ?? ''),
            is_array($body['errors'] ?? null) ? $body['errors'] : []
        );
    }
    return $body;
}

/**
 * 例外を反映処理の分類へ変換する。
 * transient: 未適用が確実な一時障害（自動再試行） / unknown: 書込みの成否不明（照合が必要）
 * conflict: リビジョン不一致等 / permission / validation / config / not_found
 */
function roster_kintone_classify(Throwable $e, bool $isWrite): string
{
    if (!$e instanceof RosterKintoneError) {
        return $e instanceof PDOException ? 'transient' : 'config';
    }
    if (str_starts_with($e->kintoneCode, 'ROSTER_')) {
        return 'config';
    }
    if ($e->transport) {
        if ($e->surelyNotSent()) {
            return 'transient';
        }
        return $isWrite ? 'unknown' : 'transient';
    }
    $status = $e->httpStatus;
    $code = $e->kintoneCode;
    if ($status === 429) {
        return 'transient';
    }
    if ($status >= 500) {
        return $isWrite ? 'unknown' : 'transient';
    }
    if ($code === 'GAIA_CO02' || $status === 409) {
        return 'conflict';
    }
    if ($status === 401 || $status === 403 || in_array($code, ['CB_NO02', 'CB_AU01', 'GAIA_NO01', 'GAIA_IA02'], true)) {
        return 'permission';
    }
    if ($code === 'CB_VA01' || str_starts_with($code, 'GAIA_LO')) {
        return 'validation';
    }
    if ($status === 404 || in_array($code, ['GAIA_AP01', 'GAIA_RE01'], true)) {
        return 'not_found';
    }
    return 'config';
}

function roster_kintone_error_class_label(string $class): string
{
    return match ($class) {
        'transient' => '一時的な通信障害',
        'unknown' => '成否不明（照合が必要）',
        'conflict' => '競合',
        'permission' => '権限エラー',
        'validation' => '入力値・フィールド設定エラー',
        'config' => '設定エラー',
        'not_found' => '対象が見つからない',
        'verification' => '反映後の照合不一致',
        'invalid' => '承認の失効',
        default => $class,
    };
}

function roster_kintone_field_code(string $logical): string
{
    $code = roster_config('kintone.row_fields.' . $logical);
    if (!is_string($code) || $code === '') {
        $code = roster_config('kintone.record_fields.' . $logical);
    }
    return is_string($code) && $code !== '' ? $code : $logical;
}

function roster_kintone_query_string(string $value): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

/** kintone のテーブル行（生）を名簿行の形へ変換する。フィールドがアプリに無い項目は null。 */
function roster_kintone_normalize_row(array $rawRow): array
{
    $value = is_array($rawRow['value'] ?? null) ? $rawRow['value'] : [];
    $get = static function (string $logical) use ($value): mixed {
        $code = roster_kintone_field_code($logical);
        if (!array_key_exists($code, $value) || !is_array($value[$code])) {
            return null;
        }
        return $value[$code]['value'] ?? null;
    };
    $checkbox = static function (string $logical) use ($get): ?bool {
        $v = $get($logical);
        if ($v === null) {
            return null;
        }
        $option = (string)roster_config('kintone.checkbox_options.' . $logical, '');
        return is_array($v) && in_array($option, array_map('strval', $v), true);
    };
    $status = $get('membership_status');
    return [
        'kintone_row_id' => (string)($rawRow['id'] ?? ''),
        'membership_status' => ($status === null || (string)$status === '') ? null : (string)$status,
        'name' => (string)($get('name') ?? ''),
        'student_id' => (string)($get('student_id') ?? ''),
        'role' => (string)($get('role') ?? ''),
        'phone_number' => (string)($get('phone_number') ?? ''),
        'role_name' => (string)($get('role_name') ?? ''),
        'uses_group_email' => $checkbox('uses_group_email'),
        'is_website_editor' => $checkbox('is_website_editor'),
    ];
}

function roster_kintone_normalize_record(array $raw): array
{
    $tableCode = roster_kintone_field_code('table');
    $rawRows = is_array($raw[$tableCode]['value'] ?? null) ? $raw[$tableCode]['value'] : [];
    return [
        'record_id' => (int)($raw['$id']['value'] ?? 0),
        'revision' => (int)($raw['$revision']['value'] ?? 0),
        'group_code' => (string)($raw[roster_kintone_field_code('group_code')]['value'] ?? ''),
        'group_name' => (string)($raw[roster_kintone_field_code('group_name')]['value'] ?? ''),
        'rows' => array_map('roster_kintone_normalize_row', $rawRows),
        'raw_rows' => $rawRows,
    ];
}

/** ID20 から団体のレコードを検索する（0件・1件・複数件を呼び出し側で判定） */
function roster_kintone_find_group_records(string $groupCode): array
{
    $groupCode = roster_normalize_group_code($groupCode);
    if ($groupCode === '') {
        throw new RosterException('団体識別記号が不正です。');
    }
    $ctx = roster_kintone_app_context('roster');
    $query = roster_kintone_field_code('group_code') . ' = ' . roster_kintone_query_string($groupCode) . ' order by $id asc limit 10';
    $res = roster_kintone_request('GET', '/k/v1/records.json', ['app' => $ctx['app_id'], 'query' => $query], [$ctx]);
    $records = is_array($res['records'] ?? null) ? $res['records'] : [];
    $normalized = [];
    foreach ($records as $record) {
        if (is_array($record)) {
            $normalized[] = roster_kintone_normalize_record($record);
        }
    }
    return ['count' => count($normalized), 'records' => $normalized];
}

function roster_kintone_get_record(int $recordId): array
{
    $ctx = roster_kintone_app_context('roster');
    $res = roster_kintone_request('GET', '/k/v1/record.json', ['app' => $ctx['app_id'], 'id' => $recordId], [$ctx]);
    if (!is_array($res['record'] ?? null)) {
        throw new RosterKintoneError('kintoneレコードを取得できませんでした。', 0, 'ROSTER_EMPTY_RECORD');
    }
    return roster_kintone_normalize_record($res['record']);
}

/** ID20 のフィールド設定を検査する。errors があれば反映しない。 */
function roster_kintone_preflight(): array
{
    $ctx = roster_kintone_app_context('roster');
    $res = roster_kintone_request('GET', '/k/v1/app/form/fields.json', ['app' => $ctx['app_id']], [$ctx]);
    $props = is_array($res['properties'] ?? null) ? $res['properties'] : [];
    $errors = [];
    $warnings = [];
    $groupCode = roster_kintone_field_code('group_code');
    if (!isset($props[$groupCode])) {
        $errors[] = '団体識別番号フィールド「' . $groupCode . '」がありません。';
    } elseif (!array_key_exists('lookup', $props[$groupCode])) {
        // ルックアップのフィールドは lookup キーを持つ（参照先アプリの詳細を見る権限がないトークンでは値が null になる）
        $warnings[] = '「' . $groupCode . '」がルックアップではありません。';
    }
    $tableCode = roster_kintone_field_code('table');
    $table = $props[$tableCode] ?? null;
    if (!is_array($table) || (string)($table['type'] ?? '') !== 'SUBTABLE') {
        $errors[] = 'テーブル「' . $tableCode . '」がありません。';
        return ['ok' => false, 'errors' => $errors, 'warnings' => $warnings, 'app_id' => $ctx['app_id']];
    }
    $inner = is_array($table['fields'] ?? null) ? $table['fields'] : [];
    $expect = [
        'name' => ['SINGLE_LINE_TEXT', null],
        'student_id' => ['SINGLE_LINE_TEXT', null],
        'phone_number' => ['SINGLE_LINE_TEXT', null],
        'role' => ['DROP_DOWN', roster_role_values()],
        'membership_status' => ['DROP_DOWN', roster_status_options()],
        'uses_group_email' => ['CHECK_BOX', [(string)roster_config('kintone.checkbox_options.uses_group_email', '使用')]],
        'is_website_editor' => ['CHECK_BOX', [(string)roster_config('kintone.checkbox_options.is_website_editor', '担当')]],
    ];
    foreach ($expect as $logical => [$type, $options]) {
        $code = roster_kintone_field_code($logical);
        $field = $inner[$code] ?? null;
        $label = roster_field_label($logical) . '（' . $code . '）';
        if (!is_array($field)) {
            $errors[] = 'テーブル内に ' . $label . ' がありません。';
            continue;
        }
        if ((string)($field['type'] ?? '') !== $type) {
            $errors[] = $label . ' の型が ' . (string)($field['type'] ?? '?') . ' です（' . $type . ' が必要）。';
            continue;
        }
        if ($options !== null) {
            $actual = array_keys(is_array($field['options'] ?? null) ? $field['options'] : []);
            $missing = array_values(array_diff($options, array_map('strval', $actual)));
            if ($missing !== []) {
                $errors[] = $label . ' に選択肢「' . implode('・', $missing) . '」がありません。';
            }
        }
        if (in_array($logical, ['membership_status', 'uses_group_email', 'is_website_editor'], true)) {
            if (!empty($field['required'])) {
                $warnings[] = $label . ' が必須になっています。状態が未設定の既存行があると更新できません。必須にしないでください。';
            }
            $default = $field['defaultValue'] ?? null;
            if ((is_string($default) && $default !== '') || (is_array($default) && $default !== [])) {
                $warnings[] = $label . ' に初期値が設定されています。初期値なしを推奨します。';
            }
        }
    }
    if (!isset($inner[roster_kintone_field_code('role_name')])) {
        $warnings[] = '職名（' . roster_kintone_field_code('role_name') . '）がテーブルにありません。';
    }
    $copyable = roster_kintone_copyable_types();
    foreach ($inner as $code => $field) {
        $type = (string)($field['type'] ?? '');
        if (!in_array($type, $copyable, true) && !in_array($type, ['USER_SELECT', 'ORGANIZATION_SELECT', 'GROUP_SELECT'], true)) {
            $warnings[] = 'テーブル内の「' . $code . '」（' . $type . '）は反映時に値を引き継げない型です。';
        }
    }
    return ['ok' => $errors === [], 'errors' => $errors, 'warnings' => $warnings, 'app_id' => $ctx['app_id']];
}

function roster_kintone_copyable_types(): array
{
    return ['SINGLE_LINE_TEXT', 'MULTI_LINE_TEXT', 'RICH_TEXT', 'NUMBER', 'LINK', 'DROP_DOWN', 'RADIO_BUTTON', 'CHECK_BOX', 'MULTI_SELECT', 'DATE', 'TIME', 'DATETIME'];
}

/** 既存行の値をそのまま送り直せる形にする（送らなかった項目の扱いが仕様上明記されていないため、全項目を送る） */
function roster_kintone_copy_row_values(array $rawRow): array
{
    $out = [];
    foreach ((is_array($rawRow['value'] ?? null) ? $rawRow['value'] : []) as $code => $field) {
        if (!is_array($field)) {
            continue;
        }
        $type = (string)($field['type'] ?? '');
        if (in_array($type, roster_kintone_copyable_types(), true)) {
            $out[$code] = ['value' => $field['value'] ?? null];
        } elseif (in_array($type, ['USER_SELECT', 'ORGANIZATION_SELECT', 'GROUP_SELECT'], true)) {
            $codes = [];
            foreach ((is_array($field['value'] ?? null) ? $field['value'] : []) as $entity) {
                if (is_array($entity) && (string)($entity['code'] ?? '') !== '') {
                    $codes[] = ['code' => (string)$entity['code']];
                }
            }
            $out[$code] = ['value' => $codes];
        }
    }
    return $out;
}

function roster_kintone_managed_values(array $row): array
{
    $emailOption = (string)roster_config('kintone.checkbox_options.uses_group_email', '使用');
    $webOption = (string)roster_config('kintone.checkbox_options.is_website_editor', '担当');
    return [
        roster_kintone_field_code('membership_status') => ['value' => (string)$row['membership_status']],
        roster_kintone_field_code('name') => ['value' => (string)$row['name']],
        roster_kintone_field_code('student_id') => ['value' => (string)$row['student_id']],
        roster_kintone_field_code('role') => ['value' => (string)$row['role']],
        roster_kintone_field_code('phone_number') => ['value' => (string)$row['phone_number']],
        roster_kintone_field_code('uses_group_email') => ['value' => !empty($row['uses_group_email']) ? [$emailOption] : []],
        roster_kintone_field_code('is_website_editor') => ['value' => !empty($row['is_website_editor']) ? [$webOption] : []],
    ];
}

/**
 * 反映するテーブル値を組み立てる。
 * - 提出行: 対応する既存行は行IDを維持し、既存値（職名等）を引き継いだうえで管理項目だけを置き換える。
 * - 提出に無い既存行: 値を変えずにそのまま残す（削除しない）。
 */
function roster_kintone_build_table(array $snapshotRows, array $freshRecord): array
{
    $match = roster_match_rows($snapshotRows, $freshRecord['rows']);
    if ($match['ambiguous'] !== []) {
        throw new RosterKintoneError('学籍番号が一致する既存行が複数あり、対応を決められません（' . count($match['ambiguous']) . '件）。', 0, 'ROSTER_AMBIGUOUS_ROWS');
    }
    $rawById = [];
    foreach ($freshRecord['raw_rows'] as $raw) {
        $rawById[(string)($raw['id'] ?? '')] = $raw;
    }
    $table = [];
    $changed = false;
    foreach (array_values($snapshotRows) as $i => $row) {
        $pairId = $match['pairs'][$i];
        if ($pairId === null) {
            $table[] = ['value' => roster_kintone_managed_values($row)];
            $changed = true;
            continue;
        }
        $values = roster_kintone_copy_row_values($rawById[$pairId] ?? []);
        $values = array_merge($values, roster_kintone_managed_values($row));
        $table[] = ['id' => $pairId, 'value' => $values];
        $current = $match['by_id'][$pairId];
        foreach (roster_managed_fields() as $field) {
            if (roster_compare_value($field, $row[$field]) !== roster_compare_value($field, $current[$field])) {
                $changed = true;
            }
        }
    }
    foreach ($match['unmatched'] as $id) {
        $table[] = ['id' => $id, 'value' => roster_kintone_copy_row_values($rawById[$id] ?? [])];
    }
    return ['table' => $table, 'changed' => $changed, 'kept' => count($match['unmatched'])];
}

function roster_kintone_add_record(string $groupCode, array $table): array
{
    $roster = roster_kintone_app_context('roster');
    $group = roster_kintone_app_context('group');
    $res = roster_kintone_request('POST', '/k/v1/record.json', [
        'app' => $roster['app_id'],
        'record' => [
            // ルックアップはコピー元キーだけを送る（団体名・格・組織は kintone がコピーする）
            roster_kintone_field_code('group_code') => ['value' => $groupCode],
            roster_kintone_field_code('table') => ['value' => $table],
        ],
    ], [$roster, $group]);
    return ['record_id' => (int)($res['id'] ?? 0), 'revision' => (int)($res['revision'] ?? 0)];
}

function roster_kintone_update_record(int $recordId, int $expectedRevision, array $table): array
{
    $roster = roster_kintone_app_context('roster');
    $group = roster_kintone_app_context('group');
    $res = roster_kintone_request('PUT', '/k/v1/record.json', [
        'app' => $roster['app_id'],
        'id' => $recordId,
        'revision' => $expectedRevision,
        'record' => [
            roster_kintone_field_code('table') => ['value' => $table],
        ],
    ], [$roster, $group]);
    return ['revision' => (int)($res['revision'] ?? 0)];
}

/**
 * 反映後の照合。承認内容の各行が kintone 上で同じ値になっているか、残すべき既存行が消えていないかを確認する。
 */
function roster_kintone_verify(array $record, array $snapshotRows, array $mustKeepRowIds = []): array
{
    $match = roster_match_rows($snapshotRows, $record['rows']);
    $mismatches = [];
    $links = [];
    foreach (array_values($snapshotRows) as $i => $row) {
        $pairId = $match['pairs'][$i];
        if ($pairId === null) {
            // ログ・試行記録に残るため、学籍番号などの個人情報は含めず行番号だけにする
            $mismatches[] = ($i + 1) . '行目が kintone 上に見つかりません。';
            continue;
        }
        $current = $match['by_id'][$pairId];
        foreach (roster_managed_fields() as $field) {
            if (roster_compare_value($field, $row[$field]) !== roster_compare_value($field, $current[$field])) {
                $mismatches[] = ($i + 1) . '行目の' . roster_field_label($field) . 'が一致しません。';
            }
        }
        $links[(string)$row['student_id']] = (int)$pairId;
    }
    $present = array_fill_keys(array_map(static fn(array $r): string => (string)$r['kintone_row_id'], $record['rows']), true);
    foreach ($mustKeepRowIds as $id) {
        if (!isset($present[(string)$id])) {
            $mismatches[] = '既存行（行ID ' . $id . '）が kintone 上から消えています。';
        }
    }
    return ['ok' => $mismatches === [], 'mismatches' => $mismatches, 'links' => $links];
}

/** ID7 から団体一覧を取得する */
function roster_kintone_fetch_groups(): array
{
    $ctx = roster_kintone_app_context('group');
    $f = static fn(string $k): string => (string)roster_config('kintone.group_fields.' . $k, $k);
    $fields = ['$id', '$revision', $f('code'), $f('name'), $f('rank'), $f('status'), $f('mail')];
    $groups = [];
    $offset = 0;
    while (true) {
        $res = roster_kintone_request('GET', '/k/v1/records.json', [
            'app' => $ctx['app_id'],
            'fields' => $fields,
            'query' => 'order by $id asc limit 500 offset ' . $offset,
        ], [$ctx]);
        $records = is_array($res['records'] ?? null) ? $res['records'] : [];
        foreach ($records as $record) {
            $code = roster_normalize_group_code((string)($record[$f('code')]['value'] ?? ''));
            if ($code === '') {
                continue;
            }
            $groups[] = [
                'group_code' => $code,
                'group_name' => (string)($record[$f('name')]['value'] ?? ''),
                'group_rank' => (string)($record[$f('rank')]['value'] ?? ''),
                'activity_status' => (string)($record[$f('status')]['value'] ?? ''),
                'group_email' => trim((string)($record[$f('mail')]['value'] ?? '')),
                'kintone_record_id' => (int)($record['$id']['value'] ?? 0),
                'kintone_revision' => (int)($record['$revision']['value'] ?? 0),
            ];
        }
        if (count($records) < 500 || $offset >= 9500) {
            break;
        }
        $offset += 500;
    }
    return $groups;
}


/* ---------------- 第2段階: ID7 / ID22 / ID21 の読取り（書込みなし） ---------------- */

function roster_kintone_organization_codes(mixed $value): array
{
    $codes = [];
    foreach (is_array($value) ? $value : [] as $entity) {
        if (is_array($entity) && (string)($entity['code'] ?? '') !== '') {
            $codes[] = (string)$entity['code'];
        }
    }
    sort($codes, SORT_STRING);
    return $codes;
}

/** ID7 の団体レコード（キャッシュではなく現在値）。見つからなければ null。 */
function roster_kintone_find_group_master(string $groupCode): ?array
{
    $ctx = roster_kintone_app_context('group');
    $f = static fn(string $k): string => (string)roster_config('kintone.group_fields.' . $k, $k);
    $res = roster_kintone_request('GET', '/k/v1/records.json', [
        'app' => $ctx['app_id'],
        'fields' => ['$id', '$revision', $f('code'), $f('name'), $f('status'), 'org'],
        'query' => $f('code') . ' = ' . roster_kintone_query_string($groupCode) . ' limit 2',
    ], [$ctx]);
    $record = $res['records'][0] ?? null;
    if (!is_array($record)) {
        return null;
    }
    return [
        'record_id' => (int)($record['$id']['value'] ?? 0),
        'revision' => (int)($record['$revision']['value'] ?? 0),
        'name' => (string)($record[$f('name')]['value'] ?? ''),
        'status' => (string)($record[$f('status')]['value'] ?? ''),
        'org' => roster_kintone_organization_codes($record['org']['value'] ?? []),
    ];
}

/** ID22 を学籍番号またはメールの完全一致で検索する */
function roster_kintone_find_people(string $field, array $values): array
{
    $values = array_values(array_unique(array_filter(array_map('strval', $values), static fn(string $v): bool => $v !== '')));
    if ($values === []) {
        return [];
    }
    $ctx = roster_kintone_app_context('people');
    $people = [];
    foreach (array_chunk($values, 50) as $chunk) {
        $res = roster_kintone_request('GET', '/k/v1/records.json', [
            'app' => $ctx['app_id'],
            'fields' => ['$id', '$revision', 'student_id', 'family_name', 'personal_name', 'name', 'mail', 'depend', 'type', 'org_depend_state'],
            'query' => $field . ' in (' . implode(', ', array_map('roster_kintone_query_string', $chunk)) . ') limit 500',
        ], [$ctx]);
        foreach ((array)($res['records'] ?? []) as $record) {
            $get = static fn(string $code): string => (string)($record[$code]['value'] ?? '');
            $people[] = [
                'record_id' => (int)$get('$id'),
                'revision' => (int)$get('$revision'),
                'student_id' => $get('student_id'),
                'family_name' => $get('family_name'),
                'personal_name' => $get('personal_name'),
                'name' => $get('name'),
                'mail' => $get('mail'),
                'depend' => $get('depend'),
                'type' => $get('type'),
                'org_depend_state' => $get('org_depend_state'),
            ];
        }
    }
    return $people;
}

/**
 * ID21 を団体識別記号で検索する（0件・1件・複数件を呼び出し側で判定）。
 * 一意キー forms_current_group_key は移行前のアプリには存在しないため、fields で列挙せず全項目を受け取る。
 */
function roster_kintone_find_officer_records(string $groupCode): array
{
    $ctx = roster_kintone_app_context('officers');
    $res = roster_kintone_request('GET', '/k/v1/records.json', [
        'app' => $ctx['app_id'],
        'query' => 'group_id = ' . roster_kintone_query_string($groupCode) . ' order by $id desc limit 10',
    ], [$ctx]);
    $records = [];
    foreach ((array)($res['records'] ?? []) as $record) {
        $slots = [];
        foreach (roster_officer_slot_definitions() as $def) {
            $slots[$def['key']] = [
                'student_id' => (string)($record[$def['student_field']]['value'] ?? ''),
                'name' => (string)($record[$def['name_field']]['value'] ?? ''),
                'mail' => (string)($record[$def['mail_field']]['value'] ?? ''),
            ];
        }
        $records[] = [
            'record_id' => (int)($record['$id']['value'] ?? 0),
            'revision' => (int)($record['$revision']['value'] ?? 0),
            'group_name' => (string)($record['group_name']['value'] ?? ''),
            'group_org' => roster_kintone_organization_codes($record['group_org']['value'] ?? []),
            'register_date' => (string)($record['register_date']['value'] ?? ''),
            'group_key' => (string)($record['forms_current_group_key']['value'] ?? ''),
            'slots' => $slots,
        ];
    }
    return ['count' => count($records), 'records' => $records];
}

/** ID22 / ID21 のフィールド設定を検査する（第2段階の接続確認。書込みはしない） */
function roster_kintone_officer_preflight(): array
{
    $errors = [];
    $warnings = [];
    $writeBlockers = [];
    $isOn = static fn(mixed $value): bool => $value === true || $value === 'true';
    $check = static function (array $props, string $app, string $code, string $type, array $options = [], bool $unique = false) use (&$errors, $isOn): ?array {
        $field = $props[$code] ?? null;
        if (!is_array($field)) {
            $errors[] = $app . '：フィールド「' . $code . '」がありません。';
            return null;
        }
        if ((string)($field['type'] ?? '') !== $type) {
            $errors[] = $app . '：「' . $code . '」の型が ' . (string)($field['type'] ?? '?') . ' です（' . $type . ' が必要）。';
            return null;
        }
        if ($options !== []) {
            $missing = array_values(array_diff($options, array_map('strval', array_keys(is_array($field['options'] ?? null) ? $field['options'] : []))));
            if ($missing !== []) {
                $errors[] = $app . '：「' . $code . '」に選択肢「' . implode('・', $missing) . '」がありません。';
            }
        }
        if ($unique && !$isOn($field['unique'] ?? false)) {
            $errors[] = $app . '：「' . $code . '」が「値の重複を禁止」になっていません。';
        }
        return $field;
    };

    $peopleCtx = roster_kintone_app_context('people');
    $res = roster_kintone_request('GET', '/k/v1/app/form/fields.json', ['app' => $peopleCtx['app_id']], [$peopleCtx]);
    $props = is_array($res['properties'] ?? null) ? $res['properties'] : [];
    $check($props, 'ID22', 'student_id', 'SINGLE_LINE_TEXT', [], true);
    $check($props, 'ID22', 'family_name', 'SINGLE_LINE_TEXT');
    $check($props, 'ID22', 'personal_name', 'SINGLE_LINE_TEXT');
    $check($props, 'ID22', 'name', 'SINGLE_LINE_TEXT');
    $check($props, 'ID22', 'mail', 'LINK', [], true);
    $check($props, 'ID22', 'depend', 'DROP_DOWN', roster_officer_depend_options());
    $check($props, 'ID22', 'type', 'DROP_DOWN', roster_officer_type_options());
    $check($props, 'ID22', 'org_depend_state', 'RADIO_BUTTON', roster_officer_org_depend_options());

    $officerCtx = roster_kintone_app_context('officers');
    $res = roster_kintone_request('GET', '/k/v1/app/form/fields.json', ['app' => $officerCtx['app_id']], [$officerCtx]);
    $props = is_array($res['properties'] ?? null) ? $res['properties'] : [];
    $lookup = static function (?array $field, string $code) use (&$errors): void {
        // lookup の値は参照先の権限がないトークンでは null になるため、キーの有無で判定する
        if ($field !== null && !array_key_exists('lookup', $field)) {
            $errors[] = 'ID21：「' . $code . '」がルックアップではありません。';
        }
    };
    $lookup($check($props, 'ID21', 'group_id', 'SINGLE_LINE_TEXT'), 'group_id');
    $check($props, 'ID21', 'group_name', 'SINGLE_LINE_TEXT');
    $check($props, 'ID21', 'group_org', 'ORGANIZATION_SELECT');
    $check($props, 'ID21', 'register_date', 'DATE');
    foreach (roster_officer_slot_definitions() as $def) {
        $lookup($check($props, 'ID21', $def['student_field'], 'SINGLE_LINE_TEXT'), $def['student_field']);
        $check($props, 'ID21', $def['name_field'], 'SINGLE_LINE_TEXT');
        $check($props, 'ID21', $def['mail_field'], 'LINK');
    }
    // 業務ルールに合わせた ID21 の設定。プレビューには影響しないが、そろうまで書込みは有効にしない
    foreach (roster_officer_slot_definitions() as $def) {
        foreach ([$def['student_field'], $def['name_field'], $def['mail_field']] as $code) {
            if (!is_array($props[$code] ?? null) || $isOn($props[$code]['required'] ?? false) === $def['required']) {
                continue;
            }
            $writeBlockers[] = $def['required']
                ? 'ID21：「' . $code . '」を必須にしてください（' . $def['label'] . 'は必須の役職です）。'
                : 'ID21：「' . $code . '」の必須指定を解除してください（' . $def['label'] . 'を空席で保存できません）。';
        }
    }
    $groupKey = $props['forms_current_group_key'] ?? null;
    if (!is_array($groupKey)) {
        $writeBlockers[] = 'ID21：一意キー「forms_current_group_key」がありません（文字列・必須・値の重複禁止で追加します）。';
    } else {
        if ((string)($groupKey['type'] ?? '') !== 'SINGLE_LINE_TEXT') {
            $writeBlockers[] = 'ID21：「forms_current_group_key」は文字列（1行）にしてください。';
        }
        if (!$isOn($groupKey['required'] ?? false)) {
            $writeBlockers[] = 'ID21：「forms_current_group_key」を必須にしてください。';
        }
        if (!$isOn($groupKey['unique'] ?? false)) {
            $writeBlockers[] = 'ID21：「forms_current_group_key」を「値の重複を禁止」にしてください。';
        }
    }
    return ['ok' => $errors === [], 'errors' => $errors, 'warnings' => $warnings, 'write_blockers' => $writeBlockers, 'people_app_id' => $peopleCtx['app_id'], 'officers_app_id' => $officerCtx['app_id']];
}
