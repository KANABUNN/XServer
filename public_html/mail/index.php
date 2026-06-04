<?php

declare(strict_types=1);

require_once __DIR__ . '/../../apps/mail_core/bootstrap.php';
require_once __DIR__ . '/../../apps/mail_core/auth.php';
require_once __DIR__ . '/../../apps/mail_core/repository.php';

$user = mail_auth_require_login();
$csrfToken = mail_auth_get_csrf_token();

$mailDbReady = false;
$mailDbError = '';
$schemaStatus = ['ready' => false, 'missing' => [], 'tables' => []];
$counts = [
    'organizations' => 0,
    'templates' => 0,
    'batches' => 0,
    'draft_batches' => 0,
    'attachments_need_review' => 0,
    'send_logs' => 0,
];
$recentBatches = [];
$recentUploads = [];

try {
    $mailPdo = mail_pdo('mail');
    $schemaStatus = mail_schema_status($mailPdo);
    $mailDbReady = (bool)$schemaStatus['ready'];
    if ($mailDbReady) {
        $counts = mail_dashboard_counts($mailPdo);
        $recentBatches = mail_recent_batches($mailPdo, 8);
        $recentUploads = mail_recent_uploads($mailPdo, 8);
    }
} catch (Throwable $e) {
    $mailDbError = $e->getMessage();
}

