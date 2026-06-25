<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_layout.php';
require_once dirname(__DIR__, 3) . '/apps/kintone_core/kintone_rest_client.php';
$user = kintone_auth_require_admin_access();
kintone_auth_require_csrf();
header('Content-Type: application/json; charset=UTF-8');
$ref = kintone_reference_id('KTC');
try {
    $credentials = kintone_credentials_default();
    if (!is_array($credentials)) {
        throw new RuntimeException('kintone接続情報が未登録です。');
    }
    $appId = (int)($credentials['kintone_app_id'] ?? 0);
    $res = kintone_rest_request('GET', '/k/v1/app.json?id=' . rawurlencode((string)$appId), [], $credentials);
    kintone_pdo('org')->prepare('UPDATE kintone_credentials SET last_tested_at=NOW(), last_error=NULL, status="connected" WHERE connection_key="default"')->execute();
    echo kintone_json_encode(['ok' => true, 'app' => $res]);
} catch (PDOException $e) {
    error_log('[kintone test_connection PDO][' . $ref . '] ' . $e->getMessage());
    http_response_code(500);
    echo kintone_json_encode(['ok' => false, 'message' => 'kintone接続テスト結果の保存に失敗しました（参照ID: ' . $ref . '）。']);
} catch (Throwable $e) {
    error_log('[kintone test_connection][' . $ref . '] ' . $e->getMessage());
    try {
        kintone_pdo('org')->prepare('UPDATE kintone_credentials SET last_tested_at=NOW(), last_error=:error, status="error" WHERE connection_key="default"')->execute([
            ':error' => mb_substr($e->getMessage(), 0, 1000, 'UTF-8'),
        ]);
    } catch (Throwable $logError) {
        error_log('[kintone test_connection state][' . $ref . '] ' . $logError->getMessage());
    }
    http_response_code(500);
    echo kintone_json_encode(['ok' => false, 'message' => 'kintone接続テストに失敗しました（参照ID: ' . $ref . '）。']);
}
