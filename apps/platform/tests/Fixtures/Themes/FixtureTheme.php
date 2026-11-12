<?php

declare(strict_types=1);

namespace Tests\Fixtures\Themes;

use Fahoba\PluginApi\Themes\Theme;

/** A design whose stylesheet a test writes, in a folder beside a font. */
final class FixtureTheme implements Theme
{
    public static string $css = '';

    public static string $name = 'Probe';

    public static function folder(): string
    {
        $folder = sys_get_temp_dir().'/fahoba-theme-fixture';

        if (! is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        copy(__DIR__.'/hand.woff2', "{$folder}/hand.woff2");

        return $folder;
    }

    public function name(): string
    {
        return self::$name;
    }

    public function stylesheet(): string
    {
        $path = self::folder().'/theme.css';
        file_put_contents($path, self::$css);

        return $path;
    }
}
