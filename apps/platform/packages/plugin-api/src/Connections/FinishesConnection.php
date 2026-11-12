<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Connections;

/**
 * What to fetch after a freshly granted consent.
 *
 * A connection alone is of no use: for the calendar the account's calendars
 * have to come over, for lists the lists. What that means per area is known
 * by the area — not by the flow that receives the consent.
 */
interface FinishesConnection
{
    public function finish(ConnectedAccount $account): void;
}
