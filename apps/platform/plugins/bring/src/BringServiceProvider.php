<?php

declare(strict_types=1);

namespace Fahoba\Bring;

use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\Plugin;

final class BringServiceProvider extends Plugin
{
    protected function registerPlugin(): void
    {
        $this->app->make(Catalog::class)->offer('lists', 'fahoba/bring', BringListProvider::class);
    }
}
