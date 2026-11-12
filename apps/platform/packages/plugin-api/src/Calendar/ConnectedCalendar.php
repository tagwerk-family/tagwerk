<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Calendar;

use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Connections\ConnectedAccount;

/**
 * One calendar of a connected account, as Fahoba keeps it — what a calendar
 * provider receives instead of a database row.
 *
 * Like `ConnectedAccount`, no method shares a name with a column (PHP method
 * names ignore case, so `timeZone()` would collide with `timezone` too).
 */
interface ConnectedCalendar
{
    public function calendarId(): int;

    /** The calendar's id at the service. */
    public function externalId(): string;

    /** The calendar's own time zone, else the household's. */
    public function zoneName(): string;

    /** The connection the calendar belongs to. */
    public function account(): ConnectedAccount;

    /**
     * The service ids of the events Fahoba holds for this calendar that
     * overlap `[$from, $to)` — what a sync without a delta compares against
     * to find the ones deleted at the service.
     *
     * @return list<string>
     */
    public function knownExternalIds(CarbonImmutable $from, CarbonImmutable $to): array;
}
