<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Holidays;

use Carbon\CarbonImmutable;

/**
 * A public holiday the way the wall needs it.
 *
 * A type of its own instead of Yasumi's Holiday: that is a DateTime with a
 * name attached, which can be neither cached sensibly nor output as JSON.
 */
final readonly class HolidayEntry
{
    public function __construct(
        public CarbonImmutable $date,
        public string $name,
        public bool $official,
    ) {}

    /** @return array{date: string, name: string, official: bool} */
    public function toArray(): array
    {
        return [
            'date' => $this->date->toDateString(),
            'name' => $this->name,
            'official' => $this->official,
        ];
    }
}
