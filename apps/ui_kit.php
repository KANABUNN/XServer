<?php

declare(strict_types=1);

/**
 * FIT-SC UI v7 の PHP ヘルパー。
 *
 * 各サイトは独立したドキュメントルート（サブドメイン）で動くため、
 * UI アセットは各サイトの assets/ui/ に複製して配置する（正本: public_html/assets/ui/）。
 * ここではページから見た相対パスでアセットを参照し、ファイル更新時刻でキャッシュを無効化する。
 */

if (!function_exists('fitsc_ui_e')) {
    function fitsc_ui_e(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('fitsc_ui_asset')) {
    /**
     * assets/ui/ 配下のファイル URL を返す（?v=更新時刻 付き）。
     */
    function fitsc_ui_asset(string $file, string $base = 'assets/ui/'): string
    {
        $relative = $base . ltrim($file, '/');
        $scriptDir = dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $candidate = $scriptDir . '/' . $relative;
        if ($scriptDir !== '' && is_file($candidate)) {
            return $relative . '?v=' . filemtime($candidate);
        }
        return $relative;
    }
}

if (!function_exists('fitsc_ui_fonts')) {
    function fitsc_ui_fonts(): string
    {
        return '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
            . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400..800&amp;family=JetBrains+Mono:wght@500;700&amp;family=Zen+Kaku+Gothic+New:wght@400;500;700&amp;display=swap">' . "\n";
    }
}

if (!function_exists('fitsc_ui_head')) {
    /**
     * <head> 内に出力する共通タグ（フォント・UI CSS/JS・コンテキストメニュー制御）。
     *
     * @param array{css?: string[], js?: string[], guard?: string|false, theme_color?: bool} $opts
     *   css   … 追加で読み込むアプリ CSS（ページからの相対パス）
     *   js    … 追加で読み込むアプリ JS（defer）
     *   guard … context-menu-guard.js のパス（false で読み込まない）
     */
    function fitsc_ui_head(array $opts = []): string
    {
        $html = '<meta name="theme-color" content="#0b0d13">' . "\n";
        $html .= fitsc_ui_fonts();
        $html .= '<link rel="stylesheet" href="' . fitsc_ui_e(fitsc_ui_asset('ui.css')) . '">' . "\n";
        foreach (($opts['css'] ?? []) as $css) {
            $html .= '<link rel="stylesheet" href="' . fitsc_ui_e(fitsc_ui_versioned((string)$css)) . '">' . "\n";
        }
        $html .= '<script src="' . fitsc_ui_e(fitsc_ui_asset('ui.js')) . '"></script>' . "\n";
        $guard = $opts['guard'] ?? 'assets/common/context-menu-guard.js';
        if ($guard !== false && $guard !== '') {
            $html .= '<script src="' . fitsc_ui_e(fitsc_ui_versioned((string)$guard)) . '" defer></script>' . "\n";
        }
        foreach (($opts['js'] ?? []) as $js) {
            $html .= '<script src="' . fitsc_ui_e(fitsc_ui_versioned((string)$js)) . '" defer></script>' . "\n";
        }
        return $html;
    }
}

if (!function_exists('fitsc_ui_versioned')) {
    /**
     * 任意のページ相対パスに ?v=更新時刻 を付ける。
     */
    function fitsc_ui_versioned(string $path): string
    {
        if ($path === '' || preg_match('/^(https?:|\/\/|data:)/i', $path) === 1) {
            return $path;
        }
        $pathOnly = preg_split('/[?#]/', $path, 2)[0] ?? $path;
        $scriptDir = dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $candidate = $scriptDir . '/' . ltrim($pathOnly, '/');
        if ($scriptDir !== '' && is_file($candidate)) {
            return $pathOnly . '?v=' . filemtime($candidate);
        }
        return $path;
    }
}

if (!function_exists('fitsc_icon')) {
    /**
     * SVG スプライトのアイコン。
     */
    function fitsc_icon(string $name, string $class = ''): string
    {
        static $sprite = null;
        if ($sprite === null) {
            $sprite = fitsc_ui_asset('icons.svg');
        }
        $cls = 'ico' . ($class !== '' ? ' ' . $class : '');
        return '<svg class="' . fitsc_ui_e($cls) . '" aria-hidden="true" focusable="false"><use href="' . fitsc_ui_e($sprite) . '#i-' . fitsc_ui_e($name) . '"></use></svg>';
    }
}

if (!function_exists('fitsc_theme_toggle')) {
    /**
     * テーマ切替ボタン（太陽 / 月アイコンがモーション付きで入れ替わる）。
     */
    function fitsc_theme_toggle(string $extraClass = ''): string
    {
        return '<button type="button" class="btn btn--ghost btn--icon theme-toggle' . ($extraClass !== '' ? ' ' . fitsc_ui_e($extraClass) : '') . '" data-theme-toggle aria-label="テーマを切り替え" title="テーマを切り替え">'
            . fitsc_icon('sun', 'ico--sun')
            . fitsc_icon('moon', 'ico--moon')
            . '</button>';
    }
}

if (!function_exists('fitsc_initials')) {
    /**
     * アバター用の頭文字（日本語名は先頭1文字、英字は2文字）。
     */
    function fitsc_initials(string $name): string
    {
        $name = trim($name);
        if ($name === '' || preg_match('/^./u', $name, $m) !== 1) {
            return '?';
        }
        $first = $m[0];
        if (preg_match('/^[A-Za-z]$/', $first) === 1) {
            $parts = preg_split('/[\s._-]+/', $name) ?: [$name];
            $second = '';
            if (isset($parts[1]) && preg_match('/^./u', $parts[1], $m2) === 1) {
                $second = $m2[0];
            } elseif (preg_match('/^.(.)/u', $name, $m3) === 1) {
                $second = $m3[1];
            }
            return strtoupper($first . $second);
        }
        return $first;
    }
}

if (!function_exists('fitsc_flash_html')) {
    /**
     * サーバー側メッセージ。JS 有効時はトーストに変換され、無効時はこのまま表示される。
     */
    function fitsc_flash_html(string $type, string $message, string $title = ''): string
    {
        $map = [
            'success' => ['success', 'check-circle'],
            'info' => ['info', 'info'],
            'warn' => ['warning', 'alert-triangle'],
            'warning' => ['warning', 'alert-triangle'],
            'error' => ['danger', 'x-circle'],
            'danger' => ['danger', 'x-circle'],
        ];
        [$tone, $icon] = $map[$type] ?? ['info', 'info'];
        $toastType = $tone === 'danger' ? 'error' : $tone;
        return '<div class="alert alert--' . $tone . '" role="status" data-toast="' . $toastType . '"'
            . ' data-toast-text="' . fitsc_ui_e($message) . '"'
            . ($title !== '' ? ' data-toast-title="' . fitsc_ui_e($title) . '"' : '') . '>'
            . fitsc_icon($icon)
            . '<div class="alert__body">' . nl2br(fitsc_ui_e($message)) . '</div></div>';
    }
}
