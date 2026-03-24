<?php

function reservation_mail_html_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function build_admin_request_html(array $data): string
{
    $mail         = (string)($data['mail'] ?? '');
    $roomName     = (string)($data['room_name'] ?? '');
    $safeFilename = (string)($data['filename'] ?? '');
    $text         = (string)($data['text'] ?? '');
    $sentAt       = (string)($data['sent_at'] ?? '');

    $mailHtml         = reservation_mail_html_escape($mail);
    $roomNameHtml     = reservation_mail_html_escape($roomName);
    $safeFilenameHtml = reservation_mail_html_escape($safeFilename);
    $sentAtHtml       = reservation_mail_html_escape($sentAt);
    $textHtml = ($text !== '')
        ? nl2br(reservation_mail_html_escape($text))
        : 'なし';

    return <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>予約申請を受け付けました</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f6f8; font-family:'Yu Gothic', 'Hiragino Kaku Gothic ProN', Meiryo, sans-serif; color:#333333;">
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f4f6f8; margin:0; padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:680px; background-color:#ffffff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">
          
          <tr>
            <td style="background-color:#1d4ed8; padding:20px 24px;">
              <div style="font-size:24px; line-height:1.4; font-weight:bold; color:#ffffff;">
                フォーム送信がありました
              </div>
            </td>
          </tr>

          <tr>
            <td style="padding:28px 24px 16px 24px;">
              <p style="margin:0 0 18px 0; font-size:16px; line-height:1.8; color:#374151;">
                予約申請フォームから新しい送信がありました。
              </p>

              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
                <tr>
                  <td style="width:180px; padding:14px 16px; background-color:#f9fafb; border-bottom:1px solid #e5e7eb; font-size:14px; font-weight:bold; color:#111827;">
                    送信者メールアドレス
                  </td>
                  <td style="padding:14px 16px; border-bottom:1px solid #e5e7eb; font-size:14px; line-height:1.7; color:#374151;">
                    {$mailHtml}
                  </td>
                </tr>
                <tr>
                  <td style="width:180px; padding:14px 16px; background-color:#f9fafb; border-bottom:1px solid #e5e7eb; font-size:14px; font-weight:bold; color:#111827;">
                    予約する部屋
                  </td>
                  <td style="padding:14px 16px; border-bottom:1px solid #e5e7eb; font-size:14px; line-height:1.7; color:#374151;">
                    {$roomNameHtml}
                  </td>
                </tr>
                <tr>
                  <td style="width:180px; padding:14px 16px; background-color:#f9fafb; border-bottom:1px solid #e5e7eb; font-size:14px; font-weight:bold; color:#111827;">
                    添付ファイル
                  </td>
                  <td style="padding:14px 16px; border-bottom:1px solid #e5e7eb; font-size:14px; line-height:1.7; color:#374151;">
                    {$safeFilenameHtml}
                  </td>
                </tr>
                <tr>
                  <td style="width:180px; padding:14px 16px; background-color:#f9fafb; border-bottom:1px solid #e5e7eb; font-size:14px; font-weight:bold; color:#111827; vertical-align:top;">
                    備考
                  </td>
                  <td style="padding:14px 16px; border-bottom:1px solid #e5e7eb; font-size:14px; line-height:1.8; color:#374151;">
                    {$textHtml}
                  </td>
                </tr>
                <tr>
                  <td style="width:180px; padding:14px 16px; background-color:#f9fafb; font-size:14px; font-weight:bold; color:#111827;">
                    送信日時
                  </td>
                  <td style="padding:14px 16px; font-size:14px; line-height:1.7; color:#374151;">
                    {$sentAtHtml}
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="padding:8px 24px 28px 24px;">
              <div style="font-size:12px; line-height:1.8; color:#6b7280;">
                ※ このメールはシステムにより自動生成されています。<br>
                ※ 申請ファイルは添付ファイルとして送信されています。
              </div>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

function build_reply_confirmation_html(array $data): string
{
    $roomName = (string)($data['room_name'] ?? '');
    $sentAt   = (string)($data['sent_at'] ?? '');

    $roomNameHtml = reservation_mail_html_escape($roomName);
    $sentAtHtml   = reservation_mail_html_escape($sentAt);

    return <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>予約を受け付けました</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f6f8; font-family:'Yu Gothic', 'Hiragino Kaku Gothic ProN', Meiryo, sans-serif; color:#333333;">
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f4f6f8; margin:0; padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:640px; background-color:#ffffff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">
          
          <tr>
            <td style="background-color:#2563eb; padding:20px 24px;">
              <div style="font-size:24px; line-height:1.4; font-weight:bold; color:#ffffff;">
                予約を受け付けました
              </div>
            </td>
          </tr>

          <tr>
            <td style="padding:32px 24px 16px 24px;">
              <p style="margin:0 0 16px 0; font-size:18px; line-height:1.7; font-weight:bold; color:#111827;">
                {$roomNameHtml} の予約を受け付けました。
              </p>

              <p style="margin:0 0 12px 0; font-size:15px; line-height:1.8; color:#374151;">
                このメールは自動送信です。
              </p>

              <p style="margin:0 0 24px 0; font-size:15px; line-height:1.8; color:#374151;">
                送信内容の控えとして、提出いただいたファイルを添付しています。
              </p>

              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse; background-color:#f9fafb; border:1px solid #e5e7eb; border-radius:8px;">
                <tr>
                  <td style="padding:14px 16px; font-size:14px; line-height:1.6; color:#111827;">
                    <span style="font-weight:bold;">送信日時:</span> {$sentAtHtml}
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="padding:8px 24px 32px 24px;">
              <div style="font-size:12px; line-height:1.8; color:#6b7280;">
                ※ このメールは送信専用です。<br>
                ※ 内容をご確認のうえ、大切に保管してください。
              </div>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}


function build_reservation_completion_html(array $data): string
{
    $roomName = (string)($data['room_name'] ?? '');
    $organizationName = (string)($data['organization_name'] ?? '');
    $useDate = (string)($data['use_date'] ?? '');
    $usageTime = (string)($data['usage_time'] ?? '');
    $peopleCount = (string)($data['people_count'] ?? '');
    $passcode = (string)($data['passcode'] ?? '');
    $passcodePeriod = (string)($data['passcode_period'] ?? '');
    $sentAt = (string)($data['sent_at'] ?? '');

    $roomNameHtml = reservation_mail_html_escape($roomName);
    $organizationNameHtml = reservation_mail_html_escape($organizationName !== '' ? $organizationName : '—');
    $useDateHtml = reservation_mail_html_escape($useDate !== '' ? $useDate : '—');
    $usageTimeHtml = reservation_mail_html_escape($usageTime !== '' ? $usageTime : '未登録');
    $peopleCountHtml = reservation_mail_html_escape($peopleCount !== '' ? $peopleCount . '人' : '未登録');
    $passcodeHtml = reservation_mail_html_escape($passcode);
    $passcodePeriodHtml = reservation_mail_html_escape($passcodePeriod !== '' ? $passcodePeriod : '—');
    $sentAtHtml = reservation_mail_html_escape($sentAt);

    return <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>予約確定のお知らせ</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f6f8; font-family:'Yu Gothic', 'Hiragino Kaku Gothic ProN', Meiryo, sans-serif; color:#333333;">
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f4f6f8; margin:0; padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:680px; background-color:#ffffff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">
          <tr>
            <td style="background-color:#2563eb; padding:20px 24px;">
              <div style="font-size:24px; line-height:1.4; font-weight:bold; color:#ffffff;">
                予約確定のお知らせ
              </div>
            </td>
          </tr>

          <tr>
            <td style="padding:28px 24px 18px 24px;">
              <p style="margin:0 0 16px 0; font-size:16px; line-height:1.9; color:#374151;">
                ご予約が確定しました。ご利用日時のご案内と、入室用パスワードをお送りします。
              </p>

              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
                <tr>
                  <td style="width:180px; padding:14px 16px; background-color:#f9fafb; border-bottom:1px solid #e5e7eb; font-size:14px; font-weight:bold; color:#111827;">予約部屋</td>
                  <td style="padding:14px 16px; border-bottom:1px solid #e5e7eb; font-size:14px; line-height:1.7; color:#374151;">{$roomNameHtml}</td>
                </tr>
                <tr>
                  <td style="width:180px; padding:14px 16px; background-color:#f9fafb; border-bottom:1px solid #e5e7eb; font-size:14px; font-weight:bold; color:#111827;">使用日</td>
                  <td style="padding:14px 16px; border-bottom:1px solid #e5e7eb; font-size:14px; line-height:1.7; color:#374151;">{$useDateHtml}</td>
                </tr>
                <tr>
                  <td style="width:180px; padding:14px 16px; background-color:#f9fafb; border-bottom:1px solid #e5e7eb; font-size:14px; font-weight:bold; color:#111827;">利用時間</td>
                  <td style="padding:14px 16px; border-bottom:1px solid #e5e7eb; font-size:14px; line-height:1.7; color:#374151;">{$usageTimeHtml}</td>
                </tr>
                <tr>
                  <td style="width:180px; padding:14px 16px; background-color:#f9fafb; border-bottom:1px solid #e5e7eb; font-size:14px; font-weight:bold; color:#111827;">団体名</td>
                  <td style="padding:14px 16px; border-bottom:1px solid #e5e7eb; font-size:14px; line-height:1.7; color:#374151;">{$organizationNameHtml}</td>
                </tr>
                <tr>
                  <td style="width:180px; padding:14px 16px; background-color:#f9fafb; border-bottom:1px solid #e5e7eb; font-size:14px; font-weight:bold; color:#111827;">人数</td>
                  <td style="padding:14px 16px; border-bottom:1px solid #e5e7eb; font-size:14px; line-height:1.7; color:#374151;">{$peopleCountHtml}</td>
                </tr>
                <tr>
                  <td style="width:180px; padding:14px 16px; background-color:#f9fafb; font-size:14px; font-weight:bold; color:#111827;">送信日時</td>
                  <td style="padding:14px 16px; font-size:14px; line-height:1.7; color:#374151;">{$sentAtHtml}</td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="padding:0 24px 24px 24px;">
              <div style="border:1px solid #dbeafe; background-color:#eff6ff; border-radius:12px; padding:18px 18px 16px 18px;">
                <div style="margin:0 0 10px 0; font-size:18px; font-weight:bold; color:#1d4ed8;">入室用パスワード</div>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;">
                  <tr>
                    <td style="width:140px; padding:8px 0; font-size:13px; font-weight:bold; color:#1e3a8a; vertical-align:top;">パスワード</td>
                    <td style="padding:8px 0;">
                      <div style="display:inline-block; padding:10px 14px; border-radius:10px; background-color:#ffffff; border:1px solid #bfdbfe; font-size:22px; letter-spacing:0.12em; font-weight:bold; color:#0f172a;">{$passcodeHtml}</div>
                    </td>
                  </tr>
                  <tr>
                    <td style="width:140px; padding:8px 0; font-size:13px; font-weight:bold; color:#1e3a8a;">有効期間</td>
                    <td style="padding:8px 0; font-size:14px; line-height:1.7; color:#1f2937;">{$passcodePeriodHtml}</td>
                  </tr>
                </table>
              </div>
            </td>
          </tr>

          <tr>
            <td style="padding:0 24px 30px 24px;">
              <div style="font-size:12px; line-height:1.9; color:#6b7280;">
                ※ このメールは自動送信です。<br>
                ※ 入室用パスワードは第三者へ共有しないでください。<br>
                ※ ご不明点がある場合は、案内元へお問い合わせください。
              </div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}
