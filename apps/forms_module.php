<?php
declare(strict_types=1);


function forms_db_name(): string
{
    $global = $GLOBALS['config']['forms']['db_name'] ?? $GLOBALS['config']['forms_db']['database'] ?? $GLOBALS['config']['forms_db_name'] ?? null;
    $name = is_string($global) ? trim($global) : '';
    return $name !== '' ? $name : 'fitsc_forms';
}

function forms_load_base_config(): array
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

    throw new RuntimeException('forms 用の DB 設定を読み込めません。');
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
    $config = forms_load_base_config();

    if (isset($config['forms_db']) && is_array($config['forms_db'])) {
        $formsDb = $config['forms_db'];
        if (!empty($formsDb['dsn'])) {
            return [
                'dsn' => (string)$formsDb['dsn'],
                'user' => (string)($formsDb['user'] ?? $config['db']['user'] ?? ''),
                'password' => (string)($formsDb['password'] ?? $config['db']['password'] ?? ''),
            ];
        }
        if (!empty($formsDb['database']) || !empty($formsDb['host']) || !empty($formsDb['unix_socket'])) {
            $databaseName = trim((string)($formsDb['database'] ?? forms_db_name()));
            return [
                'dsn' => forms_build_dsn_from_parts($formsDb + ($config['db'] ?? []), $databaseName),
                'user' => (string)($formsDb['user'] ?? $config['db']['user'] ?? ''),
                'password' => (string)($formsDb['password'] ?? $config['db']['password'] ?? ''),
            ];
        }
    }

    $db = $config['db'] ?? null;
    if (!is_array($db)) {
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
        'user' => (string)($db['user'] ?? ''),
        'password' => (string)($db['password'] ?? ''),
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
    latest_revision_id BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_managed_form_submissions_form_updated (form_id, updated_at, id),
    KEY idx_managed_form_submissions_form_email (form_id, normalized_email),
    KEY idx_managed_form_submissions_form_org (form_id, normalized_organization),
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
    $settings['enable_date_field'] = forms_normalize_boolean($formData['enable_date_field'] ?? false);
    $settings['date_required'] = forms_normalize_boolean($formData['date_required'] ?? false);
    $settings['date_label'] = trim((string)($formData['date_label'] ?? $settings['date_label'])) ?: '希望日';
    $settings['allow_file_upload'] = forms_normalize_boolean($formData['allow_file_upload'] ?? false);
    $settings['file_required'] = forms_normalize_boolean($formData['file_required'] ?? false);
    $settings['file_label'] = trim((string)($formData['file_label'] ?? $settings['file_label'])) ?: '添付ファイル';
    $settings['allowed_extensions'] = trim((string)($formData['allowed_extensions'] ?? $settings['allowed_extensions'])) ?: $settings['allowed_extensions'];
    $settings['max_upload_size_mb'] = max(1, min(30, (int)($formData['max_upload_size_mb'] ?? $settings['max_upload_size_mb'])));
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

function forms_public_forms_payload(): array
{
    $forms = forms_fetch_forms(true);
    foreach ($forms as &$form) {
        $form['fields'] = array_values(array_filter($form['fields'], static function (array $field): bool {
            return $field['is_enabled'];
        }));
    }
    unset($form);
    return $forms;
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

function forms_upload_root(): string
{
    $path = __DIR__ . '/forms_uploads';
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

function forms_find_existing_submission(int $formId, string $normalizedEmail, string $normalizedOrganization): ?array
{
    $stmt = forms_db()->prepare(
        'SELECT *
         FROM managed_form_submissions
         WHERE form_id = :form_id
           AND (normalized_email = :normalized_email OR normalized_organization = :normalized_organization)
         ORDER BY CASE
             WHEN normalized_email = :normalized_email AND normalized_organization = :normalized_organization THEN 0
             WHEN normalized_email = :normalized_email THEN 1
             ELSE 2
         END,
         updated_at DESC,
         id DESC
         LIMIT 1'
    );
    $stmt->execute([
        ':form_id' => $formId,
        ':normalized_email' => $normalizedEmail,
        ':normalized_organization' => $normalizedOrganization,
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
        $result[] = [
            'key' => (string)$key,
            'label' => $labels[$key] ?? (string)$key,
            'value' => (string)$value,
        ];
    }
    return $result;
}

function forms_fetch_admin_entries(int $formId): array
{
    $form = forms_load_form($formId);
    if (!$form) {
        return [];
    }
    $stmt = forms_db()->prepare('SELECT s.*, (SELECT COUNT(*) FROM managed_form_submission_revisions r WHERE r.submission_id = s.id) AS revision_count FROM managed_form_submissions s WHERE s.form_id = :form_id ORDER BY s.updated_at DESC, s.id DESC LIMIT 100');
    $stmt->execute([':form_id' => $formId]);
    $rows = $stmt->fetchAll();
    $entries = [];
    foreach ($rows as $row) {
        $payload = json_decode((string)($row['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $entries[] = [
            'id' => (int)$row['id'],
            'form_id' => (int)$row['form_id'],
            'submitter_email' => (string)$row['submitter_email'],
            'organization_name' => (string)$row['organization_name'],
            'submitted_date' => (string)($row['submitted_date'] ?? ''),
            'payload' => $payload,
            'payload_preview' => forms_entry_preview($payload, $form),
            'uploaded_original_name' => (string)($row['uploaded_original_name'] ?? ''),
            'latest_revision_id' => (int)($row['latest_revision_id'] ?? 0),
            'revision_count' => (int)($row['revision_count'] ?? 0),
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        ];
    }
    return $entries;
}

function forms_fetch_entry_history(int $submissionId): array
{
    $stmt = forms_db()->prepare('SELECT r.* FROM managed_form_submission_revisions r WHERE r.submission_id = :submission_id ORDER BY r.revision_number DESC, r.id DESC');
    $stmt->execute([':submission_id' => $submissionId]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return [];
    }
    $form = forms_load_form((int)$rows[0]['form_id']);
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
    return $history;
}

function forms_resolve_revision_download(int $revisionId): ?array
{
    $stmt = forms_db()->prepare('SELECT * FROM managed_form_submission_revisions WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $revisionId]);
    $row = $stmt->fetch();
    if (!$row || empty($row['uploaded_relative_path'])) {
        return null;
    }
    $path = forms_upload_root() . '/' . ltrim((string)$row['uploaded_relative_path'], '/');
    if (!is_file($path)) {
        return null;
    }
    return [
        'path' => $path,
        'filename' => (string)($row['uploaded_original_name'] ?: basename($path)),
    ];
}
