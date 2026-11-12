<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The design tokens a theme may set, read from the shared `fahoba.css`,
 * as the file `fahoba/plugin-api` ships (`Tokens`).
 *
 * Every custom property of the `:root` rules counts, the glass look's
 * included; the values come from the plain light and dark rules, so a
 * theme's contrast is measured against what it does not change.
 * `DesignReferenceTest` fails when the shipped file is stale.
 */
final class DesignReference
{
    public static function source(): string
    {
        return base_path('frontend/packages/shared/app/assets/css/fahoba.css');
    }

    public static function build(): string
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::source()));
        preg_match_all('/([^{};]+)\{([^{}]*)\}/', $css, $blocks, PREG_SET_ORDER);

        $tokens = [];

        foreach ($blocks as [, $selector, $body]) {
            $selector = trim($selector);

            if (! str_starts_with($selector, ':root') || str_contains($selector, ':not(')) {
                continue;
            }

            preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+)/', $body, $declarations, PREG_SET_ORDER);

            foreach ($declarations as [, $name, $value]) {
                $tokens[$name] ??= ['light' => null, 'dark' => null];

                match ($selector) {
                    ':root' => $tokens[$name]['light'] = trim($value),
                    ':root[data-theme="dark"]' => $tokens[$name]['dark'] = trim($value),
                    default => null,
                };
            }
        }

        ksort($tokens);

        return json_encode(['tokens' => $tokens], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }
}
