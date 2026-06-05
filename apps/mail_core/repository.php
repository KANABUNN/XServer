<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/render.php';

function mail_required_tables(): array
{
    return [
        'mail_organizations',
        'mail_templates',
        'mail_batches',
        'mail_batch_targets',
        'mail_upload_batches',
        'mail_uploaded_files',
        'mail_attachments',
        'mail_send_logs',
        'mail_audit_logs',
    ];
}

function mail_schema_status(PDO $pdo): array
{
    $tables = [];
    $missing = [];
    foreach (mail_required_tables() as $table) {
        $exists = mail_table_exists($pdo, $table);
        $tables[$table] = $exists;
        if (!$exists) {
            $missing[] = $table;
        }
    }
    return [
        'ready' => $missing === [],
        'tables' => $tables,
        'missing' => $missing,
    ];
}

function mail_count_table(PDO $pdo, string $tableName, ?string $where = null): int
{
    if (!mail_table_exists($pdo, $tableName)) {
        return 0;
    }
    $sql = 'SELECT COUNT(*) FROM `' . str_replace('`', '``', $tableName) . '`';
    if ($where !== null && trim($where) !== '') {
        $sql .= ' WHERE ' . $where;
    }
    return (int)$pdo->query($sql)->fetchColumn();
}

function mail_dashboard_counts(PDO $pdo): array
{
    return [
        'organizations' => mail_count_table($pdo, 'mail_organizations', 'is_active = 1'),
        'templates' => mail_count_table($pdo, 'mail_templates', 'is_active = 1'),
        'batches' => mail_count_table($pdo, 'mail_batches'),
        'draft_batches' => mail_count_table($pdo, 'mail_batches', "status IN ('draft','prepared','reviewing')"),
        'attachments_need_review' => mail_count_table($pdo, 'mail_attachments', "status = 'needs_review'"),
        'send_logs' => mail_count_table($pdo, 'mail_send_logs'),
    ];
}

function mail_recent_batches(PDO $pdo, int $limit = 8): array
{
    if (!mail_table_exists($pdo, 'mail_batches')) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    $stmt = $pdo->query(
        'SELECT b.id, b.batch_key, b.title, b.status, b.target_count, b.common_attachment_count, b.individual_attachment_count, b.created_at, t.title AS template_title ' .
        'FROM mail_batches b LEFT JOIN mail_templates t ON t.id = b.template_id ' .
        'ORDER BY b.created_at DESC, b.id DESC LIMIT ' . $limit
    );
    return $stmt->fetchAll() ?: [];
}

function mail_recent_uploads(PDO $pdo, int $limit = 8): array
{
    if (!mail_table_exists($pdo, 'mail_upload_batches')) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    $stmt = $pdo->query(
        'SELECT id, mail_batch_id, upload_kind, original_name, file_count, status, created_at ' .
        'FROM mail_upload_batches ORDER BY created_at DESC, id DESC LIMIT ' . $limit
    );
    return $stmt->fetchAll() ?: [];
}

function mail_status_label(string $status): string
{
    return [
        'draft' => '作成中',
        'prepared' => '準備済み',
        'reviewing' => '確認中',
        'approved' => '確認済み',
        'draft_created' => '下書き作成済み',
        'sent' => '送信済み',
        'cancelled' => '取消',
        'waiting' => '待機中',
        'ready' => '作成可能',
        'failed' => '失敗',
        'excluded' => '除外',
        'uploaded' => 'アップロード済み',
        'matched' => '自動対応済み',
        'needs_review' => '要確認',
        'unmatched' => '未対応',
        'used' => '使用済み',
        'archived' => '保管済み',
    ][$status] ?? $status;
}

function mail_bool_label(int|string|null $value): string
{
    return ((int)$value === 1) ? '有効' : '無効';
}

function mail_generate_key(string $prefix): string
{
    return $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
}

function mail_normalize_identifier(string $identifier): string
{
    return strtoupper(trim($identifier));
}

