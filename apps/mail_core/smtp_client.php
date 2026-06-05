<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/upload_service.php';

function mail_smtp_config(): array
{
    $config = mail_load_config();
    $smtp = $config['smtp'] ?? [];
    return is_array($smtp) ? $smtp : [];
}

function mail_delivery_driver(): string
{
    $config = mail_load_config();
    $delivery = $config['mail_delivery'] ?? [];
    $driver = is_array($delivery) ? strtolower(trim((string)($delivery['driver'] ?? 'smtp'))) : 'smtp';
    return in_array($driver, ['smtp', 'graph', 'manual_export'], true) ? $driver : 'smtp';
}

function mail_smtp_enabled(): bool
{
    $smtp = mail_smtp_config();
    return mail_delivery_driver() === 'smtp' && !empty($smtp['enabled']);
}

function mail_smtp_is_configured(): array
{
    $smtp = mail_smtp_config();
    $required = ['host', 'port', 'from_address', 'from_name'];
    $smtpAuth = array_key_exists('smtp_auth', $smtp) ? (bool)$smtp['smtp_auth'] : true;
    if ($smtpAuth) {
        $required[] = 'username';
        $required[] = 'password';
    }

    $missing = [];
    foreach ($required as $key) {
        if (trim((string)($smtp[$key] ?? '')) === '') {
            $missing[] = $key;
        }
    }

    return [mail_smtp_enabled() && $missing === [], $missing];
}

function mail_smtp_max_sends_per_run(): int
{
    $smtp = mail_smtp_config();
    return max(1, min(100, (int)($smtp['max_sends_per_run'] ?? 10)));
}

function mail_smtp_delay_seconds(): int
{
    $smtp = mail_smtp_config();
    return max(0, min(30, (int)($smtp['per_message_delay_seconds'] ?? 0)));
}

function mail_smtp_find_autoload(): ?string
{
    $candidates = [
        dirname(mail_apps_dir()) . '/vendor/autoload.php',
        mail_apps_dir() . '/vendor/autoload.php',
        mail_core_dir() . '/vendor/autoload.php',
        dirname(dirname(mail_core_dir())) . '/vendor/autoload.php',
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function mail_smtp_load_phpmailer(): void
{
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return;
    }
    $autoload = mail_smtp_find_autoload();
    if ($autoload !== null) {
        require_once $autoload;
    }
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        throw new RuntimeException('PHPMailer の autoload.php が見つかりません。bookと同じ vendor 配置を確認してください。');
    }
}

function mail_smtp_create_mailer(): \PHPMailer\PHPMailer\PHPMailer
{
    mail_smtp_load_phpmailer();
    [$configured, $missing] = mail_smtp_is_configured();
    if (!$configured) {
        throw new RuntimeException('SMTP設定が不足しています: ' . implode(', ', $missing));
    }

    $smtp = mail_smtp_config();
    $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = trim((string)$smtp['host']);
    $mailer->Port = (int)$smtp['port'];
    $mailer->SMTPAuth = array_key_exists('smtp_auth', $smtp) ? (bool)$smtp['smtp_auth'] : true;
    if ($mailer->SMTPAuth) {
        $mailer->Username = (string)$smtp['username'];
        $mailer->Password = (string)$smtp['password'];
    }

    $secure = strtolower(trim((string)($smtp['secure'] ?? 'tls')));
    if ($secure !== '' && $secure !== 'none') {
        $mailer->SMTPSecure = $secure;
    }
    if (isset($smtp['timeout'])) {
        $mailer->Timeout = max(5, (int)$smtp['timeout']);
    }
    if (!empty($smtp['debug'])) {
        $mailer->SMTPDebug = 2;
    }

    $mailer->CharSet = 'UTF-8';
    $mailer->Encoding = 'base64';

    $fromAddress = trim((string)$smtp['from_address']);
    $fromName = trim((string)$smtp['from_name']);
    $mailer->setFrom($fromAddress, $fromName, false);

    $replyTo = trim((string)($smtp['reply_to'] ?? ''));
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $mailer->addReplyTo($replyTo, $fromName !== '' ? $fromName : $replyTo);
    }

    $envelopeSender = trim((string)($smtp['envelope_sender'] ?? ''));
    if ($envelopeSender !== '' && filter_var($envelopeSender, FILTER_VALIDATE_EMAIL)) {
        $mailer->Sender = $envelopeSender;
    }

    return $mailer;
}

