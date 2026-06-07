<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/repository.php';

function mail_kintone_config(): array
{
    $config = mail_load_config();

    // 通常形: config.local.php の return 配列内に 'kintone' => [...] を置く。
    $kintone = $config['kintone'] ?? null;
    if (is_array($kintone)) {
        return $kintone;
    }

    // 互換形: 'mail_kintone' => [...] として置いた場合も読む。
    $mailKintone = $config['mail_kintone'] ?? null;
    if (is_array($mailKintone)) {
        return $mailKintone;
    }

    // 誤配置救済: kintone設定配列だけを直接返してしまった場合も読む。
    // 例: return ['enabled' => true, 'organization_app_id' => 123, ...];
    $directConfigKeys = [
        'organization_app_id',
        'organization_api_token',
        'representative_app_id',
        'representative_api_token',
        'organization_fields',
        'representative_fields',
        'field_map',
    ];
    foreach ($directConfigKeys as $key) {
        if (array_key_exists($key, $config)) {
            return $config;
        }
    }

    return [];
}

function mail_kintone_config_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int)$value === 1;
    }
    $value = strtolower(trim((string)$value));
    return in_array($value, ['1', 'true', 'yes', 'on', 'enabled', '有効'], true);
}

function mail_kintone_enabled(): bool
{
    $config = mail_kintone_config();
    return mail_kintone_config_bool($config['enabled'] ?? false);
}

function mail_kintone_default_field_map(): array
{
    return [
        'identifier' => 'id',
        'name' => 'name',
        'category' => 'rank',
        'email' => 'mail',
        'status' => 'status',
        'representative_group_id' => 'group_id',
        'representative_name' => 'representative_name',
    ];
}

function mail_kintone_field_map(): array
{
    $config = mail_kintone_config();
    $map = $config['field_map'] ?? [];
    if (!is_array($map)) {
        $map = [];
    }
    return array_merge(mail_kintone_default_field_map(), array_map('strval', $map));
}

function mail_kintone_is_configured(): array
{
    $config = mail_kintone_config();
    $missing = [];

    if (!mail_kintone_enabled()) {
        return [false, ['kintone.enabled']];
    }

    $hasBase = trim((string)($config['base_url'] ?? '')) !== '' || trim((string)($config['subdomain'] ?? '')) !== '';
    if (!$hasBase) {
        $missing[] = 'subdomain または base_url';
    }

    foreach (['organization_app_id', 'organization_api_token', 'representative_app_id', 'representative_api_token'] as $key) {
        if (trim((string)($config[$key] ?? '')) === '') {
            $missing[] = $key;
        }
    }

    return [$missing === [], $missing];
}

function mail_kintone_base_url(): string
{
    $config = mail_kintone_config();
    $baseUrl = trim((string)($config['base_url'] ?? ''));
    if ($baseUrl !== '') {
        return rtrim($baseUrl, '/');
    }

    $subdomain = trim((string)($config['subdomain'] ?? ''));
    if ($subdomain === '') {
        throw new RuntimeException('kintoneのsubdomainまたはbase_urlが設定されていません。');
    }
    if (preg_match('/^https?:\/\//i', $subdomain) === 1) {
        return rtrim($subdomain, '/');
    }
    if (str_contains($subdomain, '.')) {
        return 'https://' . rtrim($subdomain, '/');
    }
    return 'https://' . $subdomain . '.cybozu.com';
}

function mail_kintone_http_get(string $url, string $apiToken): array
{
    $headers = [
        'X-Cybozu-API-Token: ' . $apiToken,
        'Accept: application/json',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('kintone API通信を初期化できません。');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('kintone API通信に失敗しました: ' . $error);
        }
        return ['status' => $status, 'body' => (string)$body];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 60,
        ],
    ]);
    $body = file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) {
            $status = (int)$m[1];
            break;
        }
    }
    if ($body === false) {
        throw new RuntimeException('kintone API通信に失敗しました。');
    }
    return ['status' => $status, 'body' => (string)$body];
}

