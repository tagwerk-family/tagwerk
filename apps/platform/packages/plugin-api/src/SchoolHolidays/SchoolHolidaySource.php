<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\SchoolHolidays;

use Fahoba\PluginApi\Connections\ConnectedAccount;

/**
 * Where school holiday suggestions for a school year come from.
 *
 * Built-in sources compute from the household's region and need no account;
 * a connected source (an ICS calendar, say) is handed its own. Suggestions
 * are reviewed and confirmed, never created silently.
 */
interface SchoolHolidaySource
{
    public function key(): string;

    public function label(): string;

    /** Can this source suggest anything at all for the household? */
    public function isAvailable(?ConnectedAccount $account, ?string $region): bool;

    /**
     * The holidays of the school year that starts in the given year.
     *
     * @return array<int, SchoolHolidaySuggestion>
     */
    public function forSchoolYear(?ConnectedAccount $account, ?string $region, int $startYear): array;
}
