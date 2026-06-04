<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

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
        'approved' => '承認済み',
        'draft_created' => '下書き作成済み',
        'sent' => '送信済み',
        'cancelled' => '取消',
        'uploaded' => 'アップロード済み',
        'matched' => '自動対応済み',
        'needs_review' => '要確認',
        'approved' => '確認済み',
        'archived' => '保管済み',
    ][$status] ?? $status;
}
