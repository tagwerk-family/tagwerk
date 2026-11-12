<?php

declare(strict_types=1);

namespace Acme\Example;

use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\Plugin;

/**
 * Loaded by Fahoba through `extra.fahoba.provider` in composer.json. An
 * exception in here leaves the plugin out; the app keeps running, and
 * Operations → Plugins shows why.
 */
final class ExamplePlugin extends Plugin
{
    public const KEY = 'acme/example';

    protected function registerPlugin(): void
    {
        $this->app->make(Catalog::class)->offer('widgets', self::KEY, ExampleWidget::class);
    }
}
