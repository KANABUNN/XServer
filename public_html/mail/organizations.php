<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
[$user, $mailPdo, $dbError] = mail_app_init();
$editOrganization = null;

function mail_delete_organization_record(PDO $pdo, int $organizationId, ?array $actor = null): array
{
    if ($organizationId <= 0) {
        throw new InvalidArgumentException('削除対象の団体を選択してください。');
    }

    $organization = mail_get_organization($pdo, $organizationId);
    if (!$organization) {
        throw new InvalidArgumentException('削除対象の団体が見つかりません。');
    }

    $countTargets = $pdo->prepare('SELECT COUNT(*) FROM mail_batch_targets WHERE organization_id = :id');
    $countTargets->execute([':id' => $organizationId]);
    $targetCount = (int)$countTargets->fetchColumn();

    $countAttachments = $pdo->prepare('SELECT COUNT(*) FROM mail_attachments WHERE organization_id = :id');
    $countAttachments->execute([':id' => $organizationId]);
    $attachmentCount = (int)$countAttachments->fetchColumn();

    $pdo->beginTransaction();
    try {
        // 過去バッチ・添付の参照がある場合は、団体マスタだけを削除し、履歴側は団体未紐付けとして残す。
        $clearTargets = $pdo->prepare('UPDATE mail_batch_targets SET organization_id = NULL WHERE organization_id = :id');
        $clearTargets->execute([':id' => $organizationId]);

        $clearAttachments = $pdo->prepare('UPDATE mail_attachments SET organization_id = NULL WHERE organization_id = :id');
        $clearAttachments->execute([':id' => $organizationId]);

        $delete = $pdo->prepare('DELETE FROM mail_organizations WHERE id = :id');
        $delete->execute([':id' => $organizationId]);

        mail_audit_log($pdo, $actor, 'mail.organization.delete', 'mail_organization', (string)$organizationId, [
            'identifier' => (string)($organization['identifier'] ?? ''),
            'name' => (string)($organization['name'] ?? ''),
            'external_source' => (string)($organization['external_source'] ?? ''),
            'external_id' => (string)($organization['external_id'] ?? ''),
            'detached_batch_targets' => $targetCount,
            'detached_attachments' => $attachmentCount,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'organization' => $organization,
        'detached_batch_targets' => $targetCount,
        'detached_attachments' => $attachmentCount,
    ];
}

if ($mailPdo instanceof PDO && $dbError === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    mail_auth_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save') {
            mail_require_permission_or_forbid($user, 'organization.edit');
            $id = mail_save_organization($mailPdo, $_POST);
            mail_audit_log($mailPdo, $user, 'mail.organization.save', 'mail_organization', (string)$id);
            mail_flash_set('info', '団体データを保存しました。');
            mail_redirect('organizations.php');
        } elseif ($action === 'toggle') {
            mail_require_permission_or_forbid($user, 'organization.edit');
            $id = (int)($_POST['id'] ?? 0);
            $active = (int)($_POST['is_active'] ?? 0) === 1;
            mail_set_organization_active($mailPdo, $id, $active);
            mail_audit_log($mailPdo, $user, 'mail.organization.toggle', 'mail_organization', (string)$id, ['is_active' => $active]);
            mail_flash_set('info', $active ? '団体を有効化しました。' : '団体を無効化しました。');
            mail_redirect('organizations.php');
        } elseif ($action === 'delete') {
            mail_require_permission_or_forbid($user, 'organization.edit');
            $id = (int)($_POST['id'] ?? 0);
            $result = mail_delete_organization_record($mailPdo, $id, $user);
            $org = $result['organization'];
            $message = '団体レコード「' . (string)($org['name'] ?? '') . '」を削除しました。';
            if ((int)$result['detached_batch_targets'] > 0 || (int)$result['detached_attachments'] > 0) {
                $message .= ' 過去バッチ ' . (int)$result['detached_batch_targets'] . '件、添付 ' . (int)$result['detached_attachments'] . '件の団体紐付けを解除しました。';
            }
            mail_flash_set('info', $message);
            mail_redirect('organizations.php');
        } elseif ($action === 'import_csv') {
            mail_require_permission_or_forbid($user, 'organization.edit');
            if (!isset($_FILES['csv_file']) || (int)$_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('CSVファイルを選択してください。');
            }
            $result = mail_import_organizations_csv($mailPdo, (string)$_FILES['csv_file']['tmp_name']);
            mail_audit_log($mailPdo, $user, 'mail.organization.import', 'mail_organization', null, $result);
            $message = 'CSV取込が完了しました。追加 ' . $result['inserted'] . '件、更新 ' . $result['updated'] . '件、スキップ ' . $result['skipped'] . '件。';
            if (!empty($result['errors'])) {
                $message .= ' 一部エラーがあります。';
            }
            mail_flash_set(!empty($result['errors']) ? 'warn' : 'info', $message);
            mail_redirect('organizations.php');
        } elseif ($action === 'sync_kintone') {
            mail_require_permission_or_forbid($user, 'organization.edit');
            http_response_code(503);
            mail_audit_log($mailPdo, $user, 'mail.organization.kintone_sync_retired', 'mail_organization', null, [
                'message' => 'kintone直接同期はkintone管理サイトへ移管済みです。',
            ]);
            mail_flash_set('warn', 'kintone直接同期はkintone管理サイトへ移管済みです。kintone.fit-sc.jp で名簿を反映してください。');
            mail_redirect('organizations.php');
        }
    } catch (Throwable $e) {
        mail_flash_set('danger', mail_user_safe_error_message($e));
        mail_redirect('organizations.php');
    }
}

