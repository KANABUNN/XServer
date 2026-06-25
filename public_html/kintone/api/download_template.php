<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_layout.php';
require_once dirname(__DIR__, 3) . '/apps/kintone_core/upload_helpers.php';
kintone_auth_require_view_access();
kintone_auth_require_csrf();
$rows = [
    ['団体ID','団体名','氏名','メールアドレス','役職','在籍状態','活動可否'],
    ['A001','情報管理局','山田太郎','yamada@example.com','代表','在籍','可'],
    ['A001','情報管理局','佐藤花子','sato@example.com','副代表','在籍','可'],
];
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="kintone_roster_template.csv"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
foreach ($rows as $row) {
    fputcsv($out, array_map(static fn($v): string => kintone_csv_safe_cell((string)$v), $row));
}
fclose($out);
