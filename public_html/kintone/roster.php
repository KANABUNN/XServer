<?php

declare(strict_types=1);

require_once __DIR__ . '/_roster.php';
$user = kintone_auth_require_view_access();

$filters = [
    'fiscal_year' => (int)($_GET['fiscal_year'] ?? 0),
    'form_id' => (int)($_GET['form_id'] ?? 0),
    'state' => (string)($_GET['state'] ?? ''),
    'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100, 'UTF-8'),
];
$loadError = '';
$cases = [];
$counts = [];
$years = [];
$heartbeat = null;
try {
    $pdo = roster_db();
    $years = array_map('intval', $pdo->query('SELECT DISTINCT fiscal_year FROM roster_cases ORDER BY fiscal_year DESC')->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($filters['fiscal_year'] === 0 && $years !== []) {
        $filters['fiscal_year'] = $years[0];
    }
    $counts = roster_staff_counts($filters['fiscal_year'] ?: null);
    $cases = roster_staff_list($filters);
    $heartbeat = roster_worker_heartbeat_status($pdo);
} catch (Throwable $e) {
    error_log('[kintone roster list] ' . $e->getMessage());
    $loadError = '名簿の提出情報を読み込めませんでした（fitsc_forms への接続・テーブルを確認してください）。';
}

$stat = static function (string $state, string $label, string $icon, string $tone, int $count) use ($filters): string {
    $active = $filters['state'] === $state;
    $href = 'roster.php?' . http_build_query(['fiscal_year' => $filters['fiscal_year'] ?: null, 'state' => $active ? null : $state]);
    return '<a class="stat stat--compact ' . ($count > 0 ? 'stat--' . $tone : '') . ' kt-stat-link' . ($active ? ' is-active' : '') . '" href="' . kintone_h($href) . '"' . ($active ? ' aria-current="true"' : '') . '>'
        . '<span class="stat__label"><span class="stat__icon">' . fitsc_icon($icon) . '</span>' . kintone_h($label) . '</span>'
        . '<span class="stat__value">' . $count . '</span></a>';
};

kintone_page_header('名簿の提出・承認', $user, ['subtitle' => 'Forms で提出された部員名簿を確認し、差し戻し・accept します。accept した内容だけが自動で kintone の部員名簿へ反映されます。']);
kintone_render_flash();
?>
<?php if ($loadError !== ''): ?>
  <div class="alert alert--danger"><?= fitsc_icon('alert-circle') ?><div class="alert__body"><?= kintone_h($loadError) ?></div></div>
<?php else: ?>
  <?php if (is_array($heartbeat) && $heartbeat['is_stale']): ?>
    <div class="alert alert--warning"><?= fitsc_icon('alert-triangle') ?><div class="alert__body"><strong>反映ワーカーが最近動いていません。</strong>
      最終実行: <?= kintone_h((string)($heartbeat['row']['last_finished_at'] ?? $heartbeat['row']['last_started_at'] ?? '記録なし')) ?>。accept した名簿が反映されないため、cron の設定を確認してください（<a href="roster_settings.php">反映・接続の確認</a>）。</div></div>
  <?php endif; ?>

  <div class="stat-grid kt-roster-stats">
    <?= $stat('submitted', '確認待ち', 'inbox', 'info', (int)($counts['submitted'] ?? 0)) ?>
    <?= $stat('returned', '差し戻し中', 'return', 'warning', (int)($counts['returned'] ?? 0)) ?>
    <?= $stat('queued', '反映待ち', 'clock', 'info', (int)($counts['queued'] ?? 0) + (int)($counts['running'] ?? 0)) ?>
    <?= $stat('failed', '反映失敗', 'x-circle', 'danger', (int)($counts['failed'] ?? 0)) ?>
    <?= $stat('conflict', '競合', 'alert-triangle', 'warning', (int)($counts['conflict'] ?? 0)) ?>
    <?= $stat('unknown', '結果確認待ち', 'help', 'warning', (int)($counts['unknown'] ?? 0)) ?>
    <?= $stat('applied', '反映済み', 'check-circle', 'success', (int)($counts['applied'] ?? 0)) ?>
  </div>

  <section class="card">
    <div class="card__header">
      <div class="card__title"><?= fitsc_icon('users') ?>提出一覧</div>
      <form class="cluster" method="get" action="roster.php">
        <select name="fiscal_year" aria-label="年度" data-autosubmit>
          <?php if ($years === []): ?><option value="">年度</option><?php endif; ?>
          <?php foreach ($years as $y): ?><option value="<?= $y ?>"<?= $y === $filters['fiscal_year'] ? ' selected' : '' ?>><?= $y ?>年度</option><?php endforeach; ?>
        </select>
        <select name="state" aria-label="状態" data-autosubmit>
          <?php foreach (['' => 'すべて', 'attention' => '要対応（確認待ち・失敗・競合・結果確認待ち）', 'submitted' => '確認待ち', 'returned' => '差し戻し中', 'draft' => '下書きのみ', 'queued' => '反映待ち', 'running' => '反映中', 'failed' => '反映失敗', 'conflict' => '競合', 'unknown' => '結果確認待ち', 'applied' => '反映済み'] as $value => $label): ?>
            <option value="<?= kintone_h($value) ?>"<?= $filters['state'] === $value ? ' selected' : '' ?>><?= kintone_h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="input-icon"><?= fitsc_icon('search') ?><input type="search" name="q" value="<?= kintone_h($filters['q']) ?>" placeholder="識別記号・団体名"></span>
        <button type="submit" class="btn btn--sm"><?= fitsc_icon('filter') ?>絞り込む</button>
      </form>
    </div>
    <?php if ($cases === []): ?>
      <div class="empty"><span class="empty__icon"><?= fitsc_icon('inbox') ?></span><span>該当する提出はありません。</span></div>
    <?php else: ?>
      <div class="table-wrap is-stacked kt-table-flat">
        <table class="table">
          <thead><tr><th>団体</th><th>年度・フォーム</th><th>最新の提出</th><th>審査</th><th>反映</th><th>更新</th></tr></thead>
          <tbody>
          <?php foreach ($cases as $c): ?>
            <tr class="kt-row-link" data-href="roster_detail.php?case_id=<?= (int)$c['id'] ?>">
              <td><a class="cell-main" href="roster_detail.php?case_id=<?= (int)$c['id'] ?>"><?= kintone_h($c['group_name']) ?></a><span class="cell-sub mono"><?= kintone_h($c['group_code']) ?> ・ 提出ID <?= (int)$c['id'] ?></span></td>
              <td><?= (int)$c['fiscal_year'] ?>年度<span class="cell-sub"><?= kintone_h((string)($c['form_name'] ?? '')) ?></span></td>
              <td><?= (int)$c['latest_revision_number'] > 0 ? '第' . (int)$c['latest_revision_number'] . '版' : '<span class="text-subtle">未提出</span>' ?><span class="cell-sub"><?= kintone_h((string)($c['last_submitted_at'] ?? '')) ?><?= (int)$c['has_draft'] === 1 ? ' ・ 下書きあり' : '' ?></span></td>
              <td><?= kt_roster_review_badge((string)$c['review_state']) ?></td>
              <td><?= ((string)$c['apply_state'] !== ROSTER_APPLY_NONE || (string)$c['review_state'] === ROSTER_REVIEW_ACCEPTED) ? kt_roster_apply_badge((string)$c['apply_state']) : '<span class="text-subtle">—</span>' ?><?php if (!empty($c['last_applied_at'])): ?><span class="cell-sub">反映 <?= kintone_h((string)$c['last_applied_at']) ?></span><?php endif; ?></td>
              <td class="text-xs nowrap"><?= kintone_h((string)$c['updated_at']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php kintone_page_footer(); ?>
