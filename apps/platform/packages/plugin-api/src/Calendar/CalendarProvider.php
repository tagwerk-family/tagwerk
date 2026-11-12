<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Calendar;

/**
 * What every calendar provider must be able to do: name itself.
 *
 * Everything else — signing in, listing calendars, syncing, writing,
 * notifying — is a capability of its own and brought along separately. The
 * built-in calendar has only this contract; one big interface would force it
 * into dummies for things that do not exist without an external service.
 */
interface CalendarProvider
{
    /** Key in the database and in config/fahoba.php. */
    public function key(): string;

    /** Name for the admin and the wall. */
    public function label(): string;
}
