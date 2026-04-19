<?php
require __DIR__ . '/../../apps/todo_core/bootstrap.php';
db_init();
require_login();

$stats = [
    'meetings' => (int)db()->query('SELECT COUNT(*) FROM meetings')->fetchColumn(),
    'open_tasks' => (int)db()->query("SELECT COUNT(*) FROM tasks WHERE status IN ('todo','doing','blocked')")->fetchColumn(),
    'overdue_tasks' => (int)db()->query("SELECT COUNT(*) FROM tasks WHERE due_date IS NOT NULL AND due_date < date('now', 'localtime') AND status != 'completed'")->fetchColumn(),
    'resolved_agendas' => (int)db()->query("SELECT COUNT(*) FROM agenda_items WHERE status = 'resolved'")->fetchColumn(),
];

$nextMeetings = query_all('SELECT * FROM meetings ORDER BY meeting_date ASC, start_time ASC LIMIT 5');
$recentTasks = query_all('SELECT t.*, m.title AS meeting_title FROM tasks t LEFT JOIN meetings m ON m.id = t.meeting_id ORDER BY t.updated_at DESC LIMIT 10');
$recentLogs = query_all('SELECT * FROM activity_logs ORDER BY id DESC LIMIT 12');

require __DIR__ . '/../../apps/todo_core/header.php';
?>
<section class="page-head">
    <div>
        <h1>ダッシュボード</h1>
        <p>定例会議の議題・決定事項・継続タスクを一元管理します。</p>
    </div>
    <div>
        <a href="meetings.php" class="btn">会議を管理する</a>
    </div>
</section>

<section class="stats-grid">
    <article class="card stat-card">
        <div class="stat-label">会議件数</div>
        <div class="stat-value"><?= e((string)$stats['meetings']) ?></div>
    </article>
    <article class="card stat-card">
        <div class="stat-label">未完了タスク</div>
        <div class="stat-value"><?= e((string)$stats['open_tasks']) ?></div>
    </article>
    <article class="card stat-card">
        <div class="stat-label">期限超過</div>
        <div class="stat-value"><?= e((string)$stats['overdue_tasks']) ?></div>
    </article>
    <article class="card stat-card">
        <div class="stat-label">解決済み議題</div>
        <div class="stat-value"><?= e((string)$stats['resolved_agendas']) ?></div>
    </article>
</section>

<section class="two-col">
    <article class="card">
        <div class="section-title-row">
            <h2>直近の会議</h2>
            <a href="meetings.php">一覧へ</a>
        </div>
        <div class="stack-list">
            <?php foreach ($nextMeetings as $meeting): ?>
                <a class="list-item" href="meeting.php?id=<?= e((string)$meeting['id']) ?>">
                    <div>
                        <div class="list-title"><?= e($meeting['title']) ?></div>
                        <div class="list-meta"><?= e($meeting['meeting_date']) ?> <?= e($meeting['start_time'] ?: '') ?><?= $meeting['end_time'] ? ' - ' . e($meeting['end_time']) : '' ?></div>
                    </div>
                    <span class="<?= e(badge_class($meeting['status'])) ?>"><?= e(status_label($meeting['status'])) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </article>

    <article class="card">
        <div class="section-title-row">
            <h2>最近更新されたタスク</h2>
            <a href="tasks.php">横断一覧へ</a>
        </div>
        <div class="stack-list">
            <?php foreach ($recentTasks as $task): ?>
                <a class="list-item" href="meeting.php?id=<?= e((string)$task['meeting_id']) ?>#task-<?= e((string)$task['id']) ?>">
                    <div>
                        <div class="list-title"><?= e($task['title']) ?></div>
                        <div class="list-meta"><?= e($task['meeting_title'] ?? '会議未紐付け') ?> / 担当: <?= e($task['assignee'] ?: '未設定') ?></div>
                    </div>
                    <span class="<?= e(badge_class($task['status'])) ?>"><?= e(status_label($task['status'])) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </article>
</section>

<section class="card">
    <div class="section-title-row">
        <h2>操作ログ</h2>
    </div>
    <table class="table">
        <thead>
        <tr>
            <th>日時</th>
            <th>種別</th>
            <th>対象</th>
            <th>内容</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($recentLogs as $log): ?>
            <tr>
                <td><?= e($log['created_at']) ?></td>
                <td><?= e($log['action_type']) ?></td>
                <td><?= e($log['target_type']) ?> #<?= e((string)$log['target_id']) ?></td>
                <td><?= e($log['message']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php require __DIR__ . '/../../apps/todo_core/footer.php'; ?>
