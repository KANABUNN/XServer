<?php

declare(strict_types=1);

/**
 * FIT-SC UI v7 の共通アセットを各サイトへ複製する。
 *
 * 正本: public_html/assets/ui/（ui.css / ui.js / icons.svg）
 * 各サイトは独立したドキュメントルート（サブドメイン）で公開されるため、
 * それぞれの assets/ui/ に同じファイルを置く必要がある。
 *
 *   php tools/sync_ui_assets.php           … 複製する
 *   php tools/sync_ui_assets.php --check   … 差分の有無だけ確認（差分があれば終了コード 1）
 */

$root = dirname(__DIR__) . '/public_html';
$source = $root . '/assets/ui';
$files = ['ui.css', 'ui.js', 'icons.svg'];
$sites = ['account', 'admin.book', 'backup', 'book', 'forms', 'kintone', 'lend', 'mail', 'todo'];
$checkOnly = in_array('--check', $argv ?? [], true);

$drift = 0;
foreach ($sites as $site) {
    $targetDir = $root . '/' . $site . '/assets/ui';
    if (!$checkOnly && !is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        fwrite(STDERR, "ディレクトリを作成できません: {$targetDir}\n");
        exit(2);
    }
    foreach ($files as $file) {
        $from = $source . '/' . $file;
        $to = $targetDir . '/' . $file;
        $same = is_file($to) && hash_file('sha256', $from) === hash_file('sha256', $to);
        if ($same) {
            continue;
        }
        $drift++;
        if ($checkOnly) {
            echo "差分あり: {$site}/assets/ui/{$file}\n";
            continue;
        }
        if (!copy($from, $to)) {
            fwrite(STDERR, "コピーに失敗しました: {$to}\n");
            exit(2);
        }
        echo "更新: {$site}/assets/ui/{$file}\n";
    }
}

if ($checkOnly) {
    echo $drift === 0 ? "すべて最新です。\n" : "{$drift} 件の差分があります。\n";
    exit($drift === 0 ? 0 : 1);
}
echo $drift === 0 ? "変更はありません。\n" : "{$drift} ファイルを更新しました。\n";
