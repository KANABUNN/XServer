<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
require_once dirname(__DIR__, 2) . '/apps/kintone_core/crypto.php';
require_once dirname(__DIR__, 2) . '/apps/kintone_core/kintone_sync_service.php';

$user = kintone_auth_require_admin_access();
$pdo = kintone_pdo('org');
$error = '';

function kintone_settings_parse_key_value_text(string $text): ?string
{
    $text = trim($text);
    if ($text === '') {
        return null;
    }
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        $out = [];
        foreach ($decoded as $key => $value) {
            if (is_scalar($value) && trim((string)$key) !== '' && trim((string)$value) !== '') {
                $out[trim((string)$key)] = trim((string)$value);
            }
        }
        return $out !== [] ? kintone_json_encode($out) : null;
    }
    $out = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = preg_split('/\s*(?:=>|=|:)\s*/u', $line, 2);
        if (!is_array($parts) || count($parts) !== 2) {
            throw new InvalidArgumentException('フィールドマップは JSON、または key:field_code の行形式で入力してください。');
        }
        [$key, $value] = $parts;
        $key = trim((string)$key);
        $value = trim((string)$value);
        if ($key !== '' && $value !== '') {
            $out[$key] = $value;
        }
    }
    return $out !== [] ? kintone_json_encode($out) : null;
}

function kintone_settings_parse_allowlist(string $text): ?string
{
    $text = trim($text);
    if ($text === '') {
        return null;
    }
    $decoded = json_decode($text, true);
    $values = [];
    if (is_array($decoded)) {
        foreach ($decoded as $value) {
            if (is_scalar($value) && trim((string)$value) !== '') {
                $values[] = trim((string)$value);
            }
        }
    } else {
        foreach (preg_split('/[\s,]+/u', $text) ?: [] as $value) {
            $value = trim($value);
            if ($value !== '') {
                $values[] = $value;
            }
        }
    }
    $values = array_values(array_unique($values));
    return $values !== [] ? kintone_json_encode($values) : null;
}

function kintone_settings_parse_lookup_map_text(string $text): ?string
{
    $text = trim($text);
    if ($text === '') {
        return null;
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        $out = [];
        foreach ($decoded as $logicalKey => $target) {
            if (!is_array($target)) {
                continue;
            }
            $targetAppKey = trim((string)($target['target_app_key'] ?? ''));
            $targetLogicalKey = trim((string)($target['target_logical_key'] ?? ''));
            if (trim((string)$logicalKey) !== '' && $targetAppKey !== '' && $targetLogicalKey !== '') {
                $out[trim((string)$logicalKey)] = [
                    'target_app_key' => $targetAppKey,
                    'target_logical_key' => $targetLogicalKey,
                ];
            }
        }
        return $out !== [] ? kintone_json_encode($out) : null;
    }

    $out = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = preg_split('/\s*(?:=>|=|:)\s*/u', $line, 2);
        if (!is_array($parts) || count($parts) !== 2) {
            throw new InvalidArgumentException('ルックアップマップは JSON、または logical_key: target_app_key.target_logical_key の行形式で入力してください。');
        }
        [$logicalKey, $target] = array_map('trim', $parts);
        $targetParts = explode('.', $target, 2);
        if ($logicalKey === '' || count($targetParts) !== 2 || trim($targetParts[0]) === '' || trim($targetParts[1]) === '') {
            throw new InvalidArgumentException('ルックアップマップの値は target_app_key.target_logical_key の形式にしてください: ' . $line);
        }
        $out[$logicalKey] = [
            'target_app_key' => trim($targetParts[0]),
            'target_logical_key' => trim($targetParts[1]),
        ];
    }
    return $out !== [] ? kintone_json_encode($out) : null;
}

function kintone_settings_mask_token(?string $encrypted): string
{
    if (trim((string)$encrypted) === '') {
        return '未登録';
    }
    return '登録済み';
}

