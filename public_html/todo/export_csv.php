<?php
require __DIR__ . '/../../apps/todo_core/bootstrap.php';
db_init();
require_login();

if (($_GET['type'] ?? '') === 'tasks') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="tasks_export.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID','会議','議題','タスク','担当','状態','優先度','期限','進捗','更新日時']);
    $rows = query_all('SELECT t.*, m.title AS meeting_title, a.title AS agenda_title FROM tasks t LEFT JOIN meetings m ON m.id = t.meeting_id LEFT JOIN agenda_items a ON a.id = t.agenda_item_id ORDER BY t.id DESC');
    foreach ($rows as $row) {
        fputcsv($out, [$row['id'], $row['meeting_title'], $row['agenda_title'], $row['title'], $row['assignee'], status_label($row['status']), status_label($row['priority']), $row['due_date'], $row['progress'], $row['updated_at']]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'CSV出力';
$pageBack = 'tasks.php';
require __DIR__ . '/../../apps/todo_core/header.php';
?>
<section class="card">
    <div class="list">
        <a class="list-item" href="export_csv.php?type=tasks">
            <span class="list-item__icon"><?= fitsc_icon('file-text') ?></span>
            <span class="list-item__main">
                <span class="list-item__title">タスク一覧（CSV）</span>
                <span class="list-item__meta">会議・議題・担当者・状態・期限・進捗を含みます</span>
            </span>
            <span class="list-item__side"><span class="btn btn--sm"><?= fitsc_icon('download') ?>ダウンロード</span></span>
        </a>
    </div>
</section>
<?php require __DIR__ . '/../../apps/todo_core/footer.php'; ?>
