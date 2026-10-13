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

// 今日以降の会議を近い順に。なければ直近の過去分を新しい順に表示する
$nextMeetings = query_all("SELECT * FROM meetings WHERE meeting_date >= date('now', 'localtime') ORDER BY meeting_date ASC, start_time ASC LIMIT 5");
$meetingsAreUpcoming = $nextMeetings !== [];
if (!$meetingsAreUpcoming) {
    $nextMeetings = query_all('SELECT * FROM meetings ORDER BY meeting_date DESC, start_time DESC LIMIT 5');
}
$recentTasks = query_all('SELECT t.*, m.title AS meeting_title FROM tasks t LEFT JOIN meetings m ON m.id = t.meeting_id ORDER BY t.updated_at DESC LIMIT 10');
$recentLogs = query_all('SELECT * FROM activity_logs ORDER BY id DESC LIMIT 12');

$logActionLabels = ['create' => '追加', 'update' => '更新', 'delete' => '削除'];
$logTargetLabels = ['meeting' => '会議', 'agenda' => '議題', 'task' => 'タスク'];

$pageTitle = 'ダッシュボード';
$pageActions = '<a href="meetings.php?new=1" class="btn btn--primary">' . fitsc_icon('calendar-plus') . '<span class="hide-mobile">会議を追加</span></a>';
require __DIR__ . '/../../apps/todo_core/header.php';
?>
<div class="stat-grid">
    <a class="stat td-stat-link" href="meetings.php">
        <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('calendar') ?></span>会議</span>
        <span class="stat__value"><?= $stats['meetings'] ?></span>
    </a>
    <a class="stat stat--info td-stat-link" href="tasks.php?status=open">
        <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('list-checks') ?></span>未完了タスク</span>
        <span class="stat__value"><?= $stats['open_tasks'] ?></span>
    </a>
    <a class="stat <?= $stats['overdue_tasks'] > 0 ? 'stat--danger' : 'stat--success' ?> td-stat-link" href="tasks.php?status=overdue">
        <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('clock') ?></span>期限切れ</span>
        <span class="stat__value"><?= $stats['overdue_tasks'] ?></span>
    </a>
    <div class="stat stat--teal">
        <span class="stat__label"><span class="stat__icon"><?= fitsc_icon('check-circle') ?></span>解決済みの議題</span>
        <span class="stat__value"><?= $stats['resolved_agendas'] ?></span>
    </div>
</div>

<div class="split">
    <section class="card">
        <div class="card__header">
            <div class="card__title"><?= fitsc_icon('calendar') ?><?= $meetingsAreUpcoming ? 'これからの会議' : '最近の会議' ?></div>
            <a class="btn btn--ghost btn--sm" href="meetings.php">すべて見る<?= fitsc_icon('arrow-right') ?></a>
        </div>
        <?php if (!$nextMeetings): ?>
            <div class="empty"><span class="empty__icon"><?= fitsc_icon('calendar') ?></span><span>会議はまだありません。</span><a class="btn btn--sm" href="meetings.php?new=1"><?= fitsc_icon('plus') ?>会議を追加</a></div>
        <?php else: ?>
            <div class="list">
                <?php foreach ($nextMeetings as $meeting): ?>
                    <a class="list-item" href="meeting.php?id=<?= e((string)$meeting['id']) ?>">
                        <span class="td-date"><span><?= e(date('n/j', strtotime((string)$meeting['meeting_date']) ?: time())) ?></span><small><?= e(['日', '月', '火', '水', '木', '金', '土'][(int)date('w', strtotime((string)$meeting['meeting_date']) ?: time())]) ?></small></span>
                        <span class="list-item__main">
                            <span class="list-item__title"><?= e($meeting['title']) ?></span>
                            <span class="list-item__meta"><?= e(trim(($meeting['start_time'] ?: '') . ($meeting['end_time'] ? '–' . $meeting['end_time'] : ''))) ?><?= ($meeting['location'] ?? '') !== '' ? ' ・ ' . e($meeting['location']) : '' ?></span>
                        </span>
                        <span class="list-item__side"><?= status_badge((string)$meeting['status']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card__header">
            <div class="card__title"><?= fitsc_icon('list-checks') ?>最近更新されたタスク</div>
            <a class="btn btn--ghost btn--sm" href="tasks.php">すべて見る<?= fitsc_icon('arrow-right') ?></a>
        </div>
        <?php if (!$recentTasks): ?>
            <div class="empty"><span class="empty__icon"><?= fitsc_icon('list-checks') ?></span><span>タスクはまだありません。</span></div>
        <?php else: ?>
            <div class="list">
                <?php foreach ($recentTasks as $task): $overdue = is_overdue($task['due_date'] ?? null, (string)$task['status']); ?>
                    <a class="list-item" href="meeting.php?id=<?= e((string)$task['meeting_id']) ?>#task-<?= e((string)$task['id']) ?>">
                        <span class="list-item__main">
                            <span class="list-item__title"><?= e($task['title']) ?></span>
                            <span class="list-item__meta"><?= e($task['meeting_title'] ?? '会議なし') ?> ・ <?= e($task['assignee'] ?: '担当未定') ?><?php if (($task['due_date'] ?? '') !== ''): ?> ・ <span class="<?= $overdue ? 'text-danger' : '' ?>">期限 <?= e($task['due_date']) ?></span><?php endif; ?></span>
                        </span>
                        <span class="list-item__side"><?= status_badge((string)$task['status']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<details class="disclosure">
    <summary><?= fitsc_icon('history') ?><span>操作ログ</span><small class="text-subtle td-summary-note">最新 <?= count($recentLogs) ?> 件</small></summary>
    <?php if (!$recentLogs): ?>
        <div class="empty"><span>操作ログはありません。</span></div>
    <?php else: ?>
        <div class="list">
            <?php foreach ($recentLogs as $log): ?>
                <div class="list-item td-log">
                    <span class="td-log__type td-log__type--<?= e($log['action_type']) ?>"><?= e($logActionLabels[$log['action_type']] ?? $log['action_type']) ?></span>
                    <span class="list-item__main">
                        <span class="list-item__title"><?= e($log['message']) ?></span>
                        <span class="list-item__meta"><?= e($logTargetLabels[$log['target_type']] ?? $log['target_type']) ?> #<?= e((string)$log['target_id']) ?> ・ <?= e($log['created_at']) ?></span>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</details>
<?php require __DIR__ . '/../../apps/todo_core/footer.php'; ?>
