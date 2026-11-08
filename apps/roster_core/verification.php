<?php

declare(strict_types=1);

/* =====================================================================
   団体確認
   団体名・メールの自己申告では名簿を表示しない。団体識別記号を選んでもらい、
   kintone ID7 に登録された団体メールへ確認コードを送る。コードを入力できたセッションだけが
   その団体の名簿を扱える（団体識別記号は常にサーバー側のセッションから決める）。
   ===================================================================== */

function roster_verification_request(array $form, string $groupCode, string $sessionId, string $ipAddress): array
{
    $form = roster_require_roster_form($form);
    $code = roster_normalize_group_code($groupCode);
    $pdo = roster_db();
    $group = $code !== '' ? roster_directory_get($code, $pdo) : null;
    if (!is_array($group) || !roster_directory_is_eligible($group)) {
        throw new RosterException('名簿提出の対象団体として確認できませんでした。団体識別記号をご確認ください。', 'group_not_found', 404);
    }
    $email = trim((string)$group['group_email']);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RosterException('この団体の団体メールが登録されていないため確認できません。総合管理事務局へご連絡ください。', 'no_email', 409);
    }
    $ipHash = hash('sha256', $ipAddress);
    $since = roster_now_sql(-3600);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM roster_group_verifications WHERE group_code = :code AND created_at >= :since');
    $stmt->execute([':code' => $code, ':since' => $since]);
    if ((int)$stmt->fetchColumn() >= (int)roster_config('verification.max_requests_per_group_per_hour', 5)) {
        throw new RosterException('この団体への確認コードの送信回数が上限に達しました。1時間ほどおいてから再度お試しください。', 'rate_limited', 429);
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM roster_group_verifications WHERE request_ip_hash = :ip AND created_at >= :since');
    $stmt->execute([':ip' => $ipHash, ':since' => $since]);
    if ((int)$stmt->fetchColumn() >= (int)roster_config('verification.max_requests_per_ip_per_hour', 20)) {
        throw new RosterException('確認コードの送信回数が上限に達しました。時間をおいて再度お試しください。', 'rate_limited', 429);
    }

    $verifyCode = sprintf('%06d', random_int(0, 999999));
    $salt = bin2hex(random_bytes(16));
    $ttl = max(5, (int)roster_config('verification.code_ttl_minutes', 15));
    $expiresAt = roster_now_sql($ttl * 60);
    $masked = roster_mask_email($email);
    $pdo->prepare(
        'INSERT INTO roster_group_verifications (form_id, group_code, email_masked, code_salt, code_hash, session_ref, expires_at, request_ip_hash, created_at) '
        . 'VALUES (:form_id, :code, :masked, :salt, :hash, :session, :expires, :ip, :now)'
    )->execute([
        ':form_id' => $form['id'],
        ':code' => $code,
        ':masked' => $masked,
        ':salt' => $salt,
        ':hash' => hash('sha256', $salt . ':' . $verifyCode),
        ':session' => hash('sha256', $sessionId),
        ':expires' => $expiresAt,
        ':ip' => $ipHash,
        ':now' => roster_now_sql(),
    ]);
    $verificationId = (int)$pdo->lastInsertId();

    $body = (string)$group['group_name'] . " 御中\n\n"
        . '提出フォーム「' . $form['name'] . "」で部員名簿の入力を始めるための確認コードです。\n\n"
        . '確認コード：' . $verifyCode . "\n"
        . '有効期限：' . substr($expiresAt, 0, 16) . ' まで（' . $ttl . "分間）\n\n"
        . "画面に確認コードを入力すると、この団体の部員名簿を編集・提出できます。\n"
        . "このメールに心当たりがない場合は破棄してください。コードが入力されない限り名簿は表示されません。\n\n"
        . "福岡工業大学 学生自治会 総合管理事務局\n";
    try {
        roster_send_mail($email, (string)roster_config('verification.mail_subject', '【部員名簿】団体確認コードのお知らせ'), $body);
    } catch (Throwable $e) {
        $pdo->prepare('UPDATE roster_group_verifications SET expires_at = created_at WHERE id = :id')->execute([':id' => $verificationId]);
        roster_log('error', 'verification mail failed', ['group' => $code, 'error' => mb_substr($e->getMessage(), 0, 300, 'UTF-8')]);
        throw new RosterException('確認メールを送信できませんでした。時間をおいて再度お試しください。', 'mail_failed', 502);
    }
    return ['verification_id' => $verificationId, 'email_masked' => $masked, 'expires_at' => $expiresAt, 'group_code' => $code, 'group_name' => (string)$group['group_name']];
}