function mail_kintone_records_url(int $appId, array $fields, string $query): string
{
    $params = [
        'app' => $appId,
    ];
    if (trim($query) !== '') {
        $params['query'] = $query;
    }
    foreach (array_values($fields) as $i => $field) {
        $field = trim((string)$field);
        if ($field !== '') {
            $params['fields[' . $i . ']'] = $field;
        }
    }

    return mail_kintone_base_url() . '/k/v1/records.json?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function mail_kintone_fetch_records(int $appId, string $apiToken, array $fields, string $baseQuery = ''): array
{
    if ($appId <= 0) {
        throw new InvalidArgumentException('kintoneアプリIDが不正です。');
    }
    if (trim($apiToken) === '') {
        throw new InvalidArgumentException('kintone APIトークンが設定されていません。');
    }

    $records = [];
    $limit = 500;
    $offset = 0;
    do {
        $query = trim($baseQuery);
        $query = trim($query . ' limit ' . $limit . ' offset ' . $offset);
        $url = mail_kintone_records_url($appId, $fields, $query);
        $response = mail_kintone_http_get($url, $apiToken);
        $payload = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($payload)) {
            $message = is_array($payload) ? (string)($payload['message'] ?? $payload['code'] ?? '') : '';
            throw new RuntimeException('kintoneレコード取得に失敗しました: HTTP ' . $response['status'] . ($message !== '' ? ' / ' . $message : ''));
        }
        $pageRecords = is_array($payload['records'] ?? null) ? $payload['records'] : [];
        $records = array_merge($records, $pageRecords);
        $count = count($pageRecords);
        $offset += $limit;
    } while ($count === $limit);

    return $records;
}

function mail_kintone_field_value(array $record, string $fieldCode): string
{
    if ($fieldCode === '' || !isset($record[$fieldCode]) || !is_array($record[$fieldCode])) {
        return '';
    }
    $value = $record[$fieldCode]['value'] ?? '';
    if (is_array($value)) {
        $parts = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $parts[] = (string)($item['name'] ?? $item['code'] ?? $item['value'] ?? '');
            } else {
                $parts[] = (string)$item;
            }
        }
        return trim(implode('、', array_filter($parts, static fn(string $part): bool => $part !== '')));
    }
    return trim((string)$value);
}

function mail_kintone_configured_fields(string $key, array $defaultFields): array
{
    $config = mail_kintone_config();
    $fields = $config[$key] ?? $defaultFields;
    if (!is_array($fields)) {
        $fields = $defaultFields;
    }
    $clean = [];
    foreach ($fields as $field) {
        $field = trim((string)$field);
        if ($field !== '') {
            $clean[$field] = $field;
        }
    }
    return array_values($clean);
}

function mail_kintone_quote_query_value(string $value): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\"'], $value) . '"';
}

function mail_kintone_dropdown_in_query(string $fieldCode, string $value): string
{
    $fieldCode = trim($fieldCode);
    if ($fieldCode === '') {
        throw new InvalidArgumentException('kintoneのフィールドコードが空です。');
    }
    return $fieldCode . ' in (' . mail_kintone_quote_query_value($value) . ')';
}

function mail_kintone_default_organization_query(array $map): string
{
    return mail_kintone_dropdown_in_query((string)$map['status'], '活動中') . ' order by ' . (string)$map['identifier'] . ' asc';
}

function mail_kintone_normalize_dropdown_query(string $query, string $fieldCode): string
{
    $fieldCode = trim($fieldCode);
    if ($query === '' || $fieldCode === '') {
        return $query;
    }

    // kintoneのドロップダウンフィールドでは「=」が使えないため、過去設定との互換として
    // status = "活動中" / status = "活動中" を status in ("活動中") へ自動補正する。
    $pattern = '/(?<![A-Za-z0-9_])' . preg_quote($fieldCode, '/') . '\s*=\s*(["\'])(.*?)\1/u';
    return preg_replace_callback($pattern, static function (array $matches) use ($fieldCode): string {
        return mail_kintone_dropdown_in_query($fieldCode, (string)$matches[2]);
    }, $query) ?? $query;
}

