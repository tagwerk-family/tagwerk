<?php

declare(strict_types=1);

namespace Fahoba\IcsFeed;

use Fahoba\IcsFeed\Filament\IcsFeedResource;
use Fahoba\PluginApi\Plugins\ConfiguresPanel;
use Filament\Panel;

final class IcsFeedPanel implements ConfiguresPanel
{
    public function configurePanel(Panel $panel): void
    {
        $panel->resources([IcsFeedResource::class]);
    }
}