$currentUser = [
    'id' => (int)($user['id'] ?? 0),
    'login_id' => (string)($user['login_id'] ?? ''),
    'display_name' => (string)($user['display_name'] ?? ''),
    'role_key' => (string)($user['role_key'] ?? 'viewer'),
    'role_label' => (string)($user['role_label'] ?? '閲覧者'),
    'role_keys' => array_values(array_map('strval', (array)($user['role_keys'] ?? []))),
    'permissions' => array_values(array_map('strval', (array)($user['permissions'] ?? []))),
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="mail-csrf-token" content="<?php echo mail_h($csrfToken); ?>">
  <title>メール半自動化管理 | mail.fit-sc.jp</title>
  <script>window.MailAdminBootstrap = <?php echo mail_json_encode(['currentUser' => $currentUser, 'csrfToken' => $csrfToken]); ?>;</script>
  <link rel="stylesheet" href="./css/mail-admin.css">
</head>
<body>
  <div class="admin-shell">
    <aside class="sidebar">
      <div class="sidebar-brand">
        <p class="brand-kicker">mail.fit-sc.jp</p>
        <h1>メール管理</h1>
        <p>団体DB / テンプレート / 添付 / 下書き生成</p>
      </div>

      <nav class="sidebar-nav" aria-label="管理機能">
        <button type="button" class="sidebar-link is-active" data-view-target="dashboardView">ダッシュボード</button>
        <button type="button" class="sidebar-link" data-view-target="organizationView">団体データ</button>
        <button type="button" class="sidebar-link" data-view-target="templateView">テンプレート</button>
        <button type="button" class="sidebar-link" data-view-target="batchView">送信バッチ</button>
        <button type="button" class="sidebar-link" data-view-target="attachmentView">添付ファイル</button>
        <button type="button" class="sidebar-link" data-view-target="settingsView">Graph連携</button>
      </nav>

      <div class="sidebar-user">
        <div class="sidebar-user-card">
          <strong><?php echo mail_h((string)($user['display_name'] ?? '')); ?></strong>
          <span><?php echo mail_h((string)($user['role_label'] ?? '閲覧者')); ?> / <?php echo mail_h((string)($user['login_id'] ?? '')); ?></span>
        </div>
        <form method="post" action="logout.php" class="sidebar-logout-form">
          <?php echo mail_auth_csrf_field(); ?>
          <button type="submit" class="sidebar-logout-link">ログアウト</button>
        </form>
      </div>
    </aside>

    <main class="content-shell">
      <section id="dashboardView" class="content-view is-active">
        <div class="wrap">
          <header class="page-head">
            <div>
              <h1>メール半自動化管理</h1>
              <p class="lead">団体別の差し込みメール、個別添付、Outlook下書き作成を管理するための基礎画面です。</p>
            </div>
            <div class="head-actions">
              <a class="secondary link-button" href="./api/health.php" target="_blank" rel="noopener">接続確認</a>
            </div>
          </header>

          <?php if ($mailDbError !== ''): ?>
            <div class="alert alert-danger">
              <strong>メールDBへ接続できません。</strong>
              <p><?php echo mail_h($mailDbError); ?></p>
              <p><code>apps/mail_core/config.sample.php</code> を <code>apps/mail_core/config.local.php</code> に複製し、DB情報を設定してください。</p>
            </div>
          <?php elseif (!$mailDbReady): ?>
            <div class="alert alert-warn">
              <strong>メールDBのテーブルが未作成です。</strong>
              <p><code>apps/mail_core/schema.sql</code> を phpMyAdmin などで取り込んでください。</p>
              <?php if (!empty($schemaStatus['missing'])): ?>
                <p>不足テーブル: <code><?php echo mail_h(implode(', ', $schemaStatus['missing'])); ?></code></p>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="alert alert-info">
              <strong>基礎構成は利用可能です。</strong>
              <p>次の段階で、団体CSV取込、テンプレート編集、添付ZIP取込、Graph API下書き作成の実処理を追加できます。</p>
            </div>
          <?php endif; ?>

          <div class="summary-grid">
            <article class="summary-card"><span>活動中団体</span><strong><?php echo (int)$counts['organizations']; ?></strong></article>
            <article class="summary-card"><span>テンプレート</span><strong><?php echo (int)$counts['templates']; ?></strong></article>
            <article class="summary-card"><span>送信バッチ</span><strong><?php echo (int)$counts['batches']; ?></strong></article>
            <article class="summary-card"><span>要確認添付</span><strong><?php echo (int)$counts['attachments_need_review']; ?></strong></article>
          </div>

          <div class="dashboard-grid">
            <section class="panel">
              <div class="panel-head">
                <h2>最近の送信バッチ</h2>
                <span class="muted">下書き作成・送信単位</span>
              </div>
              <?php if ($recentBatches === []): ?>
                <p class="empty">まだ送信バッチはありません。</p>
              <?php else: ?>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>ID</th><th>名称</th><th>状態</th><th>対象数</th><th>作成日時</th></tr></thead>
                    <tbody>
                      <?php foreach ($recentBatches as $batch): ?>
                        <tr>
                          <td><?php echo (int)$batch['id']; ?></td>
                          <td><?php echo mail_h((string)$batch['title']); ?></td>
                          <td><span class="badge"><?php echo mail_h(mail_status_label((string)$batch['status'])); ?></span></td>
                          <td><?php echo (int)$batch['target_count']; ?></td>
                          <td><?php echo mail_h((string)$batch['created_at']); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </section>

            <section class="panel">
              <div class="panel-head">
                <h2>最近の添付アップロード</h2>
                <span class="muted">共通添付 / 個別添付 / ZIP</span>
              </div>
              <?php if ($recentUploads === []): ?>
                <p class="empty">まだアップロード履歴はありません。</p>
              <?php else: ?>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>ID</th><th>種別</th><th>ファイル数</th><th>状態</th><th>作成日時</th></tr></thead>
                    <tbody>
                      <?php foreach ($recentUploads as $upload): ?>
                        <tr>
                          <td><?php echo (int)$upload['id']; ?></td>
                          <td><?php echo mail_h((string)$upload['upload_kind']); ?></td>
                          <td><?php echo (int)$upload['file_count']; ?></td>
                          <td><span class="badge"><?php echo mail_h(mail_status_label((string)$upload['status'])); ?></span></td>
                          <td><?php echo mail_h((string)$upload['created_at']); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </section>
          </div>
        </div>
      </section>

      <section id="organizationView" class="content-view">
        <div class="wrap">
          <header class="page-head"><div><h1>団体データ</h1><p class="lead">団体名、代表者、メールアドレス、識別番号を管理します。</p></div></header>
          <section class="panel feature-panel">
            <h2>実装予定</h2>
            <p>CSV/Excelからのインポート、識別番号の重複チェック、活動中団体だけを送信対象にする機能を追加する領域です。</p>
            <pre>identifier,name,representative_name,email,category</pre>
          </section>
        </div>
      </section>

      <section id="templateView" class="content-view">
        <div class="wrap">
          <header class="page-head"><div><h1>テンプレート</h1><p class="lead">件名・本文に変数を埋め込み、団体データで差し込みます。</p></div></header>
          <section class="panel feature-panel">
            <h2>変数例</h2>
            <p><code>{{団体名}}</code> <code>{{代表者氏名}}</code> <code>{{識別番号}}</code> <code>{{メールアドレス}}</code></p>
            <textarea class="template-sample" readonly>{{団体名}}
{{代表者氏名}} 様

平素よりお世話になっております。
添付資料をご確認ください。

識別番号：{{識別番号}}</textarea>
          </section>
        </div>
      </section>

      <section id="batchView" class="content-view">
        <div class="wrap">
          <header class="page-head"><div><h1>送信バッチ</h1><p class="lead">対象団体、使用テンプレート、添付ファイルをまとめて1つの処理単位にします。</p></div></header>
          <section class="panel feature-panel">
            <h2>推奨フロー</h2>
            <ol class="flow-list"><li>対象団体を選択</li><li>テンプレートを選択</li><li>共通添付・個別添付を登録</li><li>全件プレビュー</li><li>Outlook下書き作成</li></ol>
          </section>
        </div>
      </section>

      <section id="attachmentView" class="content-view">
        <div class="wrap">
          <header class="page-head"><div><h1>添付ファイル</h1><p class="lead">個別添付はファイル名規則または manifest.csv で団体へ対応付けます。</p></div></header>
          <section class="panel feature-panel">
            <h2>推奨ファイル名</h2>
            <p><code>識別番号__資料種別__団体名.pdf</code></p>
            <p class="muted">例: <code>C001__代表者確認書__数学研究会.pdf</code></p>
          </section>
        </div>
      </section>

      <section id="settingsView" class="content-view">
        <div class="wrap">
          <header class="page-head"><div><h1>Graph連携</h1><p class="lead">Microsoft Graph APIでOutlook下書き作成・送信を行うための設定領域です。</p></div></header>
          <section class="panel feature-panel">
            <h2>現在の方針</h2>
            <p>初期段階では即時送信ではなく、Outlook下書き作成を既定とします。GraphのテナントID、クライアントID、送信用メールボックスは <code>apps/mail_core/config.local.php</code> に設定します。</p>
          </section>
        </div>
      </section>
    </main>
  </div>

  <script src="./js/mail-admin.js"></script>
</body>
</html>
