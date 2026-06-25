<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
$user = kintone_auth_require_view_access();
$pdo = kintone_pdo('org');
$audits = $pdo->query('SELECT * FROM kintone_audit_logs ORDER BY id DESC LIMIT 200')->fetchAll() ?: [];
$jobs = $pdo->query('SELECT * FROM kintone_sync_jobs ORDER BY id DESC LIMIT 100')->fetchAll() ?: [];
$items = $pdo->query('SELECT * FROM kintone_sync_job_items ORDER BY id DESC LIMIT 100')->fetchAll() ?: [];
function kintone_logs_status_badge(string $status): string
{
    return match ($status) {
        'success' => '<span class="badge badge-success">success</span>',
        'partial' => '<span class="badge badge-warn">partial</span>',
        'failed' => '<span class="badge badge-danger">failed</span>',
        'running' => '<span class="badge">running</span>',
        default => '<span class="badge">' . kintone_h($status) . '</span>',
    };
}
kintone_page_header('ログ', $user);
kintone_render_flash();
?>
<section class="card"><h2>同期ジョブ</h2><div class="table-wrap"><table><thead><tr><th>ID</th><th>状態</th><th>種別</th><th>対象</th><th>成功</th><th>失敗</th><th>開始</th><th>終了</th><th>詳細</th></tr></thead><tbody><?php foreach ($jobs as $r): ?><tr><td>#<?= (int)$r['id'] ?></td><td><?= kintone_logs_status_badge((string)$r['status']) ?></td><td><?= kintone_h($r['job_type']) ?></td><td><?= (int)$r['total_items'] ?></td><td><?= (int)$r['success_items'] ?></td><td><?= (int)$r['failed_items'] ?></td><td><?= kintone_h($r['started_at'] ?? '') ?></td><td><?= kintone_h($r['finished_at'] ?? '') ?></td><td><code><?= kintone_h(mb_substr((string)($r['detail_json'] ?? $r['message'] ?? ''), 0, 220, 'UTF-8')) ?></code></td></tr><?php endforeach; ?><?php if ($jobs === []): ?><tr><td colspan="9" class="empty">同期ジョブはありません。</td></tr><?php endif; ?></tbody></table></div></section>
<section class="card"><h2>同期明細</h2><div class="table-wrap"><table><thead><tr><th>ID</th><th>Job</th><th>団体ID</th><th>操作</th><th>状態</th><th>kintone ID</th><th>エラー</th><th>作成日時</th></tr></thead><tbody><?php foreach ($items as $r): ?><tr><td><?= (int)$r['id'] ?></td><td>#<?= (int)$r['job_id'] ?></td><td><?= kintone_h($r['organization_code'] ?? '') ?></td><td><?= kintone_h($r['action_type']) ?></td><td><?= kintone_logs_status_badge((string)$r['status']) ?></td><td><?= kintone_h($r['kintone_record_id'] ?? '') ?></td><td><?= kintone_h($r['error_message'] ?? '') ?></td><td><?= kintone_h($r['created_at']) ?></td></tr><?php endforeach; ?><?php if ($items === []): ?><tr><td colspan="8" class="empty">同期明細はありません。</td></tr><?php endif; ?></tbody></table></div></section>
<section class="card"><h2>監査ログ</h2><div class="table-wrap"><table><thead><tr><th>日時</th><th>操作者</th><th>操作</th><th>対象</th><th>概要</th></tr></thead><tbody><?php foreach ($audits as $r): ?><tr><td><?= kintone_h($r['created_at']) ?></td><td><?= kintone_h($r['actor_display_name'] ?: $r['actor_login_id']) ?></td><td><?= kintone_h($r['action']) ?></td><td><?= kintone_h(($r['target_type'] ?? '') . ':' . ($r['target_id'] ?? '')) ?></td><td><code><?= kintone_h(mb_substr((string)($r['summary_json'] ?? ''), 0, 240, 'UTF-8')) ?></code></td></tr><?php endforeach; ?><?php if ($audits === []): ?><tr><td colspan="5" class="empty">監査ログはありません。</td></tr><?php endif; ?></tbody></table></div></section>
<?php kintone_page_footer(); ?>