function mail_kintone_ensure_schema(PDO $pdo): void
{
    if (!mail_column_exists($pdo, 'mail_organizations', 'external_source')) {
        $pdo->exec('ALTER TABLE mail_organizations ADD COLUMN external_source VARCHAR(32) DEFAULT NULL AFTER is_active');
    }
    if (!mail_column_exists($pdo, 'mail_organizations', 'external_id')) {
        $after = mail_column_exists($pdo, 'mail_organizations', 'external_source') ? 'external_source' : 'is_active';
        $pdo->exec('ALTER TABLE mail_organizations ADD COLUMN external_id VARCHAR(191) DEFAULT NULL AFTER ' . $after);
    }
    if (!mail_column_exists($pdo, 'mail_organizations', 'synced_at')) {
        $after = mail_column_exists($pdo, 'mail_organizations', 'external_id') ? 'external_id' : 'is_active';
        $pdo->exec('ALTER TABLE mail_organizations ADD COLUMN synced_at DATETIME DEFAULT NULL AFTER ' . $after);
    }

    try {
        $indexes = $pdo->query('SHOW INDEX FROM mail_organizations')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $hasExternalIndex = false;
        foreach ($indexes as $index) {
            if ((string)($index['Key_name'] ?? '') === 'idx_mail_organizations_external') {
                $hasExternalIndex = true;
                break;
            }
        }
        if (!$hasExternalIndex) {
            $pdo->exec('ALTER TABLE mail_organizations ADD KEY idx_mail_organizations_external (external_source, external_id)');
        }
    } catch (Throwable $e) {
        error_log('[mail kintone schema] ' . $e->getMessage());
    }
}

function mail_kintone_representatives_by_group_id(): array
{
    [$ready, $missing] = mail_kintone_is_configured();
    if (!$ready) {
        throw new RuntimeException('kintone設定が不足しています: ' . implode(', ', $missing));
    }

    $config = mail_kintone_config();
    $map = mail_kintone_field_map();
    $groupField = (string)$map['representative_group_id'];
    $nameField = (string)$map['representative_name'];
    $fields = mail_kintone_configured_fields('representative_fields', [$groupField, $nameField]);
    $query = trim((string)($config['representative_query'] ?? ('order by ' . $groupField . ' asc')));

    $records = mail_kintone_fetch_records(
        (int)$config['representative_app_id'],
        (string)$config['representative_api_token'],
        $fields,
        $query
    );

    $representatives = [];
    $duplicates = 0;
    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        $groupId = mail_kintone_field_value($record, $groupField);
        $name = mail_kintone_field_value($record, $nameField);
        if ($groupId === '' || $name === '') {
            continue;
        }
        if (isset($representatives[$groupId])) {
            $duplicates++;
        }
        $representatives[$groupId] = $name;
    }

    return ['map' => $representatives, 'records' => count($records), 'duplicates' => $duplicates];
}

