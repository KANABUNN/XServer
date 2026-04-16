<?php
require __DIR__ . '/../../app/bootstrap.php';
db_init();
require_login();

$status = trim($_GET['status'] ?? '');
$priority = trim($_GET['priority'] ?? '');
$assignee = trim($_GET['assignee'] ?? '');

$where = [];
$params = [];
if ($status !== '') {
    $where[] = 't.status = ?';
    $params[] = $status;
}
if ($priority !== '') {
    $where[] = 't.priority = ?';
    $params[] = $priority;
}
if ($assignee !== '') {
    $where[] = 't.assignee LIKE ?';
    $params[] = '%' . $assignee . '%';
}
$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$tasks = query_all("SELECT t.*, m.title AS meeting_title, a.title AS agenda_title FROM tasks t LEFT JOIN meetings m ON m.id = t.meeting_id LEFT JOIN agenda_items a ON a.id = t.agenda_item_id $sqlWhere ORDER BY CASE t.status WHEN 'blocked' THEN 0 WHEN 'doing' THEN 1 WHEN 'todo' THEN 2 WHEN 'completed' THEN 3 ELSE 9 END, t.priority DESC, t.due_date ASC, t.id DESC", $params);
require __DIR__ . '/../../app/header.php';
?>
<section class="page-head">
    <div>
        <h1>横断タスク一覧</h1>
        <p>会議をまたいで、未完了・期限・担当者の状況を確認できます。</p>
    </div>
</section>

<section class="card">
    <h2>絞り込み</h2>
    <form method="get" class="form-grid slim">
        <label>
            <span>状態</span>
            <select name="status">
                <option value="">すべて</option>
                <?php foreach (['todo','doing','blocked','completed'] as $value): ?>
                    <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e(status_label($value)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>優先度</span>
            <select name="priority">
                <option value="">すべて</option>
                <?php foreach (['low','medium','high','urgent'] as $value): ?>
                    <option value="<?= e($value) ?>" <?= $priority === $value ? 'selected' : '' ?>><?= e(status_label($value)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>担当者</span>
            <input type="text" name="assignee" value="<?= e($assignee) ?>" placeholder="部分一致">
        </label>
        <div class="form-actions align-end">
            <button type="submit" class="btn">適用</button>
            <a href="tasks.php" class="btn btn-ghost">解除</a>
        </div>
    </form>
</section>

<section class="card">
    <table class="table">
        <thead>
        <tr>
            <th>タスク</th>
            <th>会議</th>
            <th>議題</th>
            <th>担当</th>
            <th>期限</th>
            <th>進捗</th>
            <th>状態</th>
            <th>優先度</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($tasks as $task): ?>
            <tr>
                <td><a href="meeting.php?id=<?= e((string)$task['meeting_id']) ?>#task-<?= e((string)$task['id']) ?>"><?= e($task['title']) ?></a></td>
                <td><?= e($task['meeting_title'] ?: '-') ?></td>
                <td><?= e($task['agenda_title'] ?: '-') ?></td>
                <td><?= e($task['assignee'] ?: '-') ?></td>
                <td><?= e($task['due_date'] ?: '-') ?></td>
                <td><?= e((string)$task['progress']) ?>%</td>
                <td><span class="<?= e(badge_class($task['status'])) ?>"><?= e(status_label($task['status'])) ?></span></td>
                <td><span class="<?= e(badge_class($task['priority'])) ?>"><?= e(status_label($task['priority'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php require __DIR__ . '/../../app/footer.php'; ?>
