<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Enums\EventOrigin;
use App\Integrations\ProviderRegistry;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\Person;
use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Calendar\EventDraft;
use Fahoba\PluginApi\Calendar\RemoteEvent;
use Fahoba\PluginApi\Calendar\WritesEvents;
use Illuminate\Support\Str;

/**
 * Creates, changes and deletes events from the tablet.
 *
 * **At the provider first, then locally** — as long as it can write. Where a
 * foreign system leads, an event stored only locally would be a ghost:
 * visible on the wall, missing from the phone calendar, and gone without a
 * trace on the next full resync. If the provider fails, nothing is created
 * here and the error is visible right away.
 *
 * If the provider cannot write, the event exists only here. That is not an
 * exception path but the way the built-in calendar works.
 */
final class EventWriteService
{
    public function __construct(private readonly ProviderRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, int>  $personIds
     */
    public function create(Calendar $calendar, array $attributes, array $personIds): Event
    {
        $personIds = $this->householdPeople($personIds);
        $revision = (string) Str::uuid();
        $provider = $this->registry->calendarFor($calendar->source);

        $remote = $provider instanceof WritesEvents
            ? $provider->insert($calendar, $this->draft($attributes, $personIds, $revision))
            : null;

        $event = $calendar->events()->create([
            ...$attributes,
            ...$this->remoteAttributes($remote),
            'external_id' => $remote?->externalId,
            'origin' => EventOrigin::App,
            'revision_marker' => $revision,
        ]);

        $event->people()->sync($personIds);

        return $event->load('people');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, int>|null  $personIds  null leaves the assignment untouched
     */
    public function update(Event $event, array $attributes, ?array $personIds = null): Event
    {
        $calendar = $event->calendar;
        $ids = $personIds === null
            ? $event->people->pluck('id')->all()
            : $this->householdPeople($personIds);

        $revision = (string) Str::uuid();
        $provider = $this->registry->calendarFor($calendar->source);

        $remote = $provider instanceof WritesEvents && $event->external_id !== null
            ? $provider->update(
                $calendar,
                (string) $event->external_id,
                $this->draft([...$event->only($this->fields()), ...$attributes], $ids, $revision),
                $event->external_version,
            )
            : null;

        $event->fill([
            ...$attributes,
            ...$this->remoteAttributes($remote),
            'origin' => EventOrigin::App,
            'revision_marker' => $revision,
        ])->save();

        if ($personIds !== null) {
            $event->people()->sync($ids);
        }

        return $event->load('people');
    }

    public function delete(Event $event): void
    {
        $provider = $this->registry->calendarFor($event->calendar->source);

        if ($provider instanceof WritesEvents && $event->external_id !== null) {
            $provider->delete($event->calendar, $event->external_id, $event->external_version);
        }

        $event->delete();
    }

    /**
     * Fetches the remote state after a conflict and takes it over.
     *
     * With competing changes the provider wins. The wall then shows the
     * remote state instead of writing its own input over it.
     */
    public function refresh(Event $event): Event
    {
        $provider = $this->registry->calendarFor($event->calendar->source);

        if (! $provider instanceof WritesEvents || $event->external_id === null) {
            return $event->load('people');
        }

        $remote = $provider->fetch($event->calendar, (string) $event->external_id);

        $event->fill([
            'title' => $remote->title,
            'description' => $remote->description,
            'location' => $remote->location,
            ...$this->remoteAttributes($remote),
            'origin' => EventOrigin::Remote,
        ])->save();

        return $event->load('people');
    }

    /**
     * Foreign person IDs silently drop out instead of raising an error.
     *
     * The query runs through the household scope; it therefore does not find
     * an ID from another household.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function householdPeople(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Person::query()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteAttributes(?RemoteEvent $remote): array
    {
        return $remote === null
            ? []
            : ['external_version' => $remote->version, 'external_updated_at' => $remote->updatedAt];
    }

    /** @return array<int, string> */
    private function fields(): array
    {
        return ['title', 'description', 'location', 'starts_at', 'ends_at', 'all_day'];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, int>  $personIds
     */
    private function draft(array $attributes, array $personIds, string $revision): EventDraft
    {
        return new EventDraft(
            title: (string) $attributes['title'],
            startsAt: CarbonImmutable::parse($attributes['starts_at']),
            endsAt: CarbonImmutable::parse($attributes['ends_at']),
            allDay: (bool) ($attributes['all_day'] ?? false),
            description: $attributes['description'] ?? null,
            location: $attributes['location'] ?? null,
            personIds: $personIds,
            revisionMarker: $revision,
        );
    }
}
