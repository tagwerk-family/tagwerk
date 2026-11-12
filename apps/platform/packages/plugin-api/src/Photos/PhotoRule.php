<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Photos;

use Carbon\CarbonImmutable;

/**
 * Which pictures the photo frame prefers.
 *
 * A rule sees every picture of every source with its date and chooses. It
 * reads nothing and asks nobody: the choice is plain arithmetic on what it
 * is handed, so plugins can bring their own without touching the sources.
 */
interface PhotoRule
{
    public function key(): string;

    /** Shown in the household settings. */
    public function label(): string;

    /** One sentence below the choice: what the wall will show. */
    public function description(): string;

    /**
     * Positions in $photos to show, at most $count, in the order to show them.
     *
     * @param  array<int, Photo>  $photos
     * @param  CarbonImmutable  $today  the household's day, as a date
     * @return array<int, int>
     */
    public function pick(array $photos, int $count, CarbonImmutable $today): array;
}
