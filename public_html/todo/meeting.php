<?php
require __DIR__ . '/../../apps/todo_core/bootstrap.php';
db_init();
require_login();

$meetingId = (int)($_GET['id'] ?? 0);
$meeting = query_one('SELECT * FROM meetings WHERE id = ?', [$meetingId]);
if (!$meeting) {
    http_response_code(404);
    exit('Meeting not found.');
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

require __DIR__ . '/../../apps/todo_core/header.php';
?>
<section class="page-head">
    <div>
        <h1><?= e($meeting['title']) ?></h1>
        <p><?= e($meeting['meeting_date']) ?> / <?= e($meeting['location'] ?: '場所未設定') ?></p>
    </div>
    <div>
        <a href="meetings.php" class="btn btn-ghost">会議一覧へ戻る</a>
    </div>
</section>

<section class="card">
    <h2>会議情報</h2>
    <form method="post" class="form-grid">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_meeting">
        <label>
            <span>会議名</span>
            <input type="text" name="title" required value="<?= e($meeting['title']) ?>">
        </label>
        <label>
            <span>開催日</span>
            <input type="date" name="meeting_date" required value="<?= e($meeting['meeting_date']) ?>">
        </label>
        <label>
            <span>開始</span>
            <input type="time" name="start_time" value="<?= e($meeting['start_time']) ?>">
        </label>
        <label>
            <span>終了</span>
            <input type="time" name="end_time" value="<?= e($meeting['end_time']) ?>">
        </label>
        <label>
            <span>場所</span>
            <input type="text" name="location" value="<?= e($meeting['location']) ?>">
        </label>
        <label>
            <span>議長 / 進行</span>
            <input type="text" name="facilitator" value="<?= e($meeting['facilitator']) ?>">
        </label>
        <label>
            <span>状態</span>
            <select name="status">
                <?php foreach (['scheduled','in_progress','done','closed'] as $status): ?>
                    <option value="<?= e($status) ?>" <?= $meeting['status'] === $status ? 'selected' : '' ?>><?= e(status_label($status)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="full">
            <span>会議要旨 / 議事概要</span>
            <textarea name="summary" rows="5"><?= e($meeting['summary']) ?></textarea>
        </label>
        <div class="full form-actions">
            <button type="submit" class="btn">会議情報を更新</button>
        </div>
    </form>
</section>

<section id="agendas" class="two-col layout-top align-start">
    <article class="card sticky-card">
        <h2>議題を追加</h2>
        <form method="post" class="form-grid single-col compact-form">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_agenda">
            <label><span>議題名</span><input type="text" name="title" required></label>
            <label><span>説明</span><textarea name="description" rows="3"></textarea></label>
            <label><span>カテゴリ</span><input type="text" name="category" placeholder="例: 広報 / 会計 / 行事"></label>
            <label><span>状態</span>
                <select name="status">
                    <option value="pending">未着手</option>
                    <option value="carried_over">継続審議</option>
                    <option value="resolved">解決済み</option>
                </select>
            </label>
            <label><span>優先度</span>
                <select name="priority">
                    <option value="low">低</option>
                    <option value="medium" selected>中</option>
                    <option value="high">高</option>
                    <option value="urgent">緊急</option>
                </select>
            </label>
            <label><span>決定事項</span><textarea name="decision" rows="3"></textarea></label>
            <label><span>審議結果メモ</span><textarea name="result_note" rows="3"></textarea></label>
            <label><span>表示順</span><input type="number" name="sort_order" value="0"></label>
            <button type="submit" class="btn">議題を追加</button>
        </form>
    </article>

    <article class="card">
        <div class="section-title-row">
            <h2>議題一覧</h2>
            <span><?= count($agendas) ?> 件</span>
        </div>
        <?php if (!$agendas): ?>
            <p class="muted">まだ議題はありません。</p>
        <?php endif; ?>
        <?php foreach ($agendas as $agenda): ?>
            <details class="detail-box" id="agenda-<?= e((string)$agenda['id']) ?>">
                <summary>
                    <div>
                        <strong><?= e($agenda['title']) ?></strong>
                        <div class="list-meta"><?= e($agenda['category'] ?: 'カテゴリ未設定') ?></div>
                    </div>
                    <div class="summary-badges">
                        <span class="<?= e(badge_class($agenda['priority'])) ?>"><?= e(status_label($agenda['priority'])) ?></span>
                        <span class="<?= e(badge_class($agenda['status'])) ?>"><?= e(status_label($agenda['status'])) ?></span>
                    </div>
                </summary>
                <form method="post" class="form-grid compact-form">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_agenda">
                    <input type="hidden" name="agenda_id" value="<?= e((string)$agenda['id']) ?>">
                    <label><span>議題名</span><input type="text" name="title" required value="<?= e($agenda['title']) ?>"></label>
                    <label><span>カテゴリ</span><input type="text" name="category" value="<?= e($agenda['category']) ?>"></label>
                    <label class="full"><span>説明</span><textarea name="description" rows="3"><?= e($agenda['description']) ?></textarea></label>
                    <label><span>状態</span>
                        <select name="status">
                            <?php foreach (['pending','carried_over','resolved'] as $status): ?>
                                <option value="<?= e($status) ?>" <?= $agenda['status'] === $status ? 'selected' : '' ?>><?= e(status_label($status)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><span>優先度</span>
                        <select name="priority">
                            <?php foreach (['low','medium','high','urgent'] as $priority): ?>
                                <option value="<?= e($priority) ?>" <?= $agenda['priority'] === $priority ? 'selected' : '' ?>><?= e(status_label($priority)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><span>表示順</span><input type="number" name="sort_order" value="<?= e((string)$agenda['sort_order']) ?>"></label>
                    <label class="full"><span>決定事項</span><textarea name="decision" rows="3"><?= e($agenda['decision']) ?></textarea></label>
                    <label class="full"><span>審議結果メモ</span><textarea name="result_note" rows="3"><?= e($agenda['result_note']) ?></textarea></label>
                    <div class="full form-actions split">
                        <button type="submit" class="btn">更新</button>
                </form>
                        <form method="post" onsubmit="return confirm('この議題を削除しますか？');">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_agenda">
                            <input type="hidden" name="agenda_id" value="<?= e((string)$agenda['id']) ?>">
                            <button type="submit" class="btn btn-danger">削除</button>
                        </form>
                    </div>
            </details>
        <?php endforeach; ?>
    </article>
</section>

<section id="tasks" class="two-col layout-top align-start">
    <article class="card sticky-card">
        <h2>タスクを追加</h2>
        <form method="post" class="form-grid single-col compact-form">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_task">
            <label><span>タスク名</span><input type="text" name="title" required></label>
            <label><span>関連議題</span>
                <select name="agenda_item_id">
                    <option value="">未紐付け</option>
                    <?php foreach ($agendas as $agenda): ?>
                        <option value="<?= e((string)$agenda['id']) ?>"><?= e($agenda['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span>内容</span><textarea name="description" rows="3"></textarea></label>
            <label><span>担当者</span><input type="text" name="assignee"></label>
            <label><span>状態</span>
                <select name="status">
                    <option value="todo">未着手</option>
                    <option value="doing">対応中</option>
                    <option value="blocked">保留</option>
                    <option value="completed">完了</option>
                </select>
            </label>
            <label><span>優先度</span>
                <select name="priority">
                    <option value="low">低</option>
                    <option value="medium" selected>中</option>
                    <option value="high">高</option>
                    <option value="urgent">緊急</option>
                </select>
            </label>
            <label><span>期限</span><input type="date" name="due_date"></label>
            <label><span>進捗(0-100)</span><input type="number" min="0" max="100" name="progress" value="0"></label>
            <button type="submit" class="btn">タスクを追加</button>
        </form>
    </article>

    <article class="card">
        <div class="section-title-row">
            <h2>タスク一覧</h2>
            <span><?= count($tasks) ?> 件</span>
        </div>
        <?php if (!$tasks): ?>
            <p class="muted">まだタスクはありません。</p>
        <?php endif; ?>
        <?php foreach ($tasks as $task): ?>
            <details class="detail-box" id="task-<?= e((string)$task['id']) ?>">
                <summary>
                    <div>
                        <strong><?= e($task['title']) ?></strong>
                        <div class="list-meta">担当: <?= e($task['assignee'] ?: '未設定') ?> / 期限: <?= e($task['due_date'] ?: '未設定') ?></div>
                    </div>
                    <div class="summary-badges">
                        <span class="<?= e(badge_class($task['priority'])) ?>"><?= e(status_label($task['priority'])) ?></span>
                        <span class="<?= e(badge_class($task['status'])) ?>"><?= e(status_label($task['status'])) ?></span>
                    </div>
                </summary>
                <form method="post" class="form-grid compact-form">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_task">
                    <input type="hidden" name="task_id" value="<?= e((string)$task['id']) ?>">
                    <label><span>タスク名</span><input type="text" name="title" required value="<?= e($task['title']) ?>"></label>
                    <label><span>関連議題</span>
                        <select name="agenda_item_id">
                            <option value="">未紐付け</option>
                            <?php foreach ($agendas as $agenda): ?>
                                <option value="<?= e((string)$agenda['id']) ?>" <?= (string)$task['agenda_item_id'] === (string)$agenda['id'] ? 'selected' : '' ?>><?= e($agenda['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="full"><span>内容</span><textarea name="description" rows="3"><?= e($task['description']) ?></textarea></label>
                    <label><span>担当者</span><input type="text" name="assignee" value="<?= e($task['assignee']) ?>"></label>
                    <label><span>状態</span>
                        <select name="status">
                            <?php foreach (['todo','doing','blocked','completed'] as $status): ?>
                                <option value="<?= e($status) ?>" <?= $task['status'] === $status ? 'selected' : '' ?>><?= e(status_label($status)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><span>優先度</span>
                        <select name="priority">
                            <?php foreach (['low','medium','high','urgent'] as $priority): ?>
                                <option value="<?= e($priority) ?>" <?= $task['priority'] === $priority ? 'selected' : '' ?>><?= e(status_label($priority)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><span>期限</span><input type="date" name="due_date" value="<?= e($task['due_date']) ?>"></label>
                    <label><span>進捗(0-100)</span><input type="number" min="0" max="100" name="progress" value="<?= e((string)$task['progress']) ?>"></label>
                    <div class="full progress-bar-wrap"><div class="progress-bar"><span style="width: <?= e((string)$task['progress']) ?>%"></span></div></div>
                    <div class="full form-actions split">
                        <button type="submit" class="btn">更新</button>
                </form>
                        <form method="post" onsubmit="return confirm('このタスクを削除しますか？');">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_task">
                            <input type="hidden" name="task_id" value="<?= e((string)$task['id']) ?>">
                            <button type="submit" class="btn btn-danger">削除</button>
                        </form>
                    </div>
            </details>
        <?php endforeach; ?>
    </article>
</section>

<section class="card">
    <div class="section-title-row">
        <h2>最近のコメント / 記録</h2>
        <span><?= count($comments) ?> 件</span>
    </div>
    <?php if (!$comments): ?>
        <p class="muted">コメント機能は土台だけ作成済みです。必要なら次段階で入力UIを追加できます。</p>
    <?php else: ?>
        <div class="stack-list">
            <?php foreach ($comments as $comment): ?>
                <div class="list-item no-link">
                    <div>
                        <div class="list-title"><?= e($comment['author'] ?: '匿名') ?></div>
                        <div class="list-meta"><?= e($comment['created_at']) ?></div>
                        <div><?= nl2br(e($comment['body'])) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../../apps/todo_core/footer.php'; ?>
