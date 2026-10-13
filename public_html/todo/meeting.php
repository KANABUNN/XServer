<?php
require __DIR__ . '/../../apps/todo_core/bootstrap.php';
db_init();
require_login();

$meetingId = (int)($_GET['id'] ?? 0);
$meeting = query_one('SELECT * FROM meetings WHERE id = ?', [$meetingId]);
if (!$meeting) {
    http_response_code(404);
    $pageTitle = '会議が見つかりません';
    $pageBack = 'meetings.php';
    require __DIR__ . '/../../apps/todo_core/header.php';
    echo '<section class="card"><div class="empty"><span class="empty__icon">' . fitsc_icon('calendar') . '</span>'
        . '<span class="empty__title">この会議は存在しないか、削除されました</span>'
        . '<a class="btn btn--sm" href="meetings.php">' . fitsc_icon('arrow-left') . '会議一覧へ</a></div></section>';
    require __DIR__ . '/../../apps/todo_core/footer.php';
    exit;
}

$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if ($action === 'update_meeting') {
        $stmt = db()->prepare('UPDATE meetings SET title=?, meeting_date=?, start_time=?, end_time=?, location=?, facilitator=?, status=?, summary=?, updated_at=? WHERE id=?');
        $stmt->execute([
            trim($_POST['title'] ?? ''),
            trim($_POST['meeting_date'] ?? ''),
            trim($_POST['start_time'] ?? ''),
            trim($_POST['end_time'] ?? ''),
            trim($_POST['location'] ?? ''),
            trim($_POST['facilitator'] ?? ''),
            trim($_POST['status'] ?? 'scheduled'),
            trim($_POST['summary'] ?? ''),
            now(),
            $meetingId,
        ]);
        log_activity('update', 'meeting', $meetingId, '会議情報を更新しました。');
        flash('success', '会議情報を更新しました。');
        redirect('meeting.php?id=' . $meetingId);
    }

    if ($action === 'add_agenda') {
        $stmt = db()->prepare('INSERT INTO agenda_items (meeting_id, title, description, category, status, priority, decision, result_note, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $meetingId,
            trim($_POST['title'] ?? ''),
            trim($_POST['description'] ?? ''),
            trim($_POST['category'] ?? ''),
            trim($_POST['status'] ?? 'pending'),
            trim($_POST['priority'] ?? 'medium'),
            trim($_POST['decision'] ?? ''),
            trim($_POST['result_note'] ?? ''),
            (int)($_POST['sort_order'] ?? 0),
            now(),
            now(),
        ]);
        $agendaId = (int)db()->lastInsertId();
        log_activity('create', 'agenda', $agendaId, '議題を追加しました。');
        flash('success', '議題を追加しました。');
        redirect('meeting.php?id=' . $meetingId . '#agendas');
    }

    if ($action === 'update_agenda') {
        $agendaId = (int)($_POST['agenda_id'] ?? 0);
        $stmt = db()->prepare('UPDATE agenda_items SET title=?, description=?, category=?, status=?, priority=?, decision=?, result_note=?, sort_order=?, updated_at=? WHERE id=? AND meeting_id=?');
        $stmt->execute([
            trim($_POST['title'] ?? ''),
            trim($_POST['description'] ?? ''),
            trim($_POST['category'] ?? ''),
            trim($_POST['status'] ?? 'pending'),
            trim($_POST['priority'] ?? 'medium'),
            trim($_POST['decision'] ?? ''),
            trim($_POST['result_note'] ?? ''),
            (int)($_POST['sort_order'] ?? 0),
            now(),
            $agendaId,
            $meetingId,
        ]);
        log_activity('update', 'agenda', $agendaId, '議題を更新しました。');
        flash('success', '議題を更新しました。');
        redirect('meeting.php?id=' . $meetingId . '#agenda-' . $agendaId);
    }

    if ($action === 'delete_agenda') {
        $agendaId = (int)($_POST['agenda_id'] ?? 0);
        $stmt = db()->prepare('DELETE FROM agenda_items WHERE id = ? AND meeting_id = ?');
        $stmt->execute([$agendaId, $meetingId]);
        log_activity('delete', 'agenda', $agendaId, '議題を削除しました。');
        flash('success', '議題を削除しました。');
        redirect('meeting.php?id=' . $meetingId . '#agendas');
    }

    if ($action === 'add_task') {
        $stmt = db()->prepare('INSERT INTO tasks (meeting_id, agenda_item_id, title, description, assignee, status, priority, due_date, progress, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $meetingId,
            ($_POST['agenda_item_id'] ?? '') !== '' ? (int)$_POST['agenda_item_id'] : null,
            trim($_POST['title'] ?? ''),
            trim($_POST['description'] ?? ''),
            trim($_POST['assignee'] ?? ''),
            trim($_POST['status'] ?? 'todo'),
            trim($_POST['priority'] ?? 'medium'),
            trim($_POST['due_date'] ?? ''),
            max(0, min(100, (int)($_POST['progress'] ?? 0))),
            now(),
            now(),
        ]);
        $taskId = (int)db()->lastInsertId();
        log_activity('create', 'task', $taskId, 'タスクを追加しました。');
        flash('success', 'タスクを追加しました。');
        redirect('meeting.php?id=' . $meetingId . '#tasks');
    }

    if ($action === 'update_task') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $stmt = db()->prepare('UPDATE tasks SET agenda_item_id=?, title=?, description=?, assignee=?, status=?, priority=?, due_date=?, progress=?, updated_at=? WHERE id=? AND meeting_id=?');
        $stmt->execute([
            ($_POST['agenda_item_id'] ?? '') !== '' ? (int)$_POST['agenda_item_id'] : null,
            trim($_POST['title'] ?? ''),
            trim($_POST['description'] ?? ''),
            trim($_POST['assignee'] ?? ''),
            trim($_POST['status'] ?? 'todo'),
            trim($_POST['priority'] ?? 'medium'),
            trim($_POST['due_date'] ?? ''),
            max(0, min(100, (int)($_POST['progress'] ?? 0))),
            now(),
            $taskId,
            $meetingId,
        ]);
        log_activity('update', 'task', $taskId, 'タスクを更新しました。');
        flash('success', 'タスクを更新しました。');
        redirect('meeting.php?id=' . $meetingId . '#task-' . $taskId);
    }

    if ($action === 'delete_task') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $stmt = db()->prepare('DELETE FROM tasks WHERE id = ? AND meeting_id = ?');
        $stmt->execute([$taskId, $meetingId]);
        log_activity('delete', 'task', $taskId, 'タスクを削除しました。');
        flash('success', 'タスクを削除しました。');
        redirect('meeting.php?id=' . $meetingId . '#tasks');
    }
}

