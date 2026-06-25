<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
require_once dirname(__DIR__, 2) . '/apps/kintone_core/crypto.php';
require_once dirname(__DIR__, 2) . '/apps/kintone_core/kintone_sync_service.php';
$user = kintone_auth_require_admin_access();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    kintone_auth_require_csrf();
    try {
        $subdomain = trim((string)($_POST['kintone_subdomain'] ?? ''));
        $appId = (int)($_POST['kintone_app_id'] ?? 0);
        $token = trim((string)($_POST['api_token'] ?? ''));
        if ($subdomain === '' || $appId < 1) {
            throw new InvalidArgumentException('サブドメインとアプリIDを入力してください。');
        }
        $pdo = kintone_pdo('org');
        $encrypted = $token !== '' ? kintone_crypto_encrypt($token) : null;
        if ($encrypted !== null) {
            $pdo->prepare('INSERT INTO kintone_credentials (connection_key, kintone_subdomain, kintone_app_id, auth_type, api_token_encrypted, status, connected_at) VALUES ("default", :subdomain, :app_id, "api_token", :token, "connected", NOW()) ON DUPLICATE KEY UPDATE kintone_subdomain=VALUES(kintone_subdomain), kintone_app_id=VALUES(kintone_app_id), api_token_encrypted=VALUES(api_token_encrypted), status="connected", connected_at=NOW(), updated_at=NOW()')->execute([':subdomain' => $subdomain, ':app_id' => $appId, ':token' => $encrypted]);
        } else {
            $updated = $pdo->prepare('UPDATE kintone_credentials SET kintone_subdomain=:subdomain, kintone_app_id=:app_id, updated_at=NOW() WHERE connection_key="default"');
            $updated->execute([':subdomain' => $subdomain, ':app_id' => $appId]);
            if ($updated->rowCount() === 0) {
                throw new InvalidArgumentException('初回保存時はAPIトークンを入力してください。');
            }
        }
        kintone_set_flash('success', 'kintone接続情報を保存しました。接続テスト後に同期を実行してください。');
        header('Location: settings.php', true, 302);
        exit;
    } catch (Throwable $e) {
        error_log('[kintone settings] ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : '接続情報の保存に失敗しました。';
    }
}
$stmt = kintone_pdo('org')->prepare('SELECT * FROM kintone_credentials WHERE connection_key="default" LIMIT 1');
$stmt->execute();
$cred = $stmt->fetch() ?: [];
$fieldMap = kintone_sync_field_map();
kintone_page_header('接続設定', $user);
kintone_render_flash();
if ($error !== ''): ?><div class="alert alert-danger"><?= kintone_h($error) ?></div><?php endif; ?>
<section class="card"><h2>kintone APIトークン設定</h2><p class="muted">APIトークンは暗号化して保存します。既存トークンを維持する場合は空欄のまま保存してください。APIトークンには対象アプリのレコード閲覧・追加・編集権限が必要です。</p><form method="post">
<?= kintone_auth_csrf_field() ?>
<label>サブドメイン</label><input name="kintone_subdomain" value="<?= kintone_h($cred['kintone_subdomain'] ?? '') ?>" placeholder="example または https://example.cybozu.com">
<label>アプリID</label><input name="kintone_app_id" type="number" value="<?= kintone_h($cred['kintone_app_id'] ?? '') ?>">
<label>APIトークン</label><input name="api_token" type="password" autocomplete="new-password" placeholder="既存トークンを維持する場合は空欄">
<div class="form-actions"><button class="primary" type="submit">保存</button><a class="btn" href="sync.php">同期画面へ</a></div>
</form></section>
<section class="card"><h2>接続テスト</h2><p class="muted">保存済みの接続情報を使って、対象アプリへGETリクエストを行います。テスト結果はJSONで返ります。</p><form method="post" action="api/test_connection.php" class="form-actions">
<?= kintone_auth_csrf_field() ?>
<button class="secondary" type="submit">保存済み接続情報でテスト</button>
</form></section>
<section class="card"><h2>フィールドマップ</h2><p class="muted">現在の同期で使うフィールドコードです。変更する場合は <code>apps/config.php</code> の <code>kintone.field_map</code> を編集してください。</p><div class="table-wrap"><table><thead><tr><th>XServer項目</th><th>kintoneフィールドコード</th></tr></thead><tbody><?php foreach ($fieldMap as $key => $field): ?><tr><td><?= kintone_h($key) ?></td><td><code><?= kintone_h($field) ?></code></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="card"><h2>接続状態</h2><p>状態: <span class="badge"><?= kintone_h($cred['status'] ?? 'not_connected') ?></span> / 最終テスト: <?= kintone_h($cred['last_tested_at'] ?? '-') ?></p><?php if (!empty($cred['last_error'])): ?><p class="alert alert-warning">前回エラー: <?= kintone_h($cred['last_error']) ?></p><?php endif; ?></section>
<?php kintone_page_footer(); ?>
