<?php

declare(strict_types=1);

namespace Fahoba\ExampleLanguage;

use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\Plugin;

/**
 * Only in development (`require-dev`): a server installs language packs
 * through `FAHOBA_PLUGINS` like any other plugin.
 */
final class ExampleLanguagePlugin extends Plugin
{
    public const KEY = 'fahoba/example-language';

    protected function registerPlugin(): void
    {
        $this->app->make(Catalog::class)->offer('languages', self::KEY, French::class);
    }
}
