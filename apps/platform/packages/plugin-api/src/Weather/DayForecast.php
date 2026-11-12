<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Weather;

/** One day: a line under the tile's temperature, a tab in the weather window. */
final readonly class DayForecast
{
    public function __construct(
        /** Calendar day in the place's own zone, YYYY-MM-DD. */
        public string $date,
        public float $min,
        public float $max,
        public int $code,
        public string $label,
        public string $symbol,
        /** @var list<HourForecast> for today only the hours still to come */
        public array $hours = [],
        /** Hours of sunshine; null when the source has none. */
        public ?float $sunshine = null,
        /** Highest chance of precipitation in percent; null when unknown. */
        public ?int $rain = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'min' => round($this->min),
            'max' => round($this->max),
            'code' => $this->code,
            'label' => $this->label,
            'symbol' => $this->symbol,
            'sunshine' => $this->sunshine === null ? null : round($this->sunshine),
            'rain' => $this->rain,
            'hours' => array_map(static fn (HourForecast $hour): array => $hour->toArray(), $this->hours),
        ];
    }
}