$keyword = trim((string)($_GET['q'] ?? ''));
$active = (string)($_GET['active'] ?? 'all');
$organizations = [];
if ($mailPdo instanceof PDO && $dbError === '') {
    if (isset($_GET['edit'])) {
        $editOrganization = mail_get_organization($mailPdo, (int)$_GET['edit']);
    }
    $organizations = mail_list_organizations($mailPdo, $keyword, $active, 500);
}

$form = $editOrganization ?: [
    'id' => 0,
    'identifier' => '',
    'name' => '',
    'representative_name' => '',
    'email' => '',
    'category' => '',
    'notes' => '',
    'is_active' => 1,
];
mail_render_page_header('団体データ', $user, 'organizations.php', [
    'subtitle' => 'メールの差し込みと添付の対応付けに使う団体台帳（識別番号はファイル名の照合にも使用）',
    'actions' => '<button type="button" class="btn btn--ghost" data-modal-open="orgImportDialog">' . fitsc_icon('upload') . '<span class="hide-mobile">CSV取込</span></button>'
        . '<button type="button" class="btn btn--primary" data-modal-open="orgEditDialog">' . fitsc_icon('plus') . '<span class="hide-mobile">団体を追加</span></button>',
]);
$canEditOrg = mail_auth_has_permission($user, 'organization.edit');
?>
<?php mail_render_db_error($dbError); ?>
<?php if ($dbError === ''): ?>

<div class="alert alert--neutral">
  <?php echo fitsc_icon('cloud'); ?>
  <div class="alert__body">kintone からの名簿反映は <strong>kintone 管理サイト</strong>で行います（メール管理側の直接同期は停止中）。</div>
</div>

