<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_mail_smtp(array $cfg, array $mailData): void
{
    $mail = new PHPMailer(true);

    try {
        // SMTPサーバーの設定
        $mail->isSMTP();
        $mail->Host       = (string)($cfg['smtp_host'] ?? '');
        $mail->Port       = (int)($cfg['smtp_port'] ?? 465);
        $mail->SMTPAuth   = true;
        $mail->Username   = (string)($cfg['smtp_user'] ?? '');
        $mail->Password   = (string)($cfg['smtp_pass'] ?? '');
        if (!empty($cfg['smtp_secure'])) {
            $mail->SMTPSecure = (string)$cfg['smtp_secure'];
        }

        // メールの基本設定
        $mail->isHTML(true);
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'quoted-printable';

        $fromAddr = (string)($cfg['from_addr'] ?? '');
        $fromName = (string)($cfg['from_name'] ?? '');
        if ($fromAddr === '') {
            throw new RuntimeException('送信元メールアドレスが未設定です。');
        }

        $to = trim((string)($mailData['to'] ?? ''));
        if ($to === '') {
            throw new RuntimeException('宛先メールアドレスが未指定です。');
        }

        // 送信元 / 宛先
        $mail->setFrom($fromAddr, $fromName);
        $mail->addAddress($to);

        if (!empty($mailData['cc'])) {
            $mail->addCC((string)$mailData['cc']);
        }
        if (!empty($mailData['bcc'])) {
            $mail->addBCC((string)$mailData['bcc']);
        }

        // 返信先
        if (!empty($mailData['reply_to'])) {
            $mail->addReplyTo((string)$mailData['reply_to']);
        }

        $htmlBody = (string)($mailData['html_body'] ?? '');
        $plainBody = (string)($mailData['body'] ?? '');
        if ($plainBody === '') {
            $plainBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)));
        }
        $plain = PHPMailer::normalizeBreaks($plainBody, "\r\n");

        $mail->Subject = (string)($mailData['subject'] ?? '');
        $mail->Body    = $htmlBody !== '' ? $htmlBody : nl2br(htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $mail->AltBody = $plain;

        $attachment = $mailData['attachment'] ?? null;
        if (is_array($attachment)) {
            $attachmentPath = (string)($attachment['path'] ?? '');
            $attachmentName = (string)($attachment['name'] ?? basename($attachmentPath));
            if ($attachmentPath !== '' && is_file($attachmentPath)) {
                $mail->addAttachment($attachmentPath, $attachmentName);
            }
        }

        $mail->send();
    } catch (Exception $e) {
        throw new RuntimeException('メール送信に失敗しました: ' . $mail->ErrorInfo);
    }
}
