<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Enums\EventOrigin;
use App\Integrations\ProviderRegistry;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\Person;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Calendar\RemoteEvent;
use Fahoba\PluginApi\Calendar\SyncCursorExpired;
use Fahoba\PluginApi\Calendar\SyncsEvents;
use Illuminate\Support\Collection;

/**
 * Syncs a calendar with its provider.
 *
 * The service knows no provider: it receives RemoteEvent objects and decides
 * what becomes of them. How they are fetched and translated is the
 * provider's business.
 */
final class CalendarSyncService
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly HouseholdContext $context,
    ) {}

    /**
     * Syncs a calendar — incrementally when a cursor is present.
     *
     * The household context is set here, not expected from the caller: a
     * forgotten context in the queue worker would be silent data loss,
     * because the global scope would then find nothing and every event would
     * be created anew.
     */
    public function sync(Calendar $calendar): SyncResult
    {
        return $this->context->forId(
            (int) $calendar->household_id,
            function () use ($calendar): SyncResult {
                $provider = $this->registry->calendarFor($calendar->source);

                /*
                 * A provider without sync has nothing to deliver — for the
                 * built-in calendar the events live here anyway.
                 */
                if (! $provider instanceof SyncsEvents) {
                    return new SyncResult;
                }

                try {
                    return $this->run($calendar, $provider, $calendar->sync_cursor);
                } catch (SyncCursorExpired) {
                    $this->reset($calendar);

                    return $this->run($calendar, $provider, null)->asFullResync();
                }
            },
        );
    }

    private function run(Calendar $calendar, SyncsEvents $provider, ?string $cursor): SyncResult
    {
        $created = 0;
        $updated = 0;
        $deleted = 0;
        $skipped = 0;

        $page = null;
        $nextCursor = null;

        do {
            $response = $provider->changes($calendar, $cursor, $page);

            // One query per page instead of one per event.
            $existing = $calendar->events()
                ->whereIn('external_id', array_map(
                    static fn (RemoteEvent $event): string => $event->externalId,
                    $response->events,
                ))
                ->get()
                ->keyBy('external_id');

            foreach ($response->events as $event) {
                match ($this->apply($calendar, $event, $existing)) {
                    'created' => $created++,
                    'updated' => $updated++,
                    'deleted' => $deleted++,
                    default => $skipped++,
                };
            }

            $page = $response->nextPage;

            // The cursor for the next run usually only comes at the end.
            $nextCursor = $response->nextCursor ?? $nextCursor;
        } while ($page !== null);

        $calendar->forceFill([
            'sync_cursor' => $nextCursor ?? $calendar->sync_cursor,
            'last_synced_at' => now(),
        ])->save();

        return new SyncResult($created, $updated, $deleted, $skipped);
    }

    /**
     * @param  Collection<string, Event>  $stored  events of the page already stored, by external ID
     * @return 'created'|'updated'|'deleted'|'skipped'
     */
    private function apply(Calendar $calendar, RemoteEvent $remote, Collection $stored): string
    {
        $existing = $stored->get($remote->externalId);

        if ($remote->cancelled) {
            if ($existing === null) {
                return 'skipped';
            }

            $existing->delete();
            $stored->forget($remote->externalId);

            return 'deleted';
        }

        /*
         * Echo protection: if the returning event carries our own revision
         * marker, the change came from us. Without this check every write of
         * our own triggers another one through the notification.
         */
        if ($existing !== null
            && $remote->revisionMarker !== null
            && $remote->revisionMarker === $existing->revision_marker) {
            return 'skipped';
        }

        $attributes = [
            'title' => $remote->title,
            'description' => $remote->description,
            'location' => $remote->location,
            'starts_at' => $remote->startsAt,
            'ends_at' => $remote->endsAt,
            'all_day' => $remote->allDay,
            'external_version' => $remote->version,
            'recurring_event_id' => $remote->recurringEventId,
            'origin' => EventOrigin::Remote,
            'external_updated_at' => $remote->updatedAt,
        ];

        if ($existing !== null) {
            $existing->fill($attributes)->save();
            $this->mirrorPeople($existing, $remote->personIds);

            return 'updated';
        }

        $created = $calendar->events()->create([
            ...$attributes,
            'external_id' => $remote->externalId,
        ]);

        // If the same ID shows up again on the page, it is an update now.
        $stored->put($remote->externalId, $created);

        $this->mirrorPeople($created, $remote->personIds);

        return 'created';
    }

    /**
     * Takes over the person assignment mirrored at the provider.
     *
     * Without this way back the mirroring would be worthless: after a
     * discarded cursor the stock is cleared and fetched again — the assignment
     * would still sit on the remote event but never reach the database again.
     *
     * null means: not specified. An existing assignment then stays untouched,
     * so a manually created event does not wipe it.
     *
     * @param  array<int, int>|null  $ids
     */
    private function mirrorPeople(Event $event, ?array $ids): void
    {
        if ($ids === null) {
            return;
        }

        // Through the household scope: the query does not find a foreign ID.
        $event->people()->sync(Person::query()->whereIn('id', $ids)->pluck('id')->all());
    }

    /**
     * Preparation for the full resync.
     *
     * Only events that came from outside are cleared — those created in
     * Fahoba survive, because the provider cannot return them as long as
     * they have not been written there yet.
     */
    private function reset(Calendar $calendar): void
    {
        $calendar->events()->where('origin', EventOrigin::Remote->value)->delete();

        $calendar->forceFill(['sync_cursor' => null])->save();
    }
}
