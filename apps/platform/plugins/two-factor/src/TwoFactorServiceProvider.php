<?php

declare(strict_types=1);

namespace Fahoba\TwoFactor;

use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\Plugin;

final class TwoFactorServiceProvider extends Plugin
{
    protected function registerPlugin(): void
    {
        $this->app->make(Catalog::class)->offer('extensions', TwoFactorExtension::KEY, TwoFactorExtension::class);
    }
}
