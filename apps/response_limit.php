<?php

function rate_limit_or_throw(string $ip, string $storePath, int $max, int $windowSec): void
{
    $now = time();
    $key = hash('sha256', $ip); // 直接IPを書かない

    // ストアファイルのディレクトリがなければ作成
    $dir = dirname($storePath);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('rate limit store dir failed');
        }
    }

    // ストアファイルがなければ空のJSONを作成
    $fp = fopen($storePath, 'c+');
    if (!$fp) {
        throw new RuntimeException('rate limit store open failed');
    }

    // ファイルロックしてから読み書き
    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('rate limit lock failed');
        }

        // ファイル内容を読み込む（空なら初期化）
        $raw = stream_get_contents($fp);
        $db = $raw ? json_decode($raw, true) : [];
        if (!is_array($db)) $db = [];

        // IPごとのアクセス時間の配列を取得
        $times = $db[$key] ?? [];
        if (!is_array($times)) $times = [];

        // window外を削除
        $times = array_values(array_filter($times, fn($t) => is_int($t) && ($now - $t) < $windowSec));

        // 制限数を超えていれば例外
        if (count($times) >= $max) {
            throw new RuntimeException('短時間に送信が多すぎます。時間をおいて再試行してください。');
        }

        // 現在のアクセス時間を追加して保存
        $times[] = $now;
        $db[$key] = $times;

        // 保存
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($db));
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }
}
 
function get_client_ip(): string
{
    // 信頼するプロキシのIPリスト（サーバーの設定に合わせて変更）
    $trusted_proxies = [
        '127.0.0.1',
        '::1',
        // Cloudflare を使っているなら CF の IP レンジを追加
        // https://www.cloudflare.com/ips/
    ];

    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

    // REMOTE_ADDR が信頼できるプロキシの場合のみ XFF を参照
    if (in_array($remote, $trusted_proxies, true)) {
        $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($xff !== '') {
            $parts = array_map('trim', explode(',', $xff));
            $ip = $parts[0] ?? '';
            if ($ip !== '') {
                return $ip;
            }
        }

        $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '') {
            return $cf;
        }
    }

    return $remote !== '' ? $remote : 'unknown';
}