function kintone_settings_default_field_map_text(): string
{
    $lines = [];
    foreach (kintone_sync_config_field_map() as $key => $value) {
        $lines[] = $key . ': ' . $value;
    }
    return implode("\n", $lines);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    kintone_auth_require_csrf();
    $ref = kintone_reference_id('SET');
    try {
        $action = (string)($_POST['action'] ?? 'save_app');
        if ($action === 'save_app') {
            $originalKey = trim((string)($_POST['original_app_key'] ?? ''));
            $appKey = kintone_normalize_app_key((string)($_POST['app_key'] ?? $originalKey));
            if ($originalKey !== '' && $originalKey !== $appKey) {
                throw new InvalidArgumentException('既存アプリの app_key は変更できません。');
            }
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            $subdomain = trim((string)($_POST['kintone_subdomain'] ?? ''));
            $appId = (int)($_POST['kintone_app_id'] ?? 0);
            $role = (string)($_POST['app_role'] ?? 'manual');
            $updateKeyField = trim((string)($_POST['update_key_field'] ?? ''));
            $fieldMapJson = kintone_settings_parse_key_value_text((string)($_POST['field_map_text'] ?? ''));
            $containsPii = !empty($_POST['contains_pii']) ? 1 : 0;
            $allowlistJson = kintone_settings_parse_allowlist((string)($_POST['cache_field_allowlist_text'] ?? ''));
            $lookupMapJson = kintone_settings_parse_lookup_map_text((string)($_POST['lookup_map_text'] ?? ''));
            $token = trim((string)($_POST['api_token'] ?? ''));
            if ($displayName === '' || $subdomain === '' || $appId < 1) {
                throw new InvalidArgumentException('表示名・サブドメイン・アプリIDを入力してください。');
            }
            if (!in_array($role, ['push', 'pull', 'manual'], true)) {
                throw new InvalidArgumentException('同期ロールが不正です。');
            }
            if ($role !== 'manual' && $updateKeyField === '') {
                throw new InvalidArgumentException('push/pull アプリでは update_key_field を入力してください。');
            }
            if ($containsPii === 1 && $role === 'pull' && $allowlistJson === null) {
                throw new InvalidArgumentException('PIIを含むpullアプリでは、キャッシュ許可フィールドを必ず指定してください。');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO kintone_apps (app_key, display_name, kintone_subdomain, kintone_app_id, app_role, update_key_field, field_map_json, lookup_map_json, contains_pii, cache_field_allowlist_json, is_active, status) ' .
                'VALUES (:app_key, :display_name, :subdomain, :app_id, :role, :update_key, :field_map, :lookup_map, :contains_pii, :allowlist, 1, "not_connected") ' .
                'ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), kintone_subdomain=VALUES(kintone_subdomain), kintone_app_id=VALUES(kintone_app_id), app_role=VALUES(app_role), update_key_field=VALUES(update_key_field), field_map_json=VALUES(field_map_json), lookup_map_json=VALUES(lookup_map_json), contains_pii=VALUES(contains_pii), cache_field_allowlist_json=VALUES(cache_field_allowlist_json), is_active=1, updated_at=NOW()'
            );
            $stmt->execute([
                ':app_key' => $appKey,
                ':display_name' => $displayName,
                ':subdomain' => $subdomain,
                ':app_id' => $appId,
                ':role' => $role,
                ':update_key' => $updateKeyField !== '' ? $updateKeyField : null,
                ':field_map' => $fieldMapJson,
                ':lookup_map' => $lookupMapJson,
                ':contains_pii' => $containsPii,
                ':allowlist' => $allowlistJson,
            ]);

            $encrypted = $token !== '' ? kintone_crypto_encrypt($token) : null;
            if ($encrypted !== null) {
                $pdo->prepare(
                    'INSERT INTO kintone_credentials (connection_key, kintone_subdomain, kintone_app_id, auth_type, api_token_encrypted, status, connected_at) ' .
                    'VALUES (:app_key, :subdomain, :app_id, "api_token", :token, "connected", NOW()) ' .
                    'ON DUPLICATE KEY UPDATE kintone_subdomain=VALUES(kintone_subdomain), kintone_app_id=VALUES(kintone_app_id), api_token_encrypted=VALUES(api_token_encrypted), status="connected", connected_at=NOW(), updated_at=NOW()'
                )->execute([':app_key' => $appKey, ':subdomain' => $subdomain, ':app_id' => $appId, ':token' => $encrypted]);
            } else {
                $updated = $pdo->prepare('UPDATE kintone_credentials SET kintone_subdomain=:subdomain, kintone_app_id=:app_id, updated_at=NOW() WHERE connection_key=:app_key');
                $updated->execute([':subdomain' => $subdomain, ':app_id' => $appId, ':app_key' => $appKey]);
                if ($updated->rowCount() === 0) {
                    throw new InvalidArgumentException('初回保存時はAPIトークンを入力してください。');
                }
            }
            kintone_write_audit_log('kintone.app_registry.save', 'kintone_app', $appKey, [
                'app_key' => $appKey,
                'role' => $role,
                'contains_pii' => $containsPii,
                'token_updated' => $encrypted !== null,
            ], $user);
            kintone_set_flash('success', 'kintoneアプリ設定を保存しました。');
            header('Location: settings.php?app_key=' . rawurlencode($appKey), true, 302);
            exit;
        }
        if ($action === 'deactivate_app' || $action === 'reactivate_app') {
            $appKey = kintone_normalize_app_key((string)($_POST['app_key'] ?? ''));
            if ($appKey === 'organizations' && $action === 'deactivate_app') {
                throw new InvalidArgumentException('団体マスタ organizations は無効化できません。');
            }
            $pdo->prepare('UPDATE kintone_apps SET is_active = :active, updated_at = NOW() WHERE app_key = :app_key')->execute([
                ':active' => $action === 'reactivate_app' ? 1 : 0,
                ':app_key' => $appKey,
            ]);
            kintone_write_audit_log('kintone.app_registry.' . ($action === 'reactivate_app' ? 'reactivate' : 'deactivate'), 'kintone_app', $appKey, [], $user);
            kintone_set_flash('success', 'アプリ状態を更新しました。');
            header('Location: settings.php', true, 302);
            exit;
        }
        throw new InvalidArgumentException('不明な操作です。');
    } catch (PDOException $e) {
        error_log('[kintone settings PDO][' . $ref . '] ' . $e->getMessage());
        $error = '接続情報の保存に失敗しました（参照ID: ' . $ref . '）。';
    } catch (Throwable $e) {
        error_log('[kintone settings][' . $ref . '] ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : '接続情報の保存に失敗しました（参照ID: ' . $ref . '）。';
    }
}

$apps = [];
try {
    $apps = kintone_sync_list_apps(false);
} catch (Throwable $e) {
    error_log('[kintone settings list] ' . $e->getMessage());
}
$selectedKey = trim((string)($_GET['app_key'] ?? ($apps[0]['app_key'] ?? 'organizations')));
$selected = null;
foreach ($apps as $app) {
    if ((string)$app['app_key'] === $selectedKey) {
        $selected = $app;
        break;
    }
}
if (!is_array($selected)) {
    $selected = [
        'app_key' => '',
        'display_name' => '',
        'kintone_subdomain' => '',
        'kintone_app_id' => '',
        'app_role' => 'pull',
        'update_key_field' => '',
        'field_map_json' => '',
        'lookup_map_json' => '',
        'contains_pii' => 0,
        'cache_field_allowlist_json' => '',
        'is_active' => 1,
        'status' => 'not_connected',
        'last_tested_at' => null,
        'last_error' => null,
        'has_token' => 0,
    ];
}
$fieldMapText = trim((string)($selected['field_map_json'] ?? ''));
if ($fieldMapText !== '') {
    $decoded = json_decode($fieldMapText, true);
    if (is_array($decoded)) {
        $lines = [];
        foreach ($decoded as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }
        $fieldMapText = implode("\n", $lines);
    }
} elseif (($selected['app_key'] ?? '') === 'organizations' || ($selected['app_key'] ?? '') === '') {
    $fieldMapText = kintone_settings_default_field_map_text();
}
$allowlistText = trim((string)($selected['cache_field_allowlist_json'] ?? ''));
if ($allowlistText !== '') {
    $decoded = json_decode($allowlistText, true);
    if (is_array($decoded)) {
        $allowlistText = implode("\n", array_map('strval', $decoded));
    }
}
$lookupMapText = trim((string)($selected['lookup_map_json'] ?? ''));
if ($lookupMapText !== '') {
    $decoded = json_decode($lookupMapText, true);
    if (is_array($decoded)) {
        $lines = [];
        foreach ($decoded as $logicalKey => $target) {
            if (is_array($target)) {
                $lines[] = $logicalKey . ': ' . ($target['target_app_key'] ?? '') . '.' . ($target['target_logical_key'] ?? '');
            }
        }
        $lookupMapText = implode("\n", $lines);
    }
}

kintone_page_header('アプリレジストリ', $user);
kintone_render_flash();
if ($error !== ''): ?><div class="alert alert-danger"><?= kintone_h($error) ?></div><?php endif; ?>
<section class="card">
  <h2>kintoneアプリ一覧</h2>
  <p class="muted">APIトークンはアプリ単位で暗号化保存します。一覧にはトークン値を表示しません。</p>
  <div class="table-wrap"><table><thead><tr><th>app_key</th><th>表示名</th><th>ロール</th><th>アプリID</th><th>状態</th><th>トークン</th><th>最終同期</th><th>操作</th></tr></thead><tbody>
    <?php foreach ($apps as $app): ?>
      <tr>
        <td><code><?= kintone_h($app['app_key']) ?></code></td>
        <td><?= kintone_h($app['display_name']) ?></td>
        <td><span class="badge"><?= kintone_h($app['app_role']) ?></span></td>
        <td><?= kintone_h($app['kintone_app_id']) ?></td>
        <td><?= ((int)$app['is_active'] === 1) ? '<span class="badge badge-success">有効</span>' : '<span class="badge badge-danger">無効</span>' ?> / <?= kintone_h($app['status']) ?></td>
        <td><?= ((int)($app['has_token'] ?? 0) === 1) ? '<span class="badge badge-success">登録済み</span>' : '<span class="badge badge-warn">未登録</span>' ?></td>
        <td><?= kintone_h($app['last_synced_at'] ?? '-') ?></td>
        <td><a class="btn" href="settings.php?app_key=<?= rawurlencode((string)$app['app_key']) ?>">編集</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($apps === []): ?><tr><td colspan="8" class="empty">アプリはまだ登録されていません。</td></tr><?php endif; ?>
  </tbody></table></div>
  <p><a class="secondary" href="settings.php?app_key=">新規アプリを追加</a></p>
</section>
<section class="card">
  <h2><?= ($selected['app_key'] ?? '') !== '' ? 'アプリ編集' : '新規アプリ追加' ?></h2>
  <form method="post" class="form-grid">
    <?= kintone_auth_csrf_field() ?>
    <input type="hidden" name="action" value="save_app">
    <input type="hidden" name="original_app_key" value="<?= kintone_h($selected['app_key'] ?? '') ?>">
    <label>app_key</label><input name="app_key" value="<?= kintone_h($selected['app_key'] ?? '') ?>" <?= ($selected['app_key'] ?? '') !== '' ? 'readonly' : '' ?> placeholder="例: organizations / members / officers">
    <label>表示名</label><input name="display_name" value="<?= kintone_h($selected['display_name'] ?? '') ?>" placeholder="例: 団体マスタ">
    <label>サブドメイン</label><input name="kintone_subdomain" value="<?= kintone_h($selected['kintone_subdomain'] ?? '') ?>" placeholder="example または https://example.cybozu.com">
    <label>アプリID</label><input name="kintone_app_id" type="number" value="<?= kintone_h($selected['kintone_app_id'] ?? '') ?>">
    <label>同期ロール</label><select name="app_role"><option value="push" <?= ($selected['app_role'] ?? '') === 'push' ? 'selected' : '' ?>>push: XServer正本→kintone</option><option value="pull" <?= ($selected['app_role'] ?? '') === 'pull' ? 'selected' : '' ?>>pull: kintone→XServerキャッシュ</option><option value="manual" <?= ($selected['app_role'] ?? '') === 'manual' ? 'selected' : '' ?>>manual: 手動管理</option></select>
    <label>update_key_field</label><input name="update_key_field" value="<?= kintone_h($selected['update_key_field'] ?? '') ?>" placeholder="例: organization_code / student_id / belongs_org_code">
    <label>APIトークン</label><input name="api_token" type="password" autocomplete="new-password" placeholder="既存トークンを維持する場合は空欄">
    <label>PIIを含む</label><label class="check-row"><input type="checkbox" name="contains_pii" value="1" <?= (int)($selected['contains_pii'] ?? 0) === 1 ? 'checked' : '' ?>> pull時はallowlist未設定なら取り込み拒否</label>
    <label>フィールドマップ</label><textarea name="field_map_text" rows="8" placeholder="logical_key: kintone_field_code&#10;JSONも可"><?= kintone_h($fieldMapText) ?></textarea>
    <label>キャッシュ許可フィールド</label><textarea name="cache_field_allowlist_text" rows="6" placeholder="pull時にrecord_jsonへ保存するフィールドコードを1行1件で指定"><?= kintone_h($allowlistText) ?></textarea>
    <label>ルックアップマップ</label><textarea name="lookup_map_text" rows="6" placeholder="logical_key: target_app_key.target_logical_key&#10;例: organization_code: organizations.organization_code&#10;例: student_id: members.student_id&#10;JSONも可"><?= kintone_h($lookupMapText) ?></textarea>
    <div class="form-actions"><button class="primary" type="submit">保存</button><a class="btn" href="sync.php?app_key=<?= rawurlencode((string)($selected['app_key'] ?? '')) ?>">同期画面へ</a></div>
  </form>
</section>
<?php if (($selected['app_key'] ?? '') !== ''): ?>
<section class="card">
  <h2>接続テスト・状態変更</h2>
  <p>状態: <span class="badge"><?= kintone_h($selected['status'] ?? 'not_connected') ?></span> / 最終テスト: <?= kintone_h($selected['last_tested_at'] ?? '-') ?> / トークン: <?= ((int)($selected['has_token'] ?? 0) === 1) ? '登録済み' : '未登録' ?></p>
  <?php if (!empty($selected['last_error'])): ?><p class="alert alert-warning">前回エラー: <?= kintone_h($selected['last_error']) ?></p><?php endif; ?>
  <form method="post" action="api/test_connection.php" class="form-actions">
    <?= kintone_auth_csrf_field() ?>
    <input type="hidden" name="app_key" value="<?= kintone_h($selected['app_key']) ?>">
    <button class="secondary" type="submit">このアプリで接続テスト</button>
  </form>
  <?php if (($selected['app_key'] ?? '') !== 'organizations'): ?>
    <form method="post" class="form-actions">
      <?= kintone_auth_csrf_field() ?>
      <input type="hidden" name="app_key" value="<?= kintone_h($selected['app_key']) ?>">
      <input type="hidden" name="action" value="<?= (int)($selected['is_active'] ?? 1) === 1 ? 'deactivate_app' : 'reactivate_app' ?>">
      <button class="danger" type="submit" onclick="return confirm('このアプリの有効状態を変更します。よろしいですか？');"><?= (int)($selected['is_active'] ?? 1) === 1 ? '無効化' : '再有効化' ?></button>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php kintone_page_footer(); ?>
