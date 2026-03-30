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
    // Cloudflare プロキシ（オレンジ雲）を有効にした場合は
    // $ip = get_client_ip(); に変更すること
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    rate_limit_or_throw($ip, __DIR__ . '/../../apps/rate_limit.json', 3, 300);

    // フォームデータの検証・構築（ファイルはまだtmp_nameのまま）
    $mailData = build_mail_from_request($cfg);

    // DB保存を先に行う（失敗したらここで例外、メールは送信されない）
    // move_uploaded_file() によりファイルが storage/ に移動される
    $pdo = db_connect($cfg);
    $storedPath = save_reservation_with_uploaded_file($pdo, $cfg, $mailData, $_FILES['file'] ?? null);

    // attachment.path を移動後のパスに差し替える
    //    tmp_name はもう存在しないので必須
    $mailData['attachment']['path'] = $storedPath;

    // メール送信（DB保存が成功した場合のみここに到達）
    send_mail_smtp($cfg, $mailData);

    $replyData = build_mail_reply($mailData);
    send_mail_smtp($cfg, $replyData);

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