function mail_smtp_test_connection(): array
{
    $mailer = mail_smtp_create_mailer();
    try {
        if (!$mailer->smtpConnect()) {
            throw new RuntimeException('SMTP接続に失敗しました。');
        }
        $mailer->smtpClose();
        return [
            'ok' => true,
            'host' => $mailer->Host,
            'port' => $mailer->Port,
            'smtp_auth' => $mailer->SMTPAuth,
        ];
    } catch (Throwable $e) {
        try {
            $mailer->smtpClose();
        } catch (Throwable) {
        }
        throw new RuntimeException('SMTP接続確認に失敗しました: ' . $e->getMessage());
    }
}

function mail_smtp_get_target(PDO $pdo, int $targetId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT bt.*, b.status AS batch_status, b.title AS batch_title, b.id AS batch_id, b.template_id, ' .
        't.body_type, o.identifier, o.name AS organization_name, o.representative_name, o.category ' .
        'FROM mail_batch_targets bt ' .
        'INNER JOIN mail_batches b ON b.id = bt.batch_id ' .
        'LEFT JOIN mail_templates t ON t.id = b.template_id ' .
        'LEFT JOIN mail_organizations o ON o.id = bt.organization_id ' .
        'WHERE bt.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $targetId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function mail_smtp_pending_attachment_count(PDO $pdo, int $batchId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM mail_attachments WHERE mail_batch_id = :batch_id AND status NOT IN ('approved','excluded','used')"
    );
    $stmt->execute([':batch_id' => $batchId]);
    return (int)$stmt->fetchColumn();
}

function mail_smtp_list_target_attachments(PDO $pdo, int $batchId, ?int $organizationId): array
{
    $stmt = $pdo->prepare(
        'SELECT a.*, f.original_name, f.relative_path, f.file_size, f.mime_type ' .
        'FROM mail_attachments a INNER JOIN mail_uploaded_files f ON f.id = a.uploaded_file_id ' .
        'WHERE a.mail_batch_id = :batch_id AND a.status = "approved" ' .
        'AND (a.is_common = 1 OR (:organization_id IS NOT NULL AND a.organization_id = :organization_id)) ' .
        'ORDER BY a.is_common DESC, f.original_name ASC'
    );
    $stmt->execute([':batch_id' => $batchId, ':organization_id' => $organizationId]);
    return $stmt->fetchAll() ?: [];
}

function mail_smtp_attachment_absolute_path(array $attachment): string
{
    $relative = str_replace(['..', '\\'], ['', '/'], (string)($attachment['relative_path'] ?? ''));
    $path = rtrim(mail_storage_root(), '/\\') . '/' . ltrim($relative, '/');
    if (!is_file($path)) {
        throw new RuntimeException('添付ファイルが見つかりません: ' . (string)($attachment['original_name'] ?? $relative));
    }
    return $path;
}

function mail_smtp_insert_send_log(PDO $pdo, ?int $batchId, ?int $targetId, string $action, string $result, ?string $responseBody, ?string $errorMessage, ?array $actor): void
{
    if (!mail_table_exists($pdo, 'mail_send_logs')) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO mail_send_logs (batch_id, batch_target_id, action, result, response_code, response_body, error_message, created_by_account_id) ' .
        'VALUES (:batch_id, :target_id, :action, :result, :response_code, :response_body, :error_message, :created_by)'
    );
    $stmt->execute([
        ':batch_id' => $batchId,
        ':target_id' => $targetId,
        ':action' => $action,
        ':result' => $result,
        ':response_code' => null,
        ':response_body' => $responseBody !== null ? mb_substr($responseBody, 0, 8000) : null,
        ':error_message' => $errorMessage !== null ? mb_substr($errorMessage, 0, 1000) : null,
        ':created_by' => $actor !== null ? (int)($actor['id'] ?? 0) : null,
    ]);
}

