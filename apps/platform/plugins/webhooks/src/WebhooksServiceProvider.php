<?php

declare(strict_types=1);

namespace Fahoba\Webhooks;

use Fahoba\PluginApi\Events\DomainEvent;
use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\Plugin;
use Illuminate\Support\Facades\Event;

final class WebhooksServiceProvider extends Plugin
{
    protected function registerPlugin(): void
    {
        $catalog = $this->app->make(Catalog::class);
        $catalog->offer('areas', WebhooksArea::KEY, WebhooksArea::class);
        $catalog->offer('export', WebhooksArea::KEY, WebhooksExport::class);
        $catalog->offer('panel', WebhooksArea::KEY, WebhooksPanel::class);

        // In register: boot already loads the German texts (see example-area).
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
    }

    public function bootPlugin(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Event::listen(DomainEvent::class, QueueWebhooks::class);
    }
}
