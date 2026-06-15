<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Existing storage maintenance integration.
 *
 * This file never exposes backup archives to the web. It only collects metrics,
 * log snippets, and policy information for the dashboard.
 */

function backup_directory_stats(string $path, int $maxFilesForHash = 0): array
{
    $path = rtrim($path, '/');
    if ($path === '' || !is_dir($path)) {
        return [
            'path' => $path,
            'masked_path' => backup_mask_path($path),
            'exists' => false,
            'files' => 0,
            'dirs' => 0,
            'bytes' => 0,
            'latest_mtime' => null,
        ];
    }

    $files = 0;
    $dirs = 0;
    $bytes = 0;
    $latestMtime = 0;
    $hashSamples = [];

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            if ($item->isDir()) {
                $dirs++;
                continue;
            }
            if (!$item->isFile()) {
                continue;
            }
            $files++;
            $size = (int)$item->getSize();
            $bytes += $size;
            $mtime = (int)$item->getMTime();
            if ($mtime > $latestMtime) {
                $latestMtime = $mtime;
            }
            if ($maxFilesForHash > 0 && count($hashSamples) < $maxFilesForHash) {
                $hashSamples[] = [
                    'path' => backup_mask_path($item->getPathname()),
                    'bytes' => $size,
                    'mtime' => date('Y-m-d H:i:s', $mtime),
                ];
            }
        }

        return [
            'path' => $path,
            'masked_path' => backup_mask_path($path),
            'exists' => true,
            'files' => $files,
            'dirs' => $dirs,
            'bytes' => $bytes,
            'latest_mtime' => $latestMtime > 0 ? date('Y-m-d H:i:s', $latestMtime) : null,
            'samples' => $hashSamples,
        ];
    } catch (Throwable $e) {
        return [
            'path' => $path,
            'masked_path' => backup_mask_path($path),
            'exists' => true,
            'files' => $files,
            'dirs' => $dirs,
            'bytes' => $bytes,
            'latest_mtime' => $latestMtime > 0 ? date('Y-m-d H:i:s', $latestMtime) : null,
            'samples' => $hashSamples,
            'error' => $e->getMessage(),
        ];
    }
}

function backup_read_tail(string $path, int $lines = 80, int $maxBytes = 262144): string
{
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return '';
    }
    $size = filesize($path);
    if (!is_int($size) || $size <= 0) {
        return '';
    }
    $readBytes = min($size, max(4096, $maxBytes));
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        return '';
    }
    try {
        fseek($fp, -$readBytes, SEEK_END);
        $chunk = fread($fp, $readBytes);
        if (!is_string($chunk)) {
            return '';
        }
    } finally {
        fclose($fp);
    }
    $parts = preg_split('/\R/u', trim($chunk));
    if (!is_array($parts)) {
        return trim($chunk);
    }
    return implode(PHP_EOL, array_slice($parts, -max(1, $lines)));
}

function backup_load_storage_maintenance_report(): array
{
    $path = backup_apps_dir() . '/storage_maintenance.php';
    if (!is_file($path)) {
        return [
            'available' => false,
            'source' => 'missing',
            'error' => 'apps/storage_maintenance.php が見つかりません。',
        ];
    }

    try {
        require_once $path;
        if (!function_exists('storage_maintenance_collect_usage_report')) {
            return [
                'available' => false,
                'source' => 'missing_function',
                'error' => 'storage_maintenance_collect_usage_report() が定義されていません。',
            ];
        }
        $report = storage_maintenance_collect_usage_report();
        if (!is_array($report)) {
            return [
                'available' => false,
                'source' => 'invalid_result',
                'error' => 'ストレージ利用レポートが配列ではありません。',
            ];
        }
        $report['available'] = true;
        $report['source'] = 'storage_maintenance_collect_usage_report';
        return $report;
    } catch (Throwable $e) {
        return [
            'available' => false,
            'source' => 'exception',
            'error' => $e->getMessage(),
        ];
    }
}

function backup_storage_maintenance_log_path(): string
{
    $configured = (string)backup_config_value('storage_maintenance.log_file', '');
    if ($configured !== '') {
        if ($configured[0] === '/') {
            return $configured;
        }
        return backup_apps_dir() . '/' . ltrim($configured, '/');
    }
    return backup_apps_dir() . '/storage/maintenance/storage_cleanup.log';
}

