<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins;

use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\Plugin;

final class GoodPlugin extends Plugin
{
    protected function registerPlugin(): void
    {
        $this->app->make(Catalog::class)->offer('photo_rules', 'acme/good', FixtureRule::class);
    }
}
