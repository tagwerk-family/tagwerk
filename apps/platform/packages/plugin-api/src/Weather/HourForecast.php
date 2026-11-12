<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Weather;

/** One hour of a day, as a row in the weather window. */
final readonly class HourForecast
{
    public function __construct(
        /** Wall-clock time at the place, HH:MM. */
        public string $time,
        public float $temperature,
        public int $code,
        public string $label,
        public string $symbol,
        /** Chance of precipitation in percent; null when the source has none. */
        public ?int $rain = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'time' => $this->time,
            'temperature' => round($this->temperature),
            'code' => $this->code,
            'label' => $this->label,
            'symbol' => $this->symbol,
            'rain' => $this->rain,
        ];
    }
}
