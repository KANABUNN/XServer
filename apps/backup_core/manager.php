<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/storage_status.php';

final class FitScBackupManager
{
    private PDO $pdo;
    private ?int $jobId = null;
    private string $jobKey = '';
    private string $jobType = 'daily';
    private string $destinationDir = '';
    /** @var array<int,array<string,mixed>> */
    private array $items = [];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: backup_pdo('backup');
        backup_install_schema($this->pdo);
    }

    public function runDaily(string $triggerType = 'cron'): array
    {
        return $this->runBackup('daily', $triggerType);
    }

    public function runWeekly(string $triggerType = 'cron'): array
    {
        return $this->runBackup('weekly', $triggerType);
    }

    public function verifyLatest(string $triggerType = 'cron'): array
    {
        $this->startJob('verify', $triggerType, 'verify_' . backup_now()->format('Ymd_His'));
        backup_write_log('info', 'backup verification started', ['job_id' => $this->jobId]);

        try {
            $stmt = $this->pdo->query("SELECT * FROM backup_jobs WHERE job_type IN ('daily','weekly','monthly') AND status IN ('success','partial','warning') ORDER BY id DESC LIMIT 1");
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)) {
                $this->finishJob('warning', '検証対象のバックアップがありません。');
                return $this->jobSummary();
            }

            $items = $this->listItems((int)$job['id']);
            $ok = 0;
            $ng = 0;
            foreach ($items as $item) {
                $path = (string)($item['backup_path'] ?? '');
                $expected = (string)($item['sha256'] ?? '');
                if ($path === '' || $expected === '') {
                    continue;
                }
                if (!is_file($path)) {
                    $ng++;
                    $this->insertItem([
                        'item_type' => 'verify',
                        'target_key' => (string)$item['target_key'],
                        'target_label' => 'missing: ' . (string)$item['target_label'],
                        'source_path' => $path,
                        'backup_path' => null,
                        'file_count' => null,
                        'total_bytes' => 0,
                        'sha256' => null,
                        'status' => 'failed',
                        'error_message' => 'バックアップファイルが存在しません。',
                    ]);
                    continue;
                }
                $actual = hash_file('sha256', $path);
                if (!is_string($actual) || !hash_equals($expected, $actual)) {
                    $ng++;
                    $this->insertItem([
                        'item_type' => 'verify',
                        'target_key' => (string)$item['target_key'],
                        'target_label' => 'hash mismatch: ' . (string)$item['target_label'],
                        'source_path' => $path,
                        'backup_path' => null,
                        'file_count' => null,
                        'total_bytes' => (int)filesize($path),
                        'sha256' => $actual ?: null,
                        'status' => 'failed',
                        'error_message' => 'SHA256 が一致しません。',
                    ]);
                    continue;
                }
                $ok++;
                $this->insertItem([
                    'item_type' => 'verify',
                    'target_key' => (string)$item['target_key'],
                    'target_label' => 'verified: ' . (string)$item['target_label'],
                    'source_path' => $path,
                    'backup_path' => null,
                    'file_count' => null,
                    'total_bytes' => (int)filesize($path),
                    'sha256' => $actual,
                    'status' => 'success',
                    'error_message' => null,
                ]);
            }

            $status = $ng > 0 ? 'failed' : 'success';
            $message = sprintf('検証完了: OK %d 件 / NG %d 件', $ok, $ng);
            $this->finishJob($status, $message);
            if ($ng > 0) {
                $this->createAlert('critical', 'backup_verify_failed', $message);
                backup_notify_on_failure('[FIT-SC Backup] 検証失敗', $message);
            }
            return $this->jobSummary();
        } catch (Throwable $e) {
            $this->finishJob('failed', $e->getMessage());
            $this->createAlert('critical', 'backup_verify_exception', $e->getMessage());
            backup_notify_on_failure('[FIT-SC Backup] 検証処理が失敗しました', $e->getMessage());
            throw $e;
        }
    }

    public function latestJobs(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare('SELECT * FROM backup_jobs ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getJob(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM backup_jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function listItems(int $jobId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM backup_items WHERE job_id = :job_id ORDER BY id ASC');
        $stmt->execute([':job_id' => $jobId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function unresolvedAlerts(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare('SELECT * FROM backup_alerts WHERE is_resolved = 0 ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function collectStorageStatus(string $triggerType = 'cron'): array
    {
        $payload = backup_collect_storage_status();
        $snapshotId = $this->insertStorageSnapshot($payload);

        $status = (string)($payload['status'] ?? 'success');
        $message = $status === 'success'
            ? 'ストレージ状態を収集しました。'
            : 'ストレージ状態の収集に警告があります: ' . (string)($payload['maintenance_error'] ?? '');

        if ($status !== 'success') {
            $this->createAlert('warning', 'storage_status_collect_warning', mb_substr($message, 0, 1000, 'UTF-8'));
            backup_notify_on_failure('[FIT-SC Backup] ストレージ状態収集に警告があります', $message);
        }

        $disk = is_array($payload['disk'] ?? null) ? $payload['disk'] : [];
        $usedRatio = $disk['used_ratio'] ?? null;
        if (is_float($usedRatio) || is_int($usedRatio)) {
            $warning = (float)backup_config_value('backup_manager.disk_warning_ratio', 0.80);
            $critical = (float)backup_config_value('backup_manager.disk_critical_ratio', 0.90);
            if ((float)$usedRatio >= $critical) {
                $this->createAlert('critical', 'disk_usage_critical', 'ディスク使用率が危険域です: ' . round((float)$usedRatio * 100, 1) . '%');
            } elseif ((float)$usedRatio >= $warning) {
                $this->createAlert('warning', 'disk_usage_warning', 'ディスク使用率が警告域です: ' . round((float)$usedRatio * 100, 1) . '%');
            }
        }

        backup_write_log('info', 'storage status collected', ['snapshot_id' => $snapshotId, 'status' => $status]);
        return $this->getStorageSnapshot($snapshotId) ?: [];
    }

    public function latestStorageSnapshots(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare('SELECT * FROM backup_storage_snapshots ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function latestStorageSnapshot(): ?array
    {
        $rows = $this->latestStorageSnapshots(1);
        return $rows[0] ?? null;
    }

    public function getStorageSnapshot(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM backup_storage_snapshots WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function latestReports(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare('SELECT * FROM backup_reports ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function generateMonthlyReport(string $triggerType = 'cron'): array
    {
        $now = backup_now();
        $reportKey = 'backup_report_' . $now->format('Y-m');
        $snapshot = $this->latestStorageSnapshot();
        if (!$snapshot) {
            $snapshot = $this->collectStorageStatus($triggerType);
        }

        $jobs = $this->latestJobs(80);
        $alerts = $this->unresolvedAlerts(50);
        $backupRootStats = backup_directory_stats(backup_root_dir());
        $payload = [
            'generated_at' => $now->format('Y-m-d H:i:s'),
            'month' => $now->format('Y-m'),
            'summary' => [
                'backup_root_bytes' => (int)($backupRootStats['bytes'] ?? 0),
                'job_count' => count($jobs),
                'unresolved_alerts' => count($alerts),
            ],
            'jobs' => array_map(static function (array $job): array {
                return [
                    'id' => (int)$job['id'],
                    'job_type' => (string)$job['job_type'],
                    'status' => (string)$job['status'],
                    'started_at' => (string)$job['started_at'],
                    'finished_at' => (string)($job['finished_at'] ?? ''),
                    'total_bytes' => (int)$job['total_bytes'],
                    'message' => (string)($job['message'] ?? ''),
                ];
            }, $jobs),
            'storage_snapshot' => $snapshot,
            'backup_root_stats' => $backupRootStats,
            'alerts' => $alerts,
        ];

        $dir = backup_state_dir() . '/reports';
        backup_ensure_dir($dir, 0700);
        $path = $dir . '/' . $reportKey . '.md';
        $markdown = backup_render_backup_monthly_report($payload);
        file_put_contents($path, $markdown, LOCK_EX);
        @chmod($path, 0600);
        $sha = hash_file('sha256', $path) ?: null;

        $stmt = $this->pdo->prepare(
            'INSERT INTO backup_reports (report_key, report_type, generated_at, report_path, report_sha256, status, summary_json) ' .
            'VALUES (:report_key, :report_type, :generated_at, :report_path, :report_sha256, :status, :summary_json) ' .
            'ON DUPLICATE KEY UPDATE generated_at = VALUES(generated_at), report_path = VALUES(report_path), report_sha256 = VALUES(report_sha256), status = VALUES(status), summary_json = VALUES(summary_json)'
        );
        $stmt->execute([
            ':report_key' => $reportKey,
            ':report_type' => 'monthly',
            ':generated_at' => $now->format('Y-m-d H:i:s'),
            ':report_path' => $path,
            ':report_sha256' => $sha,
            ':status' => 'success',
            ':summary_json' => backup_json_encode($payload['summary']),
        ]);

        if ((bool)backup_config_value('backup_manager.send_monthly_report_mail', false)) {
            backup_notify_on_failure('[FIT-SC Backup] 月次バックアップレポート ' . $now->format('Y-m'), $markdown);
        }

        backup_write_log('info', 'monthly backup report generated', ['report_key' => $reportKey, 'path' => $path]);
        return [
            'report_key' => $reportKey,
            'path' => $path,
            'sha256' => $sha,
            'summary' => $payload['summary'],
        ];
    }

    public function runRetentionCleanup(string $triggerType = 'cron'): array
    {
        $this->startJob('cleanup', $triggerType, 'cleanup_' . backup_now()->format('Ymd_His'));
        backup_write_log('info', 'backup retention cleanup started', ['job_id' => $this->jobId]);

        try {
            $deletedDirs = $this->cleanupBackupFilesByRetention();
            $deletedMeta = $this->cleanupMetadataByRetention();
            $this->insertItem([
                'item_type' => 'cleanup',
                'target_key' => 'backup_retention',
                'target_label' => 'バックアップ保持期間整理',
                'source_path' => backup_root_dir(),
                'backup_path' => null,
                'file_count' => $deletedDirs['deleted_dirs'],
                'total_bytes' => $deletedDirs['deleted_bytes'],
                'sha256' => null,
                'status' => 'success',
                'error_message' => null,
            ]);
            $this->insertItem([
                'item_type' => 'cleanup',
                'target_key' => 'metadata_retention',
                'target_label' => 'バックアップ管理DB整理',
                'source_path' => 'fitsc_backup',
                'backup_path' => null,
                'file_count' => $deletedMeta,
                'total_bytes' => 0,
                'sha256' => null,
                'status' => 'success',
                'error_message' => null,
            ]);

            $message = sprintf('保持期間整理完了: ディレクトリ %d 件 / %s, 管理行 %d 件', $deletedDirs['deleted_dirs'], backup_format_bytes((int)$deletedDirs['deleted_bytes']), $deletedMeta);
            $this->finishJob('success', $message);
            return $this->jobSummary();
        } catch (Throwable $e) {
            $this->finishJob('failed', $e->getMessage());
            $this->createAlert('critical', 'backup_cleanup_exception', $e->getMessage());
            backup_notify_on_failure('[FIT-SC Backup] バックアップ保持期間整理が失敗しました', $e->getMessage());
            throw $e;
        }
    }

    private function runBackup(string $jobType, string $triggerType): array
    {
        $now = backup_now();
        $this->startJob($jobType, $triggerType, $jobType . '_' . $now->format('Ymd_His'));

        $dateDir = $jobType === 'daily' ? $now->format('Y-m-d') : $now->format('o-\WW');
        $this->destinationDir = backup_root_dir() . '/' . $jobType . '/' . $dateDir . '/' . $this->jobKey;
        backup_ensure_dir($this->destinationDir, 0700);
        backup_ensure_dir($this->destinationDir . '/db', 0700);
        backup_ensure_dir($this->destinationDir . '/files', 0700);

        $this->updateJobRoot($this->destinationDir);
        backup_write_log('info', 'backup started', ['job_id' => $this->jobId, 'type' => $jobType, 'dir' => $this->destinationDir]);

        try {
            $this->backupDatabases();
            $this->backupFiles($jobType);
            $this->writeManifest();
            $summary = $this->calculateSummary();
            $status = $summary['failed_items'] > 0 ? ($summary['success_items'] > 0 ? 'partial' : 'failed') : 'success';
            $message = sprintf('バックアップ完了: 成功 %d 件 / 失敗 %d 件 / 合計 %s', $summary['success_items'], $summary['failed_items'], backup_format_bytes($summary['total_bytes']));
            $this->finishJob($status, $message, $summary);
            if ($status !== 'success') {
                $this->createAlert($status === 'failed' ? 'critical' : 'warning', 'backup_' . $status, $message);
                backup_notify_on_failure('[FIT-SC Backup] バックアップに失敗項目があります', $message);
            }
            return $this->jobSummary();
        } catch (Throwable $e) {
            $this->finishJob('failed', $e->getMessage());
            $this->createAlert('critical', 'backup_exception', $e->getMessage());
            backup_notify_on_failure('[FIT-SC Backup] バックアップ処理が失敗しました', $e->getMessage());
            backup_write_log('error', 'backup failed', ['job_id' => $this->jobId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function startJob(string $jobType, string $triggerType, string $jobKey): void
    {
        $this->jobType = $jobType;
        $this->jobKey = $jobKey;
        $this->items = [];
        $this->destinationDir = '';

        $stmt = $this->pdo->prepare(
            'INSERT INTO backup_jobs (job_key, job_type, trigger_type, started_at, status, host_name, php_version) ' .
            'VALUES (:job_key, :job_type, :trigger_type, :started_at, :status, :host_name, :php_version)'
        );
        $stmt->execute([
            ':job_key' => $jobKey,
            ':job_type' => $jobType,
            ':trigger_type' => $triggerType,
            ':started_at' => backup_now()->format('Y-m-d H:i:s'),
            ':status' => 'running',
            ':host_name' => gethostname() ?: php_uname('n'),
            ':php_version' => PHP_VERSION,
        ]);
        $this->jobId = (int)$this->pdo->lastInsertId();
    }

    private function updateJobRoot(string $root): void
    {
        if ($this->jobId === null) {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE backup_jobs SET backup_root = :root WHERE id = :id');
        $stmt->execute([':root' => $root, ':id' => $this->jobId]);
    }

    private function finishJob(string $status, string $message, ?array $summary = null): void
    {
        if ($this->jobId === null) {
            return;
        }
        $summary = $summary ?: $this->calculateSummary();
        $stmt = $this->pdo->prepare(
            'UPDATE backup_jobs SET finished_at = :finished_at, status = :status, message = :message, ' .
            'total_items = :total_items, success_items = :success_items, failed_items = :failed_items, total_bytes = :total_bytes ' .
            'WHERE id = :id'
        );
        $stmt->execute([
            ':finished_at' => backup_now()->format('Y-m-d H:i:s'),
            ':status' => $status,
            ':message' => mb_substr($message, 0, 1000, 'UTF-8'),
            ':total_items' => (int)$summary['total_items'],
            ':success_items' => (int)$summary['success_items'],
            ':failed_items' => (int)$summary['failed_items'],
            ':total_bytes' => (int)$summary['total_bytes'],
            ':id' => $this->jobId,
        ]);
        backup_write_log($status === 'success' ? 'info' : 'warning', 'backup job finished', ['job_id' => $this->jobId, 'status' => $status, 'message' => $message]);
    }

    private function backupDatabases(): void
    {
        foreach (backup_default_db_targets() as $target) {
            $key = (string)($target['key'] ?? $target['connection'] ?? 'db');
            $connection = (string)($target['connection'] ?? $key);
            $dbname = (string)($target['dbname'] ?? '');
            $required = (bool)($target['required'] ?? true);
            try {
                $cfg = backup_connection_config($connection);
                if ($dbname === '') {
                    $dbname = (string)$cfg['dbname'];
                }
                $out = $this->destinationDir . '/db/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $dbname) . '.sql.gz';
                $this->dumpDatabase($cfg, $dbname, $out);
                $this->insertItem([
                    'item_type' => 'database',
                    'target_key' => $key,
                    'target_label' => $dbname,
                    'source_path' => $dbname,
                    'backup_path' => $out,
                    'file_count' => 1,
                    'total_bytes' => (int)filesize($out),
                    'sha256' => hash_file('sha256', $out) ?: null,
                    'status' => 'success',
                    'error_message' => null,
                ]);
            } catch (Throwable $e) {
                $status = $required ? 'failed' : 'warning';
                $this->insertItem([
                    'item_type' => 'database',
                    'target_key' => $key,
                    'target_label' => $dbname !== '' ? $dbname : $connection,
                    'source_path' => $dbname,
                    'backup_path' => null,
                    'file_count' => 0,
                    'total_bytes' => 0,
                    'sha256' => null,
                    'status' => $status,
                    'error_message' => $e->getMessage(),
                ]);
                backup_write_log($required ? 'error' : 'warning', 'database backup failed', ['target' => $key, 'error' => $e->getMessage()]);
            }
        }
    }

    private function dumpDatabase(array $cfg, string $dbname, string $out): void
    {
        $tmpDefaults = tempnam(sys_get_temp_dir(), 'fit_sc_mysqldump_');
        if (!is_string($tmpDefaults)) {
            throw new RuntimeException('mysqldump 用の一時設定ファイルを作成できません。');
        }
        $defaults = "[client]\n" .
            'user=' . $cfg['user'] . "\n" .
            'password=' . $cfg['password'] . "\n" .
            'host=' . $cfg['host'] . "\n" .
            'port=' . (int)$cfg['port'] . "\n" .
            'default-character-set=' . $cfg['charset'] . "\n";
        file_put_contents($tmpDefaults, $defaults, LOCK_EX);
        @chmod($tmpDefaults, 0600);

        $command = sprintf(
            'mysqldump --defaults-extra-file=%s --single-transaction --quick --skip-lock-tables --routines --triggers %s | gzip -c > %s 2>&1',
            escapeshellarg($tmpDefaults),
            escapeshellarg($dbname),
            escapeshellarg($out)
        );

        $output = [];
        $exit = 0;
        exec($command, $output, $exit);
        @unlink($tmpDefaults);

        if ($exit !== 0 || !is_file($out) || filesize($out) === 0) {
            @unlink($out);
            throw new RuntimeException('mysqldump に失敗しました: ' . implode("\n", array_slice($output, -5)));
        }
        @chmod($out, 0600);
    }

    private function backupFiles(string $jobType): void
    {
        foreach (backup_default_file_targets() as $target) {
            $key = (string)($target['key'] ?? 'file');
            $path = (string)($target['path'] ?? '');
            $label = (string)($target['label'] ?? $key);
            $required = (bool)($target['required'] ?? true);
            $exclude = is_array($target['exclude'] ?? null) ? $target['exclude'] : [];

            try {
                if ($path === '' || !is_dir($path)) {
                    throw new RuntimeException('対象ディレクトリが存在しません: ' . $path);
                }
                $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $key);
                $out = $this->destinationDir . '/files/' . $safeName . '.tar.gz';
                $count = $this->countFiles($path, $exclude);
                $this->createTarGz($path, $out, $exclude);
                $this->insertItem([
                    'item_type' => 'file',
                    'target_key' => $key,
                    'target_label' => $label,
                    'source_path' => $path,
                    'backup_path' => $out,
                    'file_count' => $count,
                    'total_bytes' => (int)filesize($out),
                    'sha256' => hash_file('sha256', $out) ?: null,
                    'status' => 'success',
                    'error_message' => null,
                ]);
            } catch (Throwable $e) {
                $this->insertItem([
                    'item_type' => 'file',
                    'target_key' => $key,
                    'target_label' => $label,
                    'source_path' => $path,
                    'backup_path' => null,
                    'file_count' => 0,
                    'total_bytes' => 0,
                    'sha256' => null,
                    'status' => $required ? 'failed' : 'warning',
                    'error_message' => $e->getMessage(),
                ]);
                backup_write_log($required ? 'error' : 'warning', 'file backup failed', ['target' => $key, 'error' => $e->getMessage()]);
            }
        }
    }

    private function createTarGz(string $source, string $out, array $exclude): void
    {
        $source = rtrim($source, '/');
        $parent = dirname($source);
        $base = basename($source);
        $parts = ['tar'];
        foreach ($exclude as $pattern) {
            $pattern = trim((string)$pattern, '/');
            if ($pattern !== '') {
                $parts[] = '--exclude=' . escapeshellarg($pattern);
            }
        }
        $parts[] = '-czf';
        $parts[] = escapeshellarg($out);
        $parts[] = '-C';
        $parts[] = escapeshellarg($parent);
        $parts[] = escapeshellarg($base);
        $parts[] = '2>&1';
        $command = implode(' ', $parts);

        $output = [];
        $exit = 0;
        exec($command, $output, $exit);
        if ($exit !== 0 || !is_file($out) || filesize($out) === 0) {
            @unlink($out);
            throw new RuntimeException('tar に失敗しました: ' . implode("\n", array_slice($output, -5)));
        }
        @chmod($out, 0600);
    }

    private function countFiles(string $path, array $exclude): int
    {
        $path = rtrim($path, '/');
        $count = 0;
        $root = backup_project_root();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $full = str_replace('\\', '/', $file->getPathname());
            $relative = ltrim(str_replace(str_replace('\\', '/', $root), '', $full), '/');
            $skip = false;
            foreach ($exclude as $pattern) {
                $pattern = trim((string)$pattern, '/');
                if ($pattern !== '' && str_starts_with($relative, $pattern)) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $count++;
            }
        }
        return $count;
    }

    private function writeManifest(): void
    {
        $manifest = [
            'version' => 1,
            'job_id' => $this->jobId,
            'job_key' => $this->jobKey,
            'job_type' => $this->jobType,
            'created_at' => backup_now()->format(DateTimeInterface::ATOM),
            'project_root' => backup_mask_path(backup_project_root()),
            'items' => array_map(static function (array $item): array {
                return [
                    'item_type' => $item['item_type'] ?? '',
                    'target_key' => $item['target_key'] ?? '',
                    'target_label' => $item['target_label'] ?? '',
                    'source_path' => backup_mask_path((string)($item['source_path'] ?? '')),
                    'backup_path' => backup_mask_path((string)($item['backup_path'] ?? '')),
                    'file_count' => $item['file_count'] ?? null,
                    'total_bytes' => $item['total_bytes'] ?? 0,
                    'sha256' => $item['sha256'] ?? null,
                    'status' => $item['status'] ?? '',
                    'error_message' => $item['error_message'] ?? null,
                ];
            }, $this->items),
        ];
        $path = $this->destinationDir . '/manifest.json';
        file_put_contents($path, backup_json_encode($manifest) . PHP_EOL, LOCK_EX);
        @chmod($path, 0600);
        $sha = hash_file('sha256', $path) ?: null;
        $this->insertItem([
            'item_type' => 'manifest',
            'target_key' => 'manifest',
            'target_label' => 'manifest.json',
            'source_path' => null,
            'backup_path' => $path,
            'file_count' => 1,
            'total_bytes' => (int)filesize($path),
            'sha256' => $sha,
            'status' => 'success',
            'error_message' => null,
        ]);

        $stmt = $this->pdo->prepare('UPDATE backup_jobs SET manifest_path = :path, manifest_sha256 = :sha WHERE id = :id');
        $stmt->execute([':path' => $path, ':sha' => $sha, ':id' => $this->jobId]);
    }

    private function insertItem(array $item): void
    {
        if ($this->jobId === null) {
            throw new RuntimeException('job_id が未設定です。');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO backup_items (job_id, item_type, target_key, target_label, source_path, backup_path, file_count, total_bytes, sha256, status, error_message) ' .
            'VALUES (:job_id, :item_type, :target_key, :target_label, :source_path, :backup_path, :file_count, :total_bytes, :sha256, :status, :error_message)'
        );
        $payload = [
            ':job_id' => $this->jobId,
            ':item_type' => (string)$item['item_type'],
            ':target_key' => (string)$item['target_key'],
            ':target_label' => $item['target_label'] ?? null,
            ':source_path' => $item['source_path'] ?? null,
            ':backup_path' => $item['backup_path'] ?? null,
            ':file_count' => $item['file_count'] ?? null,
            ':total_bytes' => (int)($item['total_bytes'] ?? 0),
            ':sha256' => $item['sha256'] ?? null,
            ':status' => (string)$item['status'],
            ':error_message' => isset($item['error_message']) ? mb_substr((string)$item['error_message'], 0, 1000, 'UTF-8') : null,
        ];
        $stmt->execute($payload);
        $this->items[] = [
            'id' => (int)$this->pdo->lastInsertId(),
            'job_id' => $this->jobId,
            'item_type' => $payload[':item_type'],
            'target_key' => $payload[':target_key'],
            'target_label' => $payload[':target_label'],
            'source_path' => $payload[':source_path'],
            'backup_path' => $payload[':backup_path'],
            'file_count' => $payload[':file_count'],
            'total_bytes' => $payload[':total_bytes'],
            'sha256' => $payload[':sha256'],
            'status' => $payload[':status'],
            'error_message' => $payload[':error_message'],
        ];
    }

    private function createAlert(string $level, string $key, string $message): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO backup_alerts (job_id, level, alert_key, message) VALUES (:job_id, :level, :alert_key, :message)');
        $stmt->execute([
            ':job_id' => $this->jobId,
            ':level' => $level,
            ':alert_key' => $key,
            ':message' => mb_substr($message, 0, 1000, 'UTF-8'),
        ]);
    }

    private function insertStorageSnapshot(array $payload): int
    {
        $dirs = is_array($payload['directories'] ?? null) ? $payload['directories'] : [];
        $dirBytes = static function (string $key) use ($dirs): int {
            return is_array($dirs[$key] ?? null) ? (int)($dirs[$key]['bytes'] ?? 0) : 0;
        };
        $log = is_array($payload['cleanup_log'] ?? null) ? $payload['cleanup_log'] : [];
        $stmt = $this->pdo->prepare(
            'INSERT INTO backup_storage_snapshots (collected_at, status, source, total_bytes, forms_live_bytes, forms_archive_bytes, switchbot_bytes, report_bytes, tmp_bytes, backup_root_bytes, payload_json, cleanup_log_path, cleanup_log_mtime, cleanup_log_tail) ' .
            'VALUES (:collected_at, :status, :source, :total_bytes, :forms_live_bytes, :forms_archive_bytes, :switchbot_bytes, :report_bytes, :tmp_bytes, :backup_root_bytes, :payload_json, :cleanup_log_path, :cleanup_log_mtime, :cleanup_log_tail)'
        );
        $stmt->execute([
            ':collected_at' => (string)($payload['collected_at'] ?? backup_now()->format('Y-m-d H:i:s')),
            ':status' => (string)($payload['status'] ?? 'success'),
            ':source' => (string)($payload['source'] ?? 'unknown'),
            ':total_bytes' => (int)($payload['total_bytes'] ?? 0),
            ':forms_live_bytes' => $dirBytes('forms_live'),
            ':forms_archive_bytes' => $dirBytes('forms_archive'),
            ':switchbot_bytes' => $dirBytes('switchbot_storage'),
            ':report_bytes' => $dirBytes('report_dir'),
            ':tmp_bytes' => $dirBytes('tmp_dir'),
            ':backup_root_bytes' => $dirBytes('backup_root'),
            ':payload_json' => backup_json_encode($payload),
            ':cleanup_log_path' => (string)($log['path'] ?? ''),
            ':cleanup_log_mtime' => ($log['mtime'] ?? null) ?: null,
            ':cleanup_log_tail' => mb_substr((string)($log['tail'] ?? ''), 0, 60000, 'UTF-8'),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function cleanupBackupFilesByRetention(): array
    {
        $rules = [
            'daily' => ['days' => (int)backup_config_value('backup_manager.retention.daily_days', 60)],
            'weekly' => ['days' => (int)backup_config_value('backup_manager.retention.weekly_days', 84)],
            'monthly' => ['days' => (int)backup_config_value('backup_manager.retention.monthly_days', 730)],
        ];
        $backupRoot = realpath(backup_root_dir()) ?: backup_root_dir();
        $deletedDirs = 0;
        $deletedBytes = 0;

        foreach ($rules as $type => $rule) {
            $days = max(1, (int)$rule['days']);
            $cutoff = backup_now()->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare('SELECT id, backup_root FROM backup_jobs WHERE job_type = :type AND started_at < :cutoff AND backup_root IS NOT NULL AND backup_root != "" ORDER BY id ASC');
            $stmt->execute([':type' => $type, ':cutoff' => $cutoff]);
            foreach ($stmt as $row) {
                $path = (string)($row['backup_root'] ?? '');
                if ($path === '' || !is_dir($path)) {
                    continue;
                }
                $real = realpath($path);
                if ($real === false || !str_starts_with($real, rtrim($backupRoot, '/') . '/')) {
                    $this->createAlert('warning', 'backup_cleanup_skipped_path', '保持期間整理で安全確認に失敗したパスをスキップしました: ' . backup_mask_path($path));
                    continue;
                }
                $stats = backup_directory_stats($real);
                $deletedBytes += (int)($stats['bytes'] ?? 0);
                $this->deleteTree($real);
                $deletedDirs++;
            }
        }

        return ['deleted_dirs' => $deletedDirs, 'deleted_bytes' => $deletedBytes];
    }

    private function cleanupMetadataByRetention(): int
    {
        $deleted = 0;
        $snapshotDays = max(30, (int)backup_config_value('backup_manager.storage_snapshot_retention_days', 1461));
        $reportDays = max(30, (int)backup_config_value('backup_manager.report_retention_days', 1461));
        $verifyDays = max(7, (int)backup_config_value('backup_manager.retention.verify_days', 30));
        $cleanupDays = max(30, (int)backup_config_value('backup_manager.retention.cleanup_days', 365));

        $stmt = $this->pdo->prepare('DELETE FROM backup_storage_snapshots WHERE collected_at < :cutoff');
        $stmt->execute([':cutoff' => backup_now()->modify('-' . $snapshotDays . ' days')->format('Y-m-d H:i:s')]);
        $deleted += $stmt->rowCount();

        $stmt = $this->pdo->prepare('DELETE FROM backup_reports WHERE generated_at < :cutoff');
        $stmt->execute([':cutoff' => backup_now()->modify('-' . $reportDays . ' days')->format('Y-m-d H:i:s')]);
        $deleted += $stmt->rowCount();

        $stmt = $this->pdo->prepare('DELETE FROM backup_jobs WHERE job_type = :type AND started_at < :cutoff');
        $stmt->execute([':type' => 'verify', ':cutoff' => backup_now()->modify('-' . $verifyDays . ' days')->format('Y-m-d H:i:s')]);
        $deleted += $stmt->rowCount();

        $stmt = $this->pdo->prepare('DELETE FROM backup_jobs WHERE job_type = :type AND started_at < :cutoff');
        $stmt->execute([':type' => 'cleanup', ':cutoff' => backup_now()->modify('-' . $cleanupDays . ' days')->format('Y-m-d H:i:s')]);
        $deleted += $stmt->rowCount();

        return $deleted;
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $itemPath = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($itemPath);
            } else {
                @unlink($itemPath);
            }
        }
        @rmdir($path);
    }

    private function calculateSummary(): array
    {
        $total = count($this->items);
        $success = 0;
        $failed = 0;
        $bytes = 0;
        foreach ($this->items as $item) {
            $status = (string)($item['status'] ?? '');
            if ($status === 'success' || $status === 'skipped') {
                $success++;
            } else {
                $failed++;
            }
            $bytes += (int)($item['total_bytes'] ?? 0);
        }
        return [
            'total_items' => $total,
            'success_items' => $success,
            'failed_items' => $failed,
            'total_bytes' => $bytes,
        ];
    }

    private function jobSummary(): array
    {
        if ($this->jobId === null) {
            return [];
        }
        $job = $this->getJob($this->jobId) ?: [];
        $job['items'] = $this->listItems($this->jobId);
        return $job;
    }
}
