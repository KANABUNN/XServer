<?php

require_once __DIR__ . '/mail_html_templates.php';

function build_mail_reply(array $mailData): array
{
    $roomName   = (string)($mailData['roomName'] ?? '');
    $replyTo    = (string)($mailData['reply_to'] ?? '');
    $attachment = $mailData['attachment'] ?? null;
    $sentAt     = date('Y-m-d H:i:s');

    // ---- プレーンテキスト本文 ----
    $lines = [
        "{$roomName}の予約を受け付けました。",
        "",
        "このメールは自動送信です。",
        "送信内容の控えとして、提出いただいたファイルを添付しています。",
        "",
        "送信日時: {$sentAt}",
    ];

    $body = implode("\r\n", $lines);

    $htmlBody = build_reply_confirmation_html([
        'room_name' => $roomName,
        'sent_at'   => $sentAt,
    ]);

    return [
        'to' => $replyTo,
        'subject' => '予約を受け付けました',
        'body' => $body,           // プレーンテキスト版
        'html_body' => $htmlBody,  // HTML版
        'attachment' => $attachment,
    ];
}
