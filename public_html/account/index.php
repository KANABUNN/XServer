<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/apps/account_core/service.php';

account_site_bootstrap_session();
account_site_require_view_access();

$pdo = account_site_db();
account_site_install_extra_schema($pdo);
$currentUser = account_site_current_user();
$flash = account_site_pull_flash();
$search = trim((string)($_GET['q'] ?? ''));
$selectedId = (int)($_GET['edit'] ?? 0);
$canManage = account_site_has_any_role(['user', 'admin']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!account_site_verify_csrf($_POST['_csrf'] ?? null)) {
        $error = 'セッションの検証に失敗しました。再度お試しください。';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'save_account') {
                account_site_require_manage_access();
                $savedId = account_site_save_account($pdo, $_POST, (int)($currentUser['id'] ?? 0));
                account_site_set_flash('success', $selectedId > 0 ? 'アカウント情報を更新しました。' : 'アカウントを作成しました。');
                account_site_redirect('index.php?edit=' . $savedId);
            }

            if ($action === 'delete_account') {
                account_site_require_manage_access();
                $deleteId = (int)($_POST['id'] ?? 0);
                account_site_delete_account($pdo, $deleteId, (int)($currentUser['id'] ?? 0));
                account_site_set_flash('success', 'アカウントを削除しました。');
                account_site_redirect('index.php');
            }

            if ($action === 'change_password') {
                account_site_require_view_access();
                account_site_change_own_password(
                    $pdo,
                    (int)($currentUser['id'] ?? 0),
                    (string)($_POST['current_password'] ?? ''),
                    (string)($_POST['new_password'] ?? '')
                );
                account_site_set_flash('success', '自分のパスワードを更新しました。');
                account_site_redirect('index.php');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            if ($action === 'save_account') {
                $selectedId = (int)($_POST['id'] ?? 0);
            }
        }
    }
}

$accounts = account_site_fetch_accounts($pdo, $search);
$selectedAccount = null;
if ($selectedId > 0) {
    $selectedAccount = account_site_fetch_account($pdo, $selectedId);
}
if ($selectedAccount === null) {
    $selectedAccount = [
        'id' => 0,
        'login_id' => '',
        'email' => '',
        'display_name' => '',
        'organization_name' => '',
        'is_active' => 1,
        'roles_by_app' => [],
    ];
}

$appDefinitions = account_site_app_definitions();
$recentLogs = account_site_recent_audit_logs($pdo, 15);
$stats = [
    'total' => count($accounts),
    'active' => count(array_filter($accounts, static fn(array $row): bool => (int)$row['is_active'] === 1)),
    'account_admins' => account_site_count_active_account_admins($pdo),
];
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>共通アカウント管理</title>
    <link rel="stylesheet" href="assets/css/account.css">
    <script src="assets/js/account.js" defer></script>
</head>
<body class="account-app-body">
<header class="topbar">
    <div>
        <div class="brand-kicker">FIT-SC</div>
        <h1>共通アカウント管理</h1>
        <p class="muted">shared_accounts と shared_account_app_roles を横断管理します。</p>
    </div>
    <div class="topbar-actions">
        <div class="current-user-box">
            <strong><?= account_site_h($currentUser['display_name'] ?? '') ?></strong>
            <span><?= account_site_h($currentUser['login_id'] ?? '') ?></span>
        </div>
        <a href="logout.php" class="button ghost">ログアウト</a>
    </div>
</header>

