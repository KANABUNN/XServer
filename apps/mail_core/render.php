<?php

declare(strict_types=1);

function mail_extract_template_variables(string $template): array
{
    if (!preg_match_all('/{{\s*([a-zA-Z0-9_\-\x{3040}-\x{30ff}\x{3400}-\x{9fff}]+)\s*}}/u', $template, $matches)) {
        return [];
    }
    $vars = [];
    foreach ($matches[1] as $key) {
        $key = trim((string)$key);
        if ($key !== '') {
            $vars[$key] = $key;
        }
    }
    return array_values($vars);
}

function mail_render_template(string $template, array $context): array
{
    $unresolved = [];
    $rendered = preg_replace_callback('/{{\s*([^}]+)\s*}}/u', static function (array $match) use ($context, &$unresolved): string {
        $key = trim((string)$match[1]);
        if (array_key_exists($key, $context)) {
            return (string)$context[$key];
        }
        $unresolved[$key] = $key;
        return $match[0];
    }, $template);

    return [
        'rendered' => (string)$rendered,
        'unresolved' => array_values($unresolved),
    ];
}

function mail_organization_context(array $organization): array
{
    return [
        '区分' => (string)($organization['category'] ?? ''),
        '識別番号' => (string)($organization['identifier'] ?? ''),
        '団体名' => (string)($organization['name'] ?? ''),
        '代表者氏名' => (string)($organization['representative_name'] ?? ''),
        'メールアドレス' => (string)($organization['email'] ?? ''),
        'category' => (string)($organization['category'] ?? ''),
        'identifier' => (string)($organization['identifier'] ?? ''),
        'organization_name' => (string)($organization['name'] ?? ''),
        'representative_name' => (string)($organization['representative_name'] ?? ''),
        'email' => (string)($organization['email'] ?? ''),
    ];
}
