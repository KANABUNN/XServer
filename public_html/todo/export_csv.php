<?php
require __DIR__ . '/../../app/bootstrap.php';
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

require __DIR__ . '/../../app/header.php';
?>
<section class="page-head">
    <div>
        <h1>CSV出力</h1>
        <p>会議台帳や引継ぎ資料用に、横断タスク一覧をCSVで出力できます。</p>
    </div>
</section>
<section class="card">
    <div class="stack-list">
        <a class="list-item" href="export_csv.php?type=tasks">
            <div>
                <div class="list-title">タスク一覧をCSV出力</div>
                <div class="list-meta">会議名・議題・担当者・期限・進捗を含みます。</div>
            </div>
            <span class="btn btn-small">出力</span>
        </a>
    </div>
</section>
<?php require __DIR__ . '/../../app/footer.php'; ?>
