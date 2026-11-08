<?php

declare(strict_types=1);

require_once __DIR__ . '/_roster.php';
$user = kintone_auth_require_view_access();
$canOperate = kintone_user_can_operate($user);
$isAdmin = kintone_auth_user_has_role($user, 'admin');

$rosterKey = (string)roster_config('kintone.roster_app_key');
$groupKey = (string)roster_config('kintone.group_app_key');
$apps = [];
try {
    $stmt = kintone_pdo('org')->prepare('SELECT a.app_key, a.display_name, a.kintone_app_id, a.app_role, a.is_active, a.status, a.last_tested_at, c.api_token_encrypted IS NOT NULL AND c.api_token_encrypted <> "" AS has_token FROM kintone_apps a LEFT JOIN kintone_credentials c ON c.connection_key = a.app_key WHERE a.app_key IN (:r, :g)');
    $stmt->execute([':r' => $rosterKey, ':g' => $groupKey]);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $apps[(string)$row['app_key']] = $row;
    }
} catch (Throwable $e) {
    error_log('[kintone roster settings apps] ' . $e->getMessage());
}

$preflight = null;
$preflightError = '';
$checkFields = isset($_GET['check']);
if ($checkFields) {
    try {
        $preflight = roster_kintone_preflight();
    } catch (Throwable $e) {
        $preflightError = mb_substr($e->getMessage(), 0, 300, 'UTF-8');
    }
}
$heartbeat = null;
$directory = null;
$jobCounts = [];
$dbError = '';
try {
    $pdo = roster_db();
    $heartbeat = roster_worker_heartbeat_status($pdo);
    $directory = roster_directory_status($pdo);
    $jobCounts = $pdo->query('SELECT status, COUNT(*) AS n FROM roster_apply_jobs GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
} catch (Throwable $e) {
    error_log('[kintone roster settings db] ' . $e->getMessage());
    $dbError = 'fitsc_forms の名簿テーブルに接続できません。DB接続設定（db_connections.forms）と移行SQLの適用を確認してください。';
}
$projectRoot = dirname(__DIR__, 2);

kintone_page_header('反映・接続の確認', $user, ['subtitle' => '部員名簿の反映に必要な kintone の接続・フィールド・定期実行の状態を確認します。']);
kintone_render_flash();
?>
<?php if ($dbError !== ''): ?><div class="alert alert--danger"><?= fitsc_icon('alert-circle') ?><div class="alert__body"><?= kintone_h($dbError) ?></div></div><?php endif; ?>

<div class="split">
  <section class="card">
    <div class="card__header"><div class="card__title"><?= fitsc_icon('cloud') ?>kintone 接続</div><?php if ($isAdmin): ?><a class="btn btn--ghost btn--sm" href="settings.php"><?= fitsc_icon('settings') ?>アプリ設定</a><?php endif; ?></div>
    <div class="card__body stack">
      <?php foreach ([[$rosterKey, 'ID20 部員名簿（反映先）', '閲覧・追加・編集'], [$groupKey, 'ID7 公認団体・組織（団体一覧・ルックアップ参照）', '閲覧']] as [$key, $label, $perm]): $app = $apps[$key] ?? null; ?>
        <div class="list-item">
          <span class="list-item__icon"><?= fitsc_icon('database', 'ico--sm') ?></span>
          <span class="list-item__main">
            <span class="list-item__title"><?= kintone_h($label) ?></span>
            <span class="list-item__meta">app_key <span class="mono"><?= kintone_h($key) ?></span><?= is_array($app) ? ' ・ アプリID ' . (int)$app['kintone_app_id'] . ' ・ 同期ロール ' . kintone_h((string)$app['app_role']) : '' ?> ・ 必要なトークン権限: <?= kintone_h($perm) ?></span>
          </span>
          <span class="list-item__side">
            <?php if (!is_array($app)): ?><?= kt_roster_badge('未登録', 'danger') ?>
            <?php elseif ((int)$app['is_active'] !== 1): ?><?= kt_roster_badge('無効', 'danger') ?>
            <?php elseif ((int)$app['has_token'] !== 1): ?><?= kt_roster_badge('トークン未登録', 'danger') ?>
            <?php else: ?><?= kt_roster_badge('登録済み', 'success') ?><?php endif; ?>
            <?php if (is_array($app) && $key === $rosterKey && (string)$app['app_role'] !== 'manual'): ?><?= kt_roster_badge('同期ロールは manual にしてください', 'warning') ?><?php endif; ?>
          </span>
        </div>
      <?php endforeach; ?>
      <p class="small-note">部員名簿アプリ（<?= kintone_h($rosterKey) ?>）は汎用の同期（push/pull）からは更新・取得できないようにしています。APIトークンはアプリ設定画面で登録してください（この画面には表示しません）。</p>
    </div>
  </section>

  <section class="card">
    <div class="card__header"><div class="card__title"><?= fitsc_icon('activity') ?>反映ワーカー（定期実行）</div></div>
    <div class="card__body stack">
      <?php $row = is_array($heartbeat) ? $heartbeat['row'] : null; ?>
      <div class="cluster">
        <?= is_array($heartbeat) && !$heartbeat['is_stale'] ? kt_roster_badge('稼働中', 'success') : kt_roster_badge('最近の実行なし', 'danger') ?>
        <span class="text-sm">最終実行: <?= kintone_h((string)($row['last_finished_at'] ?? $row['last_started_at'] ?? '記録なし')) ?><?= isset($heartbeat['age_minutes']) && $heartbeat['age_minutes'] !== null ? '（' . (int)$heartbeat['age_minutes'] . '分前）' : '' ?></span>
        <?php if ($row): ?><?= kt_roster_badge((string)$row['last_status'], (string)$row['last_status'] === 'ok' ? 'success' : ((string)$row['last_status'] === 'running' ? 'info' : 'danger')) ?><?php endif; ?>
      </div>
      <?php if ($row && (string)($row['last_summary'] ?? '') !== ''): ?><code class="code-block allow-select"><?= kintone_h((string)$row['last_summary']) ?></code><?php endif; ?>
      <dl class="dl dl--grid">
        <?php foreach ([ROSTER_JOB_QUEUED, ROSTER_JOB_RUNNING, ROSTER_JOB_UNKNOWN, ROSTER_JOB_FAILED, ROSTER_JOB_CONFLICT, ROSTER_JOB_APPLIED] as $s): ?>
          <div><dt><?= kintone_h(roster_apply_state_label($s)) ?></dt><dd class="num"><?= (int)($jobCounts[$s] ?? 0) ?></dd></div>
        <?php endforeach; ?>
      </dl>
      <p class="small-note">cron（5分ごとの例）：</p>
      <code class="code-block allow-select">*/5 * * * * /usr/bin/php <?= kintone_h($projectRoot) ?>/apps/jobs/roster_kintone_apply.php &gt;&gt; <?= kintone_h($projectRoot) ?>/apps/kintone_core/storage/roster_worker_cron.log 2&gt;&amp;1</code>
    </div>
  </section>
</div>

<section class="card">
  <div class="card__header">
    <div><div class="card__title"><?= fitsc_icon('list-checks') ?>ID20 のフィールド設定</div><p class="card__subtitle">反映前にも毎回自動で確認します。不足・選択肢の不一致があると反映を「設定エラー」で止め、accept 済みの内容は保持します。</p></div>
    <a class="btn btn--sm" href="roster_settings.php?check=1"><?= fitsc_icon('search') ?>いま確認する</a>
  </div>
  <div class="card__body stack">
    <?php if (!$checkFields): ?>
      <p class="text-subtle">「いま確認する」を押すと kintone からフィールド設定を取得して確認します。</p>
    <?php elseif ($preflightError !== ''): ?>
      <div class="alert alert--danger"><?= fitsc_icon('alert-circle') ?><div class="alert__body"><?= kintone_h($preflightError) ?></div></div>
    <?php elseif (is_array($preflight)): ?>
      <?php if ($preflight['ok']): ?>
        <div class="alert alert--success"><?= fitsc_icon('check-circle') ?><div class="alert__body">必要なフィールドと選択肢がそろっています（アプリID <?= (int)$preflight['app_id'] ?>）。</div></div>
      <?php else: ?>
        <div class="alert alert--danger"><?= fitsc_icon('alert-circle') ?><div class="alert__body"><strong>反映できません</strong><ul class="kt-plain-list"><?php foreach ($preflight['errors'] as $msg): ?><li><?= kintone_h($msg) ?></li><?php endforeach; ?></ul></div></div>
      <?php endif; ?>
      <?php if ($preflight['warnings'] !== []): ?>
        <div class="alert alert--warning"><?= fitsc_icon('alert-triangle') ?><div class="alert__body"><ul class="kt-plain-list"><?php foreach ($preflight['warnings'] as $msg): ?><li><?= kintone_h($msg) ?></li><?php endforeach; ?></ul></div></div>
      <?php endif; ?>
    <?php endif; ?>
    <div class="table-wrap is-stacked kt-table-flat">
      <table class="table table--compact">
        <thead><tr><th>項目</th><th>フィールドコード</th><th>型</th><th>選択肢</th></tr></thead>
        <tbody>
          <tr><td>状態</td><td class="mono"><?= kintone_h(roster_kintone_field_code('table')) ?>.<?= kintone_h(roster_kintone_field_code('membership_status')) ?></td><td>ドロップダウン</td><td><?= kintone_h(implode(' / ', roster_status_options())) ?>（初期値なし・必須にしない）</td></tr>
          <tr><td>団体メール使用者</td><td class="mono"><?= kintone_h(roster_kintone_field_code('table')) ?>.<?= kintone_h(roster_kintone_field_code('uses_group_email')) ?></td><td>チェックボックス</td><td><?= kintone_h((string)roster_config('kintone.checkbox_options.uses_group_email')) ?></td></tr>
          <tr><td>HP更新担当者</td><td class="mono"><?= kintone_h(roster_kintone_field_code('table')) ?>.<?= kintone_h(roster_kintone_field_code('is_website_editor')) ?></td><td>チェックボックス</td><td><?= kintone_h((string)roster_config('kintone.checkbox_options.is_website_editor')) ?></td></tr>
          <tr><td>役職</td><td class="mono"><?= kintone_h(roster_kintone_field_code('table')) ?>.<?= kintone_h(roster_kintone_field_code('role')) ?></td><td>ドロップダウン（既存）</td><td><?= kintone_h(implode(' / ', roster_role_values())) ?></td></tr>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="card">
  <div class="card__header">
    <div><div class="card__title"><?= fitsc_icon('building') ?>団体一覧（ID7 のキャッシュ）</div><p class="card__subtitle">Forms の団体選択と、確認コードの送信先（ID7 の団体メール）に使います。ワーカーが <?= (int)roster_config('groups.directory_max_age_minutes', 360) ?> 分ごとに自動更新します。</p></div>
    <?php if ($canOperate): ?>
      <form method="post" action="api/roster_action.php"><?= kintone_auth_csrf_field() ?><input type="hidden" name="action" value="refresh_groups"><button type="submit" class="btn btn--sm" data-busy><?= fitsc_icon('refresh') ?>いま更新する</button></form>
    <?php endif; ?>
  </div>
  <div class="card__body">
    <?php if (is_array($directory)): ?>
      <dl class="dl dl--grid">
        <div><dt>登録団体</dt><dd class="num"><?= (int)$directory['present'] ?></dd></div>
        <div><dt>最終更新</dt><dd><?= kintone_h($directory['refreshed_at'] ?: '未取得') ?></dd></div>
        <div><dt>対象の格</dt><dd><?= kintone_h(implode('・', (array)roster_config('groups.allowed_ranks', []))) ?></dd></div>
        <div><dt>対象の活動状況</dt><dd><?= kintone_h(implode('・', (array)roster_config('groups.allowed_statuses', []))) ?></dd></div>
      </dl>
      <?php if ($directory['is_stale']): ?><p class="small-note text-warning"><?= fitsc_icon('alert-triangle', 'ico--sm') ?>団体一覧が古いか未取得です。</p><?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php kintone_page_footer(); ?>
