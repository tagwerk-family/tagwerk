<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Every entry runs ->onOneServer(): during a rollout (deploy/rollout.sh) the
 * old and the new container both run a scheduler for a few seconds, and a
 * daily summary sent twice would reach every phone twice. The lock lives in
 * the cache, the database on the server.
 */

/*
 * Fallback polling, because Google itself calls its notifications not
 * entirely reliable.
 */
Schedule::command('fahoba:sync-calendars')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Channels expire after a week at most and are never renewed automatically.
 * The odd time of day avoids the full hour.
 */
/*
 * Tasks from outside. Google Tasks has no notifications; for a wall tablet a
 * quarter of an hour is enough.
 */
Schedule::command('fahoba:sync-tasks')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Short, because a voice assistant fills the list at the provider: whoever
 * says "Milch" in the kitchen expects it on the wall shortly after.
 */
Schedule::command('fahoba:sync-lists')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('fahoba:clear-done-items')
    ->hourlyAt(41)
    ->onOneServer();

Schedule::command('fahoba:purge-household-archives')
    ->dailyAt('03:53')
    ->onOneServer();

Schedule::command('fahoba:purge-photo-trash')
    ->dailyAt('03:43')
    ->onOneServer();

Schedule::command('fahoba:renew-push-channels')
    ->dailyAt('03:17')
    ->onOneServer();

/*
 * Every quarter hour, because the summary is due in each household's own
 * time zone — the command decides by itself whose evening it is right now.
 */
Schedule::command('fahoba:send-daily-summary')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
