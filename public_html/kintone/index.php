<?php

declare(strict_types=1);

require_once __DIR__ . '/_roster.php';
$user = kintone_auth_require_view_access();
$isAdmin = kintone_auth_user_has_role($user, 'admin');

$counts = null;
$heartbeat = null;
$directory = null;
$attention = [];
try {
    $pdo = roster_db();
    $counts = roster_staff_counts();
    $heartbeat = roster_worker_heartbeat_status($pdo);
    $directory = roster_directory_status($pdo);
    $attention = array_slice(roster_staff_list(['state' => 'attention']), 0, 8);
} catch (Throwable $e) {
    error_log('[kintone dashboard roster] ' . $e->getMessage());
}

$stat = static function (string $href, string $label, string $icon, string $tone, int $count): string {
    return '<a class="stat ' . ($count > 0 && $tone !== '' ? 'stat--' . $tone : '') . ' kt-stat-link" href="' . kintone_h($href) . '">'
        . '<span class="stat__label"><span class="stat__icon">' . fitsc_icon($icon) . '</span>' . kintone_h($label) . '</span>'
        . '<span class="stat__value">' . $count . '</span></a>';
};

kintone_page_header('ダッシュボード', $user, [
    'actions' => '<a class="btn btn--primary hide-mobile" href="roster.php">' . fitsc_icon('users') . '名簿の提出・承認</a>',
]);
kintone_render_flash();
?>
<?php if (!is_array($counts)): ?>
  <div class="alert alert--danger"><?= fitsc_icon('alert-circle') ?><div class="alert__body">名簿の提出情報を読み込めませんでした。<a href="roster_settings.php">反映・接続の確認</a>を開いてください。</div></div>
<?php else: ?>
  <?php if (is_array($heartbeat) && $heartbeat['is_stale']): ?>
    <div class="alert alert--warning"><?= fitsc_icon('alert-triangle') ?><div class="alert__body"><strong>反映ワーカーが最近動いていません。</strong>accept した名簿が kintone へ反映されないため、cron の設定を確認してください（<a href="roster_settings.php">反映・接続の確認</a>）。</div></div>
  <?php endif; ?>

  <div class="stat-grid">
    <?= $stat('roster.php?state=submitted', '確認待ち', 'inbox', 'info', (int)$counts['submitted']) ?>
    <?= $stat('roster.php?state=queued', '反映待ち', 'clock', 'info', (int)$counts['queued'] + (int)$counts['running']) ?>
    <?= $stat('roster.php?state=attention', '失敗・競合・結果確認待ち', 'alert-triangle', 'danger', (int)$counts['failed'] + (int)$counts['conflict'] + (int)$counts['unknown']) ?>
    <?= $stat('roster.php?state=applied', '反映済み', 'check-circle', 'success', (int)$counts['applied']) ?>
  </div>

  <section class="card">
    <div class="card__header card__header--plain">
      <div class="card__title"><?= fitsc_icon('sparkles') ?>部員名簿の流れ</div>
    </div>
    <div class="card__body">
      <ol class="kt-flow">
        <li><a href="roster.php"><span class="kt-flow__icon"><?= fitsc_icon('file-text') ?></span><strong>団体が提出</strong><small>Forms の部員名簿フォーム</small></a></li>
        <li><a href="roster.php?state=submitted"><span class="kt-flow__icon"><?= fitsc_icon('diff') ?></span><strong>差分の確認</strong><small>kintone の現在値と比較</small></a></li>
        <li><a href="roster.php?state=returned"><span class="kt-flow__icon"><?= fitsc_icon('return') ?></span><strong>差し戻し</strong><small>理由を付けて修正を依頼</small></a></li>
        <li><a href="roster.php?state=queued"><span class="kt-flow__icon"><?= fitsc_icon('check-circle') ?></span><strong>accept</strong><small>反映を予約</small></a></li>
        <li><a href="roster.php?state=applied"><span class="kt-flow__icon"><?= fitsc_icon('refresh') ?></span><strong>自動反映</strong><small>定期実行で kintone ID20 へ</small></a></li>
      </ol>
    </div>
  </section>

  <div class="split">
    <section class="card">
      <div class="card__header">
        <div class="card__title"><?= fitsc_icon('alert-triangle') ?>対応が必要な提出</div>
        <a class="btn btn--ghost btn--sm" href="roster.php?state=attention">すべて見る<?= fitsc_icon('arrow-right') ?></a>
      </div>
      <?php if ($attention === []): ?>
        <div class="empty"><span class="empty__icon"><?= fitsc_icon('check-circle') ?></span><span>対応が必要な提出はありません。</span></div>
      <?php else: ?>
        <div class="list">
          <?php foreach ($attention as $c): ?>
            <a class="list-item" href="roster_detail.php?case_id=<?= (int)$c['id'] ?>">
              <span class="list-item__icon"><?= fitsc_icon('users', 'ico--sm') ?></span>
              <span class="list-item__main">
                <span class="list-item__title"><?= kintone_h($c['group_name']) ?></span>
                <span class="list-item__meta"><span class="mono"><?= kintone_h($c['group_code']) ?></span> ・ <?= (int)$c['fiscal_year'] ?>年度 ・ 第<?= (int)$c['latest_revision_number'] ?>版</span>
              </span>
              <span class="list-item__side"><?= (string)$c['review_state'] === ROSTER_REVIEW_SUBMITTED ? kt_roster_review_badge((string)$c['review_state']) : kt_roster_apply_badge((string)$c['apply_state']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card__header">
        <div class="card__title"><?= fitsc_icon('activity') ?>反映・接続</div>
        <a class="btn btn--ghost btn--sm" href="roster_settings.php">詳細<?= fitsc_icon('arrow-right') ?></a>
      </div>
      <div class="card__body">
        <dl class="dl dl--grid">
          <div><dt>反映ワーカー</dt><dd><?= is_array($heartbeat) && !$heartbeat['is_stale'] ? kt_roster_badge('稼働中', 'success') : kt_roster_badge('最近の実行なし', 'danger') ?></dd></div>
          <div><dt>最終実行</dt><dd class="text-sm"><?= kintone_h((string)($heartbeat['row']['last_finished_at'] ?? $heartbeat['row']['last_started_at'] ?? '記録なし')) ?></dd></div>
          <div><dt>団体一覧（ID7）</dt><dd class="num"><?= (int)($directory['present'] ?? 0) ?>団体</dd></div>
          <div><dt>団体一覧の更新</dt><dd class="text-sm"><?= kintone_h((string)($directory['refreshed_at'] ?? '') ?: '未取得') ?></dd></div>
        </dl>
        <?php if ($isAdmin): ?><p class="small-note"><a href="settings.php"><?= fitsc_icon('settings', 'ico--sm') ?>アプリ設定（APIトークン）</a></p><?php endif; ?>
      </div>
    </section>
  </div>
<?php endif; ?>
<?php kintone_page_footer(); ?>
