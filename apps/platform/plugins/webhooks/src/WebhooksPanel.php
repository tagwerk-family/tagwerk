<?php

declare(strict_types=1);

namespace Fahoba\Webhooks;

use Fahoba\PluginApi\Plugins\ConfiguresPanel;
use Fahoba\Webhooks\Filament\WebhookResource;
use Filament\Panel;

final class WebhooksPanel implements ConfiguresPanel
{
    public function configurePanel(Panel $panel): void
    {
        $panel->resources([WebhookResource::class]);
    }
}
