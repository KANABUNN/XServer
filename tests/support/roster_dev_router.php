<?php

declare(strict_types=1);

/**
 * ローカル確認用ルーター（PHP 組み込みサーバー専用）。kintone を FakeKintone に置き換えて画面を確認する。
 *
 *   FITSC_CONFIG_FILE=/path/to/local_config.php FITSC_ROSTER_DEV_MAIL=1 FITSC_ROSTER_FAKE_STATE=/path/to/fake_kintone.json \
 *     php -S 127.0.0.1:8090 -t public_html tests/support/roster_dev_router.php
 *
 * FITSC_ROSTER_FAKE_STATE を指定しない場合は、登録済みの APIトークンで実際の kintone に接続する。
 */

if (PHP_SAPI !== 'cli-server') {
    exit(1);
}

$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/\\') . $path;
if (is_dir($file)) {
    $file = rtrim($file, '/\\') . '/index.php';
}
if (!is_file($file) || !str_ends_with($file, '.php')) {
    return false;
}

$state = getenv('FITSC_ROSTER_FAKE_STATE');
if (is_string($state) && $state !== '') {
    require_once dirname(__DIR__, 2) . '/apps/roster_core/bootstrap.php';
    require_once __DIR__ . '/fake_kintone.php';
    $fake = FakeKintone::persistent($state);
    roster_kintone_test_transport(static function (string $method, string $apiPath, array $payload, int $tokens) use ($fake): array {
        $result = $fake->handle($method, $apiPath, $payload, $tokens);
        $fake->save();
        return $result;
    }, true);
}

if (str_ends_with($path, '/')) {
    $_SERVER['SCRIPT_NAME'] = $path . 'index.php';
    $_SERVER['PHP_SELF'] = $path . 'index.php';
    chdir(dirname($file));
    require $file;
    return true;
}
return false;
