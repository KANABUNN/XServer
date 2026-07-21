<?php

declare(strict_types=1);

/**
 * 期限切れ SwitchBot パスコードの自動削除ジョブ（cron 用）。
 *
 * 使い方:
 *   php /home/<account>/apps/jobs/switchbot_passcode_cleanup.php
 *   php ... --dry-run   削除せず対象だけを出力する
 *   php ... --json      JSON で出力する
 *   php ... --verbose   スキップ理由まで含めて出力する
 *
 * 終了コード: 0=正常 / 1=異常終了 / 2=個別削除に失敗あり
 */

// apps/ は公開ディレクトリ外に置いているが、万一 docroot 配下に
// 移動された場合でも Web から実行されないよう二重で防ぐ。
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/switchbot_passcode_cleanup.php';

$argv = $argv ?? [];
$dryRun = in_array('--dry-run', $argv, true);
$asJson = in_array('--json', $argv, true);
$verbose = in_array('--verbose', $argv, true);

try {
    $cfg = require dirname(__DIR__) . '/config.php';
    if (!is_array($cfg)) {
        throw new RuntimeException('config.php の読み込みに失敗しました。');
    }

    $result = switchbot_cleanup_run($cfg, $dryRun);

    if ($asJson) {
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo 'switchbot passcode cleanup completed' . PHP_EOL;
        echo 'timestamp: ' . (string)($result['timestamp'] ?? '') . PHP_EOL;
        echo 'dry_run: ' . (($result['dry_run'] ?? false) ? 'yes' : 'no') . PHP_EOL;
        echo sprintf(
            "scanned=%d candidates=%d deleted=%d failed=%d skipped=%d%s\n",
            (int)($result['scanned_keys'] ?? 0),
            (int)($result['candidates'] ?? 0),
            (int)($result['deleted'] ?? 0),
            (int)($result['failed'] ?? 0),
            (int)($result['skipped'] ?? 0),
            ($result['limit_reached'] ?? false) ? ' (limit reached)' : ''
        );

        foreach (($result['devices'] ?? []) as $device) {
            echo sprintf(
                "device[%s / %s]: total=%d deleted=%d failed=%d skipped=%d\n",
                (string)($device['device_id'] ?? ''),
                (string)($device['device_name'] ?? ''),
                (int)($device['total_keys'] ?? 0),
                (int)($device['deleted'] ?? 0),
                (int)($device['failed'] ?? 0),
                (int)($device['skipped'] ?? 0)
            );

            foreach (($device['decisions'] ?? []) as $decision) {
                $isDelete = (bool)($decision['delete'] ?? false);
                if (!$verbose && !$isDelete) {
                    continue;
                }
                echo sprintf(
                    "  - key_id=%d name=%s type=%s status=%s => %s | %s\n",
                    (int)($decision['key_id'] ?? 0),
                    (string)($decision['name'] ?? ''),
                    (string)($decision['type'] ?? ''),
                    (string)($decision['status'] ?? ''),
                    (string)($decision['result'] ?? ($isDelete ? 'pending' : 'skipped')),
                    (string)($decision['reason'] ?? '')
                );
            }
        }
    }

    foreach (($result['errors'] ?? []) as $error) {
        fwrite(STDERR, '[switchbot_passcode_cleanup] ' . $error . PHP_EOL);
    }

    exit(((int)($result['failed'] ?? 0)) > 0 ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, '[switchbot_passcode_cleanup] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
