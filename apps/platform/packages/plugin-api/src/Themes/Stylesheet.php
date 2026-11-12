<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Themes;

/**
 * What a theme's stylesheet may say, and what the server makes of it.
 *
 * Only tokens: rules on `:root[data-design]`, narrowed by the attributes
 * Fahoba sets on `<html>` (`data-theme`, `data-look`, `data-backdrop`),
 * that set custom properties from `Tokens`, plus `@font-face` for fonts
 * that lie beside the file. No class, no element, no `@import` — a theme
 * that styled components directly would break with the next release.
 *
 * Light is `:root[data-design]`, dark `:root[data-design][data-theme="dark"]`.
 * Served, each rule weighs exactly what the core's rule with the same
 * attributes weighs and wins only by coming later: the light colours give
 * way to the core's dark ones, the plain surfaces to the glass look's
 * translucent ones. To change something the core narrows further, narrow
 * as far — the glass gradient a household picked is
 * `[data-look="glass"][data-backdrop]`. A token the core sets alike in
 * both themes (fonts, corners) needs saying once.
 */
final class Stylesheet
{
    public const MAX_BYTES = 262_144;

    public const LIGHT = ':root[data-design]';

    public const DARK = ':root[data-design][data-theme="dark"]';

    /** Text against its background, in both themes; WCAG AA for body text. */
    public const CONTRAST = [
        ['--ink', '--canvas'],
        ['--ink', '--surface'],
        ['--ink-2', '--surface'],
        ['--accent-ink', '--accent'],
        ['--today-ink', '--today'],
    ];

    public const MIN_CONTRAST = 4.5;

    private const SELECTOR = '/^:root\[data-design\]((?:\[data-(?:theme|look|backdrop)(?:="[a-z0-9-]+")?\])*)$/';

    private const ASSET = '/^[A-Za-z0-9][A-Za-z0-9._-]*\.(?:woff2|webp|png|jpe?g|avif)$/';

    private const URL = '/url\(\s*([\'"]?)([^\'")\s]*)\1\s*\)/i';

    private const FONT_FACE = ['font-family', 'src', 'font-weight', 'font-style', 'font-display', 'font-stretch', 'unicode-range'];

    /** Why the theme cannot be shown, or null. */
    public static function problem(Theme $theme): ?string
    {
        if (trim($theme->name()) === '') {
            return 'A theme needs a name.';
        }

        $sheet = self::read($theme->stylesheet());

        return is_string($sheet) ? $sheet : null;
    }

    /**
     * The stylesheet taken apart, or the reason it is refused.
     *
     * @return array{rules: list<array{selector: string, declarations: array<string, string>}>, fonts: list<array<string, string>>, assets: array<string, string>}|string
     */
    public static function read(string $path): array|string
    {
        if (! is_file($path)) {
            return "The stylesheet {$path} does not exist.";
        }

        $css = (string) file_get_contents($path);

        if (strlen($css) > self::MAX_BYTES) {
            return 'The stylesheet is larger than '.(self::MAX_BYTES / 1024).' KB.';
        }

        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        if (preg_match('/[\\\\<]/', $css) === 1) {
            return 'Escapes and angle brackets are not allowed in a theme.';
        }

        $tokens = Tokens::load();
        $directory = dirname($path);
        $rules = [];
        $fonts = [];
        $assets = [];
        $offset = 0;

        while (preg_match('/\G\s*([^{}]+?)\s*\{([^{}]*)\}/', $css, $match, 0, $offset) === 1) {
            $offset += strlen($match[0]);
            $declarations = self::declarations($match[2], $directory, $assets);

            if (is_string($declarations)) {
                return $declarations;
            }

            if ($match[1] === '@font-face') {
                $unknown = array_diff(array_keys($declarations), self::FONT_FACE);

                if ($unknown !== [] || ! isset($declarations['font-family'], $declarations['src'])) {
                    return '@font-face takes font-family, src and the font-* descriptors only.';
                }

                $fonts[] = $declarations;

                continue;
            }

            foreach (array_map(trim(...), explode(',', $match[1])) as $selector) {
                if (preg_match(self::SELECTOR, $selector) !== 1) {
                    return "Selector {$selector} is not allowed; a theme sets tokens on ".self::LIGHT.', narrowed by data-theme, data-look or data-backdrop.';
                }

                foreach (array_keys($declarations) as $name) {
                    if (! array_key_exists($name, $tokens)) {
                        return "{$name} is not one of Fahoba's design tokens.";
                    }
                }

                $rules[] = ['selector' => $selector, 'declarations' => $declarations];
            }
        }

        if (trim(substr($css, $offset)) !== '') {
            return 'Only rules and @font-face are allowed: no nesting, @media or @import.';
        }

        $selectors = array_column($rules, 'selector');

        if (! in_array(self::LIGHT, $selectors, true) || ! in_array(self::DARK, $selectors, true)) {
            return 'A theme sets both '.self::LIGHT.' and '.self::DARK.'.';
        }

        $sheet = ['rules' => $rules, 'fonts' => $fonts, 'assets' => $assets];

        return self::contrastProblem($sheet, $tokens) ?? $sheet;
    }