function mail_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function mail_audit_log(PDO $pdo, ?array $actor, string $action, ?string $targetType = null, ?string $targetId = null, array $summary = []): void
{
    if (!mail_table_exists($pdo, 'mail_audit_logs')) {
        return;
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO mail_audit_logs ' .
            '(actor_account_id, actor_login_id, actor_display_name, action, target_type, target_id, summary_json, ip_address, user_agent) ' .
            'VALUES (:actor_account_id, :actor_login_id, :actor_display_name, :action, :target_type, :target_id, :summary_json, :ip_address, :user_agent)'
        );
        $stmt->execute([
            ':actor_account_id' => $actor !== null ? (int)($actor['id'] ?? 0) : null,
            ':actor_login_id' => $actor !== null ? (string)($actor['login_id'] ?? '') : null,
            ':actor_display_name' => $actor !== null ? (string)($actor['display_name'] ?? '') : null,
            ':action' => $action,
            ':target_type' => $targetType,
            ':target_id' => $targetId,
            ':summary_json' => $summary === [] ? null : mail_json_encode($summary),
            ':ip_address' => mail_client_ip(),
            ':user_agent' => mail_user_agent(),
        ]);
    } catch (Throwable $e) {
        error_log('[mail local audit] ' . $e->getMessage());
    }
}

