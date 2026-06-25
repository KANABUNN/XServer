<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_layout.php';
$user = kintone_auth_require_operator_access();
kintone_auth_require_csrf();
// Phase 1では高リスク変更の最終採否を diff.php 側の個別チェックで扱う。
// 値そのものの手動編集UIは、Claude側で業務ルール確定後に拡張する。
kintone_set_flash('error', 'レビュー値の手動編集は未実装です。Phase 1では差分画面で反映対象から除外してください。');
header('Location: ../review.php', true, 302);
exit;
