<?php
declare(strict_types=1);

require_once __DIR__ . '/storage_maintenance.php';


function forms_cfg_value(array $source, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $source)) {
            continue;
        }
        $value = $source[$key];
        if ($value === null) {
            continue;
        }
        return is_string($value) ? trim($value) : (string)$value;
    }
    return $default;
}

function forms_array_merge_replace(array $base, array $override): array
{
    foreach ($override as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
            $base[$key] = forms_array_merge_replace($base[$key], $value);
            continue;
        }
        $base[$key] = $value;
    }
    return $base;
}

function forms_load_shared_config(): array
{
    if (isset($GLOBALS['config']) && is_array($GLOBALS['config']) && isset($GLOBALS['config']['db']) && is_array($GLOBALS['config']['db'])) {
        return $GLOBALS['config'];
    }

    $candidates = [
        __DIR__ . '/config.php',
        __DIR__ . '/lend_core/config.php',
        dirname(__DIR__) . '/includes/config.php',
    ];

    foreach ($candidates as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }
        $config = require $candidate;
        if (is_array($config) && isset($config['db']) && is_array($config['db'])) {
            return $config;
        }
    }

    throw new RuntimeException('forms 用の共通設定を読み込めません。');
}

function forms_load_local_config(): array
{
    static $local = null;
    if (is_array($local)) {
        return $local;
    }

    $candidates = [
        __DIR__ . '/forms_config.php',
        __DIR__ . '/forms_config.local.php',
    ];

    foreach ($candidates as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }
        $loaded = require $candidate;
        if (is_array($loaded)) {
            $local = $loaded;
            return $local;
        }
    }

    $local = [];
    return $local;
}

function forms_runtime_config(): array
{
    static $runtime = null;
    if (is_array($runtime)) {
        return $runtime;
    }

    $runtime = forms_array_merge_replace(forms_load_shared_config(), forms_load_local_config());
    return $runtime;
}

function forms_db_name(): string
{
    $config = forms_runtime_config();
    $global = $config['forms']['db_name']
        ?? $config['forms_db']['database']
        ?? $config['forms_db']['dbname']
        ?? $config['forms_db_name']
        ?? null;
    $name = is_string($global) ? trim($global) : '';
    return $name !== '' ? $name : 'fitsc_forms';
}

function forms_build_dsn_from_parts(array $db, string $databaseName): string
{
    $driver = (string)($db['driver'] ?? 'mysql');
    if ($driver !== 'mysql') {
        throw new RuntimeException('forms は現在 mysql のみ対応しています。');
    }

    $parts = [];
    if (!empty($db['host'])) {
        $parts[] = 'host=' . $db['host'];
    }
    if (!empty($db['port'])) {
        $parts[] = 'port=' . $db['port'];
    }
    if (!empty($db['unix_socket'])) {
        $parts[] = 'unix_socket=' . $db['unix_socket'];
    }
    $parts[] = 'dbname=' . $databaseName;
    if (!empty($db['charset'])) {
        $parts[] = 'charset=' . $db['charset'];
    } else {
        $parts[] = 'charset=utf8mb4';
    }

    return 'mysql:' . implode(';', $parts);
}

function forms_rewrite_dsn_database(string $dsn, string $databaseName): string
{
    $trimmed = trim($dsn);
    if ($trimmed === '') {
        throw new RuntimeException('forms 用の DSN が空です。');
    }

    if (stripos($trimmed, 'mysql:') !== 0) {
        return $trimmed;
    }

    if (preg_match('/(^|;)dbname=[^;]*/i', $trimmed) === 1) {
        return preg_replace('/(^|;)dbname=[^;]*/i', '$1dbname=' . $databaseName, $trimmed, 1) ?? $trimmed;
    }

    return rtrim($trimmed, ';') . ';dbname=' . $databaseName;
}

function forms_db_config(): array
{
    $config = forms_runtime_config();

    $baseDb = is_array($config['db'] ?? null) ? $config['db'] : [];
    $baseUser = forms_cfg_value($baseDb, ['user', 'username']);
    $basePass = forms_cfg_value($baseDb, ['password', 'pass', 'passwd']);

    if (isset($config['forms_db']) && is_array($config['forms_db'])) {
        $formsDb = $config['forms_db'];
        $formsUser = forms_cfg_value($formsDb, ['user', 'username'], $baseUser);
        $formsPass = forms_cfg_value($formsDb, ['password', 'pass', 'passwd'], $basePass);

        if (!empty($formsDb['dsn'])) {
            return [
                'dsn' => (string)$formsDb['dsn'],
                'user' => $formsUser,
                'password' => $formsPass,
            ];
        }
        if (!empty($formsDb['database']) || !empty($formsDb['dbname']) || !empty($formsDb['host']) || !empty($formsDb['unix_socket'])) {
            $databaseName = trim((string)($formsDb['database'] ?? $formsDb['dbname'] ?? forms_db_name()));
            return [
                'dsn' => forms_build_dsn_from_parts($formsDb + $baseDb, $databaseName),
                'user' => $formsUser,
                'password' => $formsPass,
            ];
        }
    }

    $db = $baseDb;
    if ($db === []) {
        throw new RuntimeException('forms 用の DB 設定が不正です。');
    }

    $databaseName = forms_db_name();
    $dsn = '';
    if (!empty($db['dsn'])) {
        $dsn = forms_rewrite_dsn_database((string)$db['dsn'], $databaseName);
    } else {
        $dsn = forms_build_dsn_from_parts($db, $databaseName);
    }

    return [
        'dsn' => $dsn,
        'user' => $baseUser,
        'password' => $basePass,
    ];
}

