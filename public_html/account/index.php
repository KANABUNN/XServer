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
$canManage = account_site_has_any_role(['admin']);
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

            if ($action === 'delete_account') {
                account_site_require_manage_access();
                $deleteId = (int)($_POST['id'] ?? 0);
                account_site_delete_account($pdo, $deleteId, (int)($currentUser['id'] ?? 0));
                account_site_set_flash('success', 'アカウントを削除しました。');
                account_site_redirect('index.php');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            if ($action === 'save_account' || $action === 'delete_account') {
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

$selectedIsSelf = (int)($selectedAccount['id'] ?? 0) > 0 && (int)($selectedAccount['id'] ?? 0) === (int)($currentUser['id'] ?? 0);
$selectedHasAccountAdmin = in_array('admin', $selectedAccount['roles_by_app'][account_site_app_key()] ?? [], true);
$selectedDeleteRestricted = $selectedIsSelf || ($selectedHasAccountAdmin && (int)($selectedAccount['is_active'] ?? 0) === 1 && account_site_count_active_account_admins($pdo, (int)$selectedAccount['id']) === 0);
$appDefinitions = account_site_app_definitions();
$recentLogs = account_site_recent_audit_logs($pdo, 15);
$stats = [
    'total' => count($accounts),
    'active' => count(array_filter($accounts, static fn(array $row): bool => (int)$row['is_active'] === 1)),
    'account_admins' => account_site_count_active_account_admins($pdo),
];
require_once dirname(__DIR__, 2) . '/apps/ui_kit.php';
$postedAction = (string)($_POST['action'] ?? '');
$isEditing = (int)($selectedAccount['id'] ?? 0) > 0;
$openEditor = isset($_GET['edit']) || ($error !== '' && in_array($postedAction, ['save_account', 'delete_account'], true));
$openPassword = $error !== '' && $postedAction === 'change_password';
$currentName = (string)($currentUser['display_name'] ?? '');
?>
<!doctype html>
<html lang="ja" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>共通アカウント管理</title>
    <?= fitsc_ui_head(['css' => ['assets/css/app.css'], 'js' => ['assets/js/account.js']]) ?>
</head>
<body class="ac-body">
<div class="site">
    <header class="site-header">
        <div class="site-header__inner">
            <a class="site-brand" href="index.php">
                <span class="brand-mark"><?= fitsc_icon('users') ?></span>
                <span class="site-brand__text"><strong>共通アカウント管理</strong><span>FIT-SC Account</span></span>
            </a>
            <div class="site-header__actions">
                <span class="menu-anchor">
                    <button type="button" class="btn btn--ghost ac-user" data-menu-toggle aria-haspopup="menu" aria-expanded="false">
                        <span class="avatar"><?= account_site_h(fitsc_initials($currentName)) ?></span>
                        <span class="hide-mobile"><?= account_site_h($currentName) ?></span>
                        <?= fitsc_icon('chevron-down', 'ico--sm') ?>
                    </button>
                    <div class="menu" role="menu">
                        <div class="menu__label"><?= account_site_h((string)($currentUser['login_id'] ?? '')) ?></div>
                        <button type="button" class="menu__item" role="menuitem" data-modal-open="passwordDialog"><?= fitsc_icon('key') ?>パスワードを変更</button>
                        <div class="menu__sep"></div>
                        <a class="menu__item menu__item--danger" role="menuitem" href="logout.php"><?= fitsc_icon('log-out') ?>ログアウト</a>
                    </div>
                </span>
                <?= fitsc_theme_toggle() ?>
            </div>
        </div>
    </header>

    <main class="site-main stack stack--lg">
        <?php if ($flash): ?>
            <?= fitsc_flash_html((string)$flash['type'], (string)$flash['message']) ?>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <?= fitsc_flash_html('error', $error) ?>
        <?php endif; ?>

        <div class="stat-grid">
            <div class="stat"><span class="stat__label"><span class="stat__icon"><?= fitsc_icon('users') ?></span>登録アカウント</span><span class="stat__value"><?= number_format($stats['total']) ?></span></div>
            <div class="stat stat--success"><span class="stat__label"><span class="stat__icon"><?= fitsc_icon('check-circle') ?></span>有効</span><span class="stat__value"><?= number_format($stats['active']) ?></span></div>
            <div class="stat stat--info"><span class="stat__label"><span class="stat__icon"><?= fitsc_icon('shield') ?></span>アカウント管理者</span><span class="stat__value"><?= number_format($stats['account_admins']) ?></span></div>
        </div>

        <section class="card">
            <div class="card__header">
                <div class="card__title"><?= fitsc_icon('users') ?>アカウント <small><?= count($accounts) ?>件</small></div>
                <div class="card__actions">
                    <form method="get">
                        <div class="search-toggle" data-search-toggle data-submit-on-clear>
                            <button type="button" class="btn btn--ghost btn--icon search-toggle__button" aria-label="アカウントを検索"><?= fitsc_icon('search') ?></button>
                            <div class="search-toggle__field">
                                <?= fitsc_icon('search') ?>
                                <input type="search" name="q" placeholder="ID・氏名・メール" value="<?= account_site_h($search) ?>" aria-label="アカウントを検索">
                                <button type="button" class="btn btn--ghost btn--icon search-toggle__clear" aria-label="検索を閉じる"><?= fitsc_icon('x') ?></button>
                            </div>
                        </div>
                    </form>
                    <?php if ($canManage): ?>
                        <a href="index.php?edit=0" class="btn btn--primary" data-new-account><?= fitsc_icon('plus') ?><span class="hide-mobile">アカウントを追加</span></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($accounts === []): ?>
                <div class="empty"><span class="empty__icon"><?= fitsc_icon('users') ?></span><span><?= $search !== '' ? '条件に一致するアカウントはありません。' : 'アカウントはまだありません。' ?></span></div>
            <?php else: ?>
                <div class="table-wrap is-stacked">
                    <table class="table">
                        <thead>
                            <tr><th>名前</th><th>ログインID / メール</th><th>利用できるシステム</th><th>状態</th><th>最終ログイン</th><th class="col-actions"></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($accounts as $account): ?>
                                <tr class="is-clickable<?= (int)$account['id'] === (int)$selectedAccount['id'] && $isEditing ? ' is-selected' : '' ?>" data-href="index.php?edit=<?= (int)$account['id'] ?>">
                                    <td>
                                        <div class="ac-person">
                                            <span class="avatar"><?= account_site_h(fitsc_initials((string)$account['display_name'])) ?></span>
                                            <span><span class="cell-main"><?= account_site_h($account['display_name']) ?></span><?php if (!empty($account['organization_name'])): ?><span class="cell-sub"><?= account_site_h($account['organization_name']) ?></span><?php endif; ?></span>
                                        </div>
                                    </td>
                                    <td><span class="cell-main mono"><?= account_site_h($account['login_id']) ?></span><span class="cell-sub allow-select"><?= account_site_h($account['email']) ?></span></td>
                                    <td>
                                        <div class="ac-roles">
                                            <?php foreach (($account['roles_by_app'] ?? []) as $appKey => $roleKeys): ?>
                                                <?php $isAdminRole = in_array('admin', $roleKeys, true); ?>
                                                <span class="badge<?= $isAdminRole ? ' badge--accent' : '' ?>" title="<?= account_site_h(implode(' / ', $roleKeys)) ?>"><?= account_site_h($appDefinitions[$appKey]['label'] ?? $appKey) ?><?= $isAdminRole ? ' ・管理' : '' ?></span>
                                            <?php endforeach; ?>
                                            <?php if (($account['roles_by_app'] ?? []) === []): ?><span class="text-subtle text-xs">なし</span><?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?= (int)$account['is_active'] === 1 ? '<span class="badge badge--success badge--dot">有効</span>' : '<span class="badge badge--dot">停止</span>' ?></td>
                                    <td><span class="cell-sub nowrap"><?= account_site_h((string)($account['last_login_at'] ?? '—')) ?></span></td>
                                    <td class="col-actions"><a class="btn btn--ghost btn--sm" href="index.php?edit=<?= (int)$account['id'] ?>"><?= fitsc_icon($canManage ? 'pen' : 'eye') ?><?= $canManage ? '編集' : '詳細' ?></a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <details class="disclosure">
            <summary><?= fitsc_icon('history') ?><span>最近の操作履歴</span><span class="badge"><?= count($recentLogs) ?></span></summary>
            <div class="list">
                <?php foreach ($recentLogs as $log): ?>
                    <div class="list-item">
                        <span class="list-item__icon"><?= fitsc_icon('activity', 'ico--sm') ?></span>
                        <span class="list-item__main">
                            <span class="list-item__title"><code><?= account_site_h((string)$log['action_key']) ?></code></span>
                            <span class="list-item__meta">実行者：<?= account_site_h((string)($log['actor_name'] ?? $log['actor_account_id'] ?? '—')) ?> ／ 対象：<?= account_site_h((string)($log['target_name'] ?? $log['target_account_id'] ?? '—')) ?></span>
                        </span>
                        <span class="list-item__side text-subtle text-xs"><?= account_site_h((string)$log['created_at']) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if ($recentLogs === []): ?>
                    <div class="empty"><span>まだ操作履歴はありません。</span></div>
                <?php endif; ?>
            </div>
        </details>
    </main>
</div>

<dialog class="modal modal--xl" id="accountDialog" aria-labelledby="accountDialogTitle"<?= $openEditor ? ' data-open-on-load' : '' ?>>
    <div class="modal__panel">
        <div class="modal__header">
            <span class="modal__icon"><?= fitsc_icon($isEditing ? 'user' : 'plus', 'ico--lg') ?></span>
            <div>
                <h2 class="modal__title" id="accountDialogTitle"><?= $isEditing ? account_site_h((string)$selectedAccount['display_name']) : '新しいアカウント' ?></h2>
                <p class="modal__desc"><?= $isEditing ? 'ID ' . (int)$selectedAccount['id'] . ' ・ ' . account_site_h((string)$selectedAccount['login_id']) : '基本情報と、各システムで使える権限を設定します。' ?></p>
            </div>
            <a class="btn btn--ghost btn--icon modal__close" href="index.php<?= $search !== '' ? '?q=' . rawurlencode($search) : '' ?>" data-modal-close aria-label="閉じる"><?= fitsc_icon('x') ?></a>
        </div>
        <?php if ($canManage): ?>
            <form method="post" class="modal__body stack stack--lg" id="accountForm" data-busy>
                <input type="hidden" name="_csrf" value="<?= account_site_h(account_site_csrf_token()) ?>">
                <input type="hidden" name="action" value="save_account">
                <input type="hidden" name="id" value="<?= (int)$selectedAccount['id'] ?>">

                <div class="form-grid">
                    <label class="field"><span>ログインID <span class="req">必須</span></span><input type="text" name="login_id" required value="<?= account_site_h((string)$selectedAccount['login_id']) ?>" autocomplete="off" spellcheck="false" data-autofocus></label>
                    <label class="field"><span>表示名 <span class="req">必須</span></span><input type="text" name="display_name" required value="<?= account_site_h((string)$selectedAccount['display_name']) ?>"></label>
                    <label class="field"><span>メールアドレス</span><input type="email" name="email" value="<?= account_site_h((string)$selectedAccount['email']) ?>"></label>
                    <label class="field"><span>所属・組織名</span><input type="text" name="organization_name" value="<?= account_site_h((string)$selectedAccount['organization_name']) ?>"></label>
                    <label class="field"><span><?= $isEditing ? '新しいパスワード <span class="opt">変更する場合のみ</span>' : '初期パスワード <span class="req">必須</span>' ?></span><input type="password" name="password" autocomplete="new-password" <?= $isEditing ? '' : 'required' ?>></label>
                    <div class="field"><span>状態</span><label class="switch"><input type="checkbox" name="is_active" value="1" <?= (int)$selectedAccount['is_active'] === 1 ? 'checked' : '' ?>>アカウントを有効にする</label></div>
                </div>

                <div class="stack stack--sm">
                    <h3 class="section-title">利用できるシステムと権限</h3>
                    <div class="ac-role-grid">
                        <?php foreach ($appDefinitions as $appKey => $meta): ?>
                            <fieldset class="ac-role-card">
                                <legend><?= account_site_h($meta['label']) ?></legend>
                                <?php foreach ($meta['roles'] as $roleKey => $roleLabel): ?>
                                    <label class="check">
                                        <input type="checkbox" name="roles_by_app[<?= account_site_h($appKey) ?>][]" value="<?= account_site_h($roleKey) ?>" <?= in_array($roleKey, $selectedAccount['roles_by_app'][$appKey] ?? [], true) ? 'checked' : '' ?>>
                                        <span><?= account_site_h($roleLabel) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                        <?php endforeach; ?>
                    </div>
                </div>
            </form>
            <?php if ($isEditing): ?>
                <div class="modal__body ac-danger">
                    <form method="post" class="danger-zone" data-confirm="このアカウントと、各システムの権限設定を削除します。元に戻せません。" data-confirm-title="アカウントを削除しますか？" data-confirm-ok="削除する">
                        <input type="hidden" name="_csrf" value="<?= account_site_h(account_site_csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_account">
                        <input type="hidden" name="id" value="<?= (int)$selectedAccount['id'] ?>">
                        <div class="danger-zone__text">
                            <strong>アカウントを削除</strong>
                            <span><?= $selectedIsSelf ? 'ログイン中の自分自身は削除できません。' : ($selectedDeleteRestricted ? '最後のアカウント管理者は削除できません。' : '共通アカウント本体と各システムの権限が削除されます。') ?></span>
                        </div>
                        <button type="submit" class="btn btn--danger-ghost" <?= ($selectedIsSelf || $selectedDeleteRestricted) ? 'disabled' : '' ?>><?= fitsc_icon('trash') ?>削除</button>
                    </form>
                </div>
            <?php endif; ?>
            <div class="modal__footer">
                <a class="btn btn--ghost" href="index.php" data-modal-close>キャンセル</a>
                <button type="submit" form="accountForm" class="btn btn--primary"><?= fitsc_icon('save') ?><?= $isEditing ? '保存' : '作成' ?></button>
            </div>
        <?php else: ?>
            <div class="modal__body">
                <div class="alert alert--neutral"><?= fitsc_icon('lock') ?><div class="alert__body">現在の権限では閲覧のみ可能です。</div></div>
                <dl class="dl mt-4">
                    <div><dt>ログインID</dt><dd><?= account_site_h((string)$selectedAccount['login_id']) ?></dd></div>
                    <div><dt>メール</dt><dd><?= account_site_h((string)$selectedAccount['email']) ?></dd></div>
                    <div><dt>所属</dt><dd><?= account_site_h((string)$selectedAccount['organization_name']) ?></dd></div>
                </dl>
            </div>
        <?php endif; ?>
    </div>
</dialog>

<dialog class="modal modal--sm" id="passwordDialog" aria-labelledby="passwordDialogTitle"<?= $openPassword ? ' data-open-on-load' : '' ?>>
    <form method="post" class="modal__panel" data-busy>
        <input type="hidden" name="_csrf" value="<?= account_site_h(account_site_csrf_token()) ?>">
        <input type="hidden" name="action" value="change_password">
        <div class="modal__header">
            <span class="modal__icon"><?= fitsc_icon('key', 'ico--lg') ?></span>
            <div><h2 class="modal__title" id="passwordDialogTitle">パスワードを変更</h2><p class="modal__desc">すべてのシステム共通のログインパスワードです。</p></div>
            <button type="button" class="btn btn--ghost btn--icon modal__close" data-modal-close aria-label="閉じる"><?= fitsc_icon('x') ?></button>
        </div>
        <div class="modal__body stack">
            <label class="field"><span>現在のパスワード</span><input type="password" name="current_password" required autocomplete="current-password" data-autofocus></label>
            <label class="field"><span>新しいパスワード</span><input type="password" name="new_password" required autocomplete="new-password"></label>
        </div>
        <div class="modal__footer">
            <button type="button" class="btn btn--ghost" data-modal-close>キャンセル</button>
            <button type="submit" class="btn btn--primary"><?= fitsc_icon('save') ?>変更する</button>
        </div>
    </form>
</dialog>
</body>
</html>
