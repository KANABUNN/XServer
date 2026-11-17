<?php

declare(strict_types=1);

require_once __DIR__ . '/_roster.php';
$user = kintone_auth_require_view_access();
$canOperate = kintone_user_can_operate($user);
$isAdmin = kintone_auth_user_has_role($user, 'admin');

$caseId = (int)($_GET['case_id'] ?? 0);
$detail = null;
try {
    $detail = $caseId > 0 ? roster_staff_detail($caseId) : null;
} catch (Throwable $e) {
    error_log('[kintone roster detail] ' . $e->getMessage());
}
if (!is_array($detail)) {
    kintone_page_header('名簿の提出', $user);
    echo '<section class="card"><div class="empty"><span class="empty__icon">' . fitsc_icon('inbox') . '</span><span class="empty__title">提出が見つかりません</span><a class="btn btn--sm" href="roster.php">' . fitsc_icon('arrow-left') . '提出一覧へ</a></div></section>';
    kintone_page_footer();
    exit;
}
$case = $detail['case'];
$revisions = $detail['revisions'];
$latest = $revisions[0] ?? null;
$selectedNumber = (int)($_GET['rev'] ?? ($latest['revision_number'] ?? 0));
$selected = null;
foreach ($revisions as $rev) {
    if ((int)$rev['revision_number'] === $selectedNumber) {
        $selected = $rev;
    }
}
$selected = $selected ?? $latest;
$isLatestSelected = is_array($selected) && is_array($latest) && (int)$selected['id'] === (int)$latest['id'];
$payload = is_array($selected) ? roster_json_decode((string)$selected['payload_json'], []) : [];
$rows = (array)($payload['rows'] ?? []);
$base = is_array($selected) ? roster_json_decode((string)($selected['base_json'] ?? ''), null) : null;

$currentJob = null;
foreach ($detail['jobs'] as $job) {
    if ((int)$job['id'] === (int)$case['current_job_id']) {
        $currentJob = $job;
    }
}
$currentAcceptance = null;
foreach ($detail['acceptances'] as $acc) {
    if (is_array($currentJob) && (int)$acc['id'] === (int)$currentJob['acceptance_id']) {
        $currentAcceptance = $acc;
    }
}
$formFiscalYear = (int)($detail['form']['roster']['fiscal_year'] ?? 0);
$isPastYear = $formFiscalYear !== (int)$case['fiscal_year'];

// kintone の現在値との比較（最新の提出を確認中のときだけ。accept 可否の判断に使う）
$live = null;
$canReview = $canOperate && $isLatestSelected && (string)$selected['review_state'] === ROSTER_REVIEW_SUBMITTED && !$isPastYear;
// 反映済みの accept では比較は不要（kintone は反映後の内容になっている）。確認中・未反映の accept のときだけ取得する。
$jobSettled = is_array($currentJob) && in_array((string)$currentJob['status'], [ROSTER_JOB_APPLIED, ROSTER_JOB_RUNNING], true);
if ($isLatestSelected && is_array($selected) && ((string)$selected['review_state'] === ROSTER_REVIEW_SUBMITTED || ((string)$selected['review_state'] === ROSTER_REVIEW_ACCEPTED && !$jobSettled))) {
    $live = roster_staff_live_compare($case, $selected);
}
$acceptBlockers = [];
if ($canReview) {
    if (!is_array($live) || !$live['ok']) {
        $acceptBlockers[] = 'kintone の現在の名簿と比較できないため accept できません。';
    } elseif ((int)$live['count'] > 1) {
        $acceptBlockers[] = 'kintone 上に同じ団体のレコードが ' . (int)$live['count'] . ' 件あります（ID: ' . implode(', ', $live['record_ids']) . '）。1件に整理してから確認してください。';
    } elseif ((int)($live['diff']['summary']['ambiguous'] ?? 0) > 0) {
        $acceptBlockers[] = '学籍番号が一致する既存行が複数あり、更新する行を決められない部員がいます。kintone 側で重複を整理してください。';
    }
    if (is_array($currentJob) && in_array((string)$currentJob['status'], [ROSTER_JOB_QUEUED, ROSTER_JOB_RUNNING, ROSTER_JOB_UNKNOWN], true)) {
        $acceptBlockers[] = '処理中の反映ジョブがあります。';
    }
}
$needsOverwriteConfirm = is_array($live) && $live['ok'] && ((int)($live['diff']['summary']['kintone_changed'] ?? 0) > 0 || (int)($live['diff']['summary']['student_id_corrected'] ?? 0) > 0 || !empty($live['base_outdated']));

