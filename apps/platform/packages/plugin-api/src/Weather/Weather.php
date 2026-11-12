<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Weather;

/**
 * The weather at a place: now, today and the next few days.
 *
 * The tile shows only the top of it — on a wall in the hallway people read
 * in passing whether a jacket is needed. The hours are for whoever taps.
 */
final readonly class Weather
{
    public function __construct(
        public float $current,
        public float $min,
        public float $max,
        /** WMO weather code; mapping it to text and symbol is up to the source. */
        public int $code,
        public string $label,
        public string $symbol,
        /** @var list<DayForecast> the coming days, today not included */
        public array $days = [],
        /** Today as a whole, with the hours still to come. */
        public ?DayForecast $today = null,
        public TemperatureUnit $unit = TemperatureUnit::Celsius,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'current' => round($this->current),
            'min' => round($this->min),
            'max' => round($this->max),
            'code' => $this->code,
            'label' => $this->label,
            'symbol' => $this->symbol,
            'unit' => $this->unit->symbol(),
            'today' => $this->today?->toArray(),
            'days' => array_map(static fn (DayForecast $day): array => $day->toArray(), $this->days),
        ];
    }
}
