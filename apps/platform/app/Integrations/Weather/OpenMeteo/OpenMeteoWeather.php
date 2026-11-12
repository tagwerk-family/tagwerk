<?php

declare(strict_types=1);

namespace App\Integrations\Weather\OpenMeteo;

use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Weather\DayForecast;
use Fahoba\PluginApi\Weather\HourForecast;
use Fahoba\PluginApi\Weather\TemperatureUnit;
use Fahoba\PluginApi\Weather\Weather;
use Fahoba\PluginApi\Weather\WeatherSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Weather from Open-Meteo.
 *
 * No key, no sign-up, free for household use — the same line as Nominatim
 * for the address search. That keeps the basic case usable without a
 * third-party account.
 */
final class OpenMeteoWeather implements WeatherSource
{
    private const URL = 'https://api.open-meteo.com/v1/forecast';

    /**
     * One hour. A kiosk redraws every few seconds; without a cache there
     * would be thousands of requests a day for a number that changes hourly.
     */
    private const TTL = 3600;

    /** Days shown under today's weather. */
    private const COMING_DAYS = 3;

    public function key(): string
    {
        return 'open-meteo';
    }

    public function label(): string
    {
        return 'Open-Meteo';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function current(float $lat, float $lon, TemperatureUnit $unit = TemperatureUnit::Celsius): ?Weather
    {
        // v3: entries from before the hourly forecast lack the hours.
        $cacheKey = sprintf('weather:open-meteo:v3:%s:%.2f,%.2f', $unit->value, $lat, $lon);

        /** @var array<string, mixed>|null $raw */
        $raw = Cache::remember($cacheKey, self::TTL, fn (): ?array => $this->fetch($lat, $lon, $unit));

        if ($raw === null) {
            return null;
        }

        /** @var list<array<string, mixed>> $days */
        $days = $raw['days'];

        // Hours already past drop out at answer time, not at fetch time:
        // the cache holds an hour, and 14:00 is no forecast at 14:50.
        $hour = CarbonImmutable::now((string) $raw['timezone'])->format('H:00');

        // The sky now as this hour reads it, sunshine included — the raw
        // current code knows only the cloud cover.
        $thisHour = collect($days[0]['hours'])->firstWhere('time', $hour);
        $code = (int) ($thisHour['code'] ?? $raw['code']);

        return new Weather(
            current: (float) $raw['current'],
            min: (float) $days[0]['min'],
            max: (float) $days[0]['max'],
            code: $code,
            label: WmoCodes::label($code),
            symbol: WmoCodes::symbol($code, (bool) $raw['is_day']),
            days: array_map($this->day(...), array_slice($days, 1)),
            today: $this->day($days[0], $hour),
            unit: $unit,
        );
    }

    /** @param array<string, mixed> $day */
    private function day(array $day, ?string $from = null): DayForecast
    {
        /** @var list<array{time: string, temperature: float, code: int, is_day: bool, rain: int|null}> $hours */
        $hours = $day['hours'];

        return new DayForecast(
            date: (string) $day['date'],
            min: (float) $day['min'],
            max: (float) $day['max'],
            code: (int) $day['code'],
            label: WmoCodes::label((int) $day['code']),
            symbol: WmoCodes::symbol((int) $day['code']),
            hours: array_values(array_map(static fn (array $hour): HourForecast => new HourForecast(
                time: $hour['time'],
                temperature: $hour['temperature'],
                code: $hour['code'],
                label: WmoCodes::label($hour['code']),
                symbol: WmoCodes::symbol($hour['code'], $hour['is_day']),
                rain: $hour['rain'],
            ), array_filter($hours, static fn (array $hour): bool => $from === null || $hour['time'] >= $from))),
            sunshine: is_numeric($day['sunshine'] ?? null) ? (float) $day['sunshine'] : null,
            rain: is_numeric($day['rain'] ?? null) ? (int) $day['rain'] : null,
        );
    }

    /** @return array<string, mixed>|null */
    private function fetch(float $lat, float $lon, TemperatureUnit $unit): ?array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(5)
                ->get(self::URL, [
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'current' => 'temperature_2m,weather_code,is_day',
                    'hourly' => 'temperature_2m,weather_code,precipitation_probability,is_day,sunshine_duration',
                    'daily' => 'temperature_2m_max,temperature_2m_min,weather_code,sunshine_duration,daylight_duration,precipitation_probability_max',
                    'forecast_days' => 1 + self::COMING_DAYS,
                    'temperature_unit' => $unit->value,
                    'timezone' => 'auto',
                ]);
        } catch (Throwable) {
            // A kiosk shows no error message that nobody on site can do
            // anything about. Without a response the tile simply stays empty.
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $now = $response->json('current.temperature_2m');
        $days = $this->days($response->json('daily'), $this->hours($response->json('hourly')));

        if (! is_numeric($now) || $days === [] || $days[0]['index'] !== 0) {
            return null;
        }

        return [
            'current' => (float) $now,
            'code' => (int) $response->json('current.weather_code', 0),
            'is_day' => (bool) $response->json('current.is_day', 1),
            'timezone' => (string) $response->json('timezone', 'UTC'),
            'days' => $days,
        ];
    }

