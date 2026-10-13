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

require_once __DIR__ . '/../../apps/ui_kit.php';

[$statusTone, $statusIcon] = match ($status) {
    'confirmed' => ['success', 'check'],
    'rejected' => ['danger', 'x'],
    'error' => ['warning', 'alert-triangle'],
    default => ['neutral', 'help'],
};
$dateRows = is_array($row) && is_array($row['date_rows'] ?? null) ? $row['date_rows'] : [];
?>
<!DOCTYPE html>
<html lang="ja" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <meta name="referrer" content="no-referrer">
  <title><?php echo h($title); ?> - 貸し部屋予約 - 福岡工業大学</title>
  <link rel="icon" href="icon.png">
  <?php echo fitsc_ui_head(['css' => ['css/app.css']]); ?>
</head>
<body class="bk">
  <div class="site">
    <header class="site-header">
      <div class="site-header__inner">
        <a class="site-brand" href="<?php echo h($inputUrl); ?>">
          <span class="brand-mark"><?php echo fitsc_icon('door'); ?></span>
          <span class="site-brand__text"><strong>貸し部屋予約</strong><span>総合管理事務局</span></span>
        </a>
        <div class="site-header__actions"><?php echo fitsc_theme_toggle(); ?></div>
      </div>
    </header>

    <main class="site-main bk-result">
      <section class="card bk-result__card">
        <div class="bk-result__hero">
          <span class="bk-result__icon bk-result__icon--<?php echo h($statusTone); ?>"><?php echo fitsc_icon($statusIcon); ?></span>
          <h1><?php echo h($title); ?></h1>
          <p><?php echo h($message); ?></p>
        </div>

        <?php if (is_array($row)): ?>
          <?php if ($dateRows !== []): ?>
            <div class="bk-result__section">
              <h2 class="section-title"><?php echo $status === 'confirmed' ? '予約内容と入室パスコード' : '申請した日程'; ?></h2>
              <div class="bk-pass-list">
                <?php foreach ($dateRows as $dateRow): ?>
                  <div class="bk-pass">
                    <div class="bk-pass__meta">
                      <strong><?php echo h(result_format_jp_date((string)($dateRow['use_date'] ?? ''))); ?></strong>
                      <span><?php echo h((string)($dateRow['room_label'] ?? '')); ?> ・ <?php echo h((string)($dateRow['usage_time'] ?? '')); ?></span>
                    </div>
                    <?php if ($status === 'confirmed'): ?>
                      <?php if (result_access_code_is_visible($dateRow)): ?>
                        <span class="bk-pass__code allow-select" aria-label="入室パスコード"><?php echo h(result_access_code_text($dateRow)); ?></span>
                      <?php else: ?>
                        <span class="bk-pass__code bk-pass__code--masked"><?php echo fitsc_icon('lock', 'ico--sm'); ?> 利用時間内に表示</span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if ($status === 'confirmed'): ?>
                <p class="small-note"><?php echo fitsc_icon('shield', 'ico--sm'); ?> パスコードは利用時間内のみ表示されます。第三者には共有しないでください。</p>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <div class="bk-result__section">
            <h2 class="section-title">申請者</h2>
            <dl class="dl">
              <div><dt>メールアドレス</dt><dd class="allow-select"><?php echo h((string)$row['email']); ?></dd></div>
              <div><dt>団体名</dt><dd><?php echo h((string)$row['organization_name']); ?></dd></div>
              <div><dt>日数</dt><dd><?php echo h((string)$row['selected_dates_count']); ?>日</dd></div>
              <?php if ((string)($row['status_reason'] ?? '') !== ''): ?>
                <div><dt>理由・備考</dt><dd class="allow-select"><?php echo h((string)$row['status_reason']); ?></dd></div>
              <?php endif; ?>
            </dl>
          </div>
        <?php endif; ?>

        <div class="bk-result__actions">
          <?php if ($status === 'rejected' || $status === 'error'): ?>
            <a href="<?php echo h($inputUrl); ?>" class="btn btn--primary btn--lg"><?php echo fitsc_icon('rotate-ccw'); ?>もう一度申し込む</a>
          <?php else: ?>
            <a href="<?php echo h($inputUrl); ?>" class="btn btn--lg"><?php echo fitsc_icon('arrow-left'); ?>予約画面へ戻る</a>
          <?php endif; ?>
        </div>
      </section>
      <p class="small-note text-center mt-4">同じ内容をメールでもお送りしています。届かない場合は迷惑メールフォルダもご確認ください。</p>
    </main>
  </div>
</body>
</html>
