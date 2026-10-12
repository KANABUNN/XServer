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
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:760px;background:#ffffff;border:1px solid #e5e7eb;border-radius:16px;overflow:hidden;">
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

function reservation_mail_rows(array $reservation): array
{
    $rows = [];
    foreach (($reservation['date_rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rows[] = [
            'use_date' => (string)($row['use_date'] ?? ''),
            'room_label' => (string)($row['room_label'] ?? ''),
            'usage_time' => (string)($row['usage_time'] ?? ''),
            'access_code' => (string)($row['access_code'] ?? ''),
            'switchbot_status' => (string)($row['switchbot_status'] ?? ''),
            'google_sync_status' => (string)($row['google_sync_status'] ?? ''),
        ];
    }
    return $rows;
}

function reservation_mail_dates_html(array $dateRows, bool $withCodes = false): string
{
    if ($dateRows === []) {
        return '<p style="margin:0;">日付情報を取得できませんでした。</p>';
    }

    $items = '';
    foreach ($dateRows as $row) {
        $date = reservation_mail_escape((string)($row['use_date'] ?? ''));
        $room = reservation_mail_escape((string)($row['room_label'] ?? ''));
        $usage = reservation_mail_escape((string)($row['usage_time'] ?? ''));
        $code = reservation_mail_escape((string)($row['access_code'] ?? ''));
        $extra = $withCodes && $code !== '' ? ' / パスコード: <strong>' . $code . '</strong>' : '';
        $items .= '<li style="margin:0 0 8px;">' . $date . ' / ' . $room . ' / ' . $usage . $extra . '</li>';
    }

    return '<ul style="margin:0;padding-left:1.4em;line-height:1.9;">' . $items . '</ul>';
}

function build_user_result_mail(array $reservation): array
{
    $status = (string)($reservation['reservation_status'] ?? '');
    $organizationName = (string)($reservation['organization_name'] ?? '');
    $reason = (string)($reservation['status_reason'] ?? '');
    $switchbotStatus = (string)($reservation['switchbot_status'] ?? '');
    $dateRows = reservation_mail_rows($reservation);

    $subject = $status === 'confirmed'
        ? '【貸し部屋予約】予約確定のお知らせ'
        : '【貸し部屋予約】予約結果のお知らせ';

    $bodyParts = [];
    if ($status === 'confirmed') {
        $bodyParts[] = '<p style="margin:0 0 16px;font-size:16px;line-height:1.9;">予約が確定しました。以下の内容をご確認ください。</p>';
    } elseif ($status === 'error') {
        $bodyParts[] = '<p style="margin:0 0 16px;font-size:16px;line-height:1.9;">予約情報は登録されましたが、一部の自動連携で確認事項があります。管理側からの連絡もご確認ください。</p>';
    } else {
        $bodyParts[] = '<p style="margin:0 0 16px;font-size:16px;line-height:1.9;">今回の予約は受け付けできませんでした。理由をご確認ください。</p>';
    }

    $tableRows = [
        ['メールアドレス', reservation_mail_escape((string)($reservation['email'] ?? ''))],
        ['団体名', reservation_mail_escape($organizationName)],
        ['選択日数', reservation_mail_escape((string)($reservation['selected_dates_count'] ?? '0')) . '日'],
        ['予約内容', reservation_mail_dates_html($dateRows, in_array($status, ['confirmed', 'error'], true))],
    ];

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
  <td style="width:180px;padding:12px 14px;background:#f9fafb;border-bottom:1px solid #e5e7eb;font-size:14px;font-weight:700;vertical-align:top;">{$label}</td>
  <td style="padding:12px 14px;border-bottom:1px solid #e5e7eb;font-size:14px;line-height:1.8;">{$valueHtml}</td>
</tr>
HTML;
    }

    $bodyParts[] = <<<HTML
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;margin:18px 0 16px;">
  {$rowsHtml}
</table>
HTML;

    $bodyParts[] = '<p style="margin:0;font-size:13px;line-height:1.8;color:#6b7280;">※ SwitchBot のパスコード有効期間は、各日付で指定した利用時間の前後10分を自動的に含めて発行されます。</p>';

    return [
        'to' => (string)($reservation['email'] ?? ''),
        'subject' => $subject,
        'body' => trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "
", implode('', $bodyParts)))),
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
    $dateRows = reservation_mail_rows($reservation);

    $rows = [
        ['処理結果', reservation_mail_escape($statusLabel)],
        ['メールアドレス', reservation_mail_escape((string)($reservation['email'] ?? ''))],
        ['団体名', reservation_mail_escape((string)($reservation['organization_name'] ?? ''))],
        ['選択日数', reservation_mail_escape((string)($reservation['selected_dates_count'] ?? '0')) . '日'],
        ['予約内容', reservation_mail_dates_html($dateRows, true)],
        ['SwitchBot状態', reservation_mail_escape((string)($reservation['switchbot_status'] ?? ''))],
        ['Google連携', reservation_mail_escape((string)($reservation['google_sync_status'] ?? ''))],
        ['理由 / メッセージ', nl2br(reservation_mail_escape((string)($reservation['status_reason'] ?? $reservation['switchbot_message'] ?? '')))],
    ];

    $rowsHtml = '';
    foreach ($rows as [$label, $valueHtml]) {
        $rowsHtml .= <<<HTML
<tr>
  <td style="width:180px;padding:12px 14px;background:#f9fafb;border-bottom:1px solid #e5e7eb;font-size:14px;font-weight:700;vertical-align:top;">{$label}</td>
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
        'body' => trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "
", $bodyHtml))),
        'html_body' => reservation_mail_card($title, $bodyHtml),
    ];
}
