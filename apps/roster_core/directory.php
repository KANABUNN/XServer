<?php

declare(strict_types=1);

/* =====================================================================
   団体一覧（ID7 由来のキャッシュ）
   Forms の団体選択・団体確認メールの宛先に使う。kintone 停止中でも確認済みの団体は編集・提出を続けられる。
   ===================================================================== */

function roster_directory_refresh(?PDO $pdo = null): array
{
    $pdo = $pdo ?? roster_db();
    $groups = roster_kintone_fetch_groups();
    $now = roster_now_sql();
    $stmt = $pdo->prepare(
        'INSERT INTO roster_group_directory (group_code, group_name, group_rank, activity_status, group_email, kintone_record_id, kintone_revision, is_present, refreshed_at) '
        . 'VALUES (:code, :name, :rank, :status, :email, :record_id, :revision, 1, :now) '
        . 'ON DUPLICATE KEY UPDATE group_name = VALUES(group_name), group_rank = VALUES(group_rank), activity_status = VALUES(activity_status), '
        . 'group_email = VALUES(group_email), kintone_record_id = VALUES(kintone_record_id), kintone_revision = VALUES(kintone_revision), is_present = 1, refreshed_at = VALUES(refreshed_at)'
    );
    $pdo->beginTransaction();
    try {
        foreach ($groups as $g) {
            $stmt->execute([
                ':code' => $g['group_code'],
                ':name' => mb_substr($g['group_name'], 0, 255, 'UTF-8'),
                ':rank' => mb_substr($g['group_rank'], 0, 50, 'UTF-8'),
                ':status' => mb_substr($g['activity_status'], 0, 50, 'UTF-8'),
                ':email' => mb_substr($g['group_email'], 0, 255, 'UTF-8'),
                ':record_id' => $g['kintone_record_id'] ?: null,
                ':revision' => $g['kintone_revision'] ?: null,
                ':now' => $now,
            ]);
        }
        // 今回の取得に無かった団体は削除せず「不在」にする（過去の提出履歴の表示に使うため）
        $pdo->prepare('UPDATE roster_group_directory SET is_present = 0 WHERE refreshed_at < :now')->execute([':now' => $now]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['count' => count($groups), 'refreshed_at' => $now];
}

function roster_directory_status(?PDO $pdo = null): array
{
    $pdo = $pdo ?? roster_db();
    $row = $pdo->query('SELECT COUNT(*) AS total, SUM(is_present = 1) AS present, MAX(refreshed_at) AS refreshed_at FROM roster_group_directory')->fetch() ?: [];
    $refreshedAt = (string)($row['refreshed_at'] ?? '');
    $ageMinutes = null;
    if ($refreshedAt !== '') {
        $ageMinutes = (int)floor((roster_now()->getTimestamp() - (new DateTimeImmutable($refreshedAt, kintone_timezone()))->getTimestamp()) / 60);
    }
    return [
        'total' => (int)($row['total'] ?? 0),
        'present' => (int)($row['present'] ?? 0),
        'refreshed_at' => $refreshedAt,
        'age_minutes' => $ageMinutes,
        'is_stale' => $ageMinutes === null || $ageMinutes > (int)roster_config('groups.directory_max_age_minutes', 360),
    ];
}

function roster_directory_is_eligible(array $group): bool
{
    if ((int)($group['is_present'] ?? 1) !== 1) {
        return false;
    }
    $ranks = (array)roster_config('groups.allowed_ranks', []);
    $statuses = (array)roster_config('groups.allowed_statuses', []);
    if ($ranks !== [] && !in_array((string)($group['group_rank'] ?? ''), $ranks, true)) {
        return false;
    }
    if ($statuses !== [] && !in_array((string)($group['activity_status'] ?? ''), $statuses, true)) {
        return false;
    }
    return true;
}

/** 利用者に見せる団体一覧（メールアドレスは含めない） */
function roster_directory_public_list(?PDO $pdo = null): array
{
    $pdo = $pdo ?? roster_db();
    $rows = $pdo->query('SELECT group_code, group_name, group_rank, activity_status, is_present FROM roster_group_directory ORDER BY group_code ASC')->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $row) {
        if (!roster_directory_is_eligible($row)) {
            continue;
        }
        $out[] = [
            'code' => (string)$row['group_code'],
            'name' => (string)$row['group_name'],
            'rank' => (string)$row['group_rank'],
        ];
    }
    return $out;
}

function roster_directory_get(string $groupCode, ?PDO $pdo = null): ?array
{
    $code = roster_normalize_group_code($groupCode);
    if ($code === '') {
        return null;
    }
    $pdo = $pdo ?? roster_db();
    $stmt = $pdo->prepare('SELECT * FROM roster_group_directory WHERE group_code = :code LIMIT 1');
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}