function mail_list_organizations(PDO $pdo, string $keyword = '', string $active = 'all', int $limit = 300): array
{
    $where = [];
    $params = [];
    $keyword = trim($keyword);
    if ($keyword !== '') {
        $where[] = '(identifier LIKE :kw OR name LIKE :kw OR representative_name LIKE :kw OR email LIKE :kw OR category LIKE :kw)';
        $params[':kw'] = '%' . $keyword . '%';
    }
    if ($active === 'active') {
        $where[] = 'is_active = 1';
    } elseif ($active === 'inactive') {
        $where[] = 'is_active = 0';
    }

    $sql = 'SELECT * FROM mail_organizations';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY is_active DESC, identifier ASC LIMIT ' . max(1, min(1000, $limit));
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function mail_list_active_organizations(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT * FROM mail_organizations WHERE is_active = 1 ORDER BY identifier ASC");
    return $stmt->fetchAll() ?: [];
}

function mail_get_organization(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM mail_organizations WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function mail_find_organization_by_identifier(PDO $pdo, string $identifier): ?array
{
    $identifier = mail_normalize_identifier($identifier);
    if ($identifier === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM mail_organizations WHERE UPPER(identifier) = :identifier LIMIT 1');
    $stmt->execute([':identifier' => $identifier]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function mail_save_organization(PDO $pdo, array $data): int
{
    $id = (int)($data['id'] ?? 0);
    $identifier = mail_normalize_identifier((string)($data['identifier'] ?? ''));
    $name = trim((string)($data['name'] ?? ''));
    $email = mail_normalize_email((string)($data['email'] ?? ''));
    if ($identifier === '' || $name === '') {
        throw new InvalidArgumentException('識別番号と団体名は必須です。');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('メールアドレスの形式が正しくありません。');
    }

    $params = [
        ':identifier' => $identifier,
        ':name' => $name,
        ':representative_name' => trim((string)($data['representative_name'] ?? '')) ?: null,
        ':email' => $email ?: null,
        ':category' => trim((string)($data['category'] ?? '')) ?: null,
        ':notes' => trim((string)($data['notes'] ?? '')) ?: null,
        ':is_active' => (int)($data['is_active'] ?? 1) === 1 ? 1 : 0,
    ];

    if ($id > 0) {
        $params[':id'] = $id;
        $stmt = $pdo->prepare(
            'UPDATE mail_organizations SET identifier = :identifier, name = :name, representative_name = :representative_name, ' .
            'email = :email, category = :category, notes = :notes, is_active = :is_active WHERE id = :id'
        );
        $stmt->execute($params);
        return $id;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO mail_organizations (identifier, name, representative_name, email, category, notes, is_active) ' .
        'VALUES (:identifier, :name, :representative_name, :email, :category, :notes, :is_active)'
    );
    $stmt->execute($params);
    return (int)$pdo->lastInsertId();
}

function mail_set_organization_active(PDO $pdo, int $id, bool $active): void
{
    $stmt = $pdo->prepare('UPDATE mail_organizations SET is_active = :active WHERE id = :id');
    $stmt->execute([':active' => $active ? 1 : 0, ':id' => $id]);
}

function mail_csv_to_utf8(string $bytes): string
{
    $encoding = 'UTF-8';
    if (function_exists('mb_detect_encoding')) {
        $detected = mb_detect_encoding($bytes, ['UTF-8', 'SJIS-win', 'CP932', 'EUC-JP', 'ISO-2022-JP'], true);
        if (is_string($detected) && $detected !== '') {
            $encoding = $detected;
        }
    }
    if ($encoding !== 'UTF-8' && function_exists('mb_convert_encoding')) {
        return (string)mb_convert_encoding($bytes, 'UTF-8', $encoding);
    }
    return preg_replace('/^\xEF\xBB\xBF/', '', $bytes) ?? $bytes;
}

function mail_header_value(array $row, array $headers, array $aliases): string
{
    foreach ($aliases as $alias) {
        $idx = $headers[$alias] ?? null;
        if ($idx !== null && array_key_exists($idx, $row)) {
            return trim((string)$row[$idx]);
        }
    }
    return '';
}

function mail_import_organizations_csv(PDO $pdo, string $path): array
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('CSVファイルを読み込めません。');
    }
    $content = mail_csv_to_utf8($content);
    $tmp = tempnam(sys_get_temp_dir(), 'mail_csv_');
    if ($tmp === false) {
        throw new RuntimeException('一時ファイルを作成できません。');
    }
    file_put_contents($tmp, $content);
    $fh = fopen($tmp, 'rb');
    if (!$fh) {
        @unlink($tmp);
        throw new RuntimeException('CSVファイルを開けません。');
    }

    $headerRow = fgetcsv($fh);
    if (!is_array($headerRow)) {
        fclose($fh);
        @unlink($tmp);
        throw new InvalidArgumentException('CSVヘッダーを読み取れません。');
    }
    $headers = [];
    foreach ($headerRow as $i => $header) {
        $headers[trim((string)$header)] = $i;
    }

    $pdo->beginTransaction();
    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];
    $line = 1;
    try {
        while (($row = fgetcsv($fh)) !== false) {
            $line++;
            if (!is_array($row) || implode('', array_map('strval', $row)) === '') {
                $skipped++;
                continue;
            }
            $identifier = mail_header_value($row, $headers, ['identifier', '識別番号', '団体番号', 'コード']);
            $name = mail_header_value($row, $headers, ['name', '団体名', 'organization_name', '組織名']);
            if (trim($identifier) === '' || trim($name) === '') {
                $skipped++;
                $errors[] = $line . '行目: 識別番号または団体名が空です。';
                continue;
            }
            $existing = mail_find_organization_by_identifier($pdo, $identifier);
            $data = [
                'id' => $existing['id'] ?? 0,
                'identifier' => $identifier,
                'name' => $name,
                'representative_name' => mail_header_value($row, $headers, ['representative_name', '代表者氏名', '代表者名', '代表者']),
                'email' => mail_header_value($row, $headers, ['email', 'メールアドレス', 'mail', 'e-mail']),
                'category' => mail_header_value($row, $headers, ['category', '区分', '分類']),
                'notes' => mail_header_value($row, $headers, ['notes', '備考', 'メモ']),
                'is_active' => 1,
            ];
            mail_save_organization($pdo, $data);
            if ($existing) {
                $updated++;
            } else {
                $inserted++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fclose($fh);
        @unlink($tmp);
        throw $e;
    }
    fclose($fh);
    @unlink($tmp);

    return ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
}

function mail_list_templates(PDO $pdo, bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM mail_templates';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY is_active DESC, updated_at DESC, id DESC';
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll() ?: [];
}

function mail_get_template(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM mail_templates WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function mail_save_template(PDO $pdo, array $data, ?array $actor = null): int
{
    $id = (int)($data['id'] ?? 0);
    $title = trim((string)($data['title'] ?? ''));
    $subject = trim((string)($data['subject_template'] ?? ''));
    $body = (string)($data['body_template'] ?? '');
    if ($title === '' || $subject === '' || trim($body) === '') {
        throw new InvalidArgumentException('テンプレート名、件名、本文は必須です。');
    }
    $bodyType = (string)($data['body_type'] ?? 'plain');
    if (!in_array($bodyType, ['plain', 'html'], true)) {
        $bodyType = 'plain';
    }
    $templateKey = trim((string)($data['template_key'] ?? ''));
    if ($templateKey === '') {
        $templateKey = mail_generate_key('tpl');
    }
    $vars = array_values(array_unique(array_merge(
        mail_extract_template_variables($subject),
        mail_extract_template_variables($body)
    )));
    $params = [
        ':template_key' => $templateKey,
        ':title' => $title,
        ':subject_template' => $subject,
        ':body_template' => $body,
        ':body_type' => $bodyType,
        ':variables_json' => mail_json_encode(['variables' => $vars]),
        ':is_active' => (int)($data['is_active'] ?? 1) === 1 ? 1 : 0,
        ':account_id' => $actor !== null ? (int)($actor['id'] ?? 0) : null,
    ];

    if ($id > 0) {
        $params[':id'] = $id;
        $stmt = $pdo->prepare(
            'UPDATE mail_templates SET template_key = :template_key, title = :title, subject_template = :subject_template, ' .
            'body_template = :body_template, body_type = :body_type, variables_json = :variables_json, is_active = :is_active, ' .
            'updated_by_account_id = :account_id WHERE id = :id'
        );
        $stmt->execute($params);
        return $id;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO mail_templates (template_key, title, subject_template, body_template, body_type, variables_json, is_active, created_by_account_id, updated_by_account_id) ' .
        'VALUES (:template_key, :title, :subject_template, :body_template, :body_type, :variables_json, :is_active, :account_id, :account_id)'
    );
    $stmt->execute($params);
    return (int)$pdo->lastInsertId();
}

function mail_set_template_active(PDO $pdo, int $id, bool $active): void
{
    $stmt = $pdo->prepare('UPDATE mail_templates SET is_active = :active WHERE id = :id');
    $stmt->execute([':active' => $active ? 1 : 0, ':id' => $id]);
}

function mail_list_batches(PDO $pdo, int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    $stmt = $pdo->query(
        'SELECT b.*, t.title AS template_title ' .
        'FROM mail_batches b LEFT JOIN mail_templates t ON t.id = b.template_id ' .
        'ORDER BY b.created_at DESC, b.id DESC LIMIT ' . $limit
    );
    return $stmt->fetchAll() ?: [];
}

function mail_get_batch(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT b.*, t.title AS template_title, t.subject_template, t.body_template, t.body_type ' .
        'FROM mail_batches b LEFT JOIN mail_templates t ON t.id = b.template_id WHERE b.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function mail_list_batch_targets(PDO $pdo, int $batchId, int $limit = 500): array
{
    $limit = max(1, min(1000, $limit));
    $stmt = $pdo->prepare(
        'SELECT bt.*, o.identifier, o.name AS organization_name, o.representative_name, o.category ' .
        'FROM mail_batch_targets bt LEFT JOIN mail_organizations o ON o.id = bt.organization_id ' .
        'WHERE bt.batch_id = :batch_id ORDER BY o.identifier ASC, bt.id ASC LIMIT ' . $limit
    );
    $stmt->execute([':batch_id' => $batchId]);
    return $stmt->fetchAll() ?: [];
}

function mail_create_batch_from_template(PDO $pdo, int $templateId, string $title, ?array $actor = null): int
{
    $template = mail_get_template($pdo, $templateId);
    if (!$template) {
        throw new InvalidArgumentException('テンプレートが見つかりません。');
    }
    $organizations = mail_list_active_organizations($pdo);
    if ($organizations === []) {
        throw new InvalidArgumentException('有効な団体が登録されていません。');
    }
    $title = trim($title);
    if ($title === '') {
        throw new InvalidArgumentException('バッチ名を入力してください。');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO mail_batches (batch_key, title, template_id, status, created_by_account_id) ' .
            'VALUES (:batch_key, :title, :template_id, :status, :created_by)'
        );
        $stmt->execute([
            ':batch_key' => mail_generate_key('batch'),
            ':title' => $title,
            ':template_id' => $templateId,
            ':status' => 'prepared',
            ':created_by' => $actor !== null ? (int)($actor['id'] ?? 0) : null,
        ]);
        $batchId = (int)$pdo->lastInsertId();
        $targetCount = 0;
        $needsReview = 0;
        $insertTarget = $pdo->prepare(
            'INSERT INTO mail_batch_targets (batch_id, organization_id, to_email, rendered_subject, rendered_body, status, error_message) ' .
            'VALUES (:batch_id, :organization_id, :to_email, :rendered_subject, :rendered_body, :status, :error_message)'
        );
        foreach ($organizations as $org) {
            $email = mail_normalize_email((string)($org['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $needsReview++;
                continue;
            }
            $context = mail_organization_context($org);
            $subject = mail_render_template((string)$template['subject_template'], $context);
            $body = mail_render_template((string)$template['body_template'], $context);
            $unresolved = array_values(array_unique(array_merge($subject['unresolved'], $body['unresolved'])));
            $status = $unresolved === [] ? 'ready' : 'needs_review';
            if ($status === 'needs_review') {
                $needsReview++;
            }
            $insertTarget->execute([
                ':batch_id' => $batchId,
                ':organization_id' => (int)$org['id'],
                ':to_email' => $email,
                ':rendered_subject' => $subject['rendered'],
                ':rendered_body' => $body['rendered'],
                ':status' => $status,
                ':error_message' => $unresolved === [] ? null : '未解決変数: ' . implode(', ', $unresolved),
            ]);
            $targetCount++;
        }
        $stmt = $pdo->prepare('UPDATE mail_batches SET target_count = :target_count, status = :status WHERE id = :id');
        $stmt->execute([
            ':target_count' => $targetCount,
            ':status' => $needsReview > 0 ? 'reviewing' : 'prepared',
            ':id' => $batchId,
        ]);
        mail_audit_log($pdo, $actor, 'mail.batch.create', 'mail_batch', (string)$batchId, [
            'target_count' => $targetCount,
            'needs_review' => $needsReview,
        ]);
        $pdo->commit();
        return $batchId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function mail_update_batch_status(PDO $pdo, int $batchId, string $status, ?array $actor = null): void
{
    $allowed = ['draft','prepared','reviewing','approved','draft_created','sent','cancelled'];
    if (!in_array($status, $allowed, true)) {
        throw new InvalidArgumentException('不正なステータスです。');
    }
    $stmt = $pdo->prepare('UPDATE mail_batches SET status = :status, approved_by_account_id = :approved_by, approved_at = :approved_at WHERE id = :id');
    $stmt->execute([
        ':status' => $status,
        ':approved_by' => $status === 'approved' && $actor !== null ? (int)($actor['id'] ?? 0) : null,
        ':approved_at' => $status === 'approved' ? date('Y-m-d H:i:s') : null,
        ':id' => $batchId,
    ]);
    mail_audit_log($pdo, $actor, 'mail.batch.status', 'mail_batch', (string)$batchId, ['status' => $status]);
}

function mail_list_batch_attachments(PDO $pdo, int $batchId): array
{
    $stmt = $pdo->prepare(
        'SELECT a.*, f.original_name, f.relative_path, f.file_size, f.mime_type, o.identifier, o.name AS organization_name ' .
        'FROM mail_attachments a ' .
        'INNER JOIN mail_uploaded_files f ON f.id = a.uploaded_file_id ' .
        'LEFT JOIN mail_organizations o ON o.id = a.organization_id ' .
        'WHERE a.mail_batch_id = :batch_id ORDER BY a.is_common DESC, o.identifier ASC, f.original_name ASC'
    );
    $stmt->execute([':batch_id' => $batchId]);
    return $stmt->fetchAll() ?: [];
}

function mail_approve_attachment(PDO $pdo, int $attachmentId, ?array $actor = null): void
{
    $stmt = $pdo->prepare('UPDATE mail_attachments SET status = :status WHERE id = :id');
    $stmt->execute([':status' => 'approved', ':id' => $attachmentId]);
    mail_audit_log($pdo, $actor, 'mail.attachment.approve', 'mail_attachment', (string)$attachmentId);
}

function mail_exclude_attachment(PDO $pdo, int $attachmentId, ?array $actor = null): void
{
    $stmt = $pdo->prepare('UPDATE mail_attachments SET status = :status WHERE id = :id');
    $stmt->execute([':status' => 'excluded', ':id' => $attachmentId]);
    mail_audit_log($pdo, $actor, 'mail.attachment.exclude', 'mail_attachment', (string)$attachmentId);
}

function mail_refresh_batch_attachment_counts(PDO $pdo, int $batchId): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM mail_attachments WHERE mail_batch_id = :id AND is_common = 1 AND status <> "excluded"');
    $stmt->execute([':id' => $batchId]);
    $common = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM mail_attachments WHERE mail_batch_id = :id AND is_common = 0 AND status <> "excluded"');
    $stmt->execute([':id' => $batchId]);
    $individual = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('UPDATE mail_batches SET common_attachment_count = :common, individual_attachment_count = :individual WHERE id = :id');
    $stmt->execute([':common' => $common, ':individual' => $individual, ':id' => $batchId]);
}

function mail_list_upload_batches(PDO $pdo, int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    $stmt = $pdo->query(
        'SELECT u.*, b.title AS batch_title FROM mail_upload_batches u ' .
        'LEFT JOIN mail_batches b ON b.id = u.mail_batch_id ORDER BY u.created_at DESC, u.id DESC LIMIT ' . $limit
    );
    return $stmt->fetchAll() ?: [];
}

function mail_list_audit_logs(PDO $pdo, int $limit = 80): array
{
    if (!mail_table_exists($pdo, 'mail_audit_logs')) {
        return [];
    }
    $limit = max(1, min(300, $limit));
    $stmt = $pdo->query('SELECT * FROM mail_audit_logs ORDER BY created_at DESC, id DESC LIMIT ' . $limit);
    return $stmt->fetchAll() ?: [];
}