function mail_kintone_find_organization_row(PDO $pdo, string $identifier, string $externalId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM mail_organizations ' .
        'WHERE (external_source = :source AND external_id = :external_id) OR identifier = :identifier ' .
        'ORDER BY CASE WHEN external_source = :source_order AND external_id = :external_id_order THEN 0 ELSE 1 END, id ASC LIMIT 1'
    );
    $stmt->execute([
        ':source' => 'kintone',
        ':external_id' => $externalId,
        ':identifier' => $identifier,
        ':source_order' => 'kintone',
        ':external_id_order' => $externalId,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function mail_kintone_sync_organizations(PDO $pdo, ?array $actor = null): array
{
    [$ready, $missing] = mail_kintone_is_configured();
    if (!$ready) {
        throw new RuntimeException('kintone設定が不足しています: ' . implode(', ', $missing));
    }

    mail_kintone_ensure_schema($pdo);

    $config = mail_kintone_config();
    $map = mail_kintone_field_map();
    $representativeInfo = mail_kintone_representatives_by_group_id();
    $representatives = $representativeInfo['map'];

    $orgFields = mail_kintone_configured_fields('organization_fields', [
        (string)$map['identifier'],
        (string)$map['name'],
        (string)$map['category'],
        (string)$map['email'],
        (string)$map['status'],
    ]);
    $orgQuery = trim((string)($config['organization_query'] ?? mail_kintone_default_organization_query($map)));
    $orgQuery = mail_kintone_normalize_dropdown_query($orgQuery, (string)$map['status']);

    $orgRecords = mail_kintone_fetch_records(
        (int)$config['organization_app_id'],
        (string)$config['organization_api_token'],
        $orgFields,
        $orgQuery
    );

    $pdo->beginTransaction();
    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $invalidEmail = 0;
    $missingRepresentative = 0;
    $seenExternalIds = [];
    $errors = [];

    try {
        foreach ($orgRecords as $index => $record) {
            if (!is_array($record)) {
                $skipped++;
                continue;
            }

            $status = mail_kintone_field_value($record, (string)$map['status']);
            if ($status !== '' && $status !== '活動中') {
                $skipped++;
                continue;
            }

            $identifier = mail_normalize_identifier(mail_kintone_field_value($record, (string)$map['identifier']));
            $name = mail_kintone_field_value($record, (string)$map['name']);
            if ($identifier === '' || $name === '') {
                $skipped++;
                $errors[] = '団体レコード' . ($index + 1) . '行目: 識別記号または団体名が空です。';
                continue;
            }

            $rawEmail = mail_kintone_field_value($record, (string)$map['email']);
            $email = mail_normalize_email($rawEmail);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalidEmail++;
                $email = '';
            }

            $representativeName = $representatives[$identifier] ?? '';
            if ($representativeName === '') {
                $missingRepresentative++;
            }

            $category = mail_kintone_field_value($record, (string)$map['category']);
            $externalId = $identifier;
            $seenExternalIds[$externalId] = $externalId;

            $row = mail_kintone_find_organization_row($pdo, $identifier, $externalId);
            $params = [
                ':identifier' => $identifier,
                ':name' => $name,
                ':representative_name' => $representativeName !== '' ? $representativeName : null,
                ':email' => $email !== '' ? $email : null,
                ':category' => $category !== '' ? $category : null,
                ':external_source' => 'kintone',
                ':external_id' => $externalId,
            ];

            if ($row) {
                $params[':id'] = (int)$row['id'];
                $stmt = $pdo->prepare(
                    'UPDATE mail_organizations SET identifier = :identifier, name = :name, representative_name = :representative_name, ' .
                    'email = :email, category = :category, is_active = 1, external_source = :external_source, external_id = :external_id, synced_at = NOW() ' .
                    'WHERE id = :id'
                );
                $stmt->execute($params);
                $updated++;
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO mail_organizations (identifier, name, representative_name, email, category, notes, is_active, external_source, external_id, synced_at) ' .
                    'VALUES (:identifier, :name, :representative_name, :email, :category, NULL, 1, :external_source, :external_id, NOW())'
                );
                $stmt->execute($params);
                $inserted++;
            }
        }

        $deactivated = mail_kintone_deactivate_missing_organizations($pdo, array_values($seenExternalIds));

        mail_audit_log($pdo, $actor, 'mail.organization.kintone_sync', 'mail_organization', null, [
            'inserted' => $inserted,
            'updated' => $updated,
            'deactivated' => $deactivated,
            'skipped' => $skipped,
            'invalid_email' => $invalidEmail,
            'missing_representative' => $missingRepresentative,
        ]);

        $pdo->commit();
        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'deactivated' => $deactivated,
            'skipped' => $skipped,
            'invalid_email' => $invalidEmail,
            'missing_representative' => $missingRepresentative,
            'organization_records' => count($orgRecords),
            'representative_records' => (int)$representativeInfo['records'],
            'representative_duplicates' => (int)$representativeInfo['duplicates'],
            'errors' => $errors,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function mail_kintone_deactivate_missing_organizations(PDO $pdo, array $seenExternalIds): int
{
    $seen = [];
    foreach ($seenExternalIds as $externalId) {
        $externalId = trim((string)$externalId);
        if ($externalId !== '') {
            $seen[$externalId] = true;
        }
    }

    $stmt = $pdo->prepare('SELECT id, external_id FROM mail_organizations WHERE external_source = :source AND is_active = 1');
    $stmt->execute([':source' => 'kintone']);
    $rows = $stmt->fetchAll() ?: [];

    $update = $pdo->prepare('UPDATE mail_organizations SET is_active = 0, synced_at = NOW() WHERE id = :id');
    $count = 0;
    foreach ($rows as $row) {
        $externalId = trim((string)($row['external_id'] ?? ''));
        if ($externalId === '' || !isset($seen[$externalId])) {
            $update->execute([':id' => (int)$row['id']]);
            $count += $update->rowCount();
        }
    }

    return $count;
}