// 第2段階の比較（読み取りのみ）。版2の提出で、現在値との比較を行っているときだけ取得する
$officerPlan = (is_array($live) && is_array($selected)) ? roster_officer_build_plan($case, $selected) : null;
$returnSuggestion = is_array($officerPlan) && !empty($officerPlan['ok']) ? roster_officer_return_suggestion($officerPlan) : '';

$reviewsByRevision = [];
foreach ($detail['reviews'] as $review) {
    $reviewsByRevision[(int)$review['revision_id']][] = $review;
}
$attemptsByJob = [];
foreach ($detail['attempts'] as $attempt) {
    $attemptsByJob[(int)$attempt['job_id']][] = $attempt;
}
$csrf = kintone_auth_csrf_field();
$actionUrl = 'api/roster_action.php';
$actionLabel = static fn(string $action): string => match ($action) {
    'submit' => '提出',
    'return' => '差し戻し',
    'accept' => 'accept',
    'revoke' => 'accept の取り消し',
    'retry' => '反映の再試行',
    'reconcile' => '結果の照合を依頼',
    'run_now' => '反映を今すぐ実行',
    default => $action,
};

kintone_page_header($case['group_name'] . ' の部員名簿', $user, [
    'subtitle' => $case['group_code'] . ' ・ ' . (int)$case['fiscal_year'] . '年度 ・ ' . (string)($detail['form']['name'] ?? '') . ' ・ 提出ID ' . (int)$case['id'],
    'actions' => '<a class="btn btn--ghost" href="roster.php">' . fitsc_icon('arrow-left') . '<span class="hide-mobile">提出一覧</span></a>',
]);
kintone_render_flash();
?>
<div class="kt-roster-head cluster">
  <?= kt_roster_review_badge((string)$case['review_state']) ?>
  <?php if ((string)$case['apply_state'] !== ROSTER_APPLY_NONE || (string)$case['review_state'] === ROSTER_REVIEW_ACCEPTED): ?><?= kt_roster_apply_badge((string)$case['apply_state']) ?><?php endif; ?>
  <?php if ($case['last_applied_at']): ?><span class="chip"><?= fitsc_icon('check-circle') ?>第<?= (int)$case['last_applied_revision_number'] ?>版を反映済み（<?= kintone_h((string)$case['last_applied_at']) ?>）</span><?php endif; ?>
  <?php if ($isPastYear): ?><span class="chip text-warning"><?= fitsc_icon('alert-triangle') ?>過年度の提出（現在の対象年度: <?= $formFiscalYear ?: '未設定' ?>）。現行名簿へは反映できません</span><?php endif; ?>
  <?php if (is_array($detail['directory'])): ?><span class="chip"><?= fitsc_icon('mail') ?>団体メール <?= kintone_h(roster_mask_email((string)$detail['directory']['group_email'])) ?></span><?php endif; ?>
</div>

<?php if ($revisions === []): ?>
  <section class="card"><div class="empty"><span class="empty__icon"><?= fitsc_icon('pen') ?></span><span>団体が下書きを編集中です。まだ提出されていません。</span></div></section>
<?php else: ?>

