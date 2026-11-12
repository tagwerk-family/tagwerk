<?php

declare(strict_types=1);

namespace Fahoba\ExampleArea;

use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\Plugin;
use Fahoba\PluginApi\Plugins\PluginRoutes;

/**
 * Only in development (`require-dev`): the image's build leaves it out, and
 * with it its menu entry.
 */
final class ExampleAreaServiceProvider extends Plugin
{
    protected function registerPlugin(): void
    {
        $catalog = $this->app->make(Catalog::class);
        $catalog->offer('areas', ExampleArea::KEY, ExampleArea::class);
        $catalog->offer('widgets', ExampleCounter::KEY, ExampleCounter::class);

        // In register, not boot: something in boot already loads the German
        // texts, and a path added afterwards is never read.
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
    }

    public function bootPlugin(): void
    {
        PluginRoutes::api(ExampleArea::KEY, fn () => require __DIR__.'/../routes/api.php');
    }
}
