<?php

declare(strict_types=1);

namespace App\Integrations\Photos\Rules;

use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Photos\PhotoRule;

/**
 * Pictures from periods, in fixed shares, random within each period.
 *
 * The shares are counted out, not rolled per picture: forty pictures at
 * 80/20 are 32 and 8, not "about" that. Where a period has too few, the
 * others fill up in their order, and last of all come the pictures outside
 * every period or without a date — the frame never stays empty because a
 * rule is picky.
 */
abstract class WeightedRule implements PhotoRule
{
    /**
     * The periods, newest first: [years back from, years back to, share].
     * [0, 1, 80] is the last twelve months.
     *
     * @return array<int, array{0: int, 1: int, 2: int}>
     */
    abstract protected function periods(): array;

    public function pick(array $photos, int $count, CarbonImmutable $today): array
    {
        $periods = $this->periods();
        $pots = array_fill(0, count($periods) + 1, []);
        $today = $today->startOfDay();

        foreach ($photos as $position => $photo) {
            $pots[$this->potOf($photo->takenOn, $today, $periods)][] = $position;
        }

        foreach ($pots as &$pot) {
            shuffle($pot);
        }
        unset($pot);

        $total = array_sum(array_column($periods, 2));
        $chosen = [];

        foreach ($periods as $index => [, , $share]) {
            $wanted = $total > 0 ? (int) round($count * $share / $total) : 0;
            $chosen = [...$chosen, ...array_splice($pots[$index], 0, min($wanted, $count - count($chosen)))];
        }

        // Whatever a period could not deliver comes from the rest, in order.
        foreach ($pots as &$pot) {
            $chosen = [...$chosen, ...array_splice($pot, 0, $count - count($chosen))];
        }
        unset($pot);

        shuffle($chosen);

        return $chosen;
    }

    /**
     * @param  array<int, array{0: int, 1: int, 2: int}>  $periods
     * @return int the index of the period, or the last pot for everything else
     */
    private function potOf(?string $takenOn, CarbonImmutable $today, array $periods): int
    {
        if ($takenOn === null) {
            return count($periods);
        }

        $day = CarbonImmutable::parse($takenOn);

        foreach ($periods as $index => [$from, $to]) {
            if ($day <= $today->subYears($from) && $day > $today->subYears($to)) {
                return $index;
            }
        }

        return count($periods);
    }
}
