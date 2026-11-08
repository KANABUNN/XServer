<?php

declare(strict_types=1);

/**
 * 部員名簿テーブルの移行SQLを apps/roster_core/schema.php の定義から生成する。
 *
 *   php tools/roster_migration_sql.php > apps/roster_core/migrations/001_roster_tables.sql
 *   php tools/roster_migration_sql.php --check   # 生成結果とファイルの一致を確認
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/apps/roster_core/schema.php';

$sql = "-- 部員名簿（Forms 提出 → 担当者 accept → kintone ID20 反映）用テーブル\n"
    . "-- 対象DB: fitsc_forms（managed_forms が存在すること）\n"
    . "-- 既存テーブルは変更しない。CREATE TABLE IF NOT EXISTS のため再実行しても既存データは保持される。\n"
    . "-- 生成: php tools/roster_migration_sql.php （apps/roster_core/schema.php が正）\n\n"
    . "SET NAMES utf8mb4;\n\n";
foreach (roster_schema_statements() as $table => $statement) {
    $sql .= '-- ' . $table . "\n" . trim($statement) . ";\n\n";
}

if (in_array('--check', $argv, true)) {
    $path = dirname(__DIR__) . '/apps/roster_core/migrations/001_roster_tables.sql';
    $current = is_file($path) ? (string)file_get_contents($path) : '';
    if (str_replace("\r\n", "\n", $current) !== $sql) {
        fwrite(STDERR, "migration SQL is out of date: run php tools/roster_migration_sql.php > apps/roster_core/migrations/001_roster_tables.sql\n");
        exit(1);
    }
    echo "ok\n";
    exit(0);
}

echo $sql;