function mail_smtp_mark_target_failed(PDO $pdo, int $targetId, string $message): void
{
    $stmt = $pdo->prepare('UPDATE mail_batch_targets SET status = "failed", error_message = :error_message WHERE id = :id');
    $stmt->execute([':error_message' => mb_substr($message, 0, 1000), ':id' => $targetId]);
}

function mail_smtp_message_data(array $target): array
{
    $subject = trim((string)($target['rendered_subject'] ?? ''));
    $body = (string)($target['rendered_body'] ?? '');
    $toEmail = mail_normalize_email((string)($target['to_email'] ?? ''));

    if ($subject === '') {
        throw new InvalidArgumentException('件名が空です。');
    }
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('宛先メールアドレスが不正です。');
    }
    if (preg_match('/{{\s*[^}]+\s*}}/u', $subject . "\n" . $body) === 1) {
        throw new InvalidArgumentException('未置換の変数が残っています。');
    }

    return [
        'to_email' => $toEmail,
        'subject' => $subject,
        'body' => $body,
        'body_type' => strtolower((string)($target['body_type'] ?? 'plain')) === 'html' ? 'html' : 'plain',
    ];
}

function mail_smtp_plain_from_html(string $html): string
{
    $text = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html) ?? $html;
    $text = preg_replace('/<\s*\/p\s*>/i', "\n\n", $text) ?? $text;
    $text = trim(strip_tags($text));
    return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function mail_smtp_send_target(PDO $pdo, int $targetId, ?array $actor = null): array
{
    if (!mail_smtp_enabled()) {
        throw new RuntimeException('SMTP送信が無効です。config.local.php の mail_delivery.driver と smtp.enabled を確認してください。');
    }

    $target = mail_smtp_get_target($pdo, $targetId);
    if (!$target) {
        throw new InvalidArgumentException('対象メールが見つかりません。');
    }

    $batchId = (int)$target['batch_id'];
    if ((string)$target['batch_status'] !== 'approved' && (string)$target['batch_status'] !== 'draft_created') {
        throw new RuntimeException('バッチが送信可能状態ではありません。現在: ' . mail_status_label((string)$target['batch_status']));
    }
    if (!in_array((string)$target['status'], ['ready', 'failed'], true)) {
        throw new RuntimeException('対象メールの状態がSMTP送信可能ではありません。現在: ' . mail_status_label((string)$target['status']));
    }
    $pending = mail_smtp_pending_attachment_count($pdo, $batchId);
    if ($pending > 0) {
        throw new RuntimeException('要確認または未対応の添付が残っています。先に添付対応を確定してください。');
    }

    try {
        $message = mail_smtp_message_data($target);
        $mailer = mail_smtp_create_mailer();
        $mailer->addAddress($message['to_email']);
        $mailer->Subject = $message['subject'];

        if ($message['body_type'] === 'html') {
            $mailer->isHTML(true);
            $mailer->Body = $message['body'];
            $mailer->AltBody = \PHPMailer\PHPMailer\PHPMailer::normalizeBreaks(mail_smtp_plain_from_html($message['body']), "\r\n");
        } else {
            $plain = \PHPMailer\PHPMailer\PHPMailer::normalizeBreaks($message['body'], "\r\n");
            $mailer->isHTML(false);
            $mailer->Body = $plain;
            $mailer->AltBody = $plain;
        }

        $mailer->addCustomHeader('X-FIT-SC-Mail-Batch-Id', (string)$batchId);
        $mailer->addCustomHeader('X-FIT-SC-Mail-Target-Id', (string)$targetId);

        $attachments = mail_smtp_list_target_attachments($pdo, $batchId, $target['organization_id'] !== null ? (int)$target['organization_id'] : null);
        $attachedCount = 0;
        foreach ($attachments as $attachment) {
            $mailer->addAttachment(mail_smtp_attachment_absolute_path($attachment), (string)$attachment['original_name']);
            $attachedCount++;
        }

        $mailer->send();
        $stmt = $pdo->prepare('UPDATE mail_batch_targets SET status = "sent", error_message = NULL WHERE id = :id');
        $stmt->execute([':id' => $targetId]);

        if (mail_smtp_remaining_not_sent_target_count($pdo, $batchId) === 0) {
            mail_update_batch_status($pdo, $batchId, 'sent', $actor);
        }

        mail_smtp_insert_send_log($pdo, $batchId, $targetId, 'smtp.message.send', 'success', 'Message accepted by SMTP server', null, $actor);
        mail_audit_log($pdo, $actor, 'mail.smtp.send', 'mail_batch_target', (string)$targetId, [
            'batch_id' => $batchId,
            'to_email' => $message['to_email'],
            'attached_count' => $attachedCount,
        ]);

        return [
            'ok' => true,
            'target_id' => $targetId,
            'to_email' => $message['to_email'],
            'attached_count' => $attachedCount,
        ];
    } catch (Throwable $e) {
        mail_smtp_mark_target_failed($pdo, $targetId, $e->getMessage());
        mail_smtp_insert_send_log($pdo, $batchId, $targetId, 'smtp.message.send', 'failed', null, $e->getMessage(), $actor);
        throw $e;
    }
}

