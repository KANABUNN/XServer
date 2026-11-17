<?php

declare(strict_types=1);

/**
 * 部員名簿テーブルの移行SQLを apps/roster_core/schema.php の定義から生成する。
 *
 *   php tools/roster_migration_sql.php > apps/roster_core/migrations/001_roster_tables.sql
 *   php tools/roster_migration_sql.php 002 > apps/roster_core/migrations/002_roster_officer_sync.sql
 *   php tools/roster_migration_sql.php --check   # すべての移行SQLとファイルの一致を確認
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/apps/roster_core/schema.php';

$migrations = roster_schema_migrations();
$render = static function (array $migration): string {
    $sql = '';
    foreach ($migration['header'] as $line) {
        $sql .= '-- ' . $line . "\n";
    }
    $sql .= "\nSET NAMES utf8mb4;\n\n";
    foreach ($migration['statements'] as $name => $statement) {
        $sql .= '-- ' . $name . "\n" . trim($statement) . ";\n\n";
    }
    return $sql;
};

if (in_array('--check', $argv, true)) {
    $ok = true;
    foreach ($migrations as $name => $migration) {
        $path = dirname(__DIR__) . '/apps/roster_core/migrations/' . $name . '.sql';
        $current = is_file($path) ? (string)file_get_contents($path) : '';
        if (str_replace("\r\n", "\n", $current) !== $render($migration)) {
            fwrite(STDERR, "migration SQL is out of date: run php tools/roster_migration_sql.php " . substr($name, 0, 3) . " > apps/roster_core/migrations/{$name}.sql\n");
            $ok = false;
        }
    }
    echo $ok ? "ok\n" : '';
    exit($ok ? 0 : 1);
}

$wanted = $argv[1] ?? '001';
foreach ($migrations as $name => $migration) {
    if (str_starts_with($name, $wanted)) {
        echo $render($migration);
        exit(0);
    }
}
fwrite(STDERR, "unknown migration: {$wanted}\n");
exit(1);
