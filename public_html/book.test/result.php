<?php
declare(strict_types=1);
/** @var mixed $cfg */
$cfg = require __DIR__ . '/../../apps/config_test.php';
require_once __DIR__ . '/../../apps/db.php';
if (!is_array($cfg)) {
    http_response_code(500);
    exit('config error');
}
$token = trim((string)($_GET['token'] ?? ''));
$row = null;
try {
    if ($token !== '') {
        $pdo = db_connect($cfg);
        $row = reservation_fetch_by_token($pdo, $token);
    }
} catch (Throwable $e) {
    $row = null;
}
$status = (string)($row['reservation_status'] ?? '');
$title = '予約処理結果';
$message = '予約結果を表示できませんでした。メールをご確認ください。';
if (is_array($row)) {
    $title = match ($status) {
        'confirmed' => '予約を受け付けました',
        'rejected' => '予約を受け付けできませんでした',
        'error' => '予約は登録されましたが確認事項があります',
        default => '予約処理結果',
    };
    $message = match ($status) {
        'confirmed' => '予約は確定しています。詳細はメールでも送信しています。',
        'rejected' => '予約は成立していません。理由をご確認のうえ、必要に応じて再度お申し込みください。',
        'error' => '予約情報は登録されていますが、外部連携で確認事項があります。メールもあわせてご確認ください。',
        default => '処理結果を確認してください。',
    };
}
function h(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title><?php echo h($title); ?></title>
<style>
body{margin:0;background:linear-gradient(135deg,#5b67d8,#7c4dff);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#111827}
.wrap{max-width:980px;margin:40px auto;padding:24px}
.card{background:#fff;border-radius:24px;padding:32px;box-shadow:0 20px 50px rgba(0,0,0,.15)}
h1{margin:0 0 12px;font-size:38px;text-align:center}.sub{margin:0 0 28px;color:#6b7280;text-align:center;line-height:1.8}
ul{padding-left:18px}li{margin:8px 0}.btn{display:inline-block;padding:12px 24px;border:1px solid #c7d2fe;border-radius:14px;text-decoration:none;color:#1f2937;background:#fff}.actions{text-align:center;margin-top:24px}
</style></head><body><div class="wrap"><div class="card"><h1><?php echo h($title); ?></h1><p class="sub"><?php echo h($message); ?></p><?php if (is_array($row)): ?><ul><li>メールアドレス: <?php echo h((string)$row['email']); ?></li><li>団体名: <?php echo h((string)$row['organization_name']); ?></li><li>選択日数: <?php echo h((string)$row['selected_dates_count']); ?>日</li><li>SwitchBot 状態: <?php echo h((string)($row['switchbot_status'] ?? '')); ?></li><li>Google 連携: <?php echo h((string)($row['google_sync_status'] ?? '')); ?></li><?php if ((string)($row['status_reason'] ?? '') !== ''): ?><li>理由 / 備考: <?php echo h((string)$row['status_reason']); ?></li><?php endif; ?></ul><?php endif; ?><div class="actions"><a class="btn" href="/">入力画面へ戻る</a></div></div></div></body></html>
