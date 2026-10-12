<?php

declare(strict_types=1);

require_once __DIR__ . '/gmail_client.php';

function mail_gmail_html_looks_like_markup(string $value): bool
{
    return preg_match('/<\s*(p|div|br|span|strong|b|em|i|u|ul|ol|li|table|thead|tbody|tr|td|th|h[1-6]|blockquote|a|img|hr)\b|<\s*\/\s*(p|div|span|strong|b|em|i|u|ul|ol|li|table|thead|tbody|tr|td|th|h[1-6]|blockquote|a)\s*>/i', $value) === 1;
}

function mail_gmail_html_plain_to_html(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $paragraphs = preg_split('/\n{2,}/', $text) ?: [];
    $html = [];
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph, "\n");
        if ($paragraph === '') {
            continue;
        }
        $lines = array_map(static function (string $line): string {
            return htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, explode("\n", $paragraph));
        $html[] = '<p>' . implode('<br>', $lines) . '</p>';
    }
    return implode("\n", $html);
}

function mail_gmail_html_body_to_html(string $body): string
{
    $body = trim($body);
    if ($body === '') {
        return '';
    }
    if (mail_gmail_html_looks_like_markup($body)) {
        return $body;
    }
    return mail_gmail_html_plain_to_html($body);
}

function mail_gmail_html_build_mime(PDO $pdo, array $target): array
{
    mail_smtp_load_phpmailer();
    $message = mail_smtp_message_data($target);
    [$fromAddress, $fromName, $replyTo] = mail_gmail_configured_from();

    $bodyHtml = mail_gmail_html_body_to_html((string)$message['body']);
    $message['body'] = $bodyHtml;
    $message['body_type'] = 'html';

    $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->CharSet = 'UTF-8';
    $mailer->Encoding = 'base64';
    $mailer->setFrom($fromAddress, $fromName, false);
    if ($replyTo !== '') {
        $mailer->addReplyTo($replyTo, $fromName);
    }
    $mailer->addAddress($message['to_email']);
    $mailer->Subject = $message['subject'];
    $mailer->isHTML(true);
    $mailer->Body = $bodyHtml;
    $mailer->AltBody = mail_smtp_normalize_breaks(mail_smtp_plain_from_html($bodyHtml), "\r\n");

    $batchId = (int)$target['batch_id'];
    $targetId = (int)$target['id'];
    $mailer->addCustomHeader('X-FIT-SC-Mail-Batch-Id', (string)$batchId);
    $mailer->addCustomHeader('X-FIT-SC-Mail-Target-Id', (string)$targetId);

    $attachments = mail_smtp_list_target_attachments($pdo, $batchId, $target['organization_id'] !== null ? (int)$target['organization_id'] : null);
    $attachedCount = 0;
    foreach ($attachments as $attachment) {
        $mailer->addAttachment(mail_smtp_attachment_absolute_path($attachment), (string)$attachment['original_name']);
        $attachedCount++;
    }

    if (!$mailer->preSend()) {
        throw new RuntimeException('MIMEメールの生成に失敗しました: ' . $mailer->ErrorInfo);
    }

    return [
        'message' => $message,
        'mime' => $mailer->getSentMIMEMessage(),
        'attached_count' => $attachedCount,
        'from_address' => $fromAddress,
    ];
}

