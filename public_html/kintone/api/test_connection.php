<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_layout.php';
require_once dirname(__DIR__, 3) . '/apps/kintone_core/kintone_rest_client.php';

$user = kintone_auth_require_admin_access();
kintone_auth_require_csrf();
header('Content-Type: application/json; charset=UTF-8');
$ref = kintone_reference_id('KTC');
$appKey = 'organizations';
try {
    $appKey = kintone_normalize_app_key((string)($_POST['app_key'] ?? 'organizations'));
    $credentials = kintone_credentials_for($appKey);
    $appId = (int)($credentials['kintone_app_id'] ?? 0);
    if ($appId < 1) {
        throw new RuntimeException('kintoneアプリIDが未設定です。');
    }
    $res = kintone_rest_request('GET', '/k/v1/app.json', ['id' => $appId], $credentials);
    $pdo = kintone_pdo('org');
    $pdo->prepare('UPDATE kintone_credentials SET last_tested_at=NOW(), last_error=NULL, status="connected" WHERE connection_key=:app_key')->execute([':app_key' => $appKey]);
    $pdo->prepare('UPDATE kintone_apps SET last_tested_at=NOW(), last_error=NULL, status="connected" WHERE app_key=:app_key')->execute([':app_key' => $appKey]);
    kintone_write_audit_log('kintone.app_registry.test_connection', 'kintone_app', $appKey, ['app_key' => $appKey, 'app_id' => $appId], $user);
    echo kintone_json_encode(['ok' => true, 'app_key' => $appKey, 'app' => $res]);
} catch (PDOException $e) {
    error_log('[kintone test_connection PDO][' . $ref . '] ' . $e->getMessage());
    http_response_code(500);
    echo kintone_json_encode(['ok' => false, 'message' => 'kintone接続テスト結果の保存に失敗しました（参照ID: ' . $ref . '）。']);
} catch (Throwable $e) {
    error_log('[kintone test_connection][' . $ref . '] ' . $e->getMessage());
    try {
        kintone_pdo('org')->prepare('UPDATE kintone_credentials SET last_tested_at=NOW(), last_error=:error, status="error" WHERE connection_key=:app_key')->execute([
            ':error' => mb_substr($e->getMessage(), 0, 1000, 'UTF-8'),
            ':app_key' => $appKey,
        ]);
        kintone_pdo('org')->prepare('UPDATE kintone_apps SET last_tested_at=NOW(), last_error=:error, status="error" WHERE app_key=:app_key')->execute([
            ':error' => mb_substr($e->getMessage(), 0, 1000, 'UTF-8'),
            ':app_key' => $appKey,
        ]);
    } catch (Throwable $logError) {
        error_log('[kintone test_connection state][' . $ref . '] ' . $logError->getMessage());
    }
    http_response_code(500);
    echo kintone_json_encode(['ok' => false, 'message' => 'kintone接続テストに失敗しました（参照ID: ' . $ref . '）。']);
}
