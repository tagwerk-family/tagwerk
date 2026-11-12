<?php

declare(strict_types=1);

namespace App\Services\Photos;

use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Photos\Photo;

/**
 * Stories made from the pictures' dates, like the Photos apps' memories.
 *
 * Computed on every request, never stored: a memory is what the dates say
 * today, and a deleted picture must not live on in one. Two kinds — the same
 * days in earlier years, and runs of days with many pictures (a holiday, a
 * wedding weekend). Titles are made on the device from kind and dates, in
 * its language.
 */
final class PhotoMemories
{
    /** Fewer pictures than this make no story. */
    private const MIN_PHOTOS = 3;

    /** A run of days needs this many pictures to count as an event. */
    private const EVENT_PHOTOS = 8;

    /** "On this day" reaches this many days to either side. */
    private const NEAR_DAYS = 3;

    private const MAX_MEMORIES = 12;

    private const PER_MEMORY = 40;

    public function __construct(private readonly PhotoLibrary $library) {}

    /**
     * @return list<array{key: string, kind: string, years: int|null, from: string, until: string, count: int, photos: list<array<string, mixed>>}>
     */
    public function on(CarbonImmutable $today): array
    {
        $dated = [];

        foreach ($this->library->listed() as [$source, $photo]) {
            if ($photo->takenOn !== null && $photo->takenOn < $today->toDateString()) {
                $dated[] = [$source, $photo, CarbonImmutable::parse($photo->takenOn)];
            }
        }

        usort($dated, static fn (array $a, array $b): int => $a[2] <=> $b[2]);

        return array_slice([...$this->onThisDay($dated, $today), ...$this->events($dated)], 0, self::MAX_MEMORIES);
    }

    /**
     * @param  list<array{0: string, 1: Photo, 2: CarbonImmutable}>  $dated
     * @return list<array<string, mixed>>
     */
    private function onThisDay(array $dated, CarbonImmutable $today): array
    {
        $years = [];

        foreach ($dated as $entry) {
            $date = $entry[2];

            if ($date->year >= $today->year) {
                continue;
            }

            $sameDay = $today->setYear($date->year);

            if (abs((int) $date->diffInDays($sameDay)) <= self::NEAR_DAYS) {
                $years[$today->year - $date->year][] = $entry;
            }
        }

        ksort($years);
        $memories = [];

        foreach ($years as $ago => $entries) {
            if (count($entries) >= self::MIN_PHOTOS) {
                $memories[] = $this->memory('on_this_day', $entries, $ago);
            }
        }

        return $memories;
    }

    /**
     * Runs of days at most one day apart, newest first.
     *
     * @param  list<array{0: string, 1: Photo, 2: CarbonImmutable}>  $dated
     * @return list<array<string, mixed>>
     */
    private function events(array $dated): array
    {
        $runs = [];
        $run = [];

        foreach ($dated as $entry) {
            $last = end($run);

            if ($last !== false && $last[2]->diffInDays($entry[2]) > 1) {
                $runs[] = $run;
                $run = [];
            }

            $run[] = $entry;
        }

        if ($run !== []) {
            $runs[] = $run;
        }

        $memories = [];

        foreach (array_reverse($runs) as $entries) {
            if (count($entries) >= self::EVENT_PHOTOS) {
                $memories[] = $this->memory('days', $entries, null);
            }
        }

        return $memories;
    }

    /**
     * @param  list<array{0: string, 1: Photo, 2: CarbonImmutable}>  $entries  sorted by date
     * @return array<string, mixed>
     */
    private function memory(string $kind, array $entries, ?int $years): array
    {
        $from = $entries[0][2]->toDateString();
        $until = end($entries)[2]->toDateString();

        // A sample across the whole run, still in the order it happened.
        $positions = array_keys($entries);

        if (count($positions) > self::PER_MEMORY) {
            shuffle($positions);
            $positions = array_slice($positions, 0, self::PER_MEMORY);
            sort($positions);
        }

        return [
            'key' => "{$kind}:{$from}",
            'kind' => $kind,
            'years' => $years,
            'from' => $from,
            'until' => $until,
            'count' => count($entries),
            'photos' => array_map(
                fn (int $position): array => $this->library->entry($entries[$position][0], $entries[$position][1]),
                $positions,
            ),
        ];
    }
}
