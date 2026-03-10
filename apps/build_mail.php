<?php

require_once __DIR__ . '/mail_html_templates.php';

function build_mail_from_request(array $cfg): array
{
    // ---- mail（必須）----
    $mail = trim((string)($_POST['email'] ?? ''));

    // ヘッダインジェクション対策（改行除去）
    $mailSafe = str_replace(["\r", "\n"], '', $mail);

    // 部屋の選択
    $room = trim((string)($_POST['select'] ?? ''));
    if (!in_array($room, ['tamoku', 'orange'], true)) {
        throw new RuntimeException('部屋の選択が不正です。');
    }
    $roomName = ($room === 'tamoku') ? '多目的室' : 'オレンジの部屋';

    // ---- text（任意）----
    $text = trim((string)($_POST['text'] ?? ''));

    // ---- file（必須）----
    $f = $_FILES['file'];

    // PHPのアップロードエラー確認
    if (!isset($f['error']) || is_array($f['error'])) {
        throw new RuntimeException('file の形式が不正です。');
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('file のアップロードに失敗しました（error=' . $f['error'] . '）。');
    }

    // 拡張子制限
    $origName = (string)$f['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowedExt = ['pdf', 'docx'];
    if ($ext === '' || !in_array($ext, $allowedExt, true)) {
        throw new RuntimeException('許可されていないファイル拡張子です。');
    }

    // MIMEチェック（拡張子だけより安全）
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($f['tmp_name']) ?: 'application/octet-stream';
    $allowedMime = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    if (!in_array($mime, $allowedMime, true)) {
        throw new RuntimeException('許可されていないファイル形式（MIME）です。');
    }

    // 添付ファイル名の安全化（怪しい文字を除去）
    $safeFilename = preg_replace('/[^\w.\-() ]+/u', '_', $origName);
    if ($safeFilename === '' || $safeFilename === null) {
        $safeFilename = 'attachment.' . $ext;
    }

    $sentAt = date('Y-m-d H:i:s');

    // ---- プレーンテキスト本文 ----
    $lines = [
        "【フォーム送信】",
        "送信者メールアドレス: {$mailSafe}",
        "予約する部屋: {$roomName}",
        "添付: {$safeFilename}",
        "備考:",
        ($text !== '' ? $text : 'なし'),
        "",
        "送信日時: {$sentAt}",
    ];

    $body = implode("\r\n", $lines) . "\r\n";

    $htmlBody = build_admin_request_html([
        'mail'      => $mailSafe,
        'room_name' => $roomName,
        'filename'  => $safeFilename,
        'text'      => $text,
        'sent_at'   => $sentAt,
    ]);

    return [
        'to'       => $cfg['to_addr'],
        'subject'  => "{$roomName}の予約申請を受け付けました",
        'body'     => $body,
        'html_body'=> $htmlBody,
        'reply_to' => $mailSafe,

        // 添付情報
        'attachment' => [
            'path' => $f['tmp_name'],     // 一時ファイル
            'name' => $safeFilename,      // 添付として表示するファイル名
            'mime' => $mime,
        ],

        // 返信メール用に部屋名も渡す
        'roomName' => $roomName,
        'note'     => $text,
    ];
}
