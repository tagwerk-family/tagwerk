<?php

declare(strict_types=1);

namespace Fahoba\WebDavBackup;

use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\Plugin;
use Illuminate\Console\Scheduling\Schedule;

final class WebDavBackupServiceProvider extends Plugin
{
    protected function registerPlugin(): void
    {
        $this->app->make(Catalog::class)->offer('extensions', BackupExtension::KEY, BackupExtension::class);

        // In register: boot already loads the German texts (see example-area).
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
    }

    public function bootPlugin(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([BackupCommand::class]);
        }

        /*
         * Every quarter hour, not at the chosen time: the time is a setting
         * in the database, and the schedule is built before anything may
         * read it. The command decides whether today's backup is due.
         */
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('fahoba:webdav-backup')
                ->everyFifteenMinutes()
                ->withoutOverlapping(60)
                ->onOneServer();
        });
    }
}
