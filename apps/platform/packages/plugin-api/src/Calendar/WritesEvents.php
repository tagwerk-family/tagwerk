<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Calendar;

/**
 * Capability: create, change and delete events at the provider.
 *
 * Without it the event exists only in Fahoba. That is not an exception
 * branch but the way the built-in calendar works.
 */
interface WritesEvents
{
    public function insert(ConnectedCalendar $calendar, EventDraft $draft): RemoteEvent;

    /**
     * @param  string|null  $version  state the change builds on; prevents silently
     *                                overwriting someone else's change
     *
     * @throws EventConflict when the remote state is newer
     */
    public function update(ConnectedCalendar $calendar, string $externalId, EventDraft $draft, ?string $version): RemoteEvent;

    /** An event already deleted there counts as success, not as an error. */
    public function delete(ConnectedCalendar $calendar, string $externalId, ?string $version): void;

    public function fetch(ConnectedCalendar $calendar, string $externalId): RemoteEvent;
}
