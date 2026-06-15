<?php

declare(strict_types=1);

require_once __DIR__ . '/manager.php';
require_once __DIR__ . '/alert_rules.php';

function backup_restore_latest_jobs(int $limit = 30): array
{
    $pdo = backup_pdo('backup');
    backup_install_schema($pdo);
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare("SELECT * FROM backup_jobs WHERE job_type IN ('daily','weekly','monthly') AND status IN ('success','partial','warning') ORDER BY id DESC LIMIT :limit");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function backup_restore_plan_for_job(int $jobId): array
{
    $manager = new FitScBackupManager();
    $job = $manager->getJob($jobId);
    if (!$job) {
        throw new RuntimeException('指定されたバックアップジョブが見つかりません。');
    }
    $items = $manager->listItems($jobId);
    $db = [];
    $files = [];
    $missing = [];

    foreach ($items as $item) {
        $type = (string)($item['item_type'] ?? '');
        $status = (string)($item['status'] ?? '');
        $path = (string)($item['backup_path'] ?? '');
        if ($status !== 'success' || $path === '') {
            continue;
        }
        $exists = is_file($path);
        $entry = [
            'item' => $item,
            'path' => $path,
            'masked_path' => backup_mask_path($path),
            'exists' => $exists,
            'sha256' => (string)($item['sha256'] ?? ''),
            'bytes' => (int)($item['total_bytes'] ?? 0),
        ];
        if (!$exists) {
            $missing[] = $entry;
            continue;
        }
        if ($type === 'database') {
            $dbname = (string)($item['target_label'] ?? $item['source_path'] ?? $item['target_key'] ?? 'DATABASE_NAME');
            $entry['dbname'] = $dbname !== '' ? $dbname : 'DATABASE_NAME';
            $entry['commands'] = [
                '事前退避' => 'mysqldump --single-transaction --quick --skip-lock-tables ' . escapeshellarg($entry['dbname']) . ' | gzip -c > ~/before_restore_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $entry['dbname']) . '_$(date +%Y%m%d_%H%M%S).sql.gz',
                '復元' => 'gunzip -c ' . escapeshellarg($path) . ' | mysql -u DBユーザー -p ' . escapeshellarg($entry['dbname']),
            ];
            $db[] = $entry;
        } elseif ($type === 'file') {
            $key = (string)($item['target_key'] ?? 'files');
            $tmp = '~/restore_tmp/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string)($job['job_key'] ?? ('job_' . $jobId))) . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $key);
            $entry['commands'] = [
                '内容確認' => 'tar -tzf ' . escapeshellarg($path) . ' | head -100',
                '一時展開' => 'mkdir -p ' . $tmp . ' && tar -xzf ' . escapeshellarg($path) . ' -C ' . $tmp,
                '差分確認例' => 'diff -qr ' . $tmp . ' ' . escapeshellarg((string)($item['source_path'] ?? '復元先パス')) . ' | head -100',
            ];
            $files[] = $entry;
        }
    }

    $warnings = [];
    if ((string)($job['status'] ?? '') !== 'success') {
        $warnings[] = 'このバックアップジョブは完全成功ではありません。復元対象に失敗項目がないか確認してください。';
    }
    if ($missing !== []) {
        $warnings[] = 'バックアップ管理DB上に記録されたファイルのうち、現在のサーバー上に見つからないものがあります。';
    }

    return [
        'job' => $job,
        'database_items' => $db,
        'file_items' => $files,
        'missing_items' => $missing,
        'warnings' => $warnings,
        'general_steps' => [
            '現行DB・現行ファイルを先に退避する。',
            '復元対象をDB単位・ファイル単位で絞り、不要な上書きを避ける。',
            'Forms/Mail添付はDBの相対パスと実ファイルの整合性を確認する。',
            '復元後に backup_verify.php と backup_integrity_check.php を実行する。',
        ],
    ];
}

function backup_restore_note_markdown(array $plan): string
{
    $job = is_array($plan['job'] ?? null) ? $plan['job'] : [];
    $lines = [];
    $lines[] = '# 復元補助手順';
    $lines[] = '';
    $lines[] = '- 生成日時: ' . backup_now()->format('Y-m-d H:i:s');
    $lines[] = '- ジョブID: ' . (string)($job['id'] ?? '');
    $lines[] = '- ジョブキー: ' . (string)($job['job_key'] ?? '');
    $lines[] = '- 種別: ' . (string)($job['job_type'] ?? '');
    $lines[] = '- 状態: ' . (string)($job['status'] ?? '');
    $lines[] = '';
    $lines[] = '## 共通手順';
    foreach ((array)($plan['general_steps'] ?? []) as $step) {
        $lines[] = '- ' . (string)$step;
    }
    $lines[] = '';
    $lines[] = '## DB復元候補';
    foreach ((array)($plan['database_items'] ?? []) as $entry) {
        $lines[] = '';
        $lines[] = '### ' . (string)($entry['dbname'] ?? 'database');
        $lines[] = '- ファイル: ' . (string)($entry['masked_path'] ?? '');
        foreach ((array)($entry['commands'] ?? []) as $label => $command) {
            $lines[] = '';
            $lines[] = '#### ' . (string)$label;
            $lines[] = '```bash';
            $lines[] = (string)$command;
            $lines[] = '```';
        }
    }
    $lines[] = '';
    $lines[] = '## ファイル復元候補';
    foreach ((array)($plan['file_items'] ?? []) as $entry) {
        $item = is_array($entry['item'] ?? null) ? $entry['item'] : [];
        $lines[] = '';
        $lines[] = '### ' . (string)($item['target_label'] ?? $item['target_key'] ?? 'files');
        $lines[] = '- ファイル: ' . (string)($entry['masked_path'] ?? '');
        foreach ((array)($entry['commands'] ?? []) as $label => $command) {
            $lines[] = '';
            $lines[] = '#### ' . (string)$label;
            $lines[] = '```bash';
            $lines[] = (string)$command;
            $lines[] = '```';
        }
    }
    $lines[] = '';
    $lines[] = '## 注意';
    $lines[] = '- この画面・メモは復元コマンドを提示するだけで、自動復元は実行しません。';
    $lines[] = '- DBユーザー名やパスワードは表示しません。実行時にXServer上の接続情報を確認してください。';
    return implode(PHP_EOL, $lines) . PHP_EOL;
}

function backup_save_restore_note(array $plan, array $actor): string
{
    $job = is_array($plan['job'] ?? null) ? $plan['job'] : [];
    $dir = backup_state_dir() . '/restore_notes';
    backup_ensure_dir($dir, 0700);
    $jobKey = preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string)($job['job_key'] ?? ('job_' . (string)($job['id'] ?? 'unknown'))));
    $path = $dir . '/restore_note_' . $jobKey . '_' . backup_now()->format('Ymd_His') . '.md';
    file_put_contents($path, backup_restore_note_markdown($plan), LOCK_EX);
    @chmod($path, 0600);
    backup_operation_log('restore_note.generate', $actor, 'backup_job', (string)($job['id'] ?? ''), ['path' => backup_mask_path($path)]);
    return $path;
}
