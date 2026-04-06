<?php
declare(strict_types=1);

/** @var mixed $cfg */
$cfg = require __DIR__ . '/../../apps/config.php';
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
        'error' => '予約処理に確認事項があります',
        default => '予約処理結果',
    };

    $message = match ($status) {
        'confirmed' => '予約は確定しています。詳細はメールでも送信しています。',
        'rejected' => '予約は成立していません。理由をご確認のうえ、必要に応じて再度お申し込みください。',
        'error' => '予約情報は登録されていますが、追加確認が必要です。メールもあわせてご確認ください。',
        default => '処理結果を確認してください。',
    };
}

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo h($title); ?></title>
  <link rel="icon" href="icon.png">
  <link rel="stylesheet" href="css/style.css?v=20260406a">
  <link rel="stylesheet" href="css/style_responsive.css?v=20260406a">
</head>
<body>
  <div class="container">
    <div class="hero">
      <h1><?php echo h($title); ?></h1>
      <p class="subtitle"><?php echo h($message); ?></p>
    </div>

    <?php if (is_array($row)): ?>
      <section class="calendar-detail-card">
        <ul class="calendar-detail-list">
          <li><strong>メールアドレス</strong><span><?php echo h((string)$row['email']); ?></span></li>
          <li><strong>団体名</strong><span><?php echo h((string)$row['organization_name']); ?></span></li>
          <li><strong>部屋</strong><span><?php echo h((string)$row['room_label']); ?></span></li>
          <li><strong>利用日</strong><span><?php echo h((string)$row['use_date']); ?></span></li>
          <?php if ((string)($row['access_code'] ?? '') !== '' && $status === 'confirmed'): ?>
            <li><strong>パスコード</strong><span><?php echo h((string)$row['access_code']); ?></span></li>
          <?php endif; ?>
          <?php if ((string)($row['status_reason'] ?? '') !== ''): ?>
            <li><strong>理由 / 備考</strong><span><?php echo h((string)$row['status_reason']); ?></span></li>
          <?php endif; ?>
          <li><strong>SwitchBot 状態</strong><span><?php echo h((string)($row['switchbot_status'] ?? '')); ?></span></li>
        </ul>
      </section>
    <?php endif; ?>

    <div class="button-group">
      <a href="/" class="terms-link-btn">入力画面へ戻る</a>
    </div>
  </div>
</body>
</html>