<?php if (is_array($currentJob)): ?>
<section class="card kt-roster-job kt-roster-job--<?= kintone_h((string)$currentJob['status']) ?>">
  <div class="card__header">
    <div>
      <div class="card__title"><?= fitsc_icon('refresh') ?>反映状況（第<?= (int)($currentAcceptance['revision_number'] ?? 0) ?>版の accept ・ <?= kintone_h((string)($currentAcceptance['accepted_by_name'] ?? '')) ?> <?= kintone_h((string)($currentAcceptance['accepted_at'] ?? '')) ?>）</div>
      <p class="card__subtitle">ジョブ #<?= (int)$currentJob['id'] ?> ・ 試行 <?= (int)$currentJob['attempts'] ?> 回 ・ 次回 <?= kintone_h((string)$currentJob['next_run_at']) ?></p>
    </div>
    <?= kt_roster_apply_badge((string)$currentJob['status']) ?>
  </div>
  <div class="card__body stack">
    <?php
    $status = (string)$currentJob['status'];
    $explain = match ($status) {
        ROSTER_JOB_QUEUED => '次回の定期実行（cron）で kintone の部員名簿へ反映されます。',
        ROSTER_JOB_RUNNING => '反映中です。',
        ROSTER_JOB_APPLIED => 'kintone の部員名簿へ反映し、読み戻して内容を確認しました。',
        ROSTER_JOB_FAILED => '反映できませんでした（kintone は更新されていないか、更新後の照合で不一致がありました）。原因を解消してから再試行してください。',
        ROSTER_JOB_CONFLICT => 'kintone 側の変更などにより、上書きせずに停止しました。差分を確認し直し、accept を取り消してから改めて accept してください。',
        ROSTER_JOB_UNKNOWN => 'kintone への書込みの成否が確認できません。同じ登録を繰り返さず、次回の実行で kintone を検索・照合します。',
        default => '',
    };
    ?>
    <p><?= kintone_h($explain) ?></p>
    <?php if ((string)($currentJob['error_message'] ?? '') !== ''): ?>
      <div class="alert alert--<?= $status === ROSTER_JOB_APPLIED ? 'success' : ($status === ROSTER_JOB_FAILED ? 'danger' : 'warning') ?>"><?= fitsc_icon('alert-circle') ?>
        <div class="alert__body"><strong><?= kintone_h(roster_kintone_error_class_label((string)$currentJob['error_class'])) ?><?= $currentJob['error_code'] ? '（' . kintone_h((string)$currentJob['error_code']) . '）' : '' ?></strong>
          <p class="allow-select pre-line"><?= kintone_h((string)$currentJob['error_message']) ?></p></div>
      </div>
    <?php endif; ?>
    <dl class="dl dl--grid">
      <div><dt>対象レコード</dt><dd class="mono"><?= $currentJob['target_record_id'] ? 'ID ' . (int)$currentJob['target_record_id'] : '—' ?></dd></div>
      <div><dt>リビジョン</dt><dd class="mono"><?= $currentJob['before_revision'] !== null ? (int)$currentJob['before_revision'] : '—' ?> → <?= $currentJob['after_revision'] !== null ? (int)$currentJob['after_revision'] : '—' ?></dd></div>
      <div><dt>書込み</dt><dd><?= kintone_h(match ((string)($currentJob['write_kind'] ?? '')) { 'add' => '新規作成', 'update' => '更新', default => '—' }) ?></dd></div>
      <div><dt>反映日時</dt><dd><?= kintone_h((string)($currentJob['applied_at'] ?? '—')) ?></dd></div>
    </dl>
    <?php if ($canOperate): ?>
      <div class="cluster">
        <?php if ($status === ROSTER_JOB_FAILED): ?>
          <form method="post" action="<?= $actionUrl ?>"><?= $csrf ?><input type="hidden" name="action" value="retry"><input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>"><input type="hidden" name="job_id" value="<?= (int)$currentJob['id'] ?>">
            <button type="submit" class="btn btn--primary" data-busy><?= fitsc_icon('rotate-ccw') ?>原因を解消したので再試行する</button></form>
        <?php endif; ?>
        <?php if ($status === ROSTER_JOB_UNKNOWN): ?>
          <form method="post" action="<?= $actionUrl ?>"><?= $csrf ?><input type="hidden" name="action" value="retry"><input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>"><input type="hidden" name="job_id" value="<?= (int)$currentJob['id'] ?>">
            <button type="submit" class="btn" data-busy><?= fitsc_icon('search') ?>次回の実行ですぐに照合する</button></form>
        <?php endif; ?>
        <?php if (in_array($status, [ROSTER_JOB_QUEUED, ROSTER_JOB_UNKNOWN], true)): ?>
          <form method="post" action="<?= $actionUrl ?>"><?= $csrf ?><input type="hidden" name="action" value="run_now"><input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>"><input type="hidden" name="job_id" value="<?= (int)$currentJob['id'] ?>">
            <button type="submit" class="btn" data-busy><?= fitsc_icon('play') ?>定期実行を待たずに今すぐ処理</button></form>
        <?php endif; ?>
        <?php if (in_array($status, [ROSTER_JOB_QUEUED, ROSTER_JOB_FAILED, ROSTER_JOB_CONFLICT], true)): ?>
          <form method="post" action="<?= $actionUrl ?>" class="cluster" data-confirm="accept を取り消すと反映ジョブは失効し、この提出は「確認中」に戻ります。" data-confirm-title="accept を取り消しますか？" data-confirm-ok="取り消す"><?= $csrf ?>
            <input type="hidden" name="action" value="revoke"><input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>"><input type="hidden" name="job_id" value="<?= (int)$currentJob['id'] ?>">
            <input type="text" name="reason" required maxlength="255" placeholder="取り消しの理由（必須）" aria-label="取り消しの理由">
            <button type="submit" class="btn btn--danger-ghost"><?= fitsc_icon('x-circle') ?>accept を取り消す</button></form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<section class="card">
  <div class="card__header">
    <div>
      <div class="card__title"><?= fitsc_icon('file-check') ?>第<?= (int)$selected['revision_number'] ?>版の提出<?= $isLatestSelected ? '（最新）' : '' ?></div>
      <p class="card__subtitle"><?= kintone_h((string)$selected['submitted_at']) ?> ・ 提出者 <?= kintone_h((string)$selected['submitter_name']) ?> ・ 在籍・入部 <?= (int)$selected['active_count'] ?>名 / 卒部・退部 <?= (int)$selected['inactive_count'] ?>名 / 合計 <?= (int)$selected['row_count'] ?>名</p>
    </div>
    <div class="cluster">
      <?= kt_roster_review_badge((string)$selected['review_state']) ?>
      <?php if (count($revisions) > 1): ?>
        <form method="get" action="roster_detail.php"><input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
          <select name="rev" aria-label="表示する版" data-autosubmit>
            <?php foreach ($revisions as $rev): ?><option value="<?= (int)$rev['revision_number'] ?>"<?= (int)$rev['id'] === (int)$selected['id'] ? ' selected' : '' ?>>第<?= (int)$rev['revision_number'] ?>版（<?= kintone_h(roster_review_state_label((string)$rev['review_state'])) ?>）</option><?php endforeach; ?>
          </select>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="card__body stack">
    <?php foreach ((array)($payload['warnings'] ?? []) as $w): ?>
      <div class="alert alert--warning"><?= fitsc_icon('alert-triangle') ?><div class="alert__body"><?= kintone_h((string)($w['message'] ?? '')) ?></div></div>
    <?php endforeach; ?>
    <p class="small-note"><?= fitsc_icon('cloud', 'ico--sm') ?>
      <?php if (is_array($base) && !empty($base['loaded'])): ?>
        団体は kintone の名簿（<?= $base['record_id'] ? 'レコード ID ' . (int)$base['record_id'] . ' ・ リビジョン ' . (int)$base['revision'] : 'レコードなし' ?> ・ <?= kintone_h((string)$base['fetched_at']) ?> 読込）をもとに編集しました。
      <?php else: ?>
        団体は kintone の名簿を読み込まずに入力しました（学籍番号で既存行と照合します）。
      <?php endif; ?>
      電話番号の必須範囲: <?= kintone_h(match ((string)($payload['form_settings']['phone_required_scope'] ?? 'officers')) { 'all' => '在籍・入部の全員', 'none' => 'なし', default => '役職のある部員' . (!empty($payload['form_settings']['phone_required_manager']) ? '（マネージャーを含む）' : '（マネージャーを除く）') }) ?>
    </p>
    <?php foreach (array_reverse($reviewsByRevision[(int)$selected['id']] ?? []) as $review): ?>
      <?php if (in_array((string)$review['action'], ['return', 'revoke'], true)): ?>
        <div class="alert alert--warning"><?= fitsc_icon('return') ?><div class="alert__body"><strong><?= kintone_h($actionLabel((string)$review['action'])) ?>（<?= kintone_h((string)$review['created_at']) ?> ・ <?= kintone_h((string)$review['actor_name']) ?>）</strong><p class="pre-line allow-select"><?= kintone_h((string)$review['reason']) ?></p></div></div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</section>