$meeting = query_one('SELECT * FROM meetings WHERE id = ?', [$meetingId]);
$agendas = query_all('SELECT * FROM agenda_items WHERE meeting_id = ? ORDER BY sort_order ASC, id ASC', [$meetingId]);
$tasks = query_all('SELECT t.*, a.title AS agenda_title FROM tasks t LEFT JOIN agenda_items a ON a.id = t.agenda_item_id WHERE t.meeting_id = ? ORDER BY CASE t.status WHEN "blocked" THEN 0 WHEN "doing" THEN 1 WHEN "todo" THEN 2 WHEN "completed" THEN 3 ELSE 9 END, t.priority DESC, t.due_date ASC, t.id DESC', [$meetingId]);
$comments = query_all('SELECT * FROM comments WHERE agenda_item_id IN (SELECT id FROM agenda_items WHERE meeting_id = ?) OR task_id IN (SELECT id FROM tasks WHERE meeting_id = ?) ORDER BY created_at DESC LIMIT 20', [$meetingId, $meetingId]);

$csrf = csrf_token();
$timeText = trim(($meeting['start_time'] ?: '') . ($meeting['end_time'] ? '–' . $meeting['end_time'] : ''));
$openTasks = count(array_filter($tasks, static fn(array $t): bool => $t['status'] !== 'completed'));
$resolvedAgendas = count(array_filter($agendas, static fn(array $a): bool => $a['status'] === 'resolved'));

$pageTitle = (string)$meeting['title'];
$pageSubtitle = $meeting['meeting_date'] . ($timeText !== '' ? ' ' . $timeText : '') . ' ・ ' . ($meeting['location'] ?: '場所未定');
$pageBack = 'meetings.php';
$pageActions = '<button type="button" class="btn" data-modal-open="meetingEditDialog">' . fitsc_icon('pen') . '<span class="hide-mobile">会議情報を編集</span></button>';
require __DIR__ . '/../../apps/todo_core/header.php';
?>
<section class="card">
    <div class="card__body td-meeting-summary">
        <dl class="dl dl--grid td-facts">
            <div><dt>開催日時</dt><dd><?= e($meeting['meeting_date']) ?> <span class="text-muted"><?= e($timeText ?: '時刻未定') ?></span></dd></div>
            <div><dt>場所</dt><dd><?= e($meeting['location'] ?: '—') ?></dd></div>
            <div><dt>議長・進行</dt><dd><?= e($meeting['facilitator'] ?: '—') ?></dd></div>
            <div><dt>状態</dt><dd><?= status_badge((string)$meeting['status']) ?></dd></div>
        </dl>
        <?php if (trim((string)$meeting['summary']) !== ''): ?>
            <div class="td-summary-text pre-line allow-select"><?= e($meeting['summary']) ?></div>
        <?php endif; ?>
    </div>
