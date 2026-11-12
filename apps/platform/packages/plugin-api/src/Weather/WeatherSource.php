<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Weather;

/**
 * A weather source.
 *
 * Asked with coordinates, not a place name: the household has stored its
 * coordinates for the sun position anyway, and a second place field would be
 * a question already answered.
 */
interface WeatherSource
{
    public function key(): string;

    public function label(): string;

    public function isConfigured(): bool;

    /** Null when the source delivers nothing right now — that is not a fault. */
    public function current(float $lat, float $lon, TemperatureUnit $unit = TemperatureUnit::Celsius): ?Weather;
}