<?php if (is_array($live)): ?>
<section class="card">
  <div class="card__header">
    <div>
      <div class="card__title"><?= fitsc_icon('diff') ?>kintone の現在の名簿との差分</div>
      <p class="card__subtitle">
        <?php if (!$live['ok']): ?>比較できません<?php elseif ((int)$live['count'] === 0): ?>kintone にこの団体の名簿はありません（accept すると新規作成されます）<?php elseif ((int)$live['count'] === 1): ?>レコード ID <?= (int)$live['record_id'] ?> ・ リビジョン <?= (int)$live['revision'] ?>（いま取得した値）<?php else: ?>同じ団体のレコードが <?= (int)$live['count'] ?> 件あります<?php endif; ?>
      </p>
    </div>
  </div>
  <div class="card__body stack">
    <?php if (!$live['ok']): ?>
      <div class="alert alert--danger"><?= fitsc_icon('alert-circle') ?><div class="alert__body"><?= kintone_h((string)$live['error']) ?></div></div>
    <?php else: ?>
      <?php if (!empty($live['base_outdated']) && (string)$selected['review_state'] === ROSTER_REVIEW_SUBMITTED): ?>
        <div class="alert alert--warning"><?= fitsc_icon('alert-triangle') ?><div class="alert__body"><strong>団体が名簿を読み込んだ後に、kintone の名簿が更新されています</strong>（読込時リビジョン <?= (int)$live['base_revision'] ?> → 現在 <?= (int)$live['revision'] ?>）。赤字の項目は accept すると提出内容で上書きされます。必要なら差し戻して読み込み直してもらってください。</div></div>
      <?php endif; ?>
      <?php $sum = $live['diff']['summary']; ?>
      <div class="cluster">
        <span class="chip">追加 <?= (int)$sum['added'] ?></span><span class="chip">変更 <?= (int)$sum['changed'] ?></span><span class="chip">うち状態変更 <?= (int)$sum['status_changed'] ?></span>
        <span class="chip">変更なし <?= (int)$sum['unchanged'] ?></span><span class="chip">提出外の既存行（残す） <?= (int)$sum['kept'] ?></span>
        <?php if ((int)$sum['student_id_corrected'] > 0): ?><span class="chip text-warning">学籍番号の訂正 <?= (int)$sum['student_id_corrected'] ?></span><?php endif; ?>
        <?php if ((int)$sum['ambiguous'] > 0): ?><span class="chip text-danger">対応不明 <?= (int)$sum['ambiguous'] ?></span><?php endif; ?>
      </div>
      <?= kt_roster_diff_table($live['diff']) ?>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if (is_array($officerPlan)): ?>
