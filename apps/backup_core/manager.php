<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/schema.php';

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
