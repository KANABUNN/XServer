<?php
declare(strict_types=1);

function reservation_mail_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function reservation_mail_card(string $title, string $bodyHtml): string
{
    $titleHtml = reservation_mail_escape($title);

    return <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$titleHtml}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:'Yu Gothic','Hiragino Kaku Gothic ProN',Meiryo,sans-serif;color:#1f2937;">
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="padding:24px 0;background:#f4f6fb;">
    <tr>
      <td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:720px;background:#ffffff;border:1px solid #e5e7eb;border-radius:16px;overflow:hidden;">
          <tr>
            <td style="background:#2563eb;padding:20px 24px;">
              <div style="font-size:24px;font-weight:700;line-height:1.4;color:#ffffff;">{$titleHtml}</div>
            </td>
          </tr>
          <tr>
            <td style="padding:28px 24px 32px 24px;">
              {$bodyHtml}
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

function build_user_result_mail(array $reservation): array
{
    $status = (string)($reservation['reservation_status'] ?? '');
    $roomLabel = (string)($reservation['room_label'] ?? '');
    $useDate = (string)($reservation['use_date'] ?? '');
    $organizationName = (string)($reservation['organization_name'] ?? '');
    $accessCode = (string)($reservation['access_code'] ?? '');
    $reason = (string)($reservation['status_reason'] ?? '');
    $switchbotStatus = (string)($reservation['switchbot_status'] ?? '');

    $subject = $status === 'confirmed'
        ? '【貸し部屋予約】予約確定のお知らせ'
        : '【貸し部屋予約】予約結果のお知らせ';

    $bodyParts = [];
    if ($status === 'confirmed') {
        $bodyParts[] = '<p style="margin:0 0 16px;font-size:16px;line-height:1.9;">予約が確定しました。以下の内容をご確認ください。</p>';
    } elseif ($status === 'error') {
        $bodyParts[] = '<p style="margin:0 0 16px;font-size:16px;line-height:1.9;">予約情報は受け付けましたが、入室用パスコードの反映で問題が発生しました。管理側で確認します。</p>';
    } else {
        $bodyParts[] = '<p style="margin:0 0 16px;font-size:16px;line-height:1.9;">今回の予約は受け付けできませんでした。理由をご確認ください。</p>';
    }

    $tableRows = [
        ['メールアドレス', reservation_mail_escape((string)($reservation['email'] ?? ''))],
        ['団体名', reservation_mail_escape($organizationName)],
        ['部屋', reservation_mail_escape($roomLabel)],
        ['利用日', reservation_mail_escape($useDate)],
    ];

    if ($accessCode !== '' && $status === 'confirmed') {
        $tableRows[] = [
            '入室用パスコード',
            '<span style="display:inline-block;padding:8px 14px;border:1px solid #bfdbfe;border-radius:10px;background:#eff6ff;font-size:22px;letter-spacing:0.18em;font-weight:700;">'
            . reservation_mail_escape($accessCode)
            . '</span>'
        ];
    }

    if ($reason !== '') {
        $tableRows[] = ['備考', nl2br(reservation_mail_escape($reason))];
    }

    if ($switchbotStatus !== '' && $status === 'error') {
        $tableRows[] = ['SwitchBot 状態', reservation_mail_escape($switchbotStatus)];
    }

    $rowsHtml = '';
    foreach ($tableRows as [$label, $valueHtml]) {
        $rowsHtml .= <<<HTML
<tr>
  <td style="width:180px;padding:12px 14px;background:#f9fafb;border-bottom:1px solid #e5e7eb;font-size:14px;font-weight:700;">{$label}</td>
  <td style="padding:12px 14px;border-bottom:1px solid #e5e7eb;font-size:14px;line-height:1.8;">{$valueHtml}</td>
</tr>
HTML;
    }

    $bodyParts[] = <<<HTML
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;margin:18px 0 16px;">
  {$rowsHtml}
</table>
HTML;

    if ($status === 'confirmed') {
        $bodyParts[] = '<p style="margin:0;font-size:13px;line-height:1.8;color:#6b7280;">※ パスコードの有効期間は利用日当日のみを想定しています。変更がある場合は総合管理事務局から別途ご連絡します。</p>';
    } else {
        $bodyParts[] = '<p style="margin:0;font-size:13px;line-height:1.8;color:#6b7280;">※ 必要に応じて内容を見直したうえで、再度お申し込みください。</p>';
    }

    return [
        'to' => (string)($reservation['email'] ?? ''),
        'subject' => $subject,
        'body' => trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", implode('', $bodyParts)))),
        'html_body' => reservation_mail_card($subject, implode('', $bodyParts)),
    ];
}

function build_admin_notice_mail(array $reservation): array
{
    $status = (string)($reservation['reservation_status'] ?? '');
    $title = '【貸し部屋予約】新規予約処理通知';
    $statusLabel = match ($status) {
        'confirmed' => '確定',
        'rejected' => '自動却下',
        'error' => '要確認',
        default => $status,
    };

    $rows = [
        ['処理結果', reservation_mail_escape($statusLabel)],
        ['メールアドレス', reservation_mail_escape((string)($reservation['email'] ?? ''))],
        ['団体名', reservation_mail_escape((string)($reservation['organization_name'] ?? ''))],
        ['部屋', reservation_mail_escape((string)($reservation['room_label'] ?? ''))],
        ['利用日', reservation_mail_escape((string)($reservation['use_date'] ?? ''))],
        ['パスコード', reservation_mail_escape((string)($reservation['access_code'] ?? ''))],
        ['SwitchBot状態', reservation_mail_escape((string)($reservation['switchbot_status'] ?? ''))],
        ['理由 / メッセージ', nl2br(reservation_mail_escape((string)($reservation['status_reason'] ?? $reservation['switchbot_message'] ?? '')))],
    ];

    $rowsHtml = '';
    foreach ($rows as [$label, $valueHtml]) {
        $rowsHtml .= <<<HTML
<tr>
  <td style="width:180px;padding:12px 14px;background:#f9fafb;border-bottom:1px solid #e5e7eb;font-size:14px;font-weight:700;">{$label}</td>
  <td style="padding:12px 14px;border-bottom:1px solid #e5e7eb;font-size:14px;line-height:1.8;">{$valueHtml}</td>
</tr>
HTML;
    }

    $bodyHtml = <<<HTML
<p style="margin:0 0 16px;font-size:16px;line-height:1.9;">利用者側フォームから新しい予約処理が実行されました。</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
  {$rowsHtml}
</table>
HTML;

    return [
        'to' => '',
        'subject' => $title,
        'body' => trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $bodyHtml))),
        'html_body' => reservation_mail_card($title, $bodyHtml),
    ];
}