function mail_smtp_sendable_target_count(PDO $pdo, int $batchId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM mail_batch_targets WHERE batch_id = :batch_id AND status IN ("ready", "failed")');
    $stmt->execute([':batch_id' => $batchId]);
    return (int)$stmt->fetchColumn();
}

function mail_smtp_sent_target_count(PDO $pdo, int $batchId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM mail_batch_targets WHERE batch_id = :batch_id AND status = "sent"');
    $stmt->execute([':batch_id' => $batchId]);
    return (int)$stmt->fetchColumn();
}

function mail_smtp_remaining_not_sent_target_count(PDO $pdo, int $batchId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM mail_batch_targets WHERE batch_id = :batch_id AND status <> "excluded" AND status <> "sent"');
    $stmt->execute([':batch_id' => $batchId]);
    return (int)$stmt->fetchColumn();
}

function mail_smtp_list_sendable_targets(PDO $pdo, int $batchId, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare('SELECT id FROM mail_batch_targets WHERE batch_id = :batch_id AND status IN ("ready", "failed") ORDER BY id ASC LIMIT ' . $limit);
    $stmt->execute([':batch_id' => $batchId]);
    return array_map('intval', array_column($stmt->fetchAll() ?: [], 'id'));
}

function mail_smtp_send_batch(PDO $pdo, int $batchId, ?array $actor = null, int $limit = 10): array
{
    $batch = mail_get_batch($pdo, $batchId);
    if (!$batch) {
        throw new InvalidArgumentException('対象バッチが見つかりません。');
    }
    if (!in_array((string)$batch['status'], ['approved', 'draft_created'], true)) {
        throw new RuntimeException('バッチが送信可能状態ではありません。現在: ' . mail_status_label((string)$batch['status']));
    }
    $pending = mail_smtp_pending_attachment_count($pdo, $batchId);
    if ($pending > 0) {
        throw new RuntimeException('要確認または未対応の添付が残っています。先に添付対応を確定してください。');
    }

    $targetIds = mail_smtp_list_sendable_targets($pdo, $batchId, $limit);
    $results = [];
    $success = 0;
    $failed = 0;
    $delay = mail_smtp_delay_seconds();

    foreach ($targetIds as $index => $targetId) {
        try {
            if ($index > 0 && $delay > 0) {
                sleep($delay);
            }
            $results[] = mail_smtp_send_target($pdo, $targetId, $actor);
            $success++;
        } catch (Throwable $e) {
            $results[] = [
                'ok' => false,
                'target_id' => $targetId,
                'message' => $e->getMessage(),
            ];
            $failed++;
        }
    }

    return [
        'success' => $success,
        'failed' => $failed,
        'remaining' => mail_smtp_sendable_target_count($pdo, $batchId),
        'results' => $results,
    ];
}
