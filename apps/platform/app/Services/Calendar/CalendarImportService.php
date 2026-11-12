<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Integrations\ProviderRegistry;
use App\Models\ProviderAccount;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Calendar\ListsCalendars;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Fahoba\PluginApi\Connections\FinishesConnection;

/**
 * Takes over the calendar list of a connected source.
 *
 * Runs after connecting and occasionally again afterwards, because calendars
 * get added — a child's secondary calendar, for instance.
 */
final class CalendarImportService implements FinishesConnection
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly HouseholdContext $context,
    ) {}

    public function finish(ConnectedAccount $account): void
    {
        $this->import(ProviderAccount::of($account));
    }

    /**
     * @return int number of calendars taken over
     */
    public function import(ProviderAccount $source): int
    {
        return $this->context->forId(
            (int) $source->household_id,
            function () use ($source): int {
                $provider = $this->registry->calendarFor($source);

                // The built-in calendar has nothing to take over.
                if (! $provider instanceof ListsCalendars) {
                    return 0;
                }

                $count = 0;

                foreach ($provider->calendars($source) as $remote) {
                    /*
                     * is_active is deliberately not written: if the household
                     * deselected a calendar, it stays deselected. The colour
                     * only on the first import: after that it is the
                     * household's, set in the admin panel.
                     */
                    $calendar = $source->calendars()->firstOrNew(['external_id' => $remote->externalId]);

                    if (! $calendar->exists) {
                        $calendar->color = $remote->color;
                    }

                    $calendar->fill([
                        'summary' => $remote->title,
                        'timezone' => $remote->timezone,
                        'is_primary' => $remote->isPrimary,
                    ])->save();

                    $count++;
                }

                $source->forceFill(['synced_at' => now()])->save();

                return $count;
            },
        );
    }
}
