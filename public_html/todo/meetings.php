<?php
require __DIR__ . '/../../apps/todo_core/bootstrap.php';
db_init();
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $title = trim($_POST['title'] ?? '');
    $meetingDate = trim($_POST['meeting_date'] ?? '');
    $startTime = trim($_POST['start_time'] ?? '');
    $endTime = trim($_POST['end_time'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $facilitator = trim($_POST['facilitator'] ?? '');
    $status = trim($_POST['status'] ?? 'scheduled');
    $summary = trim($_POST['summary'] ?? '');

    if ($title === '' || $meetingDate === '') {
        flash('error', '会議名と開催日は必須です。');
        redirect('meetings.php');
    }

    $stmt = db()->prepare('INSERT INTO meetings (title, meeting_date, start_time, end_time, location, facilitator, status, summary, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$title, $meetingDate, $startTime, $endTime, $location, $facilitator, $status, $summary, now(), now()]);
    $id = (int)db()->lastInsertId();
    log_activity('create', 'meeting', $id, '会議を追加しました。');
    flash('success', '会議を追加しました。');
    redirect('meeting.php?id=' . $id);
}

$meetings = query_all('SELECT m.*, (SELECT COUNT(*) FROM agenda_items a WHERE a.meeting_id = m.id) AS agenda_count, (SELECT COUNT(*) FROM tasks t WHERE t.meeting_id = m.id) AS task_count FROM meetings m ORDER BY meeting_date DESC, start_time DESC, id DESC');
$today = date('Y-m-d');

$pageTitle = '会議';
$pageActions = '<button type="button" class="btn btn--primary" data-modal-open="meetingDialog">' . fitsc_icon('calendar-plus') . '<span class="hide-mobile">会議を追加</span></button>';
require __DIR__ . '/../../apps/todo_core/header.php';
?>
<section class="card">
    <div class="card__header">
        <div class="card__title"><?= fitsc_icon('calendar') ?>会議一覧 <small><?= count($meetings) ?>件</small></div>
    </div>
    <?php if (!$meetings): ?>
        <div class="empty">
            <span class="empty__icon"><?= fitsc_icon('calendar') ?></span>
            <span class="empty__title">会議はまだありません</span>
            <span>会議を追加すると、議題とタスクを登録できます。</span>
            <button type="button" class="btn btn--primary btn--sm" data-modal-open="meetingDialog"><?= fitsc_icon('plus') ?>会議を追加</button>
        </div>
    <?php else: ?>
        <div class="table-wrap is-stacked">
            <table class="table">
                <thead>
                <tr><th>開催日</th><th>会議</th><th class="text-right">議題</th><th class="text-right">タスク</th><th>状態</th><th class="col-actions"></th></tr>
                </thead>
                <tbody>
                <?php foreach ($meetings as $meeting): $isPast = (string)$meeting['meeting_date'] < $today; ?>
                    <tr data-href="meeting.php?id=<?= e((string)$meeting['id']) ?>" class="td-row-link<?= $isPast ? ' td-row-past' : '' ?>">
                        <td class="nowrap"><span class="cell-main"><?= e($meeting['meeting_date']) ?></span><span class="cell-sub"><?= e(trim(($meeting['start_time'] ?: '') . ($meeting['end_time'] ? '–' . $meeting['end_time'] : ''))) ?: '時刻未定' ?></span></td>
                        <td><span class="cell-main"><?= e($meeting['title']) ?></span><span class="cell-sub"><?= e($meeting['location'] ?: '場所未定') ?></span></td>
                        <td class="text-right num"><?= e((string)$meeting['agenda_count']) ?></td>
                        <td class="text-right num"><?= e((string)$meeting['task_count']) ?></td>
                        <td><?= status_badge((string)$meeting['status']) ?></td>
                        <td class="col-actions"><a class="btn btn--ghost btn--icon btn--sm" href="meeting.php?id=<?= e((string)$meeting['id']) ?>" aria-label="「<?= e($meeting['title']) ?>」を開く"><?= fitsc_icon('chevron-right') ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<dialog class="modal modal--lg" id="meetingDialog" aria-labelledby="meetingDialogTitle"<?= isset($_GET['new']) ? ' data-open-on-load' : '' ?>>
    <form method="post" class="modal__panel" data-busy>
        <div class="modal__header">
            <span class="modal__icon"><?= fitsc_icon('calendar-plus') ?></span>
            <div>
                <h2 class="modal__title" id="meetingDialogTitle">会議を追加</h2>
                <p class="modal__desc">追加後に、議題とタスクを登録できます</p>
            </div>
            <button type="button" class="btn btn--ghost btn--icon modal__close" data-modal-close aria-label="閉じる"><?= fitsc_icon('x') ?></button>
        </div>
        <div class="modal__body">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <div class="form-grid">
                <label class="field span-full"><span>会議名 <span class="req">必須</span></span><input type="text" name="title" required placeholder="例：第12回 定例会議"></label>
                <label class="field"><span>開催日 <span class="req">必須</span></span><input type="date" name="meeting_date" required value="<?= e($today) ?>"></label>
                <div class="field">
                    <span>時間</span>
                    <div class="td-time-range"><input type="time" name="start_time" aria-label="開始時刻"><span>〜</span><input type="time" name="end_time" aria-label="終了時刻"></div>
                </div>
                <label class="field"><span>場所</span><input type="text" name="location" placeholder="会議室・オンラインなど"></label>
                <label class="field"><span>議長・進行</span><input type="text" name="facilitator"></label>
                <label class="field"><span>状態</span>
                    <select name="status">
                        <?php foreach (['scheduled', 'in_progress', 'done', 'closed'] as $status): ?>
                            <option value="<?= e($status) ?>"><?= e(status_label($status)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field span-full"><span>会議の要旨 <span class="opt">任意</span></span><textarea name="summary" rows="3" placeholder="主題・共有事項など"></textarea></label>
            </div>
        </div>
        <div class="modal__footer">
            <button type="button" class="btn btn--ghost" data-modal-close>キャンセル</button>
            <button type="submit" class="btn btn--primary"><?= fitsc_icon('plus') ?>追加する</button>
        </div>
    </form>
</dialog>
<?php require __DIR__ . '/../../apps/todo_core/footer.php'; ?>