function mail_gmail_html_create_draft_target(PDO $pdo, int $targetId, ?array $actor = null): array
{
    if (!mail_gmail_enabled()) {
        throw new RuntimeException('Gmail下書き作成が無効です。config.local.php の mail_delivery.driver と gmail_api.enabled を確認してください。');
    }

    $target = mail_gmail_get_target($pdo, $targetId);
    if (!$target) {
        throw new InvalidArgumentException('対象メールが見つかりません。');
    }

    $batchId = (int)$target['batch_id'];
    if (!in_array((string)$target['batch_status'], ['approved', 'draft_created'], true)) {
        throw new RuntimeException('バッチが下書き作成可能状態ではありません。現在: ' . mail_status_label((string)$target['batch_status']));
    }
    if (!in_array((string)$target['status'], ['ready', 'failed'], true)) {
        throw new RuntimeException('対象メールの状態がGmail下書き作成可能ではありません。現在: ' . mail_status_label((string)$target['status']));
    }
    if (trim((string)($target['graph_message_id'] ?? '')) !== '') {
        throw new RuntimeException('この対象メールは既に外部下書きIDを持っています。重複作成を避けるため中止しました。');
    }

    $pending = mail_smtp_pending_attachment_count($pdo, $batchId);
    if ($pending > 0) {
        throw new RuntimeException('要確認または未対応の添付が残っています。先に添付対応を確定してください。');
    }

    try {
        $built = mail_gmail_html_build_mime($pdo, $target);
        $draft = mail_gmail_create_draft_raw((string)$built['mime']);
        $draftId = (string)$draft['id'];
        $messageId = isset($draft['message']['id']) ? (string)$draft['message']['id'] : '';
        $externalId = $messageId !== '' ? $draftId . '|' . $messageId : $draftId;

        $stmt = $pdo->prepare('UPDATE mail_batch_targets SET status = "draft_created", graph_message_id = :external_id, error_message = NULL WHERE id = :id');
        $stmt->execute([':external_id' => $externalId, ':id' => $targetId]);

        if (mail_gmail_draftable_target_count($pdo, $batchId) === 0) {
            mail_update_batch_status($pdo, $batchId, 'draft_created', $actor);
        }

        mail_gmail_insert_log($pdo, $batchId, $targetId, 'gmail.draft.create', 'success', json_encode([
            'draft_id' => $draftId,
            'message_id' => $messageId,
            'to_email' => $built['message']['to_email'],
            'body_type' => 'html',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), null, $actor);
        mail_audit_log($pdo, $actor, 'mail.gmail.draft.create', 'mail_batch_target', (string)$targetId, [
            'batch_id' => $batchId,
            'draft_id' => $draftId,
            'message_id' => $messageId,
            'to_email' => $built['message']['to_email'],
            'attached_count' => $built['attached_count'],
            'body_type' => 'html',
        ]);

        return [
            'ok' => true,
            'target_id' => $targetId,
            'to_email' => $built['message']['to_email'],
            'draft_id' => $draftId,
            'message_id' => $messageId,
            'attached_count' => $built['attached_count'],
        ];
    } catch (Throwable $e) {
        mail_gmail_mark_target_failed($pdo, $targetId, $e->getMessage());
        mail_gmail_insert_log($pdo, $batchId, $targetId, 'gmail.draft.create', 'failed', null, $e->getMessage(), $actor);
        throw $e;
    }
}

function mail_gmail_html_create_drafts_batch(PDO $pdo, int $batchId, ?array $actor = null, int $limit = 10): array
{
    $batch = mail_get_batch($pdo, $batchId);
    if (!$batch) {
        throw new InvalidArgumentException('対象バッチが見つかりません。');
    }
    if (!in_array((string)$batch['status'], ['approved', 'draft_created'], true)) {
        throw new RuntimeException('バッチが下書き作成可能状態ではありません。現在: ' . mail_status_label((string)$batch['status']));
    }
    $pending = mail_smtp_pending_attachment_count($pdo, $batchId);
    if ($pending > 0) {
        throw new RuntimeException('要確認または未対応の添付が残っています。先に添付対応を確定してください。');
    }

    $targetIds = mail_gmail_list_draftable_targets($pdo, $batchId, $limit);
    $results = [];
    $success = 0;
    $failed = 0;
    foreach ($targetIds as $targetId) {
        try {
            $results[] = mail_gmail_html_create_draft_target($pdo, $targetId, $actor);
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
        'remaining' => mail_gmail_draftable_target_count($pdo, $batchId),
        'results' => $results,
    ];
}
