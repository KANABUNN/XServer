<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

function send_mail_smtp(array $cfg, array $mailData): void
{
    $fromAddr = trim((string)($cfg['from_addr'] ?? ''));
    if ($fromAddr === '') {
        throw new RuntimeException('送信元メールアドレスが未設定です。');
    }

    $to = trim((string)($mailData['to'] ?? ''));
    if ($to === '') {
        throw new RuntimeException('宛先メールアドレスが未指定です。');
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = (string)($cfg['smtp_host'] ?? '');
        $mail->Port = (int)($cfg['smtp_port'] ?? 465);
        $mail->SMTPAuth = true;
        $mail->Username = (string)($cfg['smtp_user'] ?? '');
        $mail->Password = (string)($cfg['smtp_pass'] ?? '');

        $secure = trim((string)($cfg['smtp_secure'] ?? ''));
        if ($secure !== '') {
            $mail->SMTPSecure = $secure;
        }

        $mail->setFrom($fromAddr, (string)($cfg['from_name'] ?? ''));
        $mail->addAddress($to);

        if (!empty($mailData['reply_to'])) {
            $mail->addReplyTo((string)$mailData['reply_to']);
        }
        if (!empty($mailData['cc'])) {
            $mail->addCC((string)$mailData['cc']);
        }
        if (!empty($mailData['bcc'])) {
            $mail->addBCC((string)$mailData['bcc']);
        }

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'quoted-printable';

        $htmlBody = (string)($mailData['html_body'] ?? '');
        $plainBody = (string)($mailData['body'] ?? '');
        if ($plainBody === '') {
            $plainBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)));
        }

        $mail->Subject = (string)($mailData['subject'] ?? '');
        $mail->Body = $htmlBody !== '' ? $htmlBody : nl2br(htmlspecialchars($plainBody, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $mail->AltBody = PHPMailer::normalizeBreaks($plainBody, "\r\n");

        $mail->send();
    } catch (Exception $e) {
        throw new RuntimeException('メール送信に失敗しました: ' . $mail->ErrorInfo);
    }
}
