<?php

declare(strict_types=1);

namespace App\Services\Holidays;

use App\Integrations\ProviderRegistry;
use App\Models\Household;
use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Holidays\HolidayEntry;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;

/**
 * A household's public holidays in a period.
 *
 * Where they come from is up to the registry — the first source that knows
 * the region. Only the cache and the period selection live here.
 */
final class HolidayService
{
    private const CACHE_DAYS = 30;

    public function __construct(
        private readonly Cache $cache,
        private readonly ProviderRegistry $registry,
    ) {}

    /**
     * @return Collection<int, HolidayEntry>
     */
    public function between(Household $household, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $region = $household->holiday_region;

        if (! is_string($region) || $region === '') {
            return new Collection;
        }

        return (new Collection(range($from->year, $to->year)))
            ->flatMap(fn (int $year): array => $this->forYear($region, $year))
            ->filter(fn (HolidayEntry $entry): bool => $entry->date->betweenIncluded(
                $from->startOfDay(),
                $to->endOfDay(),
            ))
            ->sortBy(fn (HolidayEntry $entry): int => $entry->date->getTimestamp())
            ->values();
    }

    /**
     * @return array<int, HolidayEntry>
     */
    private function forYear(string $region, int $year): array
    {
        /*
         * The plain list is cached, not the object: a cached object would
         * become unusable as soon as the class changes.
         */
        $raw = $this->cache->remember(
            // Per language: the names come translated.
            "holidays:{$region}:{$year}:".app()->getLocale(),
            now()->addDays(self::CACHE_DAYS),
            fn (): array => $this->compute($region, $year),
        );

        return array_map(
            fn (array $entry): HolidayEntry => new HolidayEntry(
                CarbonImmutable::parse($entry['date']),
                $entry['name'],
                $entry['official'],
            ),
            $raw,
        );
    }

    /**
     * Unknown region: on the wall a view without holidays is much better than
     * no view at all.
     *
     * @return array<int, array{date: string, name: string, official: bool}>
     */
    private function compute(string $region, int $year): array
    {
        $source = $this->registry->holidaySource($region);

        if ($source === null) {
            return [];
        }

        return array_map(
            static fn (HolidayEntry $entry): array => [
                'date' => $entry->date->toDateString(),
                'name' => $entry->name,
                'official' => $entry->official,
            ],
            $source->forYear($region, $year),
        );
    }
}
