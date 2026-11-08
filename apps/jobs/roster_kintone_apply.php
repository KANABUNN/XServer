<?php

declare(strict_types=1);

/**
 * 部員名簿の反映ワーカー（accept 済みの提出だけを kintone ID20 へ反映する）
 *
 * cron 例（5分ごと）:
 *   *\/5 * * * * /usr/bin/php8.2 /home/USER/PROJECT/apps/jobs/roster_kintone_apply.php >> /home/USER/PROJECT/apps/kintone_core/storage/roster_worker_cron.log 2>&1
 *
 * オプション:
 *   --job=ID          指定ジョブだけを処理する（定期実行を待たずに確認したいとき）
 *   --skip-directory  団体一覧（ID7）の更新を行わない
 *   --refresh-groups  団体一覧（ID7）だけを今すぐ更新する
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require_once dirname(__DIR__) . '/roster_core/bootstrap.php';

$options = getopt('', ['job::', 'skip-directory', 'refresh-groups']);

if (!roster_config('enabled', true)) {
    echo "roster worker disabled\n";
    exit(0);
}

try {
    if (isset($options['refresh-groups'])) {
        $result = roster_directory_refresh();
        echo roster_json(['directory' => $result]) . "\n";
        exit(0);
    }
    $summary = roster_worker_run([
        'only_job_id' => isset($options['job']) ? (int)$options['job'] : 0,
        'skip_directory' => isset($options['skip-directory']),
        'worker_name' => 'cron',
    ]);
    echo roster_now_sql() . ' ' . roster_json([
        'status' => $summary['status'],
        'recovered' => $summary['recovered'] ?? 0,
        'processed' => array_map(static fn(array $p): array => [
            'job_id' => $p['job_id'] ?? null,
            'status' => $p['status'] ?? null,
            'error_class' => $p['error_class'] ?? null,
        ], $summary['processed'] ?? []),
        'directory' => $summary['directory'] ?? null,
        'error' => $summary['error'] ?? null,
    ]) . "\n";
    exit(($summary['status'] ?? '') === 'error' ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, roster_now_sql() . ' roster worker fatal: ' . $e->getMessage() . "\n");
    exit(1);
}
