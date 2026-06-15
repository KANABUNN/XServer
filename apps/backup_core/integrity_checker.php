<?php

declare(strict_types=1);

require_once __DIR__ . '/manager.php';
require_once __DIR__ . '/alert_rules.php';

function backup_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $stmt->execute([':table' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

function backup_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
        $stmt->execute([':table' => $table, ':column' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

function backup_resolve_relative_file_path(string $relativePath, array $extraRoots = []): array
{
    $relativePath = trim(str_replace('\\', '/', $relativePath));
    if ($relativePath === '') {
        return ['exists' => false, 'path' => '', 'masked_path' => '', 'candidates' => []];
    }

    $candidates = [];
    if (str_starts_with($relativePath, '/')) {
        $candidates[] = $relativePath;
    } else {
        $roots = array_values(array_unique(array_filter(array_merge([
            backup_project_root(),
            backup_apps_dir(),
            backup_public_html_dir(),
            backup_apps_dir() . '/storage',
            backup_apps_dir() . '/storage/forms',
            backup_apps_dir() . '/storage/forms/uploads',
            backup_apps_dir() . '/storage/mail',
            backup_apps_dir() . '/storage/mail/uploads',
            backup_apps_dir() . '/storage/switchbot',
        ], $extraRoots), 'is_string')));
        foreach ($roots as $root) {
            $candidates[] = rtrim($root, '/') . '/' . ltrim($relativePath, '/');
        }
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return [
                'exists' => true,
                'path' => $candidate,
                'masked_path' => backup_mask_path($candidate),
                'candidates' => array_map('backup_mask_path', array_slice($candidates, 0, 12)),
                'bytes' => (int)filesize($candidate),
                'mtime' => date('Y-m-d H:i:s', (int)filemtime($candidate)),
            ];
        }
    }

    return [
        'exists' => false,
        'path' => $candidates[0] ?? $relativePath,
        'masked_path' => backup_mask_path($candidates[0] ?? $relativePath),
        'candidates' => array_map('backup_mask_path', array_slice($candidates, 0, 12)),
    ];
}

function backup_integrity_insert_item(PDO $pdo, int $checkId, array $item): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO backup_integrity_items (check_id, app_key, source_table, source_id, relative_path, resolved_path, item_status, message, detail_json) ' .
        'VALUES (:check_id, :app_key, :source_table, :source_id, :relative_path, :resolved_path, :item_status, :message, :detail_json)'
    );
    $stmt->execute([
        ':check_id' => $checkId,
        ':app_key' => (string)($item['app_key'] ?? ''),
        ':source_table' => (string)($item['source_table'] ?? ''),
        ':source_id' => (string)($item['source_id'] ?? ''),
        ':relative_path' => mb_substr((string)($item['relative_path'] ?? ''), 0, 500, 'UTF-8'),
        ':resolved_path' => mb_substr((string)($item['resolved_path'] ?? ''), 0, 500, 'UTF-8'),
        ':item_status' => (string)($item['item_status'] ?? 'ok'),
        ':message' => mb_substr((string)($item['message'] ?? ''), 0, 1000, 'UTF-8'),
        ':detail_json' => backup_json_encode(is_array($item['detail'] ?? null) ? $item['detail'] : []),
    ]);
}

function backup_integrity_collect_forms(PDO $backupPdo, int $checkId): array
{
    $counts = ['checked' => 0, 'ok' => 0, 'missing' => 0, 'warning' => 0, 'skipped' => 0];
    try {
        $pdo = backup_pdo('forms');
    } catch (Throwable $e) {
        backup_integrity_insert_item($backupPdo, $checkId, [
            'app_key' => 'forms', 'source_table' => 'connection', 'source_id' => '', 'relative_path' => '',
            'resolved_path' => '', 'item_status' => 'warning', 'message' => 'forms DB接続を取得できません: ' . $e->getMessage(),
        ]);
        $counts['warning']++;
        return $counts;
    }

    $targets = [
        ['table' => 'managed_form_submissions', 'id' => 'id', 'path' => 'uploaded_relative_path'],
        ['table' => 'managed_form_submission_revisions', 'id' => 'id', 'path' => 'uploaded_relative_path'],
    ];
    foreach ($targets as $target) {
        $table = $target['table'];
        if (!backup_table_exists($pdo, $table) || !backup_column_exists($pdo, $table, $target['path'])) {
            $counts['skipped']++;
            continue;
        }
        $stmt = $pdo->query('SELECT `' . $target['id'] . '` AS id, `' . $target['path'] . '` AS path FROM `' . $table . '` WHERE `' . $target['path'] . '` IS NOT NULL AND `' . $target['path'] . '` <> "" ORDER BY `' . $target['id'] . '` DESC LIMIT 5000');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $counts['checked']++;
            $relative = (string)($row['path'] ?? '');
            $resolved = backup_resolve_relative_file_path($relative, (array)backup_config_value('backup_manager.integrity.forms_file_roots', []));
            if ($resolved['exists']) {
                $counts['ok']++;
                continue;
            }
            $counts['missing']++;
            backup_integrity_insert_item($backupPdo, $checkId, [
                'app_key' => 'forms',
                'source_table' => $table,
                'source_id' => (string)($row['id'] ?? ''),
                'relative_path' => $relative,
                'resolved_path' => (string)($resolved['masked_path'] ?? ''),
                'item_status' => 'missing',
                'message' => 'DB上のForms添付ファイルが見つかりません。',
                'detail' => ['candidates' => $resolved['candidates'] ?? []],
            ]);
        }
    }
    return $counts;
}

