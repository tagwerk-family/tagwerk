<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use Carbon\CarbonImmutable;

/**
 * What the statistics look at: plain calendar days in the household's zone,
 * optionally narrowed to some people and one task. Empty people means all.
 */
final readonly class StatisticsPeriod
{
    /** Presets of the page's period select, in days back from today; `year` is the calendar year. */
    public const PRESETS = ['28', '91', '365', 'year', 'custom'];

    /**
     * @param  list<int>  $personIds
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $until,
        public array $personIds = [],
        public ?int $taskId = null,
    ) {}

    /**
     * From the page's filter state. Anything unreadable falls back to the last
     * four weeks: a broken address must not show an empty page.
     *
     * @param  array<string, mixed>|null  $filters
     */
    public static function fromFilters(?array $filters, CarbonImmutable $today): self
    {
        $today = CarbonImmutable::parse($today->toDateString());
        $preset = (string) ($filters['period'] ?? '28');

        [$from, $until] = match ($preset) {
            'year' => [$today->startOfYear(), $today],
            'custom' => [self::day($filters['from'] ?? null) ?? $today->subDays(27), self::day($filters['until'] ?? null) ?? $today],
            default => [$today->subDays(max(1, (int) $preset) - 1), $today],
        };

        if ($from->greaterThan($until)) {
            [$from, $until] = [$until, $from];
        }

        $people = array_values(array_map('intval', array_filter((array) ($filters['people'] ?? []), 'is_numeric')));
        $task = $filters['task'] ?? null;

        return new self($from, $until, $people, is_numeric($task) ? (int) $task : null);
    }

    /** Whole days, both ends included. */
    public function days(): int
    {
        return (int) $this->from->diffInDays($this->until) + 1;
    }

    /**
     * How the timeline groups: days for up to five weeks, weeks for up to
     * half a year, months beyond — about a dozen to fifty points either way.
     */
    public function bucket(): string
    {
        return match (true) {
            $this->days() <= 35 => 'day',
            $this->days() <= 190 => 'week',
            default => 'month',
        };
    }

    private static function day(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
