<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConnectionStatus;
use App\Integrations\ProviderRegistry;
use App\Jobs\SyncCalendarJob;
use App\Models\Calendar;
use App\Models\Household;
use Fahoba\PluginApi\Calendar\SyncsEvents;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Fallback poll for all calendars.
 *
 * Google itself calls its notifications not fully reliable. The poll is
 * cheap because the syncToken mostly yields an empty delta.
 */
final class SyncCalendarsCommand extends Command
{
    protected $signature = 'fahoba:sync-calendars';

    protected $description = 'Queues a sync for every active calendar';

    public function handle(ProviderRegistry $registry): int
    {
        $queued = 0;

        Calendar::withoutHouseholdScope()
            ->where('is_active', true)
            // A suspended household keeps its data, but nothing runs for it.
            ->whereIn('household_id', Household::active()->writable()->select('id'))
            ->whereHas('source', fn (Builder $query) => $query
                ->withoutGlobalScope('household')
                ->where('status', ConnectionStatus::Active->value)

                // A provider without sync has nothing to deliver.
                ->whereIn('provider', $registry->calendarKeysWith(SyncsEvents::class)))
            ->each(function (Calendar $calendar) use (&$queued): void {
                SyncCalendarJob::dispatch($calendar->id);
                $queued++;
            });

        $this->info("Queued {$queued} calendars.");

        return self::SUCCESS;
    }
}