function backup_collect_storage_status(): array
{
    $maintenance = backup_load_storage_maintenance_report();
    $backupRoot = backup_root_dir();
    $projectRoot = backup_project_root();
    $logPath = backup_storage_maintenance_log_path();

    $directories = [];
    if (isset($maintenance['directories']) && is_array($maintenance['directories'])) {
        foreach ($maintenance['directories'] as $key => $stats) {
            if (!is_array($stats)) {
                continue;
            }
            $path = (string)($stats['path'] ?? '');
            $stats['masked_path'] = backup_mask_path($path);
            $directories[(string)$key] = $stats;
        }
    }

    if (!isset($directories['backup_root'])) {
        $directories['backup_root'] = backup_directory_stats($backupRoot);
    }
    if (!isset($directories['backup_state'])) {
        $directories['backup_state'] = backup_directory_stats(backup_state_dir());
    }

    $totalBytes = 0;
    foreach ($directories as $stats) {
        if (is_array($stats)) {
            $totalBytes += (int)($stats['bytes'] ?? 0);
        }
    }

    $diskRoot = is_dir($backupRoot) ? $backupRoot : $projectRoot;
    $diskTotal = @disk_total_space($diskRoot);
    $diskFree = @disk_free_space($diskRoot);
    $disk = [
        'path' => backup_mask_path($diskRoot),
        'total_bytes' => is_float($diskTotal) ? (int)$diskTotal : null,
        'free_bytes' => is_float($diskFree) ? (int)$diskFree : null,
        'used_ratio' => null,
    ];
    if (is_float($diskTotal) && is_float($diskFree) && $diskTotal > 0) {
        $disk['used_ratio'] = round(($diskTotal - $diskFree) / $diskTotal, 4);
    }

    return [
        'collected_at' => backup_now()->format('Y-m-d H:i:s'),
        'status' => ($maintenance['available'] ?? false) ? 'success' : 'warning',
        'source' => (string)($maintenance['source'] ?? 'fallback'),
        'total_bytes' => $totalBytes,
        'directories' => $directories,
        'db_counts' => is_array($maintenance['db_counts'] ?? null) ? $maintenance['db_counts'] : [],
        'policies' => is_array($maintenance['policies'] ?? null) ? $maintenance['policies'] : [],
        'maintenance_available' => (bool)($maintenance['available'] ?? false),
        'maintenance_error' => (string)($maintenance['error'] ?? ''),
        'maintenance_generated_at' => (string)($maintenance['generated_at'] ?? ''),
        'cleanup_log' => [
            'path' => $logPath,
            'masked_path' => backup_mask_path($logPath),
            'exists' => is_file($logPath),
            'mtime' => is_file($logPath) ? date('Y-m-d H:i:s', (int)filemtime($logPath)) : null,
            'bytes' => is_file($logPath) ? (int)filesize($logPath) : 0,
            'tail' => backup_read_tail($logPath, 60),
        ],
        'disk' => $disk,
    ];
}

function backup_render_backup_monthly_report(array $payload): string
{
    $lines = [];
    $lines[] = '# バックアップ月次レポート';
    $lines[] = '';
    $lines[] = '- 生成日時: ' . (string)($payload['generated_at'] ?? '');
    $lines[] = '- 対象月: ' . (string)($payload['month'] ?? '');
    $lines[] = '';

    $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
    $lines[] = '## 概要';
    $lines[] = '';
    $lines[] = '- バックアップ保存量: ' . backup_format_bytes((int)($summary['backup_root_bytes'] ?? 0));
    $lines[] = '- 未解決アラート: ' . (string)($summary['unresolved_alerts'] ?? 0);
    $lines[] = '- 直近ジョブ数: ' . (string)($summary['job_count'] ?? 0);
    $lines[] = '';

    $lines[] = '## 直近ジョブ';
    $lines[] = '';
    $jobs = is_array($payload['jobs'] ?? null) ? $payload['jobs'] : [];
    if ($jobs === []) {
        $lines[] = '- 対象ジョブなし';
    } else {
        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }
            $lines[] = '- #' . (string)($job['id'] ?? '') . ' ' . (string)($job['job_type'] ?? '') . ' / ' . (string)($job['status'] ?? '') . ' / ' . (string)($job['started_at'] ?? '') . ' / ' . backup_format_bytes((int)($job['total_bytes'] ?? 0));
        }
    }
    $lines[] = '';

    $snapshot = is_array($payload['storage_snapshot'] ?? null) ? $payload['storage_snapshot'] : [];
    $snapPayload = [];
    if (isset($snapshot['payload_json'])) {
        $decoded = json_decode((string)$snapshot['payload_json'], true);
        if (is_array($decoded)) {
            $snapPayload = $decoded;
        }
    }
    $dirs = is_array($snapPayload['directories'] ?? null) ? $snapPayload['directories'] : [];
    $lines[] = '## ストレージ使用量';
    $lines[] = '';
    foreach ($dirs as $key => $stats) {
        if (!is_array($stats)) {
            continue;
        }
        $lines[] = '- ' . (string)$key . ': files=' . (string)($stats['files'] ?? 0) . ', bytes=' . backup_format_bytes((int)($stats['bytes'] ?? 0)) . ', path=' . (string)($stats['masked_path'] ?? '');
    }
    $lines[] = '';

    $alerts = is_array($payload['alerts'] ?? null) ? $payload['alerts'] : [];
    $lines[] = '## 未解決アラート';
    $lines[] = '';
    if ($alerts === []) {
        $lines[] = '- なし';
    } else {
        foreach ($alerts as $alert) {
            if (!is_array($alert)) {
                continue;
            }
            $lines[] = '- [' . (string)($alert['level'] ?? '') . '] ' . (string)($alert['message'] ?? '') . ' (' . (string)($alert['created_at'] ?? '') . ')';
        }
    }
    $lines[] = '';

    return implode(PHP_EOL, $lines) . PHP_EOL;
}
