<?php
declare(strict_types=1);

// CI 用の最小 PHP 環境でも、検索語の ASCII ケースを検証できるようにする。
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $value, ?string $encoding = null): string
    {
        return strtolower($value);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $offset, ?int $length = null, ?string $encoding = null): string
    {
        return substr($value, $offset, $length);
    }
}

require_once __DIR__ . '/../apps/forms_module.php';

function assert_same($expected, $actual, string $message): void
{
    if ($expected === $actual) {
        return;
    }

    fwrite(STDERR, $message . PHP_EOL);
    fwrite(STDERR, 'expected: ' . var_export($expected, true) . PHP_EOL);
    fwrite(STDERR, 'actual:   ' . var_export($actual, true) . PHP_EOL);
    exit(1);
}

assert_same(
    'fit club',
    forms_prepare_submission_organization_search_query("  FIT \t Club  "),
    '団体検索語は小文字化し、前後・連続空白を正規化する'
);
assert_same(
    200,
    strlen(forms_prepare_submission_organization_search_query(str_repeat('A', 205))),
    '団体検索語は入力上限に合わせて200文字へ制限する'
);
assert_same(
    [],
    forms_find_form_ids_by_submission_organization(" \t "),
    '空の団体検索では DB へ接続せず空配列を返す'
);

fwrite(STDOUT, "forms submission organization search tests passed\n");
