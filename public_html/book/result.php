<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

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

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function result_parse_datetime(?string $value): ?DateTimeImmutable
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($value, new DateTimeZone('Asia/Tokyo'));
    } catch (Throwable) {
        return null;
    }
}

function result_access_code_is_visible(array $dateRow): bool
{
    $code = trim((string)($dateRow['access_code'] ?? ''));
    if ($code === '') {
        return false;
    }

    $start = result_parse_datetime($dateRow['access_code_start_at'] ?? null);
    $end = result_parse_datetime($dateRow['access_code_end_at'] ?? null);
    if (!$start || !$end) {
        return false;
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
    return $now >= $start && $now <= $end;
}

function result_access_code_text(array $dateRow): string
{
    if (result_access_code_is_visible($dateRow)) {
        return (string)($dateRow['access_code'] ?? '');
    }

    return '有効時間内のみ表示';
}

function result_base_path(): string
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = trim(str_replace('\\', '/', dirname($scriptName)));
    if ($dir === '' || $dir === '.') {
        return '';
    }
    return rtrim($dir, '/');
}

/**
 * 改善 (C): YYYY-MM-DD を「2026年5月2日(土)」形式に整形する。
 * パスコードカード内で日付を読みやすく表示する用途。
 */
function result_format_jp_date(string $iso): string
{
    $dt = DateTime::createFromFormat('Y-m-d', $iso);
    if (!$dt) {
        return $iso;
    }
    $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
    $w = $weekdays[(int)$dt->format('w')];
    return $dt->format('Y年n月j日') . '(' . $w . ')';
}

$basePath = result_base_path();
$inputUrl = ($basePath !== '' ? $basePath : '') . '/';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <meta name="referrer" content="no-referrer">
  <title><?php echo h($title); ?> - 貸し部屋予約 - 福岡工業大学</title>
  <link rel="icon" href="icon.png">
  <link rel="stylesheet" href="css/style.css?v=20260407e">
  <link rel="stylesheet" href="css/style_responsive.css?v=20260407e">
  <link rel="stylesheet" href="css/style_improvements.css?v=20260502b">
    <script src="assets/common/context-menu-guard.js?v=20260713" defer></script>
</head>
<body>
  <div class="container">
    <div class="hero">
      <h1><?php echo h($title); ?></h1>
      <p class="subtitle"><?php echo h($message); ?></p>
    </div>

    <?php if (is_array($row)): ?>
      <section class="calendar-detail-card">
        <ul class="selected-date-summary">
          <li><strong>メールアドレス</strong><span><?php echo h((string)$row['email']); ?></span></li>
          <li><strong>団体名</strong><span><?php echo h((string)$row['organization_name']); ?></span></li>
          <li><strong>選択日数</strong><span><?php echo h((string)$row['selected_dates_count']); ?>日</span></li>
          <?php if ((string)($row['status_reason'] ?? '') !== ''): ?>
            <li><strong>理由 / 備考</strong><span><?php echo h((string)$row['status_reason']); ?></span></li>
          <?php endif; ?>
        </ul>

        <?php $dateRows = is_array($row['date_rows'] ?? null) ? $row['date_rows'] : []; ?>

        <?php if ($status === 'confirmed' && $dateRows !== []): ?>
          <!-- 改善 (C): パスコード強調カード -->
          <div class="passcode-card" role="region" aria-label="入室用パスコード">
            <p class="passcode-label">入室用パスコード</p>
            <ul class="passcode-list">
              <?php foreach ($dateRows as $dateRow): ?>
                <li class="passcode-row">
                  <div class="passcode-meta">
                    <strong><?php echo h(result_format_jp_date((string)($dateRow['use_date'] ?? ''))); ?></strong>
                    <?php echo h((string)($dateRow['room_label'] ?? '')); ?> / <?php echo h((string)($dateRow['usage_time'] ?? '')); ?>
                  </div>
                  <span class="passcode-code<?php echo result_access_code_is_visible($dateRow) ? '' : ' passcode-code--masked'; ?>"><?php echo h(result_access_code_text($dateRow)); ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
            <p class="passcode-note">※ パスコードは有効時間内のみ表示されます。第三者には共有しないでください。</p>
          </div>
        <?php endif; ?>

        <?php if ($dateRows !== []): ?>
          <div class="selected-date-list-wrap">
            <strong>日付ごとの予約内容</strong>
            <ul class="selected-date-list">
              <?php foreach ($dateRows as $dateRow): ?>
                <li>
                  <?php echo h((string)($dateRow['use_date'] ?? '')); ?>
                  <span>
                    <?php echo h((string)($dateRow['room_label'] ?? '')); ?> /
                    <?php echo h((string)($dateRow['usage_time'] ?? '')); ?> /
                    SwitchBot: <?php echo h((string)($dateRow['switchbot_status'] ?? '')); ?>
                  </span>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if ($status === 'rejected' || $status === 'error'): ?>
      <!-- 改善 (C): 却下/エラー時は「再度申し込む」を主CTAに昇格 -->
      <div class="result-actions">
        <a href="<?php echo h($inputUrl); ?>" class="result-back-btn">再度申し込む</a>
        <a href="<?php echo h($inputUrl); ?>" class="result-secondary-link">入力画面へ戻るのみ</a>
      </div>
    <?php else: ?>
      <div class="button-group">
        <a href="<?php echo h($inputUrl); ?>" class="terms-link-btn">入力画面へ戻る</a>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