<section class="card">
  <div class="card__header">
    <div class="card__title"><?php echo fitsc_icon('building'); ?>団体一覧 <small><?php echo count($organizations); ?>件</small></div>
    <form method="get" class="cluster">
      <div class="search-toggle" data-search-toggle data-submit-on-clear>
        <button type="button" class="btn btn--ghost btn--icon search-toggle__button" aria-label="団体を検索"><?php echo fitsc_icon('search'); ?></button>
        <div class="search-toggle__field">
          <?php echo fitsc_icon('search'); ?>
          <input type="search" name="q" value="<?php echo mail_h($keyword); ?>" placeholder="識別番号・団体名・メール" aria-label="団体を検索">
          <button type="button" class="btn btn--ghost btn--icon search-toggle__clear" aria-label="検索を閉じる"><?php echo fitsc_icon('x'); ?></button>
        </div>
      </div>
      <select name="active" data-autosubmit aria-label="状態で絞り込み" style="width:auto">
        <option value="all"<?php echo mail_selected($active, 'all'); ?>>すべて</option>
        <option value="active"<?php echo mail_selected($active, 'active'); ?>>有効のみ</option>
        <option value="inactive"<?php echo mail_selected($active, 'inactive'); ?>>無効のみ</option>
      </select>
      <noscript><button type="submit" class="btn">検索</button></noscript>
    </form>
  </div>
  <?php if ($organizations === []): ?>
    <div class="empty"><span class="empty__icon"><?php echo fitsc_icon('building'); ?></span><span><?php echo $keyword !== '' ? '条件に一致する団体はありません。' : '団体データがありません。'; ?></span></div>
  <?php else: ?>
    <div class="table-wrap is-stacked">
      <table class="table">
        <thead><tr><th>団体</th><th>代表者</th><th>メール</th><th>区分</th><th>登録元</th><th>状態</th><th class="col-actions"></th></tr></thead>
        <tbody>
          <?php foreach ($organizations as $org): ?>
            <tr<?php echo $editOrganization && (int)$editOrganization['id'] === (int)$org['id'] ? ' class="is-selected"' : ''; ?>>
              <td><span class="cell-main"><?php echo mail_h((string)$org['name']); ?></span><span class="cell-sub"><code><?php echo mail_h((string)$org['identifier']); ?></code></span></td>
              <td><?php echo mail_h((string)$org['representative_name']); ?></td>
              <td class="allow-select"><?php echo mail_h((string)$org['email']); ?></td>
              <td><?php echo mail_h((string)$org['category']); ?></td>
              <td><?php echo (string)($org['external_source'] ?? '') === 'kintone' ? '<span class="badge badge--info">kintone</span>' : '<span class="text-subtle text-xs">手動・CSV</span>'; ?></td>
              <td><?php echo mail_active_badge($org['is_active']); ?></td>
              <td class="col-actions">
                <span class="menu-anchor">
                  <button type="button" class="btn btn--ghost btn--icon btn--sm" data-menu-toggle aria-haspopup="menu" aria-expanded="false" aria-label="<?php echo mail_h((string)$org['name']); ?> の操作"><?php echo fitsc_icon('more'); ?></button>
                  <div class="menu" role="menu">
                    <a class="menu__item" role="menuitem" href="organizations.php?edit=<?php echo (int)$org['id']; ?>"><?php echo fitsc_icon('pen'); ?>編集</a>
                    <form method="post">
                      <?php echo mail_auth_csrf_field(); ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?php echo (int)$org['id']; ?>">
                      <input type="hidden" name="is_active" value="<?php echo (int)$org['is_active'] === 1 ? 0 : 1; ?>">
                      <button type="submit" class="menu__item" role="menuitem"<?php echo $canEditOrg ? '' : ' disabled'; ?>><?php echo fitsc_icon((int)$org['is_active'] === 1 ? 'eye-off' : 'eye'); ?><?php echo (int)$org['is_active'] === 1 ? '無効化' : '有効化'; ?></button>
                    </form>
                    <div class="menu__sep"></div>
                    <form method="post" data-confirm="団体「<?php echo mail_h((string)$org['name']); ?>」を削除します。過去のバッチや添付に紐付いている場合は、履歴側の紐付けが解除されます。" data-confirm-title="団体を削除しますか？" data-confirm-ok="削除する">
                      <?php echo mail_auth_csrf_field(); ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$org['id']; ?>">
                      <button type="submit" class="menu__item menu__item--danger" role="menuitem"<?php echo $canEditOrg ? '' : ' disabled'; ?>><?php echo fitsc_icon('trash'); ?>削除</button>
                    </form>
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

