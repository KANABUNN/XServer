<?php
require __DIR__ . '/../../apps/todo_core/bootstrap.php';
db_init();
require_login();

$status = trim($_GET['status'] ?? '');
$priority = trim($_GET['priority'] ?? '');
$assignee = trim($_GET['assignee'] ?? '');

$where = [];
$params = [];
if ($status === 'open') {
    $where[] = "t.status IN ('todo','doing','blocked')";
} elseif ($status === 'overdue') {
    $where[] = "t.due_date IS NOT NULL AND t.due_date != '' AND t.due_date < date('now', 'localtime') AND t.status != 'completed'";
} elseif ($status !== '') {
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
$hasFilter = $status !== '' || $priority !== '' || $assignee !== '';
$statusOptions = ['' => 'すべての状態', 'open' => '未完了', 'overdue' => '期限切れ', 'todo' => status_label('todo'), 'doing' => status_label('doing'), 'blocked' => status_label('blocked'), 'completed' => status_label('completed')];

$pageTitle = 'タスク';
$pageSubtitle = 'すべての会議のタスクをまとめて確認';
$pageActions = '<a class="btn" href="export_csv.php?type=tasks">' . fitsc_icon('download') . '<span class="hide-mobile">CSV出力</span></a>';
require __DIR__ . '/../../apps/todo_core/header.php';
?>
<section class="card">
    <div class="card__header">
        <div class="card__title"><?= fitsc_icon('list-checks') ?>タスク <small><?= count($tasks) ?>件</small></div>
        <form method="get" class="cluster td-filters" role="search">
            <div class="search-toggle" data-search-toggle data-submit-on-clear<?= $assignee !== '' ? ' data-open' : '' ?>>
                <button type="button" class="btn btn--ghost btn--icon search-toggle__button" aria-label="担当者で検索" data-tip="担当者で検索" data-tip-pos="bottom"><?= fitsc_icon('search') ?></button>
                <div class="search-toggle__field">
                    <?= fitsc_icon('user') ?>
                    <input type="search" name="assignee" value="<?= e($assignee) ?>" placeholder="担当者" aria-label="担当者で検索">
                    <button type="button" class="btn btn--ghost btn--icon search-toggle__clear" aria-label="検索を閉じる"><?= fitsc_icon('x') ?></button>
                </div>
            </div>
            <select name="status" data-autosubmit aria-label="状態で絞り込み">
                <?php foreach ($statusOptions as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="priority" data-autosubmit aria-label="優先度で絞り込み">
                <option value="">すべての優先度</option>
                <?php foreach (['urgent', 'high', 'medium', 'low'] as $value): ?>
                    <option value="<?= e($value) ?>" <?= $priority === $value ? 'selected' : '' ?>>優先度：<?= e(status_label($value)) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($hasFilter): ?><a class="btn btn--ghost btn--sm" href="tasks.php"><?= fitsc_icon('rotate-ccw') ?>解除</a><?php endif; ?>
            <noscript><button type="submit" class="btn">適用</button></noscript>
        </form>
    </div>
    <?php if (!$tasks): ?>
        <div class="empty"><span class="empty__icon"><?= fitsc_icon('list-checks') ?></span><span><?= $hasFilter ? '条件に一致するタスクはありません。' : 'タスクはまだありません。' ?></span></div>
    <?php else: ?>
        <div class="table-wrap is-stacked">
            <table class="table">
                <thead>
                <tr><th>タスク</th><th>担当</th><th>期限</th><th>進捗</th><th>状態</th><th>優先度</th></tr>
                </thead>
                <tbody>
                <?php foreach ($tasks as $task): $overdue = is_overdue($task['due_date'] ?? null, (string)$task['status']); $progress = max(0, min(100, (int)$task['progress'])); $href = 'meeting.php?id=' . (int)$task['meeting_id'] . '#task-' . (int)$task['id']; ?>
                    <tr data-href="<?= e($href) ?>" class="td-row-link<?= $task['status'] === 'completed' ? ' is-muted' : '' ?>">
                        <td>
                            <a class="cell-main td-task-link" href="<?= e($href) ?>"><?= e($task['title']) ?></a>
                            <span class="cell-sub"><?= e($task['meeting_title'] ?: '会議なし') ?><?= ($task['agenda_title'] ?? '') !== '' ? ' ・ ' . e($task['agenda_title']) : '' ?></span>
                        </td>
                        <td><?= e($task['assignee'] ?: '—') ?></td>
                        <td class="nowrap<?= $overdue ? ' text-danger fw-bold' : '' ?>"><?= e($task['due_date'] ?: '—') ?><?= $overdue ? '<span class="cell-sub text-danger">期限切れ</span>' : '' ?></td>
                        <td><span class="td-progress td-progress--cell"><span class="progress<?= $progress >= 100 ? ' progress--success' : '' ?>"><span style="width: <?= $progress ?>%"></span></span><small><?= $progress ?>%</small></span></td>
                        <td><?= status_badge((string)$task['status']) ?></td>
                        <td><?= priority_badge((string)$task['priority']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../../apps/todo_core/footer.php'; ?>
