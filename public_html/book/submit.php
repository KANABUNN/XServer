<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
$cfg = require __DIR__ . '/../../apps/config.php';
require_once __DIR__ . '/../../apps/build_mail.php';
require_once __DIR__ . '/../../apps/reply_mail_build.php';
require_once __DIR__ . '/../../apps/smtp_mailer.php';
require_once __DIR__ . '/../../apps/response_limit.php';
require_once __DIR__ . '/../../apps/db.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method Not Allowed');
    }

    // 簡易的なレート制限（IPアドレス単位で5分(300秒)に3回まで）
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    rate_limit_or_throw($ip, __DIR__ . '/../../apps/rate_limit.json', 3, 300);

    // フォーム内容からメールデータを構築
    $mailData = build_mail_from_request($cfg);
    send_mail_smtp($cfg, $mailData);

    // 返信メールの構築と送信
    $replyData = build_mail_reply($mailData);
    send_mail_smtp($cfg, $replyData);

    // DBへ予約情報と添付ファイル情報を保存
    $pdo = db_connect($cfg);
    save_reservation_with_uploaded_file($pdo, $cfg, $mailData, $_FILES['file'] ?? null);

    header('Location: /success.html', true, 303);
    exit;
} catch (Throwable $e) {
    $id = bin2hex(random_bytes(6));
    error_log("[upload:$id] " . $e->getMessage());
    error_log("[upload:$id] " . $e->getFile() . ':' . $e->getLine());
    error_log("[upload:$id] FILES=" . json_encode($_FILES, JSON_UNESCAPED_UNICODE));
    error_log("[upload:$id] POST keys=" . implode(',', array_keys($_POST)));

    header('Location: /mistake.html', true, 303);
}
