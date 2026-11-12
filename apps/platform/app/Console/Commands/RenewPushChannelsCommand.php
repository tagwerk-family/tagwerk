<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConnectionStatus;
use App\Integrations\ProviderRegistry;
use App\Models\Calendar;
use App\Models\Household;
use App\Services\Calendar\PushChannelService;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Calendar\WatchesChanges;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Renews expiring push channels.
 *
 * Google never renews by itself; once a channel expires the calendar goes
 * quiet and only the fallback poll would keep it current.
 */
final class RenewPushChannelsCommand extends Command
{
    protected $signature = 'fahoba:renew-push-channels';

    protected $description = 'Creates new Google channels before the old ones expire';

    public function handle(PushChannelService $channels, HouseholdContext $context, ProviderRegistry $registry): int
    {
        $renewed = 0;

        Calendar::withoutHouseholdScope()
            ->where('is_active', true)
            // A suspended household keeps its data, but nothing runs for it.
            ->whereIn('household_id', Household::active()->writable()->select('id'))
            ->whereHas('source', fn (Builder $query) => $query
                ->withoutGlobalScope('household')
                ->where('status', ConnectionStatus::Active->value)

                // Only providers that know channels at all.
                ->whereIn('provider', $registry->calendarKeysWith(WatchesChanges::class)))
            ->each(function (Calendar $calendar) use ($channels, $context, &$renewed): void {
                // The channel needs the source, and the source carries the scope.
                $context->forId((int) $calendar->household_id, function () use ($calendar, $channels, &$renewed): void {
                    if ($channels->ensure($calendar)) {
                        $renewed++;
                    }
                });
            });

        $this->info("Renewed {$renewed} channels.");

        return self::SUCCESS;
    }
}
