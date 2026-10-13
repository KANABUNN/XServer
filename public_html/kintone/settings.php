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


$isEditingExisting = ($selected['app_key'] ?? '') !== '';
$postedAction = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['action'] ?? 'save_app') : '';
$openEditor = array_key_exists('app_key', $_GET) || ($error !== '' && $postedAction === 'save_app');
kintone_page_header('アプリ設定', $user, [
    'actions' => '<a class="btn btn--primary" href="settings.php?app_key=" data-new-app>' . fitsc_icon('plus') . '<span class="hide-mobile">アプリを追加</span></a>',
]);
kintone_render_flash();
if ($error !== '' && !$openEditor): ?><?= fitsc_flash_html('error', $error) ?><?php endif; ?>
<section class="card">
  <div class="card__header">
    <div class="card__title"><?= fitsc_icon('cloud') ?>kintoneアプリ <small><?= count($apps) ?>件</small></div>
    <span class="text-xs text-subtle"><?= fitsc_icon('lock', 'ico--sm') ?> APIトークンは暗号化して保存され、画面には表示されません</span>
  </div>
  <?php if ($apps === []): ?>
    <div class="empty"><span class="empty__icon"><?= fitsc_icon('cloud') ?></span><span>アプリはまだ登録されていません。</span><a class="btn btn--primary btn--sm" href="settings.php?app_key="><?= fitsc_icon('plus') ?>アプリを追加</a></div>
  <?php else: ?>
    <div class="table-wrap is-stacked kt-table-flat">
      <table class="table">
        <thead><tr><th>アプリ</th><th>同期</th><th>kintone</th><th>接続</th><th>トークン</th><th>状態</th><th class="col-actions"></th></tr></thead>
        <tbody>
        <?php foreach ($apps as $app): $key = (string)$app['app_key']; $active = (int)$app['is_active'] === 1; ?>
          <tr data-href="settings.php?app_key=<?= rawurlencode($key) ?>" class="kt-row-link<?= $isEditingExisting && $openEditor && $key === (string)$selected['app_key'] ? ' is-selected' : '' ?><?= $active ? '' : ' is-muted' ?>">
            <td><span class="cell-main"><?= kintone_h($app['display_name']) ?></span><span class="cell-sub mono"><?= kintone_h($key) ?></span></td>
            <td><?= kintone_role_badge((string)$app['app_role']) ?></td>
            <td><span class="mono">ID <?= kintone_h($app['kintone_app_id']) ?></span><span class="cell-sub mono"><?= kintone_h($app['kintone_subdomain'] ?? '') ?></span></td>
            <td><?= kintone_status_badge((string)$app['status']) ?><?php if (!empty($app['last_error'])): ?><span class="cell-sub text-danger truncate kt-cell-error" title="<?= kintone_h($app['last_error']) ?>"><?= kintone_h($app['last_error']) ?></span><?php endif; ?></td>
            <td><?= ((int)($app['has_token'] ?? 0) === 1) ? '<span class="badge badge--success badge--dot">登録済み</span>' : '<span class="badge badge--warning badge--dot">未登録</span>' ?></td>
            <td><?= $active ? '<span class="badge badge--success">有効</span>' : '<span class="badge">無効</span>' ?></td>
            <td class="col-actions">
              <span class="menu-anchor">
                <button type="button" class="btn btn--ghost btn--icon btn--sm" data-menu-toggle aria-haspopup="menu" aria-expanded="false" aria-label="<?= kintone_h($app['display_name']) ?> の操作"><?= fitsc_icon('more') ?></button>
                <div class="menu" role="menu">
                  <a class="menu__item" role="menuitem" href="settings.php?app_key=<?= rawurlencode($key) ?>"><?= fitsc_icon('pen') ?>編集</a>
                  <form method="post" action="api/test_connection.php" data-kt-test-connection>
                    <?= kintone_auth_csrf_field() ?>
                    <input type="hidden" name="app_key" value="<?= kintone_h($key) ?>">
                    <button type="submit" class="menu__item" role="menuitem"><?= fitsc_icon('zap') ?>接続テスト</button>
                  </form>
                  <?php if ($active): ?><a class="menu__item" role="menuitem" href="sync.php?app_key=<?= rawurlencode($key) ?>"><?= fitsc_icon('refresh') ?>同期画面へ</a><?php endif; ?>
                  <?php if ($key !== 'organizations'): ?>
                    <div class="menu__sep"></div>
                    <form method="post" action="settings.php" data-confirm="<?= $active ? '無効化すると同期対象から外れます。設定は残ります。' : 'このアプリを再び同期対象にします。' ?>" data-confirm-title="「<?= kintone_h($app['display_name']) ?>」を<?= $active ? '無効化' : '再有効化' ?>しますか？" data-confirm-ok="<?= $active ? '無効化する' : '再有効化する' ?>"<?= $active ? '' : ' data-confirm-tone="default"' ?>>
                      <?= kintone_auth_csrf_field() ?>
                      <input type="hidden" name="app_key" value="<?= kintone_h($key) ?>">
                      <input type="hidden" name="action" value="<?= $active ? 'deactivate_app' : 'reactivate_app' ?>">
                      <button type="submit" class="menu__item<?= $active ? ' menu__item--danger' : '' ?>" role="menuitem"><?= fitsc_icon($active ? 'eye-off' : 'eye') ?><?= $active ? '無効化' : '再有効化' ?></button>
                    </form>
                  <?php endif; ?>
                </div>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<dialog class="modal modal--lg" id="appDialog" aria-labelledby="appDialogTitle"<?= $openEditor ? ' data-open-on-load' : '' ?>>
  <form method="post" action="settings.php?app_key=<?= rawurlencode((string)($selected['app_key'] ?? '')) ?>" class="modal__panel" data-busy>
    <div class="modal__header">
      <span class="modal__icon"><?= fitsc_icon($isEditingExisting ? 'pen' : 'plus') ?></span>
      <div>
        <h2 class="modal__title" id="appDialogTitle"><?= $isEditingExisting ? kintone_h($selected['display_name'] ?: $selected['app_key']) : 'アプリを追加' ?></h2>
        <p class="modal__desc"><?= $isEditingExisting ? '接続 ' . kintone_status_badge((string)($selected['status'] ?? '')) . ' ・ 最終テスト ' . kintone_h($selected['last_tested_at'] ?? '—') : 'kintoneアプリの接続情報と同期方法を登録します' ?></p>
      </div>
      <button type="button" class="btn btn--ghost btn--icon modal__close" data-modal-close aria-label="閉じる"><?= fitsc_icon('x') ?></button>
    </div>
    <div class="modal__body stack">
      <?php if ($error !== ''): ?>
        <div class="alert alert--danger" role="alert"><?= fitsc_icon('x-circle') ?><div class="alert__body"><?= kintone_h($error) ?></div></div>
      <?php endif; ?>
      <?php if ($isEditingExisting && !empty($selected['last_error'])): ?>
        <div class="alert alert--warning"><?= fitsc_icon('alert-triangle') ?><div class="alert__body"><strong class="alert__title">前回のエラー</strong><span class="allow-select"><?= kintone_h($selected['last_error']) ?></span></div></div>
      <?php endif; ?>
      <?= kintone_auth_csrf_field() ?>
      <input type="hidden" name="action" value="save_app">
      <input type="hidden" name="original_app_key" value="<?= kintone_h($selected['app_key'] ?? '') ?>">
      <div class="form-grid">
        <label class="field">
          <span>app_key <span class="req">必須</span></span>
          <input name="app_key" class="mono" value="<?= kintone_h($selected['app_key'] ?? '') ?>" <?= $isEditingExisting ? 'readonly' : 'required' ?> placeholder="例: members">
          <?php if ($isEditingExisting): ?><span class="field__hint">登録後は変更できません</span><?php endif; ?>
        </label>
        <label class="field">
          <span>表示名 <span class="req">必須</span></span>
          <input name="display_name" value="<?= kintone_h($selected['display_name'] ?? '') ?>" required placeholder="例: 団体マスタ">
        </label>
        <label class="field">
          <span>サブドメイン <span class="req">必須</span></span>
          <input name="kintone_subdomain" class="mono" value="<?= kintone_h($selected['kintone_subdomain'] ?? '') ?>" required placeholder="example または https://example.cybozu.com">
        </label>
        <label class="field">
          <span>kintoneアプリID <span class="req">必須</span></span>
          <input name="kintone_app_id" type="number" min="1" class="mono" value="<?= kintone_h($selected['kintone_app_id'] ?? '') ?>" required>
        </label>
        <label class="field">
          <span>同期方向</span>
          <select name="app_role">
            <option value="push" <?= ($selected['app_role'] ?? '') === 'push' ? 'selected' : '' ?>>送信 push：XServer正本 → kintone</option>
            <option value="pull" <?= ($selected['app_role'] ?? '') === 'pull' ? 'selected' : '' ?>>取得 pull：kintone → XServerキャッシュ</option>
            <option value="manual" <?= ($selected['app_role'] ?? '') === 'manual' ? 'selected' : '' ?>>手動：自動同期しない</option>
          </select>
        </label>
        <label class="field">
          <span>更新キー（update_key_field）</span>
          <input name="update_key_field" class="mono" value="<?= kintone_h($selected['update_key_field'] ?? '') ?>" placeholder="例: organization_code">
          <span class="field__hint">push / pull では必須です</span>
        </label>
        <label class="field">
          <span>APIトークン <?= ((int)($selected['has_token'] ?? 0) === 1) ? '<span class="badge badge--success badge--dot">登録済み</span>' : '' ?></span>
          <input name="api_token" type="password" autocomplete="new-password" placeholder="<?= ((int)($selected['has_token'] ?? 0) === 1) ? '変更しない場合は空欄' : 'トークンを入力' ?>">
        </label>
        <div class="field">
          <span>個人情報</span>
          <label class="switch"><input type="checkbox" name="contains_pii" value="1" <?= (int)($selected['contains_pii'] ?? 0) === 1 ? 'checked' : '' ?>>個人情報（PII）を含む</label>
          <span class="field__hint">pull の場合、キャッシュ許可フィールドが未設定だと取り込みません</span>
        </div>
      </div>

      <details class="disclosure"<?= $isEditingExisting ? '' : ' open' ?>>
        <summary><?= fitsc_icon('sliders') ?><span>フィールドの対応づけ</span><small class="text-subtle">フィールドマップ・キャッシュ許可・ルックアップ</small></summary>
        <div class="stack kt-disclosure__body">
          <label class="field">
            <span>フィールドマップ</span>
            <textarea name="field_map_text" rows="7" class="mono" placeholder="logical_key: kintone_field_code（JSONも可）"><?= kintone_h($fieldMapText) ?></textarea>
            <span class="field__hint">1行に1件、<code>論理キー: kintoneのフィールドコード</code></span>
          </label>
          <label class="field">
            <span>キャッシュ許可フィールド</span>
            <textarea name="cache_field_allowlist_text" rows="4" class="mono" placeholder="フィールドコードを1行1件"><?= kintone_h($allowlistText) ?></textarea>
            <span class="field__hint">pull 時に保存するフィールドだけを指定します</span>
          </label>
          <label class="field">
            <span>ルックアップマップ</span>
            <textarea name="lookup_map_text" rows="4" class="mono" placeholder="organization_code: organizations.organization_code"><?= kintone_h($lookupMapText) ?></textarea>
            <span class="field__hint">1行に1件、<code>論理キー: 参照先app_key.参照先の論理キー</code>（JSONも可）</span>
          </label>
        </div>
      </details>
    </div>
    <div class="modal__footer">
      <button type="button" class="btn btn--ghost" data-modal-close>キャンセル</button>
      <button type="submit" class="btn btn--primary"><?= fitsc_icon('save') ?>保存</button>
    </div>
  </form>
</dialog>
<?php kintone_page_footer(); ?>
