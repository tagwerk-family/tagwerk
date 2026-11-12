<?php

declare(strict_types=1);

namespace Fahoba\ExampleTheme;

use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\Plugin;

/**
 * Only in development (`require-dev`): a server installs design packs
 * through `FAHOBA_PLUGINS` like any other plugin.
 */
final class ExampleThemePlugin extends Plugin
{
    public const KEY = 'fahoba/example-theme';

    protected function registerPlugin(): void
    {
        $this->app->make(Catalog::class)->offer('themes', self::KEY, Fjord::class);
    }
}