<section class="card">
  <div class="card__header">
    <div>
      <div class="card__title"><?= fitsc_icon('shield') ?>登録者・役員台帳との比較（第2段階プレビュー）</div>
      <p class="card__subtitle">ID22・ID21 の現在値と比較した結果です。この画面と accept では、ID22・ID21 へは書き込みません。accept しても反映されるのは部員名簿（ID20）だけです。</p>
    </div>
    <?= kt_roster_badge('書込み無効', 'warning') ?>
  </div>
  <div class="card__body stack"><?= kt_roster_officer_plan_html($officerPlan, $canOperate) ?></div>
</section>
<?php endif; ?>

<?php if ($canReview): ?>
<section class="card">
  <div class="card__header"><div class="card__title"><?= fitsc_icon('check-circle') ?>審査</div></div>
  <div class="card__body">
    <div class="split">
      <form method="post" action="<?= $actionUrl ?>" class="stack" data-confirm="差し戻すと団体に理由が表示され、修正・再提出を待つ状態になります。kintone は更新されません。" data-confirm-title="差し戻しますか？" data-confirm-ok="差し戻す" data-confirm-tone="warning">
        <?= $csrf ?><input type="hidden" name="action" value="return"><input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>"><input type="hidden" name="revision_id" value="<?= (int)$selected['id'] ?>">
        <label class="field"><span class="field__label">差し戻し理由 <span class="req">必須</span></span>
          <textarea name="reason" rows="4" required maxlength="2000" placeholder="例：3行目の学籍番号が誤っています。在籍者の電話番号（部長）が未入力です。"><?= kintone_h($returnSuggestion) ?></textarea>
          <span class="field__hint">団体の提出画面に、第<?= (int)$selected['revision_number'] ?>版への差し戻し理由として表示されます。</span></label>
        <div><button type="submit" class="btn"><?= fitsc_icon('return') ?>差し戻す</button></div>
      </form>
      <form method="post" action="<?= $actionUrl ?>" class="stack" data-confirm="第<?= (int)$selected['revision_number'] ?>版の内容を承認し、kintone の部員名簿への自動反映を予約します。" data-confirm-title="accept しますか？" data-confirm-ok="accept する" data-confirm-tone="primary">
        <?= $csrf ?><input type="hidden" name="action" value="accept"><input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>"><input type="hidden" name="revision_id" value="<?= (int)$selected['id'] ?>">
        <input type="hidden" name="compare_token" value="<?= kintone_h((string)($live['compare_token'] ?? '')) ?>">
        <p>accept すると、<strong>この第<?= (int)$selected['revision_number'] ?>版の内容だけ</strong>が固定され、定期実行で kintone の部員名簿へ自動反映されます。kintone の名簿が上の比較の後に変更されていた場合は、上書きせずに「競合」で止まります。</p>
        <?php if ($acceptBlockers !== []): ?>
          <div class="alert alert--danger"><?= fitsc_icon('lock') ?><div class="alert__body"><ul class="kt-plain-list"><?php foreach ($acceptBlockers as $b): ?><li><?= kintone_h($b) ?></li><?php endforeach; ?></ul></div></div>
        <?php endif; ?>
        <?php if ($needsOverwriteConfirm && $acceptBlockers === []): ?>
          <label class="check kt-high-confirm"><input type="checkbox" name="confirm_overwrite" value="1" required><span>上書きになる項目・学籍番号の訂正を確認しました</span></label>
        <?php endif; ?>
        <div><button type="submit" class="btn btn--primary"<?= $acceptBlockers !== [] ? ' disabled' : '' ?>><?= fitsc_icon('check') ?>accept して反映を予約</button></div>
      </form>
    </div>
  </div>