function roster_verification_confirm(array $form, int $verificationId, string $inputCode, string $sessionId): array
{
    $inputCode = preg_replace('/\D/', '', mb_convert_kana($inputCode, 'n', 'UTF-8')) ?? '';
    $pdo = roster_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM roster_group_verifications WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $verificationId]);
        $row = $stmt->fetch();
        if (!is_array($row) || (int)$row['form_id'] !== (int)$form['id'] || !hash_equals((string)$row['session_ref'], hash('sha256', $sessionId))) {
            throw new RosterException('確認コードの有効期限が切れたか、別の画面で発行されたものです。もう一度コードを送信してください。', 'expired', 410);
        }
        if ($row['verified_at'] !== null) {
            throw new RosterException('この確認コードは使用済みです。もう一度コードを送信してください。', 'expired', 410);
        }
        if (roster_now_sql() > (string)$row['expires_at']) {
            throw new RosterException('確認コードの有効期限が切れました。もう一度コードを送信してください。', 'expired', 410);
        }
        $max = max(1, (int)roster_config('verification.max_attempts', 5));
        if ((int)$row['attempts'] >= $max) {
            throw new RosterException('確認コードの入力回数が上限に達しました。もう一度コードを送信してください。', 'expired', 410);
        }
        $attempts = (int)$row['attempts'] + 1;
        $ok = strlen($inputCode) === 6 && hash_equals((string)$row['code_hash'], hash('sha256', $row['code_salt'] . ':' . $inputCode));
        $pdo->prepare('UPDATE roster_group_verifications SET attempts = :attempts' . ($ok ? ', verified_at = :now' : '') . ' WHERE id = :id')->execute(
            $ok ? [':attempts' => $attempts, ':now' => roster_now_sql(), ':id' => $verificationId] : [':attempts' => $attempts, ':id' => $verificationId]
        );
        $pdo->commit();
        if (!$ok) {
            $left = $max - $attempts;
            throw new RosterException($left > 0 ? '確認コードが違います（あと' . $left . '回入力できます）。' : '確認コードの入力回数が上限に達しました。もう一度コードを送信してください。', $left > 0 ? 'wrong_code' : 'expired', $left > 0 ? 422 : 410);
        }
        $group = roster_directory_get((string)$row['group_code'], $pdo);
        return [
            'verification_id' => $verificationId,
            'group_code' => (string)$row['group_code'],
            'group_name' => (string)($group['group_name'] ?? ''),
            'email_masked' => (string)$row['email_masked'],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function roster_send_mail(string $to, string $subject, string $body): void
{
    $transport = (string)roster_config('verification.mail_transport', 'smtp');
    if ($transport === 'log') {
        if (getenv('FITSC_ROSTER_DEV_MAIL') !== '1') {
            throw new RuntimeException('開発用のメール出力は環境変数 FITSC_ROSTER_DEV_MAIL=1 のときだけ使えます。');
        }
        file_put_contents(kintone_storage_root() . '/roster_dev_mail.log', roster_now_sql() . "\nTo: " . $to . "\nSubject: " . $subject . "\n\n" . $body . "\n----\n", FILE_APPEND | LOCK_EX);
        return;
    }
    require_once dirname(__DIR__) . '/smtp_mailer.php';
    $cfg = kintone_load_config();
    $cfg['from_name'] = (string)roster_config('verification.mail_from_name', (string)($cfg['from_name'] ?? ''));
    send_mail_smtp($cfg, ['to' => $to, 'subject' => $subject, 'body' => $body]);
}

/** 確認済みの団体（サーバー側セッション）。期限切れ・対象外になった団体は null。 */
function roster_session_group(array $session, int $formId): ?array
{
    $auth = $session['roster_auth'][$formId] ?? null;
    if (!is_array($auth) || (int)($auth['expires_at'] ?? 0) < time()) {
        return null;
    }
    $group = roster_directory_get((string)($auth['group_code'] ?? ''));
    if (!is_array($group) || !roster_directory_is_eligible($group)) {
        return null;
    }
    return ['auth' => $auth, 'group' => $group];
}

function roster_purge_old_verifications(PDO $pdo): int
{
    $days = max(1, (int)roster_config('worker.verification_retention_days', 30));
    $stmt = $pdo->prepare('DELETE FROM roster_group_verifications WHERE created_at < :before');
    $stmt->execute([':before' => roster_now_sql(-$days * 86400)]);
    return $stmt->rowCount();
}