    /**
     * The CSS the server sends: selectors that win over the core's, file
     * names turned into addresses below `$assetBase`.
     *
     * @param  array{rules: list<array{selector: string, declarations: array<string, string>}>, fonts: list<array<string, string>>, assets: array<string, string>}  $sheet
     */
    public static function serve(array $sheet, string $assetBase): string
    {
        $out = [];

        foreach ($sheet['fonts'] as $font) {
            $out[] = '@font-face{'.self::block($font, $assetBase).'}';
        }

        foreach ($sheet['rules'] as $rule) {
            preg_match(self::SELECTOR, $rule['selector'], $match);
            $out[] = ':root'.$match[1].':where([data-design]){'.self::block($rule['declarations'], $assetBase).'}';
        }

        return implode("\n", $out)."\n";
    }

    /** WCAG contrast of two `#rgb`/`#rrggbb` colours; null for anything else. */
    public static function contrast(string $a, string $b): ?float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        if ($la === null || $lb === null) {
            return null;
        }

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * @param  array<string, string>  $assets  name => path, filled
     * @return array<string, string>|string
     */
    private static function declarations(string $body, string $directory, array &$assets): array|string
    {
        $declarations = [];

        foreach (explode(';', $body) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^(--[a-z0-9-]+|[a-z-]+)\s*:\s*(.+)$/s', $line, $match) !== 1) {
                return "Cannot read the declaration {$line}.";
            }

            [, $name, $value] = $match;
            $value = trim($value);

            if (preg_match('/expression\s*\(|javascript:|@|!important/i', $value) === 1) {
                return "The value of {$name} is not allowed.";
            }

            preg_match_all(self::URL, $value, $urls);

            if (count($urls[2]) !== substr_count(strtolower($value), 'url(')) {
                return "The value of {$name} names a file in a way that cannot be checked.";
            }

            foreach ($urls[2] as $file) {
                if (preg_match(self::ASSET, $file) !== 1 || ! is_file("{$directory}/{$file}")) {
                    return "{$file} must be a font or picture (woff2, webp, png, jpg, avif) beside the stylesheet.";
                }

                $assets[$file] = "{$directory}/{$file}";
            }

            $declarations[$name] = $value;
        }

        return $declarations;
    }

    /**
     * @param  array{rules: list<array{selector: string, declarations: array<string, string>}>}  $sheet
     * @param  array<string, array{light: ?string, dark: ?string}>  $tokens
     */
    private static function contrastProblem(array $sheet, array $tokens): ?string
    {
        foreach (['light' => self::LIGHT, 'dark' => self::DARK] as $mode => $selector) {
            $values = array_filter(array_map(
                static fn (array $token): ?string => $mode === 'dark' ? ($token['dark'] ?? $token['light']) : $token['light'],
                $tokens,
            ), is_string(...));

            foreach ($sheet['rules'] as $rule) {
                if ($rule['selector'] === self::LIGHT && $mode === 'dark') {
                    $values = [...$values, ...array_filter($rule['declarations'], static fn (string $name): bool => self::alike($tokens[$name] ?? null), ARRAY_FILTER_USE_KEY)];
                } elseif ($rule['selector'] === $selector) {
                    $values = [...$values, ...$rule['declarations']];
                }
            }

            foreach (self::CONTRAST as [$text, $background]) {
                $ratio = self::contrast($values[$text] ?? '', $values[$background] ?? '');

                if ($ratio !== null && $ratio < self::MIN_CONTRAST) {
                    return sprintf('%s on %s reaches %.1f:1 in %s, at least %.1f:1 are needed.', $text, $background, $ratio, $mode, self::MIN_CONTRAST);
                }
            }
        }

        return null;
    }

    /** @param array{light: ?string, dark: ?string}|null $token */
    private static function alike(?array $token): bool
    {
        return $token !== null && $token['light'] !== null && ($token['dark'] === null || $token['dark'] === $token['light']);
    }

    /** @param array<string, string> $declarations */
    private static function block(array $declarations, string $assetBase): string
    {
        $lines = [];

        foreach ($declarations as $name => $value) {
            $lines[] = $name.':'.preg_replace_callback(self::URL, static fn (array $m): string => 'url("'.rtrim($assetBase, '/').'/'.$m[2].'")', $value);
        }

        return implode(';', $lines);
    }

    private static function luminance(string $colour): ?float
    {
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($colour), $match) !== 1) {
            return null;
        }

        $hex = strlen($match[1]) === 3 ? preg_replace('/(.)/', '$1$1', $match[1]) : $match[1];
        $channels = array_map(static function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split((string) $hex, 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