function backup_integrity_collect_mail(PDO $backupPdo, int $checkId): array
{
    $counts = ['checked' => 0, 'ok' => 0, 'missing' => 0, 'warning' => 0, 'skipped' => 0];
    try {
        $pdo = backup_pdo('mail');
    } catch (Throwable $e) {
        backup_integrity_insert_item($backupPdo, $checkId, [
            'app_key' => 'mail', 'source_table' => 'connection', 'source_id' => '', 'relative_path' => '',
            'resolved_path' => '', 'item_status' => 'warning', 'message' => 'mail DB接続を取得できません: ' . $e->getMessage(),
        ]);
        $counts['warning']++;
        return $counts;
    }

    if (!backup_table_exists($pdo, 'mail_uploaded_files') || !backup_column_exists($pdo, 'mail_uploaded_files', 'relative_path')) {
        $counts['skipped']++;
        return $counts;
    }
    $where = backup_column_exists($pdo, 'mail_uploaded_files', 'status') ? " WHERE relative_path IS NOT NULL AND relative_path <> '' AND status <> 'deleted'" : " WHERE relative_path IS NOT NULL AND relative_path <> ''";
    $stmt = $pdo->query('SELECT id, relative_path FROM mail_uploaded_files' . $where . ' ORDER BY id DESC LIMIT 5000');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $counts['checked']++;
        $relative = (string)($row['relative_path'] ?? '');
        $resolved = backup_resolve_relative_file_path($relative, (array)backup_config_value('backup_manager.integrity.mail_file_roots', []));
        if ($resolved['exists']) {
            $counts['ok']++;
            continue;
        }
        $counts['missing']++;
        backup_integrity_insert_item($backupPdo, $checkId, [
            'app_key' => 'mail',
            'source_table' => 'mail_uploaded_files',
            'source_id' => (string)($row['id'] ?? ''),
            'relative_path' => $relative,
            'resolved_path' => (string)($resolved['masked_path'] ?? ''),
            'item_status' => 'missing',
            'message' => 'DB上のMail添付ファイルが見つかりません。',
            'detail' => ['candidates' => $resolved['candidates'] ?? []],
        ]);
    }
    return $counts;
}

function backup_integrity_collect_book(PDO $backupPdo, int $checkId): array
{
    $counts = ['checked' => 0, 'ok' => 0, 'missing' => 0, 'warning' => 0, 'skipped' => 0];
    try {
        $pdo = backup_pdo('book');
    } catch (Throwable $e) {
        backup_integrity_insert_item($backupPdo, $checkId, [
            'app_key' => 'book', 'source_table' => 'connection', 'source_id' => '', 'relative_path' => '',
            'resolved_path' => '', 'item_status' => 'warning', 'message' => 'book DB接続を取得できません: ' . $e->getMessage(),
        ]);
        $counts['warning']++;
        return $counts;
    }
    if (!backup_table_exists($pdo, 'switchbot_passcode_requests') || !backup_column_exists($pdo, 'switchbot_passcode_requests', 'detail_json_path')) {
        $counts['skipped']++;
        return $counts;
    }
    $stmt = $pdo->query("SELECT id, detail_json_path FROM switchbot_passcode_requests WHERE detail_json_path IS NOT NULL AND detail_json_path <> '' ORDER BY id DESC LIMIT 3000");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $counts['checked']++;
        $relative = (string)($row['detail_json_path'] ?? '');
        $resolved = backup_resolve_relative_file_path($relative, (array)backup_config_value('backup_manager.integrity.switchbot_file_roots', []));
        if ($resolved['exists']) {
            $counts['ok']++;
            continue;
        }
        $counts['missing']++;
        backup_integrity_insert_item($backupPdo, $checkId, [
            'app_key' => 'book',
            'source_table' => 'switchbot_passcode_requests',
            'source_id' => (string)($row['id'] ?? ''),
            'relative_path' => $relative,
            'resolved_path' => (string)($resolved['masked_path'] ?? ''),
            'item_status' => 'missing',
            'message' => 'DB上のSwitchBot詳細JSONが見つかりません。',
            'detail' => ['candidates' => $resolved['candidates'] ?? []],
        ]);
    }
    return $counts;
}

