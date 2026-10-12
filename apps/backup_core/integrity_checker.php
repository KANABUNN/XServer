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

function backup_integrity_path_is_absolute(string $path): bool
{
    return $path !== '' && (str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1);
}

function backup_integrity_normalize_path(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    return rtrim($path, '/');
}

function backup_integrity_config_path(?string $path, string $baseDir = ''): ?string
{
    $path = backup_integrity_normalize_path((string)$path);
    if ($path === '') {
        return null;
    }
    if (backup_integrity_path_is_absolute($path)) {
        return $path;
    }

    $baseDir = $baseDir !== '' ? $baseDir : backup_apps_dir();
    return backup_integrity_normalize_path(rtrim($baseDir, '/') . '/' . ltrim($path, '/'));
}

function backup_integrity_configured_roots(array $rawRoots): array
{
    $roots = [];
    foreach ($rawRoots as $root) {
        if (!is_string($root) || trim($root) === '') {
            continue;
        }
        $resolved = backup_integrity_config_path($root, backup_apps_dir());
        if ($resolved !== null) {
            $roots[] = $resolved;
        }
        if (!backup_integrity_path_is_absolute($root)) {
            $projectResolved = backup_integrity_config_path($root, backup_project_root());
            if ($projectResolved !== null) {
                $roots[] = $projectResolved;
            }
        }
    }
    return $roots;
}

function backup_integrity_default_roots(string $context = 'generic'): array
{
    $roots = [
        backup_project_root(),
        backup_apps_dir(),
        backup_public_html_dir(),
        backup_apps_dir() . '/storage',
    ];

    if ($context === 'forms') {
        $roots = array_merge($roots, backup_integrity_configured_roots([
            (string)backup_config_value('forms.upload_root', ''),
            (string)backup_config_value('forms_storage.upload_root', ''),
            (string)backup_config_value('storage_maintenance.forms_uploads.live_root', ''),
            (string)backup_config_value('storage_maintenance.forms_uploads.archive_root', ''),
        ]), [
            backup_apps_dir() . '/forms_uploads',
            backup_apps_dir() . '/storage/archives/forms_uploads',
            backup_apps_dir() . '/storage/forms',
            backup_apps_dir() . '/storage/forms/uploads',
        ]);
    } elseif ($context === 'mail') {
        $roots = array_merge($roots, backup_integrity_configured_roots([
            (string)backup_config_value('storage.attachments_dir', ''),
            (string)backup_config_value('mail.storage.attachments_dir', ''),
            (string)backup_config_value('backup_manager.integrity.mail_storage_root', ''),
        ]), [
            backup_apps_dir() . '/storage/mail_attachments',
            backup_apps_dir() . '/storage/mail',
            backup_apps_dir() . '/storage/mail/uploads',
        ]);
    } elseif ($context === 'switchbot' || $context === 'book') {
        $roots = array_merge($roots, backup_integrity_configured_roots([
            (string)backup_config_value('switchbot.storage_dir', ''),
            (string)backup_config_value('storage.switchbot_dir', ''),
        ]), [
            backup_apps_dir() . '/storage/switchbot',
            backup_apps_dir() . '/storage/switchbot/events',
            backup_apps_dir() . '/storage/switchbot_webhook',
            backup_apps_dir() . '/storage/webhooks/switchbot',
        ]);
    }

    $normalized = [];
    foreach ($roots as $root) {
        if (!is_string($root) || trim($root) === '') {
            continue;
        }
        $normalized[backup_integrity_normalize_path($root)] = true;
    }
    return array_keys($normalized);
}

function backup_integrity_relative_variants(string $relativePath): array
{
    $raw = backup_integrity_normalize_path($relativePath);
    $raw = ltrim($raw, '/');
    $variants = [];
    $add = static function (string $path) use (&$variants): void {
        $path = ltrim(backup_integrity_normalize_path($path), '/');
        if ($path !== '' && !isset($variants[$path])) {
            $variants[$path] = true;
        }
    };

    $add($raw);
    foreach (['$ROOT/', 'apps/', 'public_html/', './'] as $prefix) {
        if (str_starts_with($raw, $prefix)) {
            $add(substr($raw, strlen($prefix)));
        }
    }
    foreach ([
        'forms_uploads/',
        'storage/forms/uploads/',
        'storage/forms/',
        'storage/mail_attachments/',
        'storage/mail/uploads/',
        'storage/mail/',
        'storage/switchbot/',
        'storage/switchbot/events/',
        'storage/switchbot_webhook/',
    ] as $prefix) {
        if (str_starts_with($raw, $prefix)) {
            $add(substr($raw, strlen($prefix)));
        }
    }

    return array_keys($variants);
}