<dialog class="modal modal--lg" id="orgEditDialog" aria-labelledby="orgEditTitle"<?php echo $editOrganization ? ' data-open-on-load' : ''; ?>>
  <form method="post" class="modal__panel" data-busy>
    <?php echo mail_auth_csrf_field(); ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">
    <div class="modal__header">
      <span class="modal__icon"><?php echo fitsc_icon($editOrganization ? 'edit' : 'building', 'ico--lg'); ?></span>
      <div><h2 class="modal__title" id="orgEditTitle"><?php echo $editOrganization ? '団体を編集' : '団体を追加'; ?></h2><p class="modal__desc">識別番号は重複できません。</p></div>
      <?php if ($editOrganization): ?>
        <a class="btn btn--ghost btn--icon modal__close" href="organizations.php" aria-label="閉じる"><?php echo fitsc_icon('x'); ?></a>
      <?php else: ?>
        <button type="button" class="btn btn--ghost btn--icon modal__close" data-modal-close aria-label="閉じる"><?php echo fitsc_icon('x'); ?></button>
      <?php endif; ?>
    </div>
    <div class="modal__body form-grid">
      <label class="field"><span>識別番号 <span class="req">必須</span></span><input type="text" name="identifier" value="<?php echo mail_h((string)$form['identifier']); ?>" required data-autofocus spellcheck="false"></label>
      <label class="field"><span>団体名 <span class="req">必須</span></span><input type="text" name="name" value="<?php echo mail_h((string)$form['name']); ?>" required></label>
      <label class="field"><span>代表者氏名</span><input type="text" name="representative_name" value="<?php echo mail_h((string)$form['representative_name']); ?>"></label>
      <label class="field"><span>メールアドレス</span><input type="email" name="email" value="<?php echo mail_h((string)$form['email']); ?>"></label>
      <label class="field"><span>区分</span><input type="text" name="category" value="<?php echo mail_h((string)$form['category']); ?>"></label>
      <label class="field"><span>状態</span><select name="is_active"><option value="1"<?php echo mail_selected($form['is_active'], 1); ?>>有効</option><option value="0"<?php echo mail_selected($form['is_active'], 0); ?>>無効</option></select></label>
      <label class="field span-full"><span>備考</span><textarea name="notes" rows="3"><?php echo mail_h((string)$form['notes']); ?></textarea></label>
    </div>
    <div class="modal__footer">
      <?php if ($editOrganization): ?>
        <a class="btn btn--ghost" href="organizations.php">キャンセル</a>
      <?php else: ?>
        <button type="button" class="btn btn--ghost" data-modal-close>キャンセル</button>
      <?php endif; ?>
      <button type="submit" class="btn btn--primary"<?php echo $canEditOrg ? '' : ' disabled'; ?>><?php echo fitsc_icon('save'); ?>保存</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="orgImportDialog" aria-labelledby="orgImportTitle">
  <form method="post" enctype="multipart/form-data" class="modal__panel" data-busy>
    <?php echo mail_auth_csrf_field(); ?>
    <input type="hidden" name="action" value="import_csv">
    <div class="modal__header">
      <span class="modal__icon"><?php echo fitsc_icon('upload', 'ico--lg'); ?></span>
      <div><h2 class="modal__title" id="orgImportTitle">CSV から取り込む</h2><p class="modal__desc">識別番号が一致する団体は更新、ない場合は追加します。</p></div>
      <button type="button" class="btn btn--ghost btn--icon modal__close" data-modal-close aria-label="閉じる"><?php echo fitsc_icon('x'); ?></button>
    </div>
    <div class="modal__body stack">
      <input type="file" name="csv_file" accept=".csv,text/csv" required aria-label="CSVファイル">
      <div class="small-note stack stack--sm">
        <span>列名（英語）：<code>identifier,name,representative_name,email,category,notes</code></span>
        <span>列名（日本語）：<code>識別番号,団体名,代表者氏名,メールアドレス,区分,備考</code></span>
      </div>
      <div><button type="button" class="btn btn--sm" data-download-href="./template_file/organizations_template.csv" data-download-name="organizations_template.csv"><?php echo fitsc_icon('download'); ?>CSV のひな形</button></div>
    </div>
    <div class="modal__footer">
      <button type="button" class="btn btn--ghost" data-modal-close>キャンセル</button>
      <button type="submit" class="btn btn--primary"<?php echo $canEditOrg ? '' : ' disabled'; ?>><?php echo fitsc_icon('upload'); ?>取り込む</button>
    </div>
  </form>
</dialog>
<?php endif; ?>
<?php
mail_render_page_footer();