function backup_run_integrity_check(string $triggerType = 'cron', ?array $actor = null): array
{
    $pdo = backup_pdo('backup');
    backup_install_schema($pdo);
    $started = backup_now();
    $checkKey = 'integrity_' . $started->format('Ymd_His');
    $stmt = $pdo->prepare('INSERT INTO backup_integrity_checks (check_key, trigger_type, started_at, status) VALUES (:check_key, :trigger_type, :started_at, :status)');
    $stmt->execute([
        ':check_key' => $checkKey,
        ':trigger_type' => $triggerType,
        ':started_at' => $started->format('Y-m-d H:i:s'),
        ':status' => 'running',
    ]);
    $checkId = (int)$pdo->lastInsertId();

    $summary = ['checked' => 0, 'ok' => 0, 'missing' => 0, 'warning' => 0, 'skipped' => 0];
    try {
        foreach ([backup_integrity_collect_forms($pdo, $checkId), backup_integrity_collect_mail($pdo, $checkId), backup_integrity_collect_book($pdo, $checkId)] as $part) {
            foreach ($summary as $key => $_) {
                $summary[$key] += (int)($part[$key] ?? 0);
            }
        }
        $status = $summary['missing'] > 0 ? 'failed' : ($summary['warning'] > 0 ? 'warning' : 'success');
        $message = sprintf('整合性チェック完了: 確認 %d 件 / 欠損 %d 件 / 警告 %d 件', $summary['checked'], $summary['missing'], $summary['warning']);
        $update = $pdo->prepare('UPDATE backup_integrity_checks SET finished_at = :finished_at, status = :status, checked_items = :checked, ok_items = :ok, missing_items = :missing, warning_items = :warning, summary_json = :summary_json WHERE id = :id');
        $update->execute([
            ':finished_at' => backup_now()->format('Y-m-d H:i:s'),
            ':status' => $status,
            ':checked' => $summary['checked'],
            ':ok' => $summary['ok'],
            ':missing' => $summary['missing'],
            ':warning' => $summary['warning'],
            ':summary_json' => backup_json_encode($summary + ['message' => $message]),
            ':id' => $checkId,
        ]);
        if ($summary['missing'] > 0) {
            backup_alert_raise('integrity_missing_files', 'critical', 'DBと実ファイルの整合性チェックで欠損を検出しました: ' . $summary['missing'] . ' 件', null, ['check_id' => $checkId, 'summary' => $summary]);
            backup_notify_on_failure('[FIT-SC Backup] ファイル整合性チェックで欠損を検出しました', $message);
        } elseif ($summary['warning'] > 0) {
            backup_alert_raise('integrity_check_warning', 'warning', 'ファイル整合性チェックに警告があります。', null, ['check_id' => $checkId, 'summary' => $summary]);
        }
        backup_operation_log('integrity.run', $actor ?? [], 'integrity_check', (string)$checkId, $summary + ['trigger_type' => $triggerType]);
        return backup_get_integrity_check($checkId) ?: [];
    } catch (Throwable $e) {
        $pdo->prepare('UPDATE backup_integrity_checks SET finished_at = :finished_at, status = :status, summary_json = :summary_json WHERE id = :id')->execute([
            ':finished_at' => backup_now()->format('Y-m-d H:i:s'),
            ':status' => 'failed',
            ':summary_json' => backup_json_encode(['error' => $e->getMessage()]),
            ':id' => $checkId,
        ]);
        backup_alert_raise('integrity_check_exception', 'critical', 'ファイル整合性チェックが失敗しました: ' . $e->getMessage(), null, ['check_id' => $checkId]);
        throw $e;
    }
}

function backup_latest_integrity_checks(int $limit = 20): array
{
    $pdo = backup_pdo('backup');
    backup_install_schema($pdo);
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare('SELECT * FROM backup_integrity_checks ORDER BY id DESC LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function backup_get_integrity_check(int $id): ?array
{
    $pdo = backup_pdo('backup');
    backup_install_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM backup_integrity_checks WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function backup_integrity_items(int $checkId, int $limit = 200): array
{
    $pdo = backup_pdo('backup');
    backup_install_schema($pdo);
    $limit = max(1, min(1000, $limit));
    $stmt = $pdo->prepare('SELECT * FROM backup_integrity_items WHERE check_id = :check_id ORDER BY item_status DESC, id ASC LIMIT :limit');
    $stmt->bindValue(':check_id', $checkId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