function forms_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = forms_db_config();
    $pdo = new PDO(
        $cfg['dsn'],
        $cfg['user'],
        $cfg['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}


function forms_bootstrap(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }

    forms_ensure_schema();
    $booted = true;
}

function forms_ensure_schema(): void
{
    $pdo = forms_db();

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS managed_forms (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(120) NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    settings_json LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_managed_forms_slug (slug),
    KEY idx_managed_forms_active_sort (is_active, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS managed_form_fields (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    form_id BIGINT UNSIGNED NOT NULL,
    field_key VARCHAR(120) NOT NULL,
    field_label VARCHAR(150) NOT NULL,
    field_type VARCHAR(32) NOT NULL,
    placeholder VARCHAR(255) DEFAULT NULL,
    help_text VARCHAR(255) DEFAULT NULL,
    options_json LONGTEXT DEFAULT NULL,
    default_value TEXT DEFAULT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_managed_form_fields_form_key (form_id, field_key),
    KEY idx_managed_form_fields_form_sort (form_id, sort_order, id),
    CONSTRAINT fk_managed_form_fields_form FOREIGN KEY (form_id) REFERENCES managed_forms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS managed_form_submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    form_id BIGINT UNSIGNED NOT NULL,
    submitter_email VARCHAR(255) NOT NULL,
    normalized_email VARCHAR(255) NOT NULL,
    organization_name VARCHAR(255) NOT NULL,
    normalized_organization VARCHAR(255) NOT NULL,
    submitted_date DATE DEFAULT NULL,
    payload_json LONGTEXT DEFAULT NULL,
    uploaded_original_name VARCHAR(255) DEFAULT NULL,
    uploaded_stored_name VARCHAR(255) DEFAULT NULL,
    uploaded_relative_path VARCHAR(500) DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'new',
    admin_note TEXT DEFAULT NULL,
    status_updated_at DATETIME DEFAULT NULL,
    status_updated_by_user_id BIGINT DEFAULT NULL,
    status_updated_by_name VARCHAR(190) DEFAULT NULL,
    latest_revision_id BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_managed_form_submissions_form_updated (form_id, updated_at, id),
    KEY idx_managed_form_submissions_form_email (form_id, normalized_email),
    KEY idx_managed_form_submissions_form_org (form_id, normalized_organization),
    KEY idx_managed_form_submissions_form_status (form_id, status, updated_at, id),
    CONSTRAINT fk_managed_form_submissions_form FOREIGN KEY (form_id) REFERENCES managed_forms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS managed_form_submission_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id BIGINT UNSIGNED NOT NULL,
    form_id BIGINT UNSIGNED NOT NULL,
    revision_number INT UNSIGNED NOT NULL,
    submitter_email VARCHAR(255) NOT NULL,
    organization_name VARCHAR(255) NOT NULL,
    submitted_date DATE DEFAULT NULL,
    payload_json LONGTEXT DEFAULT NULL,
    uploaded_original_name VARCHAR(255) DEFAULT NULL,
    uploaded_stored_name VARCHAR(255) DEFAULT NULL,
    uploaded_relative_path VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_managed_form_submission_revisions_submission_revision (submission_id, revision_number),
    KEY idx_managed_form_submission_revisions_submission (submission_id, revision_number),
    KEY idx_managed_form_submission_revisions_form_created (form_id, created_at),
    CONSTRAINT fk_managed_form_submission_revisions_submission FOREIGN KEY (submission_id) REFERENCES managed_form_submissions (id) ON DELETE CASCADE,
    CONSTRAINT fk_managed_form_submission_revisions_form FOREIGN KEY (form_id) REFERENCES managed_forms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS managed_form_submission_status_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id BIGINT UNSIGNED NOT NULL,
    form_id BIGINT UNSIGNED NOT NULL,
    previous_status VARCHAR(32) DEFAULT NULL,
    next_status VARCHAR(32) NOT NULL,
    note TEXT DEFAULT NULL,
    changed_by_user_id BIGINT DEFAULT NULL,
    changed_by_name VARCHAR(190) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_managed_form_submission_status_logs_submission (submission_id, id),
    KEY idx_managed_form_submission_status_logs_form_created (form_id, created_at),
    CONSTRAINT fk_managed_form_submission_status_logs_submission FOREIGN KEY (submission_id) REFERENCES managed_form_submissions (id) ON DELETE CASCADE,
    CONSTRAINT fk_managed_form_submission_status_logs_form FOREIGN KEY (form_id) REFERENCES managed_forms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    forms_ensure_column($pdo, 'managed_form_submissions', 'status', "VARCHAR(32) NOT NULL DEFAULT 'new'");
    forms_ensure_column($pdo, 'managed_form_submissions', 'admin_note', 'TEXT DEFAULT NULL');
    forms_ensure_column($pdo, 'managed_form_submissions', 'status_updated_at', 'DATETIME DEFAULT NULL');
    forms_ensure_column($pdo, 'managed_form_submissions', 'status_updated_by_user_id', 'BIGINT DEFAULT NULL');
    forms_ensure_column($pdo, 'managed_form_submissions', 'status_updated_by_name', 'VARCHAR(190) DEFAULT NULL');
}

function forms_table_has_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name');
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

function forms_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (forms_table_has_column($pdo, $table, $column)) {
        return;
    }
    $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', str_replace('`', '``', $table), str_replace('`', '``', $column), $definition));
}

function forms_default_settings(): array
{
    return [
        'enable_date_field' => true,
        'date_required' => false,
        'date_label' => '希望日',
        'allow_file_upload' => false,
        'file_required' => false,
        'file_label' => '添付ファイル',
        'allowed_extensions' => 'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,zip',
        'max_upload_size_mb' => 5,
        'distribution_enabled' => false,
        'distribution_title' => '',
        'distribution_body' => '',
        'distribution_download_label' => '資料をダウンロード',
        'distribution_file_original_name' => '',
        'distribution_file_stored_name' => '',
        'distribution_file_relative_path' => '',
        'distribution_file_size_bytes' => 0,
        'distribution_file_uploaded_at' => '',
        'public_start_date' => '',
        'public_start_time' => '',
        'public_end_date' => '',
        'public_end_time' => '',
        'submit_button_label' => '送信する',
        'completion_message' => '送信を受け付けました。',
    ];
}

function forms_decode_settings(?string $json): array
{
    $defaults = forms_default_settings();
    if (!is_string($json) || trim($json) === '') {
        return $defaults;
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return $defaults;
    }
    return array_merge($defaults, $decoded);
}

function forms_encode_json($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function forms_sanitize_slug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?? '';
    $value = trim($value, '-_');
    if ($value === '') {
        $value = 'form';
    }
    return $value;
}

function forms_safe_download_name(string $value, string $fallback = 'file'): string
{
    $value = trim($value);
    if ($value === '') {
        return $fallback;
    }
    $value = preg_replace('/[\\\/\:\*\?"<>\|]+/u', '-', $value) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    $value = trim($value, " .-_\t\n\r\0\x0B");
    return $value !== '' ? $value : $fallback;
}

function forms_make_unique_slug(PDO $pdo, string $rawSlug, ?int $ignoreId = null): string
{
    $base = forms_sanitize_slug($rawSlug);
    if ($base === 'form') {
        $base .= '-' . date('YmdHis');
    }

    $slug = $base;
    $counter = 2;
    while (true) {
        $sql = 'SELECT id FROM managed_forms WHERE slug = :slug';
        $params = [':slug' => $slug];
        if ($ignoreId !== null) {
            $sql .= ' AND id != :id';
            $params[':id'] = $ignoreId;
        }
        $stmt = $pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        if (!$stmt->fetch()) {
            return $slug;
        }
        $slug = $base . '-' . $counter;
        $counter++;
    }
}

function forms_normalize_email(string $value): string
{
    return mb_strtolower(trim($value), 'UTF-8');
}

function forms_normalize_organization(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    return preg_replace('/\s+/u', ' ', $value) ?? $value;
}

function forms_build_form_record(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'slug' => (string)$row['slug'],
        'name' => (string)$row['name'],
        'description' => (string)($row['description'] ?? ''),
        'is_active' => (bool)$row['is_active'],
        'sort_order' => (int)$row['sort_order'],
        'settings' => forms_decode_settings($row['settings_json'] ?? null),
        'availability' => forms_public_period_context([
            'settings' => forms_decode_settings($row['settings_json'] ?? null),
            'is_active' => (bool)$row['is_active'],
        ]),
        'fields' => [],
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

function forms_fetch_forms(bool $activeOnly = false): array
{
    forms_bootstrap();
    $sql = 'SELECT * FROM managed_forms';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY is_active DESC, sort_order ASC, id ASC';
    $rows = forms_db()->query($sql)->fetchAll();
    if (!$rows) {
        return [];
    }

    $forms = [];
    $ids = [];
    foreach ($rows as $row) {
        $form = forms_build_form_record($row);
        $forms[$form['id']] = $form;
        $ids[] = $form['id'];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $fieldStmt = forms_db()->prepare('SELECT * FROM managed_form_fields WHERE form_id IN (' . $placeholders . ') ORDER BY sort_order ASC, id ASC');
    $fieldStmt->execute($ids);
    foreach ($fieldStmt->fetchAll() as $field) {
        $formId = (int)$field['form_id'];
        if (!isset($forms[$formId])) {
            continue;
        }
        $forms[$formId]['fields'][] = forms_build_field_record($field);
    }

    return array_values($forms);
}

function forms_build_field_record(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'field_key' => (string)$row['field_key'],
        'field_label' => (string)$row['field_label'],
        'field_type' => (string)$row['field_type'],
        'placeholder' => (string)($row['placeholder'] ?? ''),
        'help_text' => (string)($row['help_text'] ?? ''),
        'options' => forms_decode_options_json($row['options_json'] ?? null),
        'default_value' => (string)($row['default_value'] ?? ''),
        'is_required' => (bool)$row['is_required'],
        'is_enabled' => (bool)$row['is_enabled'],
        'sort_order' => (int)$row['sort_order'],
    ];
}

function forms_decode_options_json(?string $json): array
{
    if (!is_string($json) || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    return array_values(array_filter(array_map(static function ($value) {
        return trim((string)$value);
    }, $decoded), static function ($value) {
        return $value !== '';
    }));
}

function forms_load_form(int $formId, bool $activeOnly = false): ?array
{
    forms_bootstrap();
    $sql = 'SELECT * FROM managed_forms WHERE id = :id';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $stmt = forms_db()->prepare($sql . ' LIMIT 1');
    $stmt->execute([':id' => $formId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $form = forms_build_form_record($row);
    $fieldStmt = forms_db()->prepare('SELECT * FROM managed_form_fields WHERE form_id = :form_id ORDER BY sort_order ASC, id ASC');
    $fieldStmt->execute([':form_id' => $formId]);
    foreach ($fieldStmt->fetchAll() as $field) {
        $form['fields'][] = forms_build_field_record($field);
    }
    return $form;
}

function forms_normalize_boolean($value): bool
{
    return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
}

function forms_sanitize_fields(array $fields): array
{
    $allowedTypes = ['text', 'textarea', 'date', 'number', 'select', 'checkbox'];
    $result = [];
    $usedKeys = [];
    foreach (array_values($fields) as $index => $field) {
        if (!is_array($field)) {
            continue;
        }
        $label = trim((string)($field['field_label'] ?? ''));
        $key = forms_sanitize_slug((string)($field['field_key'] ?? ''));
        $key = str_replace('-', '_', $key);
        $type = (string)($field['field_type'] ?? 'text');
        if ($label === '' || $key === '') {
            continue;
        }
        if (in_array($key, ['email', 'organization_name', 'submitted_date', 'uploaded_file'], true)) {
            continue;
        }
        if (isset($usedKeys[$key])) {
            continue;
        }
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'text';
        }
        $options = [];
        $optionsRaw = trim((string)($field['options_text'] ?? ''));
        if ($optionsRaw !== '') {
            $parts = preg_split('/\r\n|\r|\n|,/', $optionsRaw) ?: [];
            foreach ($parts as $part) {
                $part = trim((string)$part);
                if ($part !== '') {
                    $options[] = $part;
                }
            }
        }
        $result[] = [
            'field_key' => $key,
            'field_label' => $label,
            'field_type' => $type,
            'placeholder' => trim((string)($field['placeholder'] ?? '')),
            'help_text' => trim((string)($field['help_text'] ?? '')),
            'options' => $options,
            'default_value' => trim((string)($field['default_value'] ?? '')),
            'is_required' => forms_normalize_boolean($field['is_required'] ?? false),
            'is_enabled' => forms_normalize_boolean($field['is_enabled'] ?? true),
            'sort_order' => $index,
        ];
        $usedKeys[$key] = true;
    }
    return $result;
}

function forms_save_form(array $formData, array $fields): array
{
    forms_bootstrap();
    $pdo = forms_db();

    $id = (int)($formData['id'] ?? 0);
    $name = trim((string)($formData['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('フォーム名を入力してください。');
    }

    $settings = forms_default_settings();
    if ($id > 0) {
        $existingSettingsStmt = $pdo->prepare('SELECT settings_json FROM managed_forms WHERE id = :id LIMIT 1');
        $existingSettingsStmt->execute([':id' => $id]);
        $existingSettingsJson = $existingSettingsStmt->fetchColumn();
        if (is_string($existingSettingsJson)) {
            $settings = forms_decode_settings($existingSettingsJson);
        }
    }

    $settings['enable_date_field'] = forms_normalize_boolean($formData['enable_date_field'] ?? false);
    $settings['date_required'] = forms_normalize_boolean($formData['date_required'] ?? false);
    $settings['date_label'] = trim((string)($formData['date_label'] ?? $settings['date_label'])) ?: '希望日';
    $settings['allow_file_upload'] = forms_normalize_boolean($formData['allow_file_upload'] ?? false);
    $settings['file_required'] = forms_normalize_boolean($formData['file_required'] ?? false);
    $settings['file_label'] = trim((string)($formData['file_label'] ?? $settings['file_label'])) ?: '添付ファイル';
    $settings['allowed_extensions'] = trim((string)($formData['allowed_extensions'] ?? $settings['allowed_extensions'])) ?: $settings['allowed_extensions'];
    $settings['max_upload_size_mb'] = max(1, min(30, (int)($formData['max_upload_size_mb'] ?? $settings['max_upload_size_mb'])));
    $settings['distribution_enabled'] = forms_normalize_boolean($formData['distribution_enabled'] ?? false);
    $settings['distribution_title'] = mb_substr(trim((string)($formData['distribution_title'] ?? '')), 0, 150, 'UTF-8');
    $settings['distribution_body'] = mb_substr(trim((string)($formData['distribution_body'] ?? '')), 0, 5000, 'UTF-8');
    $settings['distribution_download_label'] = mb_substr(trim((string)($formData['distribution_download_label'] ?? '資料をダウンロード')), 0, 80, 'UTF-8') ?: '資料をダウンロード';
    $settings['public_start_date'] = forms_validate_filter_date($formData['public_start_date'] ?? $settings['public_start_date'] ?? '');
    $settings['public_start_time'] = forms_validate_filter_time($formData['public_start_time'] ?? $settings['public_start_time'] ?? '');
    $settings['public_end_date'] = forms_validate_filter_date($formData['public_end_date'] ?? $settings['public_end_date'] ?? '');
    $settings['public_end_time'] = forms_validate_filter_time($formData['public_end_time'] ?? $settings['public_end_time'] ?? '');
    if (($formData['public_start_date'] ?? '') !== '' && $settings['public_start_date'] === '') {
        throw new InvalidArgumentException('公開開始日の形式が不正です。');
    }
    if (($formData['public_start_time'] ?? '') !== '' && $settings['public_start_time'] === '') {
        throw new InvalidArgumentException('受付開始時刻の形式が不正です。');
    }
    if (($formData['public_end_date'] ?? '') !== '' && $settings['public_end_date'] === '') {
        throw new InvalidArgumentException('公開終了日の形式が不正です。');
    }
    if (($formData['public_end_time'] ?? '') !== '' && $settings['public_end_time'] === '') {
        throw new InvalidArgumentException('受付終了時刻の形式が不正です。');
    }
    if ($settings['public_start_time'] !== '' && $settings['public_start_date'] === '') {
        throw new InvalidArgumentException('受付開始時刻を設定する場合は公開開始日も設定してください。');
    }
    if ($settings['public_end_time'] !== '' && $settings['public_end_date'] === '') {
        throw new InvalidArgumentException('受付終了時刻を設定する場合は公開終了日も設定してください。');
    }
    $publicStartAt = forms_public_boundary_datetime($settings['public_start_date'], $settings['public_start_time'], false);
    $publicEndAt = forms_public_boundary_datetime($settings['public_end_date'], $settings['public_end_time'], true);
    if ($publicStartAt && $publicEndAt && $publicStartAt > $publicEndAt) {
        throw new InvalidArgumentException('公開期間の開始日時は終了日時以前にしてください。');
    }
    $settings['submit_button_label'] = trim((string)($formData['submit_button_label'] ?? $settings['submit_button_label'])) ?: '送信する';
    $settings['completion_message'] = trim((string)($formData['completion_message'] ?? $settings['completion_message'])) ?: '送信を受け付けました。';

    if (!$settings['enable_date_field']) {
        $settings['date_required'] = false;
    }
    if (!$settings['allow_file_upload']) {
        $settings['file_required'] = false;
    }

    $slugSource = trim((string)($formData['slug'] ?? ''));
    if ($slugSource === '') {
        $slugSource = 'form-' . date('YmdHis');
    }
    $slug = forms_make_unique_slug($pdo, $slugSource, $id > 0 ? $id : null);

    $payload = [
        ':slug' => $slug,
        ':name' => $name,
        ':description' => trim((string)($formData['description'] ?? '')),
        ':is_active' => forms_normalize_boolean($formData['is_active'] ?? true) ? 1 : 0,
        ':sort_order' => (int)($formData['sort_order'] ?? 0),
        ':settings_json' => forms_encode_json($settings),
    ];

    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE managed_forms SET slug = :slug, name = :name, description = :description, is_active = :is_active, sort_order = :sort_order, settings_json = :settings_json WHERE id = :id');
            $payload[':id'] = $id;
            $stmt->execute($payload);
            $formId = $id;

            $deleteStmt = $pdo->prepare('DELETE FROM managed_form_fields WHERE form_id = :form_id');
            $deleteStmt->execute([':form_id' => $formId]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO managed_forms (slug, name, description, is_active, sort_order, settings_json) VALUES (:slug, :name, :description, :is_active, :sort_order, :settings_json)');
            $stmt->execute($payload);
            $formId = (int)$pdo->lastInsertId();
        }

        $insertFieldStmt = $pdo->prepare('INSERT INTO managed_form_fields (form_id, field_key, field_label, field_type, placeholder, help_text, options_json, default_value, is_required, is_enabled, sort_order) VALUES (:form_id, :field_key, :field_label, :field_type, :placeholder, :help_text, :options_json, :default_value, :is_required, :is_enabled, :sort_order)');
        foreach (forms_sanitize_fields($fields) as $field) {
            $insertFieldStmt->execute([
                ':form_id' => $formId,
                ':field_key' => $field['field_key'],
                ':field_label' => $field['field_label'],
                ':field_type' => $field['field_type'],
                ':placeholder' => $field['placeholder'] !== '' ? $field['placeholder'] : null,
                ':help_text' => $field['help_text'] !== '' ? $field['help_text'] : null,
                ':options_json' => $field['options'] ? forms_encode_json($field['options']) : null,
                ':default_value' => $field['default_value'] !== '' ? $field['default_value'] : null,
                ':is_required' => $field['is_required'] ? 1 : 0,
                ':is_enabled' => $field['is_enabled'] ? 1 : 0,
                ':sort_order' => $field['sort_order'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return forms_load_form($formId) ?? [];
}

function forms_delete_form(int $formId): void
{
    forms_bootstrap();
    $pdo = forms_db();

    $relativePaths = [];
    $formSettingsStmt = $pdo->prepare('SELECT settings_json FROM managed_forms WHERE id = :id LIMIT 1');
    $formSettingsStmt->execute([':id' => $formId]);
    $formSettingsJson = $formSettingsStmt->fetchColumn();
    if (is_string($formSettingsJson)) {
        $formSettings = forms_decode_settings($formSettingsJson);
        $distributionPath = trim((string)($formSettings['distribution_file_relative_path'] ?? ''));
        if ($distributionPath !== '') {
            $relativePaths[] = $distributionPath;
        }
    }

    $pathStmt = $pdo->prepare('SELECT uploaded_relative_path FROM managed_form_submissions WHERE form_id = :form_id AND uploaded_relative_path IS NOT NULL AND uploaded_relative_path != ""');
    $pathStmt->execute([':form_id' => $formId]);
    foreach ($pathStmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
        if (is_string($path) && trim($path) !== '') {
            $relativePaths[] = trim($path);
        }
    }

    $revPathStmt = $pdo->prepare('SELECT uploaded_relative_path FROM managed_form_submission_revisions WHERE form_id = :form_id AND uploaded_relative_path IS NOT NULL AND uploaded_relative_path != ""');
    $revPathStmt->execute([':form_id' => $formId]);
    foreach ($revPathStmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
        if (is_string($path) && trim($path) !== '') {
            $relativePaths[] = trim($path);
        }
    }

    $relativePaths = array_values(array_unique($relativePaths));

    $pdo->beginTransaction();
    try {
        $deleteStmt = $pdo->prepare('DELETE FROM managed_forms WHERE id = :id');
        $deleteStmt->execute([':id' => $formId]);
        if ($deleteStmt->rowCount() < 1) {
            throw new InvalidArgumentException('削除対象のフォームが見つかりません。');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $uploadRoot = forms_upload_root();
    foreach ($relativePaths as $relativePath) {
        $fullPath = $uploadRoot . '/' . ltrim($relativePath, '/');
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}

function forms_public_forms_payload(): array
{
    $forms = forms_fetch_forms(true);
    $visibleForms = [];
    foreach ($forms as $form) {
        if (!forms_is_publicly_available($form)) {
            continue;
        }
        $form['fields'] = array_values(array_filter($form['fields'], static function (array $field): bool {
            return $field['is_enabled'];
        }));
        $visibleForms[] = $form;
    }
    return $visibleForms;
}

function forms_allowed_extensions(array $settings): array
{
    $raw = trim((string)($settings['allowed_extensions'] ?? ''));
    if ($raw === '') {
        return ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'zip'];
    }
    $parts = preg_split('/\s*,\s*/', $raw) ?: [];
    $result = [];
    foreach ($parts as $part) {
        $part = strtolower(trim((string)$part));
        $part = ltrim($part, '.');
        if ($part !== '') {
            $result[] = $part;
        }
    }
    return array_values(array_unique($result));
}

function forms_resolve_path(string $path): string
{
    $trimmed = trim($path);
    if ($trimmed === '') {
        return __DIR__ . '/forms_uploads';
    }

    if ($trimmed[0] === '/' || preg_match('/^[A-Za-z]:[\\/]/', $trimmed) === 1) {
        return $trimmed;
    }

    return __DIR__ . '/' . ltrim($trimmed, '/');
}

function forms_upload_root(): string
{
    $config = forms_runtime_config();
    $configured = forms_cfg_value((array)($config['forms'] ?? []), ['upload_root']);
    if ($configured === '') {
        $configured = forms_cfg_value((array)($config['forms_storage'] ?? []), ['upload_root']);
    }

    $path = forms_resolve_path($configured);
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('添付ファイル保存ディレクトリを作成できません。');
    }
    return $path;
}

function forms_validate_uploaded_file(array $file, array $settings): void
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('添付ファイルが見つかりません。');
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('添付ファイルのアップロードに失敗しました。');
    }

    $originalName = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = forms_allowed_extensions($settings);
    if ($extension === '' || !in_array($extension, $allowed, true)) {
        throw new InvalidArgumentException('許可されていない拡張子です。');
    }

    $maxBytes = (int)($settings['max_upload_size_mb'] ?? 5) * 1024 * 1024;
    if ((int)($file['size'] ?? 0) > $maxBytes) {
        throw new InvalidArgumentException('添付ファイルのサイズが上限を超えています。');
    }
}

function forms_store_uploaded_file(array $file, array $settings): array
{
    forms_validate_uploaded_file($file, $settings);
    $originalName = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $subdir = date('Y/m');
    $root = forms_upload_root();
    $targetDir = $root . '/' . $subdir;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException('添付ファイル保存先を作成できません。');
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $extension;
    $targetPath = $targetDir . '/' . $stored;
    if (!move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
        throw new RuntimeException('添付ファイルの保存に失敗しました。');
    }

    return [
        'uploaded_original_name' => $originalName,
        'uploaded_stored_name' => $stored,
        'uploaded_relative_path' => $subdir . '/' . $stored,
    ];
}

function forms_distribution_allowed_extensions(): array
{
    return ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'zip', 'csv', 'txt'];
}

function forms_upload_error_message(int $errorCode, string $label = 'ファイル'): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE => $label . 'のサイズがサーバー設定 upload_max_filesize を超えています。',
        UPLOAD_ERR_FORM_SIZE => $label . 'のサイズがフォーム側の上限を超えています。',
        UPLOAD_ERR_PARTIAL => $label . 'のアップロードが途中で中断されました。',
        UPLOAD_ERR_NO_FILE => $label . 'が選択されていません。',
        UPLOAD_ERR_NO_TMP_DIR => 'サーバー側の一時保存ディレクトリが利用できません。',
        UPLOAD_ERR_CANT_WRITE => 'サーバー側で' . $label . 'を書き込めませんでした。',
        UPLOAD_ERR_EXTENSION => 'PHP拡張機能により' . $label . 'のアップロードが停止されました。',
        default => $label . 'のアップロードに失敗しました。',
    };
}

function forms_validate_distribution_file(array $file): void
{
    $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException(forms_upload_error_message($errorCode, '配布ファイル'));
    }
    if ($errorCode !== UPLOAD_ERR_OK) {
        throw new RuntimeException(forms_upload_error_message($errorCode, '配布ファイル'));
    }

    $originalName = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === '' || !in_array($extension, forms_distribution_allowed_extensions(), true)) {
        throw new InvalidArgumentException('配布ファイルに使用できない拡張子です。');
    }

    $maxBytes = 30 * 1024 * 1024;
    if ((int)($file['size'] ?? 0) > $maxBytes) {
        throw new InvalidArgumentException('配布ファイルのサイズが上限の30MBを超えています。');
    }
}

function forms_store_distribution_file(int $formId, array $file): array
{
    forms_validate_distribution_file($file);
    $originalName = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $subdir = '_form_assets/' . $formId . '/' . date('Y/m');
    $root = forms_upload_root();
    $targetDir = $root . '/' . $subdir;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException('配布ファイル保存先を作成できません。');
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $extension;
    $targetPath = $targetDir . '/' . $stored;
    if (!move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
        throw new RuntimeException('配布ファイルの保存に失敗しました。');
    }

    return [
        'distribution_file_original_name' => $originalName,
        'distribution_file_stored_name' => $stored,
        'distribution_file_relative_path' => $subdir . '/' . $stored,
        'distribution_file_size_bytes' => (int)($file['size'] ?? 0),
        'distribution_file_uploaded_at' => now_str(),
    ];
}

function forms_delete_relative_file(?string $relativePath): void
{
    $relativePath = trim((string)$relativePath);
    if ($relativePath === '' || str_contains($relativePath, '..')) {
        return;
    }
    $fullPath = forms_upload_root() . '/' . ltrim($relativePath, '/');
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function forms_apply_distribution_settings_from_input(array $settings, array $formData): array
{
    if (array_key_exists('distribution_enabled', $formData)) {
        $settings['distribution_enabled'] = forms_normalize_boolean($formData['distribution_enabled']);
    }
    if (array_key_exists('distribution_title', $formData)) {
        $settings['distribution_title'] = mb_substr(trim((string)($formData['distribution_title'] ?? '')), 0, 150, 'UTF-8');
    }
    if (array_key_exists('distribution_body', $formData)) {
        $settings['distribution_body'] = mb_substr(trim((string)($formData['distribution_body'] ?? '')), 0, 5000, 'UTF-8');
    }
    if (array_key_exists('distribution_download_label', $formData)) {
        $settings['distribution_download_label'] = mb_substr(trim((string)($formData['distribution_download_label'] ?? '資料をダウンロード')), 0, 80, 'UTF-8') ?: '資料をダウンロード';
    }

    return $settings;
}

function forms_update_form_settings(int $formId, array $settings): void
{
    $stmt = forms_db()->prepare('UPDATE managed_forms SET settings_json = :settings_json WHERE id = :id');
    $stmt->execute([
        ':settings_json' => forms_encode_json($settings),
        ':id' => $formId,
    ]);
    if ($stmt->rowCount() < 1) {
        $existsStmt = forms_db()->prepare('SELECT COUNT(*) FROM managed_forms WHERE id = :id');
        $existsStmt->execute([':id' => $formId]);
        if ((int)$existsStmt->fetchColumn() < 1) {
            throw new InvalidArgumentException('対象フォームが見つかりません。');
        }
    }
}

function forms_save_distribution_file(int $formId, array $file, array $formData = []): array
{
    forms_bootstrap();
    $form = forms_load_form($formId, false);
    if (!$form) {
        throw new InvalidArgumentException('対象フォームが見つかりません。');
    }

    $oldPath = (string)($form['settings']['distribution_file_relative_path'] ?? '');
    $meta = forms_store_distribution_file($formId, $file);
    $newPath = forms_upload_root() . '/' . $meta['distribution_file_relative_path'];

    try {
        $settings = forms_apply_distribution_settings_from_input($form['settings'], $formData);
        $settings = array_merge($settings, $meta);
        if (!empty($settings['distribution_file_relative_path']) && empty($settings['distribution_title']) && empty($settings['distribution_body'])) {
            $settings['distribution_title'] = '配布資料';
        }
        forms_update_form_settings($formId, $settings);
        if ($oldPath !== '' && $oldPath !== $meta['distribution_file_relative_path']) {
            forms_delete_relative_file($oldPath);
        }
    } catch (Throwable $e) {
        if (is_file($newPath)) {
            @unlink($newPath);
        }
        throw $e;
    }

    return forms_load_form($formId, false) ?? [];
}

function forms_delete_distribution_file(int $formId): array
{
    forms_bootstrap();
    $form = forms_load_form($formId, false);
    if (!$form) {
        throw new InvalidArgumentException('対象フォームが見つかりません。');
    }

    $settings = $form['settings'];
    $oldPath = (string)($settings['distribution_file_relative_path'] ?? '');
    $settings['distribution_file_original_name'] = '';
    $settings['distribution_file_stored_name'] = '';
    $settings['distribution_file_relative_path'] = '';
    $settings['distribution_file_size_bytes'] = 0;
    $settings['distribution_file_uploaded_at'] = '';
    forms_update_form_settings($formId, $settings);
    forms_delete_relative_file($oldPath);

    return forms_load_form($formId, false) ?? [];
}

function forms_distribution_file_full_path(array $form): string
{
    $relativePath = trim((string)($form['settings']['distribution_file_relative_path'] ?? ''));
    if ($relativePath === '' || str_contains($relativePath, '..')) {
        throw new InvalidArgumentException('配布ファイルが設定されていません。');
    }
    $root = realpath(forms_upload_root());
    $path = realpath(forms_upload_root() . '/' . ltrim($relativePath, '/'));
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
        throw new InvalidArgumentException('配布ファイルが見つかりません。');
    }
    return $path;
}

function forms_output_distribution_file(array $form): void
{
    $path = forms_distribution_file_full_path($form);
    $downloadName = forms_safe_download_name((string)($form['settings']['distribution_file_original_name'] ?? ''), basename($path));
    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $path);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
            finfo_close($finfo);
        }
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($path));
    header('Content-Disposition: attachment; filename="' . addcslashes($downloadName, "\\\"") . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($path);
    exit;
}

function forms_find_existing_submission(int $formId, string $normalizedEmail, string $normalizedOrganization): ?array
{
    $stmt = forms_db()->prepare(
        'SELECT *
         FROM managed_form_submissions
         WHERE form_id = :form_id
           AND (normalized_email = :match_email OR normalized_organization = :match_org)
         ORDER BY CASE
             WHEN normalized_email = :rank_email_both AND normalized_organization = :rank_org_both THEN 0
             WHEN normalized_email = :rank_email_only THEN 1
             ELSE 2
         END,
         updated_at DESC,
         id DESC
         LIMIT 1'
    );
    $stmt->execute([
        ':form_id' => $formId,
        ':match_email' => $normalizedEmail,
        ':match_org' => $normalizedOrganization,
        ':rank_email_both' => $normalizedEmail,
        ':rank_org_both' => $normalizedOrganization,
        ':rank_email_only' => $normalizedEmail,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function forms_validate_submission(array $form, array $post, array $files): array
{
    $settings = $form['settings'];
    $errors = [];

    $email = trim((string)($post['email'] ?? ''));
    $organization = trim((string)($post['organization_name'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'メールアドレスを正しく入力してください。';
    }
    if ($organization === '') {
        $errors[] = '団体名を入力してください。';
    }

    $submittedDate = null;
    if (!empty($settings['enable_date_field'])) {
        $submittedDateRaw = trim((string)($post['submitted_date'] ?? ''));
        if ($submittedDateRaw === '') {
            if (!empty($settings['date_required'])) {
                $errors[] = '日付を入力してください。';
            }
        } else {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', $submittedDateRaw);
            if (!$dt || $dt->format('Y-m-d') !== $submittedDateRaw) {
                $errors[] = '日付の形式が不正です。';
            } else {
                $submittedDate = $submittedDateRaw;
            }
        }
    }

    $payload = [];
    foreach ($form['fields'] as $field) {
        if (!$field['is_enabled']) {
            continue;
        }
        $key = $field['field_key'];
        $value = $post['custom'][$key] ?? null;
        switch ($field['field_type']) {
            case 'checkbox':
                $normalized = forms_normalize_boolean($value) ? '1' : '';
                break;
            case 'number':
                $raw = trim((string)$value);
                if ($raw !== '' && !is_numeric($raw)) {
                    $errors[] = $field['field_label'] . 'は数値で入力してください。';
                }
                $normalized = $raw;
                break;
            case 'select':
                $normalized = trim((string)$value);
                if ($normalized !== '' && $field['options'] && !in_array($normalized, $field['options'], true)) {
                    $errors[] = $field['field_label'] . 'の選択肢が不正です。';
                }
                break;
            case 'date':
                $normalized = trim((string)$value);
                if ($normalized !== '') {
                    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $normalized);
                    if (!$dt || $dt->format('Y-m-d') !== $normalized) {
                        $errors[] = $field['field_label'] . 'の日付形式が不正です。';
                    }
                }
                break;
            default:
                $normalized = trim((string)$value);
                break;
        }
        if ($field['is_required'] && $normalized === '') {
            $errors[] = $field['field_label'] . 'を入力してください。';
        }
        $payload[$key] = $normalized;
    }

    $uploadFile = null;
    $file = $files['uploaded_file'] ?? null;
    if (!empty($settings['allow_file_upload'])) {
        if (is_array($file) && (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
            try {
                forms_validate_uploaded_file($file, $settings);
                $uploadFile = $file;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        } elseif (!empty($settings['file_required'])) {
            $errors[] = '添付ファイルを選択してください。';
        }
    }

    return [
        'errors' => $errors,
        'data' => [
            'email' => $email,
            'normalized_email' => forms_normalize_email($email),
            'organization_name' => $organization,
            'normalized_organization' => forms_normalize_organization($organization),
            'submitted_date' => $submittedDate,
            'payload' => $payload,
            'upload_file' => $uploadFile,
        ],
    ];
}

function forms_save_submission(array $form, array $normalized): array
{
    $pdo = forms_db();
    $newUploadPath = null;
    $pdo->beginTransaction();
    try {
        $existing = forms_find_existing_submission((int)$form['id'], $normalized['normalized_email'], $normalized['normalized_organization']);
        $uploadMeta = null;
        if (is_array($normalized['upload_file'] ?? null)) {
            $uploadMeta = forms_store_uploaded_file($normalized['upload_file'], $form['settings']);
            $newUploadPath = forms_upload_root() . '/' . $uploadMeta['uploaded_relative_path'];
        }
        if ($existing && !$uploadMeta) {
            $uploadMeta = [
                'uploaded_original_name' => $existing['uploaded_original_name'] ?: null,
                'uploaded_stored_name' => $existing['uploaded_stored_name'] ?: null,
                'uploaded_relative_path' => $existing['uploaded_relative_path'] ?: null,
            ];
        }

        $payloadJson = forms_encode_json($normalized['payload']);
        $submissionId = 0;
        $revisionNumber = 1;
        $status = 'created';

        if ($existing) {
            $submissionId = (int)$existing['id'];
            $status = 'updated';

            $revStmt = $pdo->prepare('SELECT COALESCE(MAX(revision_number), 0) FROM managed_form_submission_revisions WHERE submission_id = :submission_id');
            $revStmt->execute([':submission_id' => $submissionId]);
            $revisionNumber = (int)$revStmt->fetchColumn() + 1;

            $updateStmt = $pdo->prepare('UPDATE managed_form_submissions SET submitter_email = :email, normalized_email = :normalized_email, organization_name = :organization_name, normalized_organization = :normalized_organization, submitted_date = :submitted_date, payload_json = :payload_json, uploaded_original_name = :uploaded_original_name, uploaded_stored_name = :uploaded_stored_name, uploaded_relative_path = :uploaded_relative_path WHERE id = :id');
            $updateStmt->execute([
                ':email' => $normalized['email'],
                ':normalized_email' => $normalized['normalized_email'],
                ':organization_name' => $normalized['organization_name'],
                ':normalized_organization' => $normalized['normalized_organization'],
                ':submitted_date' => $normalized['submitted_date'],
                ':payload_json' => $payloadJson,
                ':uploaded_original_name' => $uploadMeta['uploaded_original_name'] ?? null,
                ':uploaded_stored_name' => $uploadMeta['uploaded_stored_name'] ?? null,
                ':uploaded_relative_path' => $uploadMeta['uploaded_relative_path'] ?? null,
                ':id' => $submissionId,
            ]);
        } else {
            $insertStmt = $pdo->prepare('INSERT INTO managed_form_submissions (form_id, submitter_email, normalized_email, organization_name, normalized_organization, submitted_date, payload_json, uploaded_original_name, uploaded_stored_name, uploaded_relative_path) VALUES (:form_id, :email, :normalized_email, :organization_name, :normalized_organization, :submitted_date, :payload_json, :uploaded_original_name, :uploaded_stored_name, :uploaded_relative_path)');
            $insertStmt->execute([
                ':form_id' => (int)$form['id'],
                ':email' => $normalized['email'],
                ':normalized_email' => $normalized['normalized_email'],
                ':organization_name' => $normalized['organization_name'],
                ':normalized_organization' => $normalized['normalized_organization'],
                ':submitted_date' => $normalized['submitted_date'],
                ':payload_json' => $payloadJson,
                ':uploaded_original_name' => $uploadMeta['uploaded_original_name'] ?? null,
                ':uploaded_stored_name' => $uploadMeta['uploaded_stored_name'] ?? null,
                ':uploaded_relative_path' => $uploadMeta['uploaded_relative_path'] ?? null,
            ]);
            $submissionId = (int)$pdo->lastInsertId();
        }

        $revisionStmt = $pdo->prepare('INSERT INTO managed_form_submission_revisions (submission_id, form_id, revision_number, submitter_email, organization_name, submitted_date, payload_json, uploaded_original_name, uploaded_stored_name, uploaded_relative_path) VALUES (:submission_id, :form_id, :revision_number, :email, :organization_name, :submitted_date, :payload_json, :uploaded_original_name, :uploaded_stored_name, :uploaded_relative_path)');
        $revisionStmt->execute([
            ':submission_id' => $submissionId,
            ':form_id' => (int)$form['id'],
            ':revision_number' => $revisionNumber,
            ':email' => $normalized['email'],
            ':organization_name' => $normalized['organization_name'],
            ':submitted_date' => $normalized['submitted_date'],
            ':payload_json' => $payloadJson,
            ':uploaded_original_name' => $uploadMeta['uploaded_original_name'] ?? null,
            ':uploaded_stored_name' => $uploadMeta['uploaded_stored_name'] ?? null,
            ':uploaded_relative_path' => $uploadMeta['uploaded_relative_path'] ?? null,
        ]);
        $revisionId = (int)$pdo->lastInsertId();

        $pdo->prepare('UPDATE managed_form_submissions SET latest_revision_id = :latest_revision_id WHERE id = :id')->execute([
            ':latest_revision_id' => $revisionId,
            ':id' => $submissionId,
        ]);

        $pdo->commit();

        return [
            'status' => $status,
            'submission_id' => $submissionId,
            'revision_id' => $revisionId,
            'revision_number' => $revisionNumber,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($newUploadPath && is_file($newUploadPath)) {
            @unlink($newUploadPath);
        }
        throw $e;
    }
}

function forms_entry_preview(array $payload, array $form): array
{
    $labels = [];
    foreach ($form['fields'] as $field) {
        $labels[$field['field_key']] = $field['field_label'];
    }
    $result = [];
    foreach ($payload as $key => $value) {
        if (is_array($value)) {
            $value = implode(', ', array_map(static fn($item) => is_scalar($item) ? (string)$item : forms_encode_json($item), $value));
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (!is_scalar($value) && $value !== null) {
            $value = forms_encode_json($value);
        }
        $result[] = [
            'key' => (string)$key,
            'label' => $labels[$key] ?? (string)$key,
            'value' => trim((string)$value),
        ];
    }
    return $result;
}

function forms_status_definitions(): array
{
    return [
        'new' => ['label' => '未確認', 'class' => 'status-new'],
        'reviewing' => ['label' => '確認中', 'class' => 'status-reviewing'],
        'on_hold' => ['label' => '保留', 'class' => 'status-on-hold'],
        'resolved' => ['label' => '対応済', 'class' => 'status-resolved'],
        'rejected' => ['label' => '差戻し', 'class' => 'status-rejected'],
    ];
}

function forms_normalize_status(?string $status): string
{
    $status = trim((string)$status);
    $definitions = forms_status_definitions();
    return isset($definitions[$status]) ? $status : 'new';
}

function forms_status_label(string $status): string
{
    $definitions = forms_status_definitions();
    return $definitions[$status]['label'] ?? '未確認';
}

function forms_status_class(string $status): string
{
    $definitions = forms_status_definitions();
    return $definitions[$status]['class'] ?? 'status-new';
}

function forms_validate_filter_date(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return ($dt && $dt->format('Y-m-d') === $value) ? $value : '';
}

function forms_validate_filter_time(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $dt = DateTimeImmutable::createFromFormat('H:i', $value);
    return ($dt && $dt->format('H:i') === $value) ? $value : '';
}

function forms_today(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
}

function forms_public_boundary_datetime(string $date, string $time, bool $isEnd): ?DateTimeImmutable
{
    $date = forms_validate_filter_date($date);
    if ($date === '') {
        return null;
    }

    $normalizedTime = forms_validate_filter_time($time);
    if ($normalizedTime === '') {
        $timeString = $isEnd ? '23:59:59' : '00:00:00';
    } else {
        $timeString = $normalizedTime . ($isEnd ? ':59' : ':00');
    }

    $dt = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $date . ' ' . $timeString,
        new DateTimeZone('Asia/Tokyo')
    );

    return $dt ?: null;
}

function forms_public_period_text(string $startDate, string $startTime, string $endDate, string $endTime): string
{
    $parts = [];

    if ($startDate !== '') {
        $parts[] = $startDate . ($startTime !== '' ? ' ' . $startTime : '');
    }

    if ($endDate !== '') {
        $parts[] = $endDate . ($endTime !== '' ? ' ' . $endTime : '');
    }

    if ($parts === []) {
        return '';
    }

    if (count($parts) === 1) {
        return $startDate !== '' ? ($parts[0] . ' 以降') : ($parts[0] . ' まで');
    }

    return $parts[0] . ' 〜 ' . $parts[1];
}

function forms_public_period_context(array $formOrSettings, ?bool $isActive = null): array
{
    $settings = isset($formOrSettings['settings']) && is_array($formOrSettings['settings']) ? $formOrSettings['settings'] : $formOrSettings;
    $active = $isActive;
    if ($active === null) {
        $active = isset($formOrSettings['is_active']) ? (bool)$formOrSettings['is_active'] : true;
    }

    $startDate = forms_validate_filter_date($settings['public_start_date'] ?? '');
    $startTime = forms_validate_filter_time($settings['public_start_time'] ?? '');
    $endDate = forms_validate_filter_date($settings['public_end_date'] ?? '');
    $endTime = forms_validate_filter_time($settings['public_end_time'] ?? '');
    $now = forms_today();

    $startAt = forms_public_boundary_datetime($startDate, $startTime, false);
    $endAt = forms_public_boundary_datetime($endDate, $endTime, true);
    $rangeText = forms_public_period_text($startDate, $startTime, $endDate, $endTime);

    $isOpen = true;
    $status = 'always_open';
    $label = '常時公開';
    $note = '公開期間の制限はありません。';

    if (!$active) {
        $isOpen = false;
        $status = 'inactive';
        $label = '非公開';
        $note = 'フォーム自体が非公開です。';
    } elseif ($startAt || $endAt) {
        if ($startAt && $now < $startAt) {
            $isOpen = false;
            $status = 'scheduled';
            $label = '受付前';
            $note = '受付開始前です。公開予定: ' . ($rangeText !== '' ? $rangeText : $startDate);
        } elseif ($endAt && $now > $endAt) {
            $isOpen = false;
            $status = 'closed';
            $label = '受付終了';
            $note = '公開期間は終了しています。設定期間: ' . ($rangeText !== '' ? $rangeText : $endDate);
        } else {
            $isOpen = true;
            $status = 'open';
            $label = '公開期間内';
            $note = '公開期間: ' . ($rangeText !== '' ? $rangeText : '設定済み');
        }
    }

    return [
        'is_active' => $active,
        'is_open' => $isOpen,
        'status' => $status,
        'label' => $label,
        'note' => $note,
        'start_date' => $startDate,
        'start_time' => $startTime,
        'end_date' => $endDate,
        'end_time' => $endTime,
        'window_text' => $rangeText,
        'today' => $now->format('Y-m-d'),
        'current_at' => $now->format('Y-m-d H:i'),
    ];
}

function forms_is_publicly_available(array $form): bool
{
    $context = forms_public_period_context($form);
    return $context['is_active'] && $context['is_open'];
}

function forms_admin_entry_filters(array $source): array
{
    $query = trim((string)($source['query'] ?? ''));
    $status = trim((string)($source['status'] ?? 'all'));
    $definitions = forms_status_definitions();
    if ($status !== 'all' && !isset($definitions[$status])) {
        $status = 'all';
    }

    $limit = (int)($source['limit'] ?? 100);
    $allowedLimits = [25, 50, 100, 200, 500];
    if (!in_array($limit, $allowedLimits, true)) {
        $limit = 100;
    }

    return [
        'query' => $query,
        'status' => $status,
        'date_from' => forms_validate_filter_date($source['date_from'] ?? ''),
        'date_to' => forms_validate_filter_date($source['date_to'] ?? ''),
        'limit' => $limit,
    ];
}

function forms_build_admin_entry_query_parts(int $formId, array $filters): array
{
    $where = ['s.form_id = :form_id'];
    $params = [':form_id' => $formId];

    if (($filters['status'] ?? 'all') !== 'all') {
        $where[] = "COALESCE(s.status, 'new') = :status";
        $params[':status'] = forms_normalize_status((string)$filters['status']);
    }

    $query = trim((string)($filters['query'] ?? ''));
    if ($query !== '') {
        $like = '%' . $query . '%';
        $where[] = "(
            s.submitter_email LIKE :query_email
            OR s.organization_name LIKE :query_org
            OR COALESCE(s.submitted_date, '') LIKE :query_date
            OR COALESCE(s.payload_json, '') LIKE :query_payload
            OR COALESCE(s.admin_note, '') LIKE :query_note
        )";
        $params[':query_email'] = $like;
        $params[':query_org'] = $like;
        $params[':query_date'] = $like;
        $params[':query_payload'] = $like;
        $params[':query_note'] = $like;
    }

    $dateFrom = (string)($filters['date_from'] ?? '');
    if ($dateFrom !== '') {
        $where[] = 'DATE(s.updated_at) >= :date_from';
        $params[':date_from'] = $dateFrom;
    }

    $dateTo = (string)($filters['date_to'] ?? '');
    if ($dateTo !== '') {
        $where[] = 'DATE(s.updated_at) <= :date_to';
        $params[':date_to'] = $dateTo;
    }

    return [
        'where_sql' => implode(' AND ', $where),
        'params' => $params,
    ];
}

function forms_build_status_summary(int $formId): array
{
    $definitions = forms_status_definitions();
    $counts = [];
    foreach ($definitions as $status => $meta) {
        $counts[$status] = [
            'status' => $status,
            'label' => $meta['label'],
            'class' => $meta['class'],
            'count' => 0,
        ];
    }

    $stmt = forms_db()->prepare("SELECT COALESCE(status, :default_status_select) AS status_key, COUNT(*) AS entry_count FROM managed_form_submissions WHERE form_id = :form_id GROUP BY COALESCE(status, :default_status_group)");
    $stmt->execute([
        ':default_status_select' => 'new',
        ':default_status_group' => 'new',
        ':form_id' => $formId,
    ]);
    foreach ($stmt->fetchAll() as $row) {
        $status = forms_normalize_status((string)($row['status_key'] ?? 'new'));
        if (!isset($counts[$status])) {
            continue;
        }
        $counts[$status]['count'] = (int)($row['entry_count'] ?? 0);
    }

    return array_values($counts);
}

function forms_build_admin_entry_record(array $row, array $form): array
{
    $payload = json_decode((string)($row['payload_json'] ?? '{}'), true);
    if (!is_array($payload)) {
        $payload = [];
    }
    $status = forms_normalize_status((string)($row['status'] ?? 'new'));

    return [
        'id' => (int)$row['id'],
        'form_id' => (int)$row['form_id'],
        'submitter_email' => (string)$row['submitter_email'],
        'organization_name' => (string)$row['organization_name'],
        'submitted_date' => (string)($row['submitted_date'] ?? ''),
        'payload' => $payload,
        'payload_preview' => forms_entry_preview($payload, $form),
        'uploaded_original_name' => (string)($row['uploaded_original_name'] ?? ''),
        'uploaded_relative_path' => (string)($row['uploaded_relative_path'] ?? ''),
        'latest_revision_id' => (int)($row['latest_revision_id'] ?? 0),
        'revision_count' => (int)($row['revision_count'] ?? 0),
        'status' => $status,
        'status_label' => forms_status_label($status),
        'status_class' => forms_status_class($status),
        'admin_note' => (string)($row['admin_note'] ?? ''),
        'status_updated_at' => (string)($row['status_updated_at'] ?? ''),
        'status_updated_by_user_id' => (int)($row['status_updated_by_user_id'] ?? 0),
        'status_updated_by_name' => (string)($row['status_updated_by_name'] ?? ''),
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

function forms_fetch_admin_entries(int $formId, array $rawFilters = [], ?int $limitOverride = null): array
{
    $form = forms_load_form($formId);
    if (!$form) {
        return [
            'entries' => [],
            'summary' => [
                'total_count' => 0,
                'filtered_count' => 0,
                'status_counts' => [],
            ],
            'filters' => forms_admin_entry_filters($rawFilters),
        ];
    }

    $filters = forms_admin_entry_filters($rawFilters);
    $queryParts = forms_build_admin_entry_query_parts($formId, $filters);
    $whereSql = $queryParts['where_sql'];
    $params = $queryParts['params'];

    $countStmt = forms_db()->prepare('SELECT COUNT(*) FROM managed_form_submissions s WHERE ' . $whereSql);
    $countStmt->execute($params);
    $filteredCount = (int)$countStmt->fetchColumn();

    $totalStmt = forms_db()->prepare('SELECT COUNT(*) FROM managed_form_submissions WHERE form_id = :form_id');
    $totalStmt->execute([':form_id' => $formId]);
    $totalCount = (int)$totalStmt->fetchColumn();

    $limitSql = '';
    if ($limitOverride === null) {
        $limitSql = ' LIMIT ' . (int)$filters['limit'];
    } elseif ($limitOverride > 0) {
        $limitSql = ' LIMIT ' . (int)$limitOverride;
    }

    $sql = 'SELECT s.*, (SELECT COUNT(*) FROM managed_form_submission_revisions r WHERE r.submission_id = s.id) AS revision_count FROM managed_form_submissions s WHERE ' . $whereSql . ' ORDER BY s.updated_at DESC, s.id DESC' . $limitSql;
    $stmt = forms_db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $entries = [];
    foreach ($rows as $row) {
        $entries[] = forms_build_admin_entry_record($row, $form);
    }

    return [
        'entries' => $entries,
        'summary' => [
            'total_count' => $totalCount,
            'filtered_count' => $filteredCount,
            'status_counts' => forms_build_status_summary($formId),
        ],
        'filters' => $filters,
        'form' => $form,
    ];
}

function forms_fetch_status_logs(int $submissionId): array
{
    $stmt = forms_db()->prepare('SELECT * FROM managed_form_submission_status_logs WHERE submission_id = :submission_id ORDER BY created_at DESC, id DESC');
    $stmt->execute([':submission_id' => $submissionId]);
    $rows = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $previousStatus = $row['previous_status'] !== null ? forms_normalize_status((string)$row['previous_status']) : null;
        $nextStatus = forms_normalize_status((string)($row['next_status'] ?? 'new'));
        $result[] = [
            'id' => (int)$row['id'],
            'submission_id' => (int)$row['submission_id'],
            'form_id' => (int)$row['form_id'],
            'previous_status' => $previousStatus,
            'previous_status_label' => $previousStatus !== null ? forms_status_label($previousStatus) : '',
            'next_status' => $nextStatus,
            'next_status_label' => forms_status_label($nextStatus),
            'next_status_class' => forms_status_class($nextStatus),
            'note' => (string)($row['note'] ?? ''),
            'changed_by_user_id' => (int)($row['changed_by_user_id'] ?? 0),
            'changed_by_name' => (string)($row['changed_by_name'] ?? ''),
            'created_at' => (string)$row['created_at'],
        ];
    }
    return $result;
}

function forms_fetch_entry_history(int $submissionId): array
{
    $stmt = forms_db()->prepare('SELECT r.* FROM managed_form_submission_revisions r WHERE r.submission_id = :submission_id ORDER BY r.revision_number DESC, r.id DESC');
    $stmt->execute([':submission_id' => $submissionId]);
    $rows = $stmt->fetchAll();
    $form = null;
    if ($rows) {
        $form = forms_load_form((int)$rows[0]['form_id']);
    }

    $history = [];
    foreach ($rows as $row) {
        $payload = json_decode((string)($row['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $history[] = [
            'id' => (int)$row['id'],
            'submission_id' => (int)$row['submission_id'],
            'revision_number' => (int)$row['revision_number'],
            'submitter_email' => (string)$row['submitter_email'],
            'organization_name' => (string)$row['organization_name'],
            'submitted_date' => (string)($row['submitted_date'] ?? ''),
            'payload' => $payload,
            'payload_preview' => $form ? forms_entry_preview($payload, $form) : [],
            'uploaded_original_name' => (string)($row['uploaded_original_name'] ?? ''),
            'created_at' => (string)$row['created_at'],
        ];
    }

    return [
        'revisions' => $history,
        'status_logs' => forms_fetch_status_logs($submissionId),
    ];
}

function forms_get_submission(int $submissionId): ?array
{
    $stmt = forms_db()->prepare('SELECT * FROM managed_form_submissions WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $submissionId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function forms_update_submission_status(int $submissionId, string $nextStatus, string $adminNote, array $actor = []): array
{
    $submission = forms_get_submission($submissionId);
    if (!$submission) {
        throw new InvalidArgumentException('対象の回答が見つかりません。');
    }

    $nextStatus = forms_normalize_status($nextStatus);
    $adminNote = trim($adminNote);
    if (mb_strlen($adminNote, 'UTF-8') > 4000) {
        throw new InvalidArgumentException('管理メモは4000文字以内で入力してください。');
    }

    $currentStatus = forms_normalize_status((string)($submission['status'] ?? 'new'));
    $currentNote = trim((string)($submission['admin_note'] ?? ''));
    if ($currentStatus === $nextStatus && $currentNote === $adminNote) {
        $form = forms_load_form((int)$submission['form_id']);
        return $form ? forms_build_admin_entry_record($submission, $form) : [];
    }

    $actorId = isset($actor['id']) ? (int)$actor['id'] : null;
    if ($actorId !== null && $actorId <= 0) {
        $actorId = null;
    }
    $actorName = trim((string)($actor['name'] ?? ''));

    $pdo = forms_db();
    $pdo->beginTransaction();
    try {
        $updateStmt = $pdo->prepare('UPDATE managed_form_submissions SET status = :status, admin_note = :admin_note, status_updated_at = CURRENT_TIMESTAMP, status_updated_by_user_id = :user_id, status_updated_by_name = :user_name WHERE id = :id');
        $updateStmt->execute([
            ':status' => $nextStatus,
            ':admin_note' => $adminNote !== '' ? $adminNote : null,
            ':user_id' => $actorId,
            ':user_name' => $actorName !== '' ? $actorName : null,
            ':id' => $submissionId,
        ]);

        $logStmt = $pdo->prepare('INSERT INTO managed_form_submission_status_logs (submission_id, form_id, previous_status, next_status, note, changed_by_user_id, changed_by_name) VALUES (:submission_id, :form_id, :previous_status, :next_status, :note, :user_id, :user_name)');
        $logStmt->execute([
            ':submission_id' => $submissionId,
            ':form_id' => (int)$submission['form_id'],
            ':previous_status' => $currentStatus,
            ':next_status' => $nextStatus,
            ':note' => $adminNote !== '' ? $adminNote : null,
            ':user_id' => $actorId,
            ':user_name' => $actorName !== '' ? $actorName : null,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $updated = forms_get_submission($submissionId);
    $form = forms_load_form((int)$submission['form_id']);
    if (!$updated || !$form) {
        return [];
    }

    $revisionCountStmt = $pdo->prepare('SELECT COUNT(*) FROM managed_form_submission_revisions WHERE submission_id = :submission_id');
    $revisionCountStmt->execute([':submission_id' => $submissionId]);
    $updated['revision_count'] = (int)$revisionCountStmt->fetchColumn();

    return forms_build_admin_entry_record($updated, $form);
}

function forms_csv_headers(array $form): array
{
    $headers = [
        '回答ID',
        '状態',
        '状態ラベル',
        '管理メモ',
        '団体名',
        'メールアドレス',
        '日付',
        '添付ファイル名',
        '更新履歴数',
        '登録日時',
        '更新日時',
        '状態更新日時',
        '状態更新者',
    ];

    foreach ($form['fields'] as $field) {
        $headers[] = $field['field_label'];
    }

    return $headers;
}

function forms_csv_rows(int $formId, array $rawFilters = []): array
{
    $form = forms_load_form($formId);
    if (!$form) {
        return ['headers' => [], 'rows' => []];
    }

    $result = forms_fetch_admin_entries($formId, $rawFilters, 0);
    $rows = [];
    foreach ($result['entries'] as $entry) {
        $row = [
            (string)$entry['id'],
            $entry['status'],
            $entry['status_label'],
            $entry['admin_note'],
            $entry['organization_name'],
            $entry['submitter_email'],
            $entry['submitted_date'],
            $entry['uploaded_original_name'],
            (string)$entry['revision_count'],
            $entry['created_at'],
            $entry['updated_at'],
            $entry['status_updated_at'],
            $entry['status_updated_by_name'],
        ];

        $payloadMap = [];
        foreach ($entry['payload_preview'] as $payloadItem) {
            $payloadMap[$payloadItem['label']] = $payloadItem['value'];
        }
        foreach ($form['fields'] as $field) {
            $row[] = $payloadMap[$field['field_label']] ?? '';
        }
        $rows[] = $row;
    }

    return [
        'headers' => forms_csv_headers($form),
        'rows' => $rows,
        'form' => $form,
        'filters' => $result['filters'],
    ];
}

function forms_resolve_revision_download(int $revisionId): ?array
{
    $stmt = forms_db()->prepare('SELECT * FROM managed_form_submission_revisions WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $revisionId]);
    $row = $stmt->fetch();
    if (!$row || empty($row['uploaded_relative_path'])) {
        return null;
    }

    $relativePath = ltrim((string)$row['uploaded_relative_path'], '/');
    $path = forms_upload_root() . '/' . $relativePath;
    if (is_file($path)) {
        return [
            'path' => $path,
            'filename' => (string)($row['uploaded_original_name'] ?: basename($path)),
        ];
    }

    $cfg = forms_runtime_config();
    $archivedPath = storage_maintenance_forms_extract_archived_upload($cfg, $relativePath);
    if (!is_string($archivedPath) || !is_file($archivedPath)) {
        return null;
    }

    return [
        'path' => $archivedPath,
        'filename' => (string)($row['uploaded_original_name'] ?: basename($relativePath)),
        'cleanup_path' => $archivedPath,
        'from_archive' => true,
    ];
}


function forms_collect_latest_submission_attachments(int $formId, array $rawFilters = []): array
{
    $result = forms_fetch_admin_entries($formId, $rawFilters, 0);
    $files = [];
    foreach ($result['entries'] as $entry) {
        $relativePath = trim((string)($entry['uploaded_relative_path'] ?? ''));
        if ($relativePath === '') {
            continue;
        }
        $path = forms_upload_root() . '/' . ltrim($relativePath, '/');
        if (!is_file($path)) {
            continue;
        }

        $entryId = (int)($entry['id'] ?? 0);
        $org = forms_safe_download_name((string)($entry['organization_name'] ?? ''), 'organization');
        $email = forms_safe_download_name((string)($entry['submitter_email'] ?? ''), 'email');
        $original = forms_safe_download_name((string)($entry['uploaded_original_name'] ?: basename($path)), 'attachment');
        $zipName = sprintf('%03d_%s_%s_%s', $entryId, $org, $email, $original);

        $files[] = [
            'entry_id' => $entryId,
            'path' => $path,
            'filename' => $original,
            'zip_name' => $zipName,
            'organization_name' => (string)($entry['organization_name'] ?? ''),
            'submitter_email' => (string)($entry['submitter_email'] ?? ''),
            'updated_at' => (string)($entry['updated_at'] ?? ''),
        ];
    }

    return [
        'files' => $files,
        'form' => $result['form'] ?? forms_load_form($formId),
        'filters' => $result['filters'] ?? forms_admin_entry_filters($rawFilters),
        'summary' => $result['summary'] ?? ['total_count' => 0, 'filtered_count' => 0, 'status_counts' => []],
    ];
}

function forms_build_latest_attachments_archive(int $formId, array $rawFilters = []): ?array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive が利用できません。');
    }

    $bundle = forms_collect_latest_submission_attachments($formId, $rawFilters);
    $files = $bundle['files'];
    if ($files === []) {
        return null;
    }

    $form = is_array($bundle['form'] ?? null) ? $bundle['form'] : forms_load_form($formId);
    $slug = forms_safe_download_name((string)($form['slug'] ?? 'forms'), 'forms');
    $zipFilename = sprintf('%s_latest_attachments_%s.zip', $slug, date('Ymd_His'));
    $tmpPath = tempnam(sys_get_temp_dir(), 'forms_zip_');
    if ($tmpPath === false) {
        throw new RuntimeException('一時ファイルを作成できませんでした。');
    }
    @unlink($tmpPath);
    $zipPath = $tmpPath . '.zip';

    $zip = new ZipArchive();
    $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) {
        throw new RuntimeException('ZIP ファイルを作成できませんでした。');
    }

    $usedNames = [];
    foreach ($files as $file) {
        $zipName = (string)$file['zip_name'];
        $path = (string)$file['path'];
        $finalName = $zipName;
        $counter = 2;
        while (isset($usedNames[$finalName])) {
            $dot = strrpos($zipName, '.');
            if ($dot !== false) {
                $base = substr($zipName, 0, $dot);
                $ext = substr($zipName, $dot);
            } else {
                $base = $zipName;
                $ext = '';
            }
            $finalName = $base . '_' . $counter . $ext;
            $counter++;
        }
        $usedNames[$finalName] = true;
        $zip->addFile($path, $finalName);
    }

    $manifest = [
        'フォーム: ' . (string)($form['name'] ?? ''),
        'slug: ' . (string)($form['slug'] ?? ''),
        '出力日時: ' . date('Y-m-d H:i:s'),
        '件数: ' . count($files),
        '',
        'entry_id,organization_name,submitter_email,updated_at,zip_name',
    ];
    foreach ($files as $file) {
        $manifest[] = implode(',', [
            '"' . str_replace('"', '""', (string)$file['entry_id']) . '"',
            '"' . str_replace('"', '""', (string)$file['organization_name']) . '"',
            '"' . str_replace('"', '""', (string)$file['submitter_email']) . '"',
            '"' . str_replace('"', '""', (string)$file['updated_at']) . '"',
            '"' . str_replace('"', '""', (string)$file['zip_name']) . '"',
        ]);
    }
    $zip->addFromString('manifest.txt', implode("
", $manifest));
    $zip->close();

    return [
        'path' => $zipPath,
        'filename' => $zipFilename,
        'count' => count($files),
        'form' => $form,
        'filters' => $bundle['filters'],
        'summary' => $bundle['summary'],
    ];
}
