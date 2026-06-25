<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
require_once dirname(__DIR__, 2) . '/apps/kintone_core/crypto.php';
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
        $encrypted = $token !== '' ? kintone_crypto_encrypt($token) : null;
        if ($encrypted !== null) {
            kintone_pdo('org')->prepare('INSERT INTO kintone_credentials (connection_key, kintone_subdomain, kintone_app_id, auth_type, api_token_encrypted, status, connected_at) VALUES ("default", :subdomain, :app_id, "api_token", :token, "connected", NOW()) ON DUPLICATE KEY UPDATE kintone_subdomain=VALUES(kintone_subdomain), kintone_app_id=VALUES(kintone_app_id), api_token_encrypted=VALUES(api_token_encrypted), status="connected", connected_at=NOW(), updated_at=NOW()')->execute([':subdomain' => $subdomain, ':app_id' => $appId, ':token' => $encrypted]);
        } else {
            kintone_pdo('org')->prepare('UPDATE kintone_credentials SET kintone_subdomain=:subdomain, kintone_app_id=:app_id, updated_at=NOW() WHERE connection_key="default"')->execute([':subdomain' => $subdomain, ':app_id' => $appId]);
        }
        kintone_set_flash('success', 'kintone接続情報を保存しました。');
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
kintone_page_header('接続設定', $user);
kintone_render_flash();
if ($error !== ''): ?><div class="alert alert-danger"><?= kintone_h($error) ?></div><?php endif; ?>
<section class="card"><h2>kintone APIトークン設定</h2><p class="muted">APIトークンは暗号化して保存します。既存トークンを維持する場合は空欄のまま保存してください。</p><form method="post">
<?= kintone_auth_csrf_field() ?>
<label>サブドメイン</label><input name="kintone_subdomain" value="<?= kintone_h($cred['kintone_subdomain'] ?? '') ?>" placeholder="example または https://example.cybozu.com">
<label>アプリID</label><input name="kintone_app_id" type="number" value="<?= kintone_h($cred['kintone_app_id'] ?? '') ?>">
<label>APIトークン</label><input name="api_token" type="password" autocomplete="new-password">
<button class="primary" type="submit">保存</button>
</form></section>
<?php kintone_page_footer(); ?>