<main class="layout-grid">
    <section class="summary-grid">
        <article class="panel stat-card"><span>登録アカウント</span><strong><?= number_format($stats['total']) ?></strong></article>
        <article class="panel stat-card"><span>有効アカウント</span><strong><?= number_format($stats['active']) ?></strong></article>
        <article class="panel stat-card"><span>アカウント管理者</span><strong><?= number_format($stats['account_admins']) ?></strong></article>
    </section>

    <?php if ($flash): ?>
        <div class="flash <?= account_site_h($flash['type']) ?> full-width"><?= nl2br(account_site_h($flash['message'])) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="flash error full-width"><?= nl2br(account_site_h($error)) ?></div>
    <?php endif; ?>

    <section class="panel list-panel">
        <div class="section-head">
            <div>
                <h2>アカウント一覧</h2>
                <p class="muted">アプリ別権限と状態をまとめて確認できます。</p>
            </div>
            <div class="section-head-actions">
                <form method="get" class="search-form">
                    <input type="search" name="q" placeholder="ID・氏名・メールで検索" value="<?= account_site_h($search) ?>">
                    <button type="submit" class="button ghost">検索</button>
                </form>
                <?php if ($canManage): ?>
                    <a href="index.php" class="button primary">新規作成</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>表示名</th>
                    <th>ログインID</th>
                    <th>メール</th>
                    <th>権限</th>
                    <th>状態</th>
                    <th>最終ログイン</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($accounts as $account): ?>
                    <tr>
                        <td><?= (int)$account['id'] ?></td>
                        <td>
                            <strong><?= account_site_h($account['display_name']) ?></strong>
                            <?php if (!empty($account['organization_name'])): ?>
                                <div class="subline"><?= account_site_h($account['organization_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= account_site_h($account['login_id']) ?></td>
                        <td><?= account_site_h($account['email']) ?></td>
                        <td>
                            <div class="role-badges">
                                <?php foreach (($account['roles_by_app'] ?? []) as $appKey => $roleKeys): ?>
                                    <span class="badge soft"><?= account_site_h(($appDefinitions[$appKey]['label'] ?? $appKey) . ':' . implode('/', $roleKeys)) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?= (int)$account['is_active'] === 1 ? 'success' : 'muted' ?>"><?= (int)$account['is_active'] === 1 ? '有効' : '停止' ?></span>
                        </td>
                        <td><?= account_site_h((string)($account['last_login_at'] ?? '—')) ?></td>
                        <td><a class="button ghost small" href="index.php?edit=<?= (int)$account['id'] ?>">詳細</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($accounts === []): ?>
                    <tr><td colspan="8" class="empty">対象のアカウントはありません。</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel edit-panel">
        <div class="section-head">
            <div>
                <h2><?= (int)$selectedAccount['id'] > 0 ? 'アカウント編集' : '新規アカウント作成' ?></h2>
                <p class="muted">基本情報と、各システムでの利用権限を同時に設定します。</p>
            </div>
        </div>

        <?php if ($canManage): ?>
            <form method="post" class="form-stack">
                <input type="hidden" name="_csrf" value="<?= account_site_h(account_site_csrf_token()) ?>">
                <input type="hidden" name="action" value="save_account">
                <input type="hidden" name="id" value="<?= (int)$selectedAccount['id'] ?>">

                <div class="two-col">
                    <label>
                        <span>ログインID</span>
                        <input type="text" name="login_id" required value="<?= account_site_h((string)$selectedAccount['login_id']) ?>">
                    </label>
                    <label>
                        <span>表示名</span>
                        <input type="text" name="display_name" required value="<?= account_site_h((string)$selectedAccount['display_name']) ?>">
                    </label>
                </div>

                <div class="two-col">
                    <label>
                        <span>メールアドレス</span>
                        <input type="email" name="email" value="<?= account_site_h((string)$selectedAccount['email']) ?>">
                    </label>
                    <label>
                        <span>所属・組織名</span>
                        <input type="text" name="organization_name" value="<?= account_site_h((string)$selectedAccount['organization_name']) ?>">
                    </label>
                </div>

                <div class="two-col">
                    <label>
                        <span><?= (int)$selectedAccount['id'] > 0 ? '新しいパスワード（変更時のみ）' : '初期パスワード' ?></span>
                        <input type="password" name="password" <?= (int)$selectedAccount['id'] > 0 ? '' : 'required' ?>>
                    </label>
                    <label class="checkbox-line top-align">
                        <input type="checkbox" name="is_active" value="1" <?= (int)$selectedAccount['is_active'] === 1 ? 'checked' : '' ?>>
                        <span>アカウントを有効にする</span>
                    </label>
                </div>

                <div class="role-matrix">
                    <?php foreach ($appDefinitions as $appKey => $meta): ?>
                        <fieldset class="role-card">
                            <legend><?= account_site_h($meta['label']) ?></legend>
                            <?php foreach ($meta['roles'] as $roleKey => $roleLabel): ?>
                                <label class="checkbox-line">
                                    <input type="checkbox"
                                           name="roles_by_app[<?= account_site_h($appKey) ?>][]"
                                           value="<?= account_site_h($roleKey) ?>"
                                        <?= in_array($roleKey, $selectedAccount['roles_by_app'][$appKey] ?? [], true) ? 'checked' : '' ?>>
                                    <span><?= account_site_h($roleLabel) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php endforeach; ?>
                </div>

                <div class="button-row">
                    <button type="submit" class="button primary"><?= (int)$selectedAccount['id'] > 0 ? '更新する' : '作成する' ?></button>
                    <?php if ((int)$selectedAccount['id'] > 0): ?>
                        <a href="index.php" class="button ghost">新規作成に戻る</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ((int)$selectedAccount['id'] > 0): ?>
                <form method="post" class="delete-form" onsubmit="return confirm('このアカウントを削除しますか？ ロール設定も同時に削除されます。');">
                    <input type="hidden" name="_csrf" value="<?= account_site_h(account_site_csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_account">
                    <input type="hidden" name="id" value="<?= (int)$selectedAccount['id'] ?>">
                    <button type="submit" class="button danger">このアカウントを削除する</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <div class="read-only-box">現在の権限では編集操作はできません。閲覧のみ可能です。</div>
        <?php endif; ?>
    </section>

    <section class="panel profile-panel">
        <div class="section-head">
            <div>
                <h2>自分のパスワード変更</h2>
                <p class="muted">共通アカウントのログイン用パスワードを更新します。</p>
            </div>
        </div>
        <form method="post" class="form-stack narrow-form">
            <input type="hidden" name="_csrf" value="<?= account_site_h(account_site_csrf_token()) ?>">
            <input type="hidden" name="action" value="change_password">
            <label>
                <span>現在のパスワード</span>
                <input type="password" name="current_password" required>
            </label>
            <label>
                <span>新しいパスワード</span>
                <input type="password" name="new_password" required>
            </label>
            <button type="submit" class="button primary">更新する</button>
        </form>
    </section>

    <section class="panel audit-panel">
        <div class="section-head">
            <div>
                <h2>直近の操作履歴</h2>
                <p class="muted">アカウント作成・更新・パスワード変更を記録します。</p>
            </div>
        </div>
        <div class="audit-list">
            <?php foreach ($recentLogs as $log): ?>
                <article class="audit-item">
                    <div class="audit-meta">
                        <strong><?= account_site_h((string)$log['action_key']) ?></strong>
                        <span><?= account_site_h((string)$log['created_at']) ?></span>
                    </div>
                    <div class="audit-body">
                        実行者: <?= account_site_h((string)($log['actor_name'] ?? $log['actor_account_id'] ?? '—')) ?> / 
                        対象: <?= account_site_h((string)($log['target_name'] ?? $log['target_account_id'] ?? '—')) ?>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if ($recentLogs === []): ?>
                <div class="empty">まだ操作履歴はありません。</div>
            <?php endif; ?>
        </div>
    </section>
</main>
</body>
</html>
