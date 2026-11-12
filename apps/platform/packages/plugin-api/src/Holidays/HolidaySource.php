<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Holidays;

/**
 * Where the public holidays of a region come from.
 *
 * The cache is not here but in the HolidayService: a source computes or
 * fetches, it remembers nothing.
 */
interface HolidaySource
{
    public function key(): string;

    public function label(): string;

    /** ISO 3166-2 code such as "AT-4" or "DE-BY". */
    public function supports(string $region): bool;

    /** @return array<int, HolidayEntry> */
    public function forYear(string $region, int $year): array;
}
