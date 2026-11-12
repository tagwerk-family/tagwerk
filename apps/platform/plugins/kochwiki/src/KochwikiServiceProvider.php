<?php

declare(strict_types=1);

namespace Fahoba\Kochwiki;

use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\Plugin;
use Illuminate\Console\Scheduling\Schedule;

final class KochwikiServiceProvider extends Plugin
{
    protected function registerPlugin(): void
    {
        $catalog = $this->app->make(Catalog::class);

        $catalog->offer('recipes', KochwikiRecipeProvider::KEY, KochwikiRecipeProvider::class);
        $catalog->offer('csp', KochwikiRecipeProvider::KEY, KochwikiImages::class);
    }

    public function bootPlugin(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([KochwikiSyncCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('fahoba:kochwiki-sync')
                ->dailyAt('03:41')
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
