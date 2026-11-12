<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Themes;

use JsonException;
use RuntimeException;

/**
 * The design tokens a theme may set, with the core's own values, shipped
 * with this package so Fahoba and a designer's tests check the same list.
 * Fahoba writes it (`php artisan fahoba:design-reference`).
 *
 * A token in this list is a promise: renaming one breaks every theme.
 */
final class Tokens
{
    public static function path(): string
    {
        return dirname(__DIR__, 2).'/resources/reference/design.json';
    }

    /** @return array<string, array{light: ?string, dark: ?string}> token => core values */
    public static function load(): array
    {
        $file = self::path();

        try {
            $data = json_decode(is_file($file) ? (string) file_get_contents($file) : '', true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("The design reference {$file} is missing or broken.", previous: $exception);
        }

        return is_array($data) && is_array($data['tokens'] ?? null) ? $data['tokens'] : [];
    }
}
