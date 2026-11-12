<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Households;

/**
 * Something a household should know — a trial running out, a household
 * turned read-only: `offer('notices', 'vendor/name', Class)`.
 *
 * Asked for the household of the request, inside its language, and cached
 * by the core for a minute per household and language. Every notice shows,
 * the most severe first, each to its audience. An exception costs the
 * notice, never the page.
 */
interface HouseholdNotice
{
    public function notice(int $householdId): ?Notice;
}
