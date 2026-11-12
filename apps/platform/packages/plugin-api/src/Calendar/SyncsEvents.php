<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Calendar;

/**
 * Capability: fetch changes from the provider.
 *
 * A provider without this capability is skipped by the sync — the built-in
 * calendar, for instance, whose events live only here anyway.
 */
interface SyncsEvents
{
    /**
     * One page of changes.
     *
     * Paging is up to the caller, because only it decides what happens to
     * the cursor at the end.
     *
     * @param  string|null  $cursor  state of the last complete run; null requests a full sync
     * @param  string|null  $page  token of the next page from the previous response
     *
     * @throws SyncCursorExpired when the cursor is no longer valid
     */
    public function changes(ConnectedCalendar $calendar, ?string $cursor, ?string $page): ChangePage;
}
