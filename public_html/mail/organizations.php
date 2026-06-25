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

mail_render_page_header('団体データ', $user, 'organizations.php');
?>
<header class="page-head">
  <div>
    <h1>団体データ</h1>
    <p class="lead">メール差し込みと添付ファイル対応付けに使う団体台帳です。識別番号はファイル名照合にも使います。</p>
  </div>
</header>
<?php mail_render_db_error($dbError); ?>

<?php if ($dbError === ''): ?>
<div class="two-column-grid">
  <section class="panel">
    <div class="panel-head"><h2><?php echo $editOrganization ? '団体を編集' : '団体を追加'; ?></h2><span class="muted">識別番号は重複不可</span></div>
    <form method="post" class="form-grid">
      <?php echo mail_auth_csrf_field(); ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">
      <label><span>識別番号 *</span><input type="text" name="identifier" value="<?php echo mail_h((string)$form['identifier']); ?>" required></label>
      <label><span>団体名 *</span><input type="text" name="name" value="<?php echo mail_h((string)$form['name']); ?>" required></label>
      <label><span>代表者氏名</span><input type="text" name="representative_name" value="<?php echo mail_h((string)$form['representative_name']); ?>"></label>
      <label><span>メールアドレス</span><input type="email" name="email" value="<?php echo mail_h((string)$form['email']); ?>"></label>
      <label><span>区分</span><input type="text" name="category" value="<?php echo mail_h((string)$form['category']); ?>"></label>
      <label><span>状態</span><select name="is_active"><option value="1"<?php echo mail_selected($form['is_active'], 1); ?>>有効</option><option value="0"<?php echo mail_selected($form['is_active'], 0); ?>>無効</option></select></label>
      <label class="full"><span>備考</span><textarea name="notes" rows="4"><?php echo mail_h((string)$form['notes']); ?></textarea></label>
      <div class="form-actions full">
        <button type="submit" class="primary"<?php echo mail_auth_has_permission($user, 'organization.edit') ? '' : ' disabled'; ?>>保存</button>
        <?php if ($editOrganization): ?><button type="button" class="secondary link-button" data-nav-href="organizations.php">新規入力へ戻る</button><?php endif; ?>
      </div>
    </form>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>CSV取込</h2><span class="muted">追加・更新に対応</span></div>
    <p class="muted">ヘッダー例: <code>identifier,name,representative_name,email,category,notes</code></p>
    <p class="muted">日本語ヘッダーでは <code>識別番号,団体名,代表者氏名,メールアドレス,区分,備考</code> も利用できます。</p>
    <div class="template-downloads mt-14">
      <button type="button" class="link-button secondary" data-download-href="./template_file/organizations_template.csv" data-download-name="organizations_template.csv">団体CSVテンプレートをダウンロード</button>
    </div>
    <form method="post" enctype="multipart/form-data" class="stack-form mt-14">
      <?php echo mail_auth_csrf_field(); ?>
      <input type="hidden" name="action" value="import_csv">
      <input type="file" name="csv_file" accept=".csv,text/csv" required>
      <button type="submit" class="primary"<?php echo mail_auth_has_permission($user, 'organization.edit') ? '' : ' disabled'; ?>>CSVを取り込む</button>
    </form>
  </section>
</div>

<section class="panel mt-18">
  <div class="panel-head"><h2>kintone同期</h2><span class="muted">kintone管理サイトへ移管済み</span></div>
  <div class="kintone-sync-layout">
    <div>
      <p>団体データのkintone同期は <code>kintone.fit-sc.jp</code> のkintone管理サイトへ移管しました。</p>
      <p class="muted">mail側の直接同期は二重writerと未登場団体の自動無効化を避けるため停止しています。名簿アップロード・差分承認・mail_organizations反映はkintone管理サイトから実行してください。</p>
    </div>
    <button type="button" class="secondary" disabled>kintone直接同期は停止中</button>
  </div>
</section>

<section class="panel mt-18">
  <div class="panel-head"><h2>団体一覧</h2><span class="muted"><?php echo count($organizations); ?>件表示</span></div>
  <form method="get" class="search-row">
    <input type="search" name="q" value="<?php echo mail_h($keyword); ?>" placeholder="識別番号・団体名・メールで検索">
    <select name="active"><option value="all"<?php echo mail_selected($active, 'all'); ?>>すべて</option><option value="active"<?php echo mail_selected($active, 'active'); ?>>有効のみ</option><option value="inactive"<?php echo mail_selected($active, 'inactive'); ?>>無効のみ</option></select>
    <button type="submit" class="secondary">検索</button>
  </form>
  <div class="table-wrap mt-14">
    <table>
      <thead><tr><th>識別番号</th><th>団体名</th><th>代表者</th><th>メール</th><th>区分</th><th>同期元</th><th>状態</th><th>操作</th></tr></thead>
      <tbody>
        <?php if ($organizations === []): ?>
          <tr><td colspan="8" class="empty">団体データがありません。</td></tr>
        <?php endif; ?>
        <?php foreach ($organizations as $org): ?>
          <tr>
            <td><code><?php echo mail_h((string)$org['identifier']); ?></code></td>
            <td><?php echo mail_h((string)$org['name']); ?></td>
            <td><?php echo mail_h((string)$org['representative_name']); ?></td>
            <td><?php echo mail_h((string)$org['email']); ?></td>
            <td><?php echo mail_h((string)$org['category']); ?></td>
            <td><?php echo (string)($org['external_source'] ?? '') === 'kintone' ? '<span class="badge">kintone管理サイト</span>' : '<span class="muted">手動/CSV</span>'; ?></td>
            <td><span class="badge"><?php echo mail_h(mail_bool_label($org['is_active'])); ?></span></td>
            <td class="action-cell">
              <div class="org-action-stack">
                <button type="button" class="text-link" data-nav-href="organizations.php?edit=<?php echo (int)$org['id']; ?>">編集</button>
                <form method="post" class="inline-form">
                  <?php echo mail_auth_csrf_field(); ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?php echo (int)$org['id']; ?>">
                  <input type="hidden" name="is_active" value="<?php echo (int)$org['is_active'] === 1 ? 0 : 1; ?>">
                  <button type="submit" class="text-button"<?php echo mail_auth_has_permission($user, 'organization.edit') ? '' : ' disabled'; ?>><?php echo (int)$org['is_active'] === 1 ? '無効化' : '有効化'; ?></button>
                </form>
                <form method="post" class="inline-form" data-confirm="団体レコード「<?php echo mail_h((string)$org['name']); ?>」を削除します。過去バッチや添付に紐付いている場合は、履歴側の団体紐付けが解除されます。続行しますか？">
                  <?php echo mail_auth_csrf_field(); ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?php echo (int)$org['id']; ?>">
                  <button type="submit" class="text-button danger-text"<?php echo mail_auth_has_permission($user, 'organization.edit') ? '' : ' disabled'; ?>>削除</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
<?php
mail_render_page_footer();
