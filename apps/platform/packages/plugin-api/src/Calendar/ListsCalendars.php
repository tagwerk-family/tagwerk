<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Calendar;

use Fahoba\PluginApi\Connections\ConnectedAccount;

/**
 * Capability: the provider knows which calendars a source contains.
 *
 * With the built-in calendar there is nothing to take over — its calendars
 * are created in Fahoba itself.
 */
interface ListsCalendars
{
    /** @return array<int, RemoteCalendar> */
    public function calendars(ConnectedAccount $source): array;
}
