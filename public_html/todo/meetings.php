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
require __DIR__ . '/../../apps/todo_core/header.php';
?>
<section class="page-head">
    <div>
        <h1>会議一覧</h1>
        <p>週次定例会議ごとに議題とタスクを紐付けて管理できます。</p>
    </div>
</section>

<section class="two-col layout-top">
    <article class="card">
        <h2>会議を新規追加</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <label>
                <span>会議名 *</span>
                <input type="text" name="title" required placeholder="例: 第12回 定例会議">
            </label>
            <label>
                <span>開催日 *</span>
                <input type="date" name="meeting_date" required>
            </label>
            <label>
                <span>開始時刻</span>
                <input type="time" name="start_time">
            </label>
            <label>
                <span>終了時刻</span>
                <input type="time" name="end_time">
            </label>
            <label>
                <span>場所</span>
                <input type="text" name="location" placeholder="会議室・オンラインなど">
            </label>
            <label>
                <span>議長 / 進行</span>
                <input type="text" name="facilitator">
            </label>
            <label>
                <span>状態</span>
                <select name="status">
                    <option value="scheduled">予定</option>
                    <option value="in_progress">進行中</option>
                    <option value="done">完了</option>
                    <option value="closed">終了</option>
                </select>
            </label>
            <label class="full">
                <span>会議要旨</span>
                <textarea name="summary" rows="4" placeholder="この会議の主題、概要、共有事項など"></textarea>
            </label>
            <div class="full form-actions">
                <button type="submit" class="btn">会議を追加</button>
            </div>
        </form>
    </article>

    <article class="card">
        <h2>運用メモ</h2>
        <ul class="plain-list">
            <li>会議を作成したあとに、議題とタスクを追加します。</li>
            <li>議題には「決定事項」「審議結果」を残せます。</li>
            <li>タスクには担当者・期限・進捗率を設定できます。</li>
            <li>横断タスク一覧から、全会議分を一括で追えます。</li>
        </ul>
    </article>
</section>

<section class="card">
    <h2>登録済み会議</h2>
    <table class="table">
        <thead>
        <tr>
            <th>開催日</th>
            <th>会議名</th>
            <th>場所</th>
            <th>議題数</th>
            <th>タスク数</th>
            <th>状態</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($meetings as $meeting): ?>
            <tr>
                <td><?= e($meeting['meeting_date']) ?></td>
                <td><?= e($meeting['title']) ?></td>
                <td><?= e($meeting['location'] ?: '-') ?></td>
                <td><?= e((string)$meeting['agenda_count']) ?></td>
                <td><?= e((string)$meeting['task_count']) ?></td>
                <td><span class="<?= e(badge_class($meeting['status'])) ?>"><?= e(status_label($meeting['status'])) ?></span></td>
                <td><a class="btn btn-small" href="meeting.php?id=<?= e((string)$meeting['id']) ?>">詳細</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php require __DIR__ . '/../../apps/todo_core/footer.php'; ?>
