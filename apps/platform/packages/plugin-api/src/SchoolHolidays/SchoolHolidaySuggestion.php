<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\SchoolHolidays;

use Carbon\CarbonImmutable;

/**
 * A suggested holiday period.
 *
 * Explicitly a suggestion and not an entry: it lands in a form that someone
 * reviews and confirms.
 */
final readonly class SchoolHolidaySuggestion
{
    public function __construct(
        public string $title,
        public CarbonImmutable $startsOn,
        public CarbonImmutable $endsOn,
    ) {}

    /** Unique key for the checkbox list. */
    public function key(): string
    {
        return $this->startsOn->toDateString().'_'.$this->endsOn->toDateString();
    }

    public function label(): string
    {
        return __(':title (:from to :to)', [
            'title' => $this->title,
            'from' => $this->startsOn->locale(app()->getLocale())->isoFormat('L'),
            'to' => $this->endsOn->locale(app()->getLocale())->isoFormat('L'),
        ]);
    }
}
