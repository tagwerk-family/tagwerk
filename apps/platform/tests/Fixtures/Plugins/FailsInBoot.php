<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins;

use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\Plugin;
use RuntimeException;

final class FailsInBoot extends Plugin
{
    protected function registerPlugin(): void
    {
        $this->app->make(Catalog::class)->offer('photo_rules', 'acme/late', FixtureRule::class);
    }

    public function bootPlugin(): void
    {
        throw new RuntimeException('Cannot reach the service.');
    }
}