</section>

<section class="card" data-tabs-root>
    <div class="card__header card__header--tabs">
        <div class="tabs" data-tabs aria-label="会議の内容">
            <button type="button" class="tab" data-tab="agendas"><?= fitsc_icon('list') ?>議題 <span class="badge"><?= count($agendas) ?></span></button>
            <button type="button" class="tab" data-tab="tasks"><?= fitsc_icon('list-checks') ?>タスク <span class="badge<?= $openTasks > 0 ? ' badge--info' : '' ?>"><?= $openTasks ?>/<?= count($tasks) ?></span></button>
            <button type="button" class="tab" data-tab="records"><?= fitsc_icon('message') ?>記録 <span class="badge"><?= count($comments) ?></span></button>
        </div>
    </div>

    <!-- 議題 -->
    <div data-tab-panel="agendas" id="agendas">
        <div class="td-panel-head">
            <span class="text-sm text-muted">解決済み <?= $resolvedAgendas ?> / <?= count($agendas) ?> 件</span>
            <button type="button" class="btn btn--primary btn--sm" data-modal-open="agendaDialog"><?= fitsc_icon('plus') ?>議題を追加</button>
        </div>
        <?php if (!$agendas): ?>
            <div class="empty"><span class="empty__icon"><?= fitsc_icon('list') ?></span><span>まだ議題はありません。</span></div>
        <?php else: ?>
            <div class="td-items">
                <?php foreach ($agendas as $agenda): $aid = (int)$agenda['id']; ?>
                    <details class="disclosure td-item" id="agenda-<?= $aid ?>">
                        <summary>
                            <span class="td-item__main">
                                <strong class="td-item__title"><?= e($agenda['title']) ?></strong>
                                <span class="td-item__meta">
                                    <?php if (($agenda['category'] ?? '') !== ''): ?><span class="chip"><?= fitsc_icon('tag') ?><?= e($agenda['category']) ?></span><?php endif; ?>
                                    <?php if (trim((string)$agenda['decision']) !== ''): ?><span class="td-decision"><?= fitsc_icon('check', 'ico--sm') ?><?= e(mb_strimwidth((string)$agenda['decision'], 0, 60, '…', 'UTF-8')) ?></span><?php endif; ?>
                                </span>
                            </span>
                            <span class="td-item__badges"><?= priority_badge((string)$agenda['priority']) ?><?= status_badge((string)$agenda['status']) ?></span>
                        </summary>
                        <form method="post" class="td-item__form" data-busy>
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="update_agenda">
                            <input type="hidden" name="agenda_id" value="<?= $aid ?>">
                            <div class="form-grid form-grid--3">
                                <label class="field span-full"><span>議題名</span><input type="text" name="title" required value="<?= e($agenda['title']) ?>"></label>
                                <label class="field"><span>状態</span>
                                    <select name="status">
                                        <?php foreach (['pending', 'carried_over', 'resolved'] as $status): ?>
                                            <option value="<?= e($status) ?>" <?= $agenda['status'] === $status ? 'selected' : '' ?>><?= e(status_label($status)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="field"><span>優先度</span>
                                    <select name="priority">
                                        <?php foreach (['low', 'medium', 'high', 'urgent'] as $priority): ?>
                                            <option value="<?= e($priority) ?>" <?= $agenda['priority'] === $priority ? 'selected' : '' ?>><?= e(status_label($priority)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="field"><span>カテゴリ</span><input type="text" name="category" value="<?= e($agenda['category']) ?>" placeholder="広報・会計・行事など"></label>
                                <label class="field span-full"><span>説明</span><textarea name="description" rows="2"><?= e($agenda['description']) ?></textarea></label>
                                <label class="field span-full"><span>決定事項</span><textarea name="decision" rows="2"><?= e($agenda['decision']) ?></textarea></label>
                                <label class="field span-full"><span>審議結果メモ</span><textarea name="result_note" rows="2"><?= e($agenda['result_note']) ?></textarea></label>
                                <label class="field"><span>表示順</span><input type="number" name="sort_order" value="<?= e((string)$agenda['sort_order']) ?>"></label>
                            </div>
                            <div class="form-actions td-item__actions">
                                <button type="submit" form="delete-agenda-<?= $aid ?>" class="btn btn--danger-ghost btn--sm"><?= fitsc_icon('trash') ?>削除</button>
                                <button type="submit" class="btn btn--primary btn--sm"><?= fitsc_icon('save') ?>保存</button>
                            </div>
                        </form>
                        <form method="post" id="delete-agenda-<?= $aid ?>" hidden data-confirm="「<?= e($agenda['title']) ?>」を削除します。紐づくタスクは残ります。" data-confirm-title="議題を削除しますか？" data-confirm-ok="削除する">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="delete_agenda">
                            <input type="hidden" name="agenda_id" value="<?= $aid ?>">
                        </form>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- タスク -->
    <div data-tab-panel="tasks" id="tasks" hidden>
        <div class="td-panel-head">
            <span class="text-sm text-muted">未完了 <?= $openTasks ?> / <?= count($tasks) ?> 件</span>
            <button type="button" class="btn btn--primary btn--sm" data-modal-open="taskDialog"><?= fitsc_icon('plus') ?>タスクを追加</button>
        </div>
        <?php if (!$tasks): ?>
            <div class="empty"><span class="empty__icon"><?= fitsc_icon('list-checks') ?></span><span>まだタスクはありません。</span></div>
        <?php else: ?>
            <div class="td-items">
                <?php foreach ($tasks as $task): $tid = (int)$task['id']; $overdue = is_overdue($task['due_date'] ?? null, (string)$task['status']); $progress = max(0, min(100, (int)$task['progress'])); ?>
                    <details class="disclosure td-item<?= $task['status'] === 'completed' ? ' is-done' : '' ?>" id="task-<?= $tid ?>">
                        <summary>
                            <span class="td-item__main">
                                <strong class="td-item__title"><?= e($task['title']) ?></strong>
                                <span class="td-item__meta">
                                    <span><?= fitsc_icon('user', 'ico--sm') ?><?= e($task['assignee'] ?: '担当未定') ?></span>
                                    <span class="<?= $overdue ? 'text-danger fw-bold' : '' ?>"><?= fitsc_icon('clock', 'ico--sm') ?><?= e($task['due_date'] ?: '期限なし') ?><?= $overdue ? '（期限切れ）' : '' ?></span>
                                    <?php if (($task['agenda_title'] ?? '') !== ''): ?><span><?= fitsc_icon('link', 'ico--sm') ?><?= e($task['agenda_title']) ?></span><?php endif; ?>
                                </span>
                                <span class="td-progress" aria-label="進捗 <?= $progress ?>%"><span class="progress<?= $progress >= 100 ? ' progress--success' : '' ?>"><span style="width: <?= $progress ?>%"></span></span><small><?= $progress ?>%</small></span>
                            </span>
                            <span class="td-item__badges"><?= priority_badge((string)$task['priority']) ?><?= status_badge((string)$task['status']) ?></span>
                        </summary>
                        <form method="post" class="td-item__form" data-busy>
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="update_task">
                            <input type="hidden" name="task_id" value="<?= $tid ?>">
                            <div class="form-grid form-grid--3">
                                <label class="field span-full"><span>タスク名</span><input type="text" name="title" required value="<?= e($task['title']) ?>"></label>
                                <label class="field"><span>担当者</span><input type="text" name="assignee" value="<?= e($task['assignee']) ?>"></label>
                                <label class="field"><span>期限</span><input type="date" name="due_date" value="<?= e($task['due_date']) ?>"></label>
                                <label class="field"><span>関連議題</span>
                                    <select name="agenda_item_id">
                                        <option value="">なし</option>
                                        <?php foreach ($agendas as $agenda): ?>
                                            <option value="<?= e((string)$agenda['id']) ?>" <?= (string)$task['agenda_item_id'] === (string)$agenda['id'] ? 'selected' : '' ?>><?= e($agenda['title']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="field"><span>状態</span>
                                    <select name="status">
                                        <?php foreach (['todo', 'doing', 'blocked', 'completed'] as $status): ?>
                                            <option value="<?= e($status) ?>" <?= $task['status'] === $status ? 'selected' : '' ?>><?= e(status_label($status)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="field"><span>優先度</span>
                                    <select name="priority">
                                        <?php foreach (['low', 'medium', 'high', 'urgent'] as $priority): ?>
                                            <option value="<?= e($priority) ?>" <?= $task['priority'] === $priority ? 'selected' : '' ?>><?= e(status_label($priority)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="field"><span>進捗 <output class="td-range-value"><?= $progress ?>%</output></span><input type="range" name="progress" min="0" max="100" step="5" value="<?= $progress ?>" data-range-output></label>
                                <label class="field span-full"><span>内容</span><textarea name="description" rows="2"><?= e($task['description']) ?></textarea></label>
                            </div>
                            <div class="form-actions td-item__actions">
                                <button type="submit" form="delete-task-<?= $tid ?>" class="btn btn--danger-ghost btn--sm"><?= fitsc_icon('trash') ?>削除</button>
                                <button type="submit" class="btn btn--primary btn--sm"><?= fitsc_icon('save') ?>保存</button>
                            </div>
                        </form>
                        <form method="post" id="delete-task-<?= $tid ?>" hidden data-confirm="「<?= e($task['title']) ?>」を削除します。" data-confirm-title="タスクを削除しますか？" data-confirm-ok="削除する">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="delete_task">
                            <input type="hidden" name="task_id" value="<?= $tid ?>">
                        </form>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- 記録 -->
    <div data-tab-panel="records" hidden>
        <?php if (!$comments): ?>
            <div class="empty"><span class="empty__icon"><?= fitsc_icon('message') ?></span><span>記録はまだありません。</span></div>
        <?php else: ?>
            <div class="list">
                <?php foreach ($comments as $comment): ?>
                    <div class="list-item td-comment">
                        <span class="avatar"><?= e(fitsc_initials((string)($comment['author'] ?: '匿名'))) ?></span>
                        <span class="list-item__main">
                            <span class="list-item__title"><?= e($comment['author'] ?: '匿名') ?> <small class="text-subtle"><?= e($comment['created_at']) ?></small></span>
                            <span class="td-comment__body allow-select"><?= nl2br(e($comment['body'])) ?></span>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- 会議情報の編集 -->
<dialog class="modal modal--lg" id="meetingEditDialog" aria-labelledby="meetingEditTitle">
    <form method="post" class="modal__panel" data-busy>
        <div class="modal__header">
            <span class="modal__icon"><?= fitsc_icon('pen') ?></span>
            <div><h2 class="modal__title" id="meetingEditTitle">会議情報を編集</h2></div>
            <button type="button" class="btn btn--ghost btn--icon modal__close" data-modal-close aria-label="閉じる"><?= fitsc_icon('x') ?></button>
        </div>
        <div class="modal__body">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="update_meeting">
            <div class="form-grid">
                <label class="field span-full"><span>会議名 <span class="req">必須</span></span><input type="text" name="title" required value="<?= e($meeting['title']) ?>"></label>
                <label class="field"><span>開催日 <span class="req">必須</span></span><input type="date" name="meeting_date" required value="<?= e($meeting['meeting_date']) ?>"></label>
                <div class="field">
                    <span>時間</span>
                    <div class="td-time-range"><input type="time" name="start_time" value="<?= e($meeting['start_time']) ?>" aria-label="開始時刻"><span>〜</span><input type="time" name="end_time" value="<?= e($meeting['end_time']) ?>" aria-label="終了時刻"></div>
                </div>
                <label class="field"><span>場所</span><input type="text" name="location" value="<?= e($meeting['location']) ?>"></label>
                <label class="field"><span>議長・進行</span><input type="text" name="facilitator" value="<?= e($meeting['facilitator']) ?>"></label>
                <label class="field"><span>状態</span>
                    <select name="status">
                        <?php foreach (['scheduled', 'in_progress', 'done', 'closed'] as $status): ?>
                            <option value="<?= e($status) ?>" <?= $meeting['status'] === $status ? 'selected' : '' ?>><?= e(status_label($status)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field span-full"><span>会議の要旨・議事概要</span><textarea name="summary" rows="5"><?= e($meeting['summary']) ?></textarea></label>
            </div>
        </div>
        <div class="modal__footer">
            <button type="button" class="btn btn--ghost" data-modal-close>キャンセル</button>
            <button type="submit" class="btn btn--primary"><?= fitsc_icon('save') ?>保存</button>
        </div>
    </form>
</dialog>

<!-- 議題を追加 -->
<dialog class="modal modal--lg" id="agendaDialog" aria-labelledby="agendaDialogTitle">
    <form method="post" class="modal__panel" data-busy>
        <div class="modal__header">
            <span class="modal__icon"><?= fitsc_icon('plus') ?></span>
            <div><h2 class="modal__title" id="agendaDialogTitle">議題を追加</h2></div>
            <button type="button" class="btn btn--ghost btn--icon modal__close" data-modal-close aria-label="閉じる"><?= fitsc_icon('x') ?></button>
        </div>
        <div class="modal__body">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="add_agenda">
            <div class="form-grid">
                <label class="field span-full"><span>議題名 <span class="req">必須</span></span><input type="text" name="title" required></label>
                <label class="field"><span>カテゴリ</span><input type="text" name="category" placeholder="広報・会計・行事など"></label>
                <label class="field"><span>優先度</span>
                    <select name="priority">
                        <?php foreach (['low', 'medium', 'high', 'urgent'] as $priority): ?>
                            <option value="<?= e($priority) ?>" <?= $priority === 'medium' ? 'selected' : '' ?>><?= e(status_label($priority)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field span-full"><span>説明</span><textarea name="description" rows="3"></textarea></label>
            </div>
            <details class="disclosure mt-3">
                <summary><?= fitsc_icon('sliders') ?><span>状態・決定事項など</span></summary>
                <div class="form-grid td-disclosure-body">
                    <label class="field"><span>状態</span>
                        <select name="status">
                            <?php foreach (['pending', 'carried_over', 'resolved'] as $status): ?>
                                <option value="<?= e($status) ?>"><?= e(status_label($status)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field"><span>表示順</span><input type="number" name="sort_order" value="<?= count($agendas) + 1 ?>"></label>
                    <label class="field span-full"><span>決定事項</span><textarea name="decision" rows="2"></textarea></label>
                    <label class="field span-full"><span>審議結果メモ</span><textarea name="result_note" rows="2"></textarea></label>
                </div>
            </details>
        </div>
        <div class="modal__footer">
            <button type="button" class="btn btn--ghost" data-modal-close>キャンセル</button>
            <button type="submit" class="btn btn--primary"><?= fitsc_icon('plus') ?>追加する</button>
        </div>
    </form>
</dialog>

<!-- タスクを追加 -->
<dialog class="modal modal--lg" id="taskDialog" aria-labelledby="taskDialogTitle">
    <form method="post" class="modal__panel" data-busy>
        <div class="modal__header">
            <span class="modal__icon"><?= fitsc_icon('plus') ?></span>
            <div><h2 class="modal__title" id="taskDialogTitle">タスクを追加</h2></div>
            <button type="button" class="btn btn--ghost btn--icon modal__close" data-modal-close aria-label="閉じる"><?= fitsc_icon('x') ?></button>
        </div>
        <div class="modal__body">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="add_task">
            <div class="form-grid">
                <label class="field span-full"><span>タスク名 <span class="req">必須</span></span><input type="text" name="title" required></label>
                <label class="field"><span>担当者</span><input type="text" name="assignee"></label>
                <label class="field"><span>期限</span><input type="date" name="due_date"></label>
                <label class="field"><span>関連議題</span>
                    <select name="agenda_item_id">
                        <option value="">なし</option>
                        <?php foreach ($agendas as $agenda): ?>
                            <option value="<?= e((string)$agenda['id']) ?>"><?= e($agenda['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field"><span>優先度</span>
                    <select name="priority">
                        <?php foreach (['low', 'medium', 'high', 'urgent'] as $priority): ?>
                            <option value="<?= e($priority) ?>" <?= $priority === 'medium' ? 'selected' : '' ?>><?= e(status_label($priority)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field"><span>状態</span>
                    <select name="status">
                        <?php foreach (['todo', 'doing', 'blocked', 'completed'] as $status): ?>
                            <option value="<?= e($status) ?>"><?= e(status_label($status)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field"><span>進捗 <output class="td-range-value">0%</output></span><input type="range" name="progress" min="0" max="100" step="5" value="0" data-range-output></label>
                <label class="field span-full"><span>内容</span><textarea name="description" rows="3"></textarea></label>
            </div>
        </div>
        <div class="modal__footer">
            <button type="button" class="btn btn--ghost" data-modal-close>キャンセル</button>
            <button type="submit" class="btn btn--primary"><?= fitsc_icon('plus') ?>追加する</button>
        </div>
    </form>
</dialog>
<?php require __DIR__ . '/../../apps/todo_core/footer.php'; ?>