function backup_integrity_forms_archive_lookup(string $relativePath): ?array
{
    if (!class_exists('ZipArchive')) {
        return null;
    }

    $normalized = ltrim(backup_integrity_normalize_path($relativePath), '/');
    if ($normalized === '' || str_contains('/' . $normalized . '/', '/../')) {
        return null;
    }

    $archiveRoot = backup_integrity_config_path((string)backup_config_value('storage_maintenance.forms_uploads.archive_root', ''), backup_apps_dir());
    if ($archiveRoot === null) {
        $archiveRoot = backup_apps_dir() . '/storage/archives/forms_uploads';
    }

    if (preg_match('#^(\d{4})/(\d{2})/#', $normalized, $m) === 1) {
        $zipPath = $archiveRoot . '/' . $m[1] . '/' . $m[2] . '/forms_revisions_' . $m[1] . '-' . $m[2] . '.zip';
    } else {
        $zipPath = $archiveRoot . '/misc/forms_revisions_misc.zip';
    }

    if (!is_file($zipPath)) {
        return null;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return null;
    }
    try {
        if ($zip->locateName($normalized) === false) {
            return null;
        }
    } finally {
        $zip->close();
    }

    return [
        'exists' => true,
        'path' => $zipPath,
        'masked_path' => backup_mask_path($zipPath) . '#' . $normalized,
        'candidates' => [backup_mask_path($zipPath) . '#' . $normalized],
        'bytes' => (int)filesize($zipPath),
        'mtime' => date('Y-m-d H:i:s', (int)filemtime($zipPath)),
        'from_archive' => true,
    ];
}

function backup_resolve_relative_file_path(string $relativePath, array $extraRoots = [], string $context = 'generic'): array
{
    $relativePath = backup_integrity_normalize_path($relativePath);
    if ($relativePath === '') {
        return ['exists' => false, 'path' => '', 'masked_path' => '', 'candidates' => []];
    }
    if (str_contains('/' . ltrim($relativePath, '/') . '/', '/../')) {
        return [
            'exists' => false,
            'path' => '',
            'masked_path' => '',
            'candidates' => [],
            'unsafe_path' => true,
        ];
    }

    $candidates = [];
    if (backup_integrity_path_is_absolute($relativePath)) {
        $candidates[] = $relativePath;
    } else {
        $roots = array_merge(
            backup_integrity_default_roots($context),
            backup_integrity_configured_roots($extraRoots)
        );
        $roots = array_values(array_unique(array_filter($roots, 'is_string')));
        foreach ($roots as $root) {
            foreach (backup_integrity_relative_variants($relativePath) as $variant) {
                $candidates[] = rtrim($root, '/') . '/' . ltrim($variant, '/');
            }
        }
    }

    $seen = [];
    $uniqueCandidates = [];
    foreach ($candidates as $candidate) {
        $candidate = backup_integrity_normalize_path($candidate);
        if ($candidate === '' || isset($seen[$candidate])) {
            continue;
        }
        $seen[$candidate] = true;
        $uniqueCandidates[] = $candidate;
    }

    foreach ($uniqueCandidates as $candidate) {
        if (is_file($candidate)) {
            return [
                'exists' => true,
                'path' => $candidate,
                'masked_path' => backup_mask_path($candidate),
                'candidates' => array_map('backup_mask_path', array_slice($uniqueCandidates, 0, 20)),
                'bytes' => (int)filesize($candidate),
                'mtime' => date('Y-m-d H:i:s', (int)filemtime($candidate)),
                'from_archive' => false,
            ];
        }
    }

    if ($context === 'forms') {
        foreach (backup_integrity_relative_variants($relativePath) as $variant) {
            $archived = backup_integrity_forms_archive_lookup($variant);
            if ($archived !== null) {
                $archived['candidates'] = array_merge($archived['candidates'], array_map('backup_mask_path', array_slice($uniqueCandidates, 0, 20)));
                return $archived;
            }
        }
    }

    return [
        'exists' => false,
        'path' => $uniqueCandidates[0] ?? $relativePath,
        'masked_path' => backup_mask_path($uniqueCandidates[0] ?? $relativePath),
        'candidates' => array_map('backup_mask_path', array_slice($uniqueCandidates, 0, 20)),
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
        ['table' => 'managed_form_submission_files', 'id' => 'id', 'path' => 'relative_path'],
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
            $resolved = backup_resolve_relative_file_path($relative, (array)backup_config_value('backup_manager.integrity.forms_file_roots', []), 'forms');
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
        $resolved = backup_resolve_relative_file_path($relative, (array)backup_config_value('backup_manager.integrity.mail_file_roots', []), 'mail');
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
        $resolved = backup_resolve_relative_file_path($relative, (array)backup_config_value('backup_manager.integrity.switchbot_file_roots', []), 'switchbot');
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
