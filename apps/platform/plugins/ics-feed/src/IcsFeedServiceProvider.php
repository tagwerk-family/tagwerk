<?php

declare(strict_types=1);

namespace Fahoba\IcsFeed;

use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\Plugin;
use Illuminate\Support\Facades\Route;

final class IcsFeedServiceProvider extends Plugin
{
    protected function registerPlugin(): void
    {
        $catalog = $this->app->make(Catalog::class);
        $catalog->offer('areas', IcsFeedArea::KEY, IcsFeedArea::class);
        $catalog->offer('export', IcsFeedArea::KEY, IcsFeedExport::class);
        $catalog->offer('panel', IcsFeedArea::KEY, IcsFeedPanel::class);

        // In register: boot already loads the German texts (see example-area).
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
    }

    public function bootPlugin(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Calendar apps refetch every few minutes to hours; 60 a minute is many feeds behind one address.
        Route::get('/ics/{token}', IcsFeedController::class)
            ->where('token', '[A-Za-z0-9]{40}')
            ->middleware('throttle:60,1')
            ->name('ics-feed.show');
    }
}