</section>
<?php elseif ($isLatestSelected && (string)$selected['review_state'] === ROSTER_REVIEW_SUBMITTED && !$canOperate): ?>
  <div class="alert alert--neutral"><?= fitsc_icon('info') ?><div class="alert__body">差し戻し・accept には kintone管理サイトの担当者（operator）以上の権限が必要です。</div></div>
<?php endif; ?>

<section class="card">
  <div class="card__header"><div class="card__title"><?= fitsc_icon('list') ?>第<?= (int)$selected['revision_number'] ?>版の部員一覧</div></div>
  <?= kt_roster_rows_table($rows) ?>
</section>

<section class="card" data-tabs-root>
  <div class="card__header card__header--tabs">
    <div class="tabs" data-tabs aria-label="履歴">
      <button type="button" class="tab" data-tab="reviews"><?= fitsc_icon('history') ?>審査・操作の履歴</button>
      <button type="button" class="tab" data-tab="jobs"><?= fitsc_icon('refresh') ?>反映の履歴 <span class="badge"><?= count($detail['jobs']) ?></span></button>
    </div>
  </div>
  <div data-tab-panel="reviews">
    <div class="table-wrap is-stacked kt-table-flat">
      <table class="table table--compact">
        <thead><tr><th>日時</th><th>版</th><th>操作</th><th>実施者</th><th>理由</th></tr></thead>
        <tbody>
        <?php $revNumbers = []; foreach ($revisions as $rev) { $revNumbers[(int)$rev['id']] = (int)$rev['revision_number']; } ?>
        <?php foreach ($detail['reviews'] as $review): ?>
          <tr>
            <td class="text-xs nowrap"><?= kintone_h((string)$review['created_at']) ?></td>
            <td><?= isset($revNumbers[(int)$review['revision_id']]) ? '第' . $revNumbers[(int)$review['revision_id']] . '版' : '—' ?></td>
            <td><?= kintone_h($actionLabel((string)$review['action'])) ?></td>
            <td><?= kintone_h((string)$review['actor_name']) ?><span class="cell-sub"><?= kintone_h((string)$review['actor_type'] === 'group' ? '団体' : ((string)$review['actor_login_id'] ?: '')) ?></span></td>
            <td class="pre-line allow-select"><?= kintone_h((string)($review['reason'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div data-tab-panel="jobs" hidden>
    <?php if ($detail['jobs'] === []): ?>
      <div class="empty"><span>反映ジョブはまだありません。</span></div>
    <?php else: ?>
      <div class="table-wrap is-stacked kt-table-flat">
        <table class="table table--compact">
          <thead><tr><th>ジョブ</th><th>状態</th><th>accept</th><th>結果</th><th>試行</th></tr></thead>
          <tbody>
          <?php foreach ($detail['jobs'] as $job): ?>
            <tr>
              <td><span class="cell-main mono">#<?= (int)$job['id'] ?></span><span class="cell-sub">第<?= (int)($revNumbers[(int)$job['revision_id']] ?? 0) ?>版 ・ <?= kintone_h((string)$job['created_at']) ?></span></td>
              <td><?= kt_roster_apply_badge((string)$job['status']) ?><?php if ($job['invalidated_reason']): ?><span class="cell-sub">失効: <?= kintone_h((string)$job['invalidated_reason']) ?></span><?php endif; ?></td>
              <td class="text-xs"><?php foreach ($detail['acceptances'] as $acc): if ((int)$acc['id'] === (int)$job['acceptance_id']): ?><?= kintone_h((string)$acc['accepted_by_name']) ?> ・ <?= kintone_h((string)$acc['accepted_at']) ?><span class="cell-sub">比較元: <?= (int)$acc['compared_record_count'] === 0 ? 'レコードなし' : 'ID ' . (int)$acc['compared_record_id'] . ' / rev ' . (int)$acc['compared_revision'] ?></span><?php endif; endforeach; ?></td>
              <td class="text-xs allow-select"><?= $job['target_record_id'] ? 'レコード ' . (int)$job['target_record_id'] . ' ・ rev ' . kintone_h((string)($job['before_revision'] ?? '—')) . ' → ' . kintone_h((string)($job['after_revision'] ?? '—')) : '' ?><?php if ($job['error_message']): ?><span class="cell-sub text-danger"><?= kintone_h(roster_kintone_error_class_label((string)$job['error_class'])) ?>: <?= kintone_h(mb_substr((string)$job['error_message'], 0, 200, 'UTF-8')) ?></span><?php endif; ?></td>
              <td class="text-xs">
                <?php foreach ($attemptsByJob[(int)$job['id']] ?? [] as $a): ?>
                  <div class="nowrap"><?= (int)$a['attempt_no'] ?>回目 <?= kintone_h((string)$a['mode'] === 'reconcile' ? '照合' : '反映') ?> <?= kintone_h(substr((string)$a['started_at'], 5, 11)) ?> → <?= kintone_h(roster_apply_state_label((string)($a['outcome'] ?? 'running'))) ?><?= $a['kintone_code'] ? ' (' . kintone_h((string)$a['kintone_code']) . ')' : '' ?></div>
                <?php endforeach; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
<?php kintone_page_footer(); ?>
