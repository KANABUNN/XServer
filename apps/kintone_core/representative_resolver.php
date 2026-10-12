<?php

declare(strict_types=1);

require_once __DIR__ . '/organization_normalizer.php';

function kintone_resolve_representative(array $rows): array
{
    $activeRows = array_values(array_filter($rows, static fn(array $row): bool => ($row['member_status'] ?? 'unknown') !== 'inactive'));
    if ($activeRows === []) {
        return ['status' => 'needs_review', 'reason' => 'rep_missing', 'source' => 'unresolved', 'member' => null, 'message' => '在籍扱いの部員が見つからないため代表者を判定できません。', 'candidates' => []];
    }
    $flagged = array_values(array_filter($activeRows, static fn(array $row): bool => (int)($row['is_representative_candidate'] ?? 0) === 1));
    $pool = $flagged !== [] ? $flagged : $activeRows;
    $maxRank = max(array_map(static fn(array $row): int => (int)($row['role_rank'] ?? 0), $pool));
    if ($maxRank <= 0) {
        return ['status' => 'needs_review', 'reason' => 'rep_missing', 'source' => 'unresolved', 'member' => null, 'message' => '代表候補となる役職が見つかりません。', 'candidates' => []];
    }
    $candidates = array_values(array_filter($pool, static fn(array $row): bool => (int)($row['role_rank'] ?? 0) === $maxRank));
    if (count($candidates) !== 1) {
        return ['status' => 'needs_review', 'reason' => 'rep_ambiguous', 'source' => 'unresolved', 'member' => null, 'message' => '代表候補が複数あります。', 'candidates' => $candidates];
    }
    $member = $candidates[0];
    $email = (string)($member['member_email'] ?? '');
    if (!kintone_email_is_valid($email)) {
        return ['status' => 'needs_review', 'reason' => 'rep_email_invalid', 'source' => 'unresolved', 'member' => $member, 'message' => '代表候補のメールアドレスが空、または形式不正です。', 'candidates' => $candidates];
    }
    return [
        'status' => 'decided',
        'reason' => null,
        'source' => $flagged !== [] ? 'flag' : 'role_rank',
        'member' => $member,
        'message' => sprintf('役職ランク%dの %s を代表者として判定しました。', $maxRank, (string)($member['member_name'] ?? '')),
        'candidates' => $candidates,
    ];
}
