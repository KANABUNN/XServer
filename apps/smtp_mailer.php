<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_mail_smtp(array $cfg, array $mailData): void
{
    $mail = new PHPMailer(true);

    try {
        // SMTPサーバーの設定
        $mail->isSMTP();
        $mail->Host       = $cfg['smtp_host'];
        $mail->Port       = (int)$cfg['smtp_port'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['smtp_user'];
        $mail->Password   = $cfg['smtp_pass'];
        if (!empty($cfg['smtp_secure'])) {
            $mail->SMTPSecure = $cfg['smtp_secure'];
        }

        // メールの基本設定
        $mail->isHTML(true);
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'quoted-printable';

        // 送信元は固定
        $mail->setFrom($cfg['from_addr'], $cfg['from_name']);
        $mail->addAddress($mailData['to']);

        // 返信先としてユーザーの mail を入れる
        if (!empty($mailData['reply_to'])) {
            $mail->addReplyTo($mailData['reply_to']);
        }

        // 本文の改行コードをCRLFに統一
        $plain = PHPMailer::normalizeBreaks((string)$mailData['body'], "\r\n");

        // 件名と本文をセット
        $mail->Subject = $mailData['subject'];
        $mail->Body    = $mailData['html_body'];
        $mail->AltBody = $plain;

        $mail->addAttachment(
            // 添付ファイルのパスと表示名を指定
            $mailData['attachment']['path'],
            $mailData['attachment']['name']
        );

        $mail->send();
    } catch (Exception $e) {
        throw new RuntimeException('メール送信に失敗しました: ' . $mail->ErrorInfo);
    }
}