    /**
     * The hours grouped by calendar day. An hour without temperature or code
     * is left out rather than shown as 0° — a wrong number reads as forecast.
     *
     * @return array<string, list<array{time: string, temperature: float, code: int, is_day: bool, rain: int|null}>>
     */
    private function hours(mixed $hourly): array
    {
        if (! is_array($hourly) || ! is_array($hourly['time'] ?? null)) {
            return [];
        }

        $byDay = [];

        foreach ($hourly['time'] as $index => $stamp) {
            $temperature = $hourly['temperature_2m'][$index] ?? null;
            $code = $hourly['weather_code'][$index] ?? null;
            $rain = $hourly['precipitation_probability'][$index] ?? null;
            $sun = $hourly['sunshine_duration'][$index] ?? null;
            $isDay = (bool) ($hourly['is_day'][$index] ?? true);

            if (! is_string($stamp) || ! is_numeric($temperature) || ! is_numeric($code)) {
                continue;
            }

            // "2026-09-30T14:00" in the place's own zone.
            $byDay[substr($stamp, 0, 10)][] = [
                'time' => substr($stamp, 11, 5),
                'temperature' => (float) $temperature,
                // At night there is no sunshine to judge by.
                'code' => WmoCodes::bySunshine((int) $code, $isDay && is_numeric($sun) ? (float) $sun / 3600 : null),
                'is_day' => $isDay,
                'rain' => is_numeric($rain) ? (int) $rain : null,
            ];
        }

        return $byDay;
    }

    /**
     * Today and the coming days. A day with a gap is left out rather than
     * shown with 0° — a wrong number reads as a forecast.
     *
     * @param  array<string, list<array{time: string, temperature: float, code: int, is_day: bool, rain: int|null}>>  $hours
     * @return list<array<string, mixed>>
     */
    private function days(mixed $daily, array $hours): array
    {
        if (! is_array($daily) || ! is_array($daily['time'] ?? null)) {
            return [];
        }

        $days = [];

        for ($index = 0; $index <= self::COMING_DAYS; $index++) {
            $date = $daily['time'][$index] ?? null;
            $high = $daily['temperature_2m_max'][$index] ?? null;
            $low = $daily['temperature_2m_min'][$index] ?? null;

            if (! is_string($date) || ! is_numeric($high) || ! is_numeric($low)) {
                continue;
            }

            $ofDay = $hours[$date] ?? [];
            $sunshine = $daily['sunshine_duration'][$index] ?? null;
            $daylight = $daily['daylight_duration'][$index] ?? null;

            $days[] = [
                'index' => $index,
                'date' => $date,
                'min' => (float) $low,
                'max' => (float) $high,
                'code' => WmoCodes::bySunshine(
                    WmoCodes::forDay(
                        array_values(array_map(
                            static fn (array $hour): int => $hour['code'],
                            array_filter($ofDay, static fn (array $hour): bool => $hour['is_day']),
                        )),
                        (int) ($daily['weather_code'][$index] ?? 0),
                    ),
                    is_numeric($sunshine) && is_numeric($daylight) && $daylight > 0 ? (float) $sunshine / (float) $daylight : null,
                ),
                // Seconds from the source, hours on the wall.
                'sunshine' => is_numeric($sunshine) ? (float) $sunshine / 3600 : null,
                'rain' => $daily['precipitation_probability_max'][$index] ?? null,
                'hours' => $ofDay,
            ];
        }

        return $days;
    }
}
