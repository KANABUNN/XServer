<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function mail_html_purifier(): HTMLPurifier
{
    static $purifier = null;
    if ($purifier instanceof HTMLPurifier) {
        return $purifier;
    }

    if (!class_exists('HTMLPurifier')) {
        foreach ([
            dirname(mail_apps_dir()) . '/vendor/autoload.php', // リポジトリ直下 /vendor
            mail_apps_dir() . '/vendor/autoload.php',
            mail_core_dir() . '/vendor/autoload.php',
        ] as $autoload) {
            if (is_file($autoload)) {
                require_once $autoload;
                break;
            }
        }
    }
    if (!class_exists('HTMLPurifier')) {
        throw new RuntimeException('HTML Purifier が読み込めません。リポジトリ直下で composer require ezyang/htmlpurifier を実行し、vendor/ を配置してください。');
    }

    $config = HTMLPurifier_Config::createDefault();
    $config->set('Core.Encoding', 'UTF-8');
    $config->set('HTML.Doctype', 'HTML 4.01 Transitional');

    // メール本文として許可する要素・属性（エディタのツールバー機能に対応）
    $config->set('HTML.Allowed',
        'p,br,span[style],div[style],strong,b,em,i,u,font[color|size|face],'
        . 'ul,ol,li,blockquote,h1,h2,h3,h4,h5,h6,hr,'
        . 'a[href|title|target],'
        . 'table[border|cellpadding|cellspacing|style],thead,tbody,tr,'
        . 'td[style|colspan|rowspan],th[style|colspan|rowspan]'
    );
    // 文字色・サイズなど、ツールバーで使うCSSのみ許可
    $config->set('CSS.AllowedProperties',
        'color,background-color,font-size,font-weight,font-style,text-align,text-decoration'
    );
    // リンクは http / https / mailto のみ。javascript: data: 等は除去
    $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
    $config->set('Attr.AllowedFrameTargets', ['_blank']);
    $config->set('HTML.TargetNoopener', true);
    $config->set('HTML.TargetNoreferrer', true);

    // 定義キャッシュ先（書込可能なら高速化、不可ならキャッシュ無効で動作）
    $cacheDir = mail_apps_dir() . '/storage/mail_htmlpurifier_cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0750, true);
    }
    if (is_dir($cacheDir) && is_writable($cacheDir)) {
        $config->set('Cache.SerializerPath', $cacheDir);
    } else {
        $config->set('Cache.DefinitionImpl', null);
    }

    $purifier = new HTMLPurifier($config);
    return $purifier;
}

// HTML Purifier 不在時のフォールバック（従来の正規表現サニタイズ相当）
function mail_fallback_sanitize_html(string $html): string
{
    $html = preg_replace('/<\s*(script|style|iframe|object|embed|form|input|button|meta|link)\b[^>]*>[\s\S]*?<\s*\/\s*\1\s*>/i', '', $html) ?? $html;
    $html = preg_replace('/<\s*(script|style|iframe|object|embed|form|input|button|meta|link)\b[^>]*\/?>/i', '', $html) ?? $html;
    $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
    $html = preg_replace('/javascript\s*:/i', '', $html) ?? $html;
    return $html;
}

function mail_purify_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    try {
        return mail_html_purifier()->purify($html);
    } catch (Throwable $e) {
        // 未導入・設定不備時もアプリを止めず、従来相当のサニタイズへ退避
        error_log('[mail html_purify fallback] ' . $e->getMessage());
        return mail_fallback_sanitize_html($html);
    }
}
