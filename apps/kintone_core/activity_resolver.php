<?php

declare(strict_types=1);

require_once __DIR__ . '/organization_normalizer.php';

function kintone_resolve_activity(array $rows, ?array $representativeResult): array
{
    $hints = [];
    foreach ($rows as $row) {
        $hint = (string)($row['activity_hint'] ?? 'unknown');
        if ($hint !== 'unknown') {
            $hints[$hint] = true;
        }
    }
    if (count($hints) === 1) {
        $status = array_key_first($hints);
        return ['status' => $status, 'source' => 'csv_column', 'reason' => $status === 'inactive' ? 'deactivation' : null, 'message' => 'CSVの活動可否列を優先しました。'];
    }
    if (count($hints) > 1) {
        return ['status' => 'needs_review', 'source' => 'unresolved', 'reason' => 'activity_unknown', 'message' => '同一団体内で活動可否の値が一致していません。'];
    }
    $activeCount = 0;
    foreach ($rows as $row) {
        if (($row['member_status'] ?? 'unknown') === 'active') {
            $activeCount++;
        }
    }
    if ($activeCount > 0 && is_array($representativeResult) && ($representativeResult['status'] ?? '') === 'decided') {
        return ['status' => 'active', 'source' => 'derived', 'reason' => null, 'message' => '在籍部員と有効な代表メールから活動可と判定しました。'];
    }
    if ($activeCount === 0) {
        return ['status' => 'inactive', 'source' => 'derived', 'reason' => 'deactivation', 'message' => '在籍扱いの部員が0人のため活動不可候補です。'];
    }
    return ['status' => 'needs_review', 'source' => 'unresolved', 'reason' => 'activity_unknown', 'message' => '活動可否を自動判定できませんでした。'];
}
