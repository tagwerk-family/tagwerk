<?php

declare(strict_types=1);

namespace App\Integrations\Geocoding\Nominatim;

use App\Integrations\Geocoding\TimezoneAtLocation;
use Fahoba\PluginApi\Geocoding\Coordinates;
use Fahoba\PluginApi\Geocoding\Geocoder;
use Illuminate\Support\Facades\Http;

/**
 * Address search via Nominatim, the search service of OpenStreetMap.
 *
 * No credentials and no account. The usage policy asks for a recognisable
 * User-Agent and at most one request per second. Both are honoured: the
 * search field waits after the last keystroke before asking, and it only asks
 * while someone is typing in the household form.
 *
 * The coordinates are rounded to about a kilometre — enough for sun and
 * weather. The address parts come along to fill the household form.
 */
final class NominatimGeocoder implements Geocoder
{
    private const URL = 'https://nominatim.openstreetmap.org/search';

    public function __construct(private readonly TimezoneAtLocation $timezones) {}

    public function isConfigured(): bool
    {
        return true;
    }

    public function label(): string
    {
        return 'OpenStreetMap';
    }

    public function search(string $query, int $limit = 5): array
    {
        if (trim($query) === '') {
            return [];
        }

        return $this->ask(['q' => $query, 'limit' => $limit], $query);
    }

    public function locate(?string $street, ?string $postalCode, string $city, string $countryCode): ?Coordinates
    {
        if (trim($city) === '' || trim($countryCode) === '') {
            return null;
        }

        // Nominatim's structured search: no guessing which word is the street.
        return $this->ask(array_filter([
            'street' => $street,
            'postalcode' => $postalCode,
            'city' => $city,
            'countrycodes' => mb_strtolower($countryCode),
            'limit' => 1,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''), $city)[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<int, Coordinates>
     */
    private function ask(array $parameters, string $fallbackLabel): array
    {
        $response = Http::withHeaders([
            // Without a recognisable sender Nominatim rejects the request.
            'User-Agent' => 'Fahoba/1.0 (self-hosted family calendar)',
        ])
            ->acceptJson()
            /*
             * Kept short: the search runs through Livewire, and while it runs,
             * every further input in the form waits behind it. A suggestion
             * arriving only after ten seconds helps nobody anyway.
             */
            ->timeout(4)
            ->get(self::URL, [
                ...$parameters,
                'format' => 'jsonv2',
                // The address parts fill the form; the country gives the zone.
                'addressdetails' => 1,
                // Place names in the interface's language, not the local one.
                'accept-language' => app()->getLocale(),
            ]);

        if ($response->failed()) {
            return [];
        }

        return collect($response->json())
            ->filter(fn (mixed $hit): bool => is_array($hit) && isset($hit['lat'], $hit['lon']))
            ->map(fn (array $hit): Coordinates => new Coordinates(
                /*
                 * Two decimals, about a kilometre: fine enough that the
                 * weather is the valley's and not the next ridge's. The
                 * address is stored anyway, so coarser would hide nothing.
                 */
                latitude: round((float) $hit['lat'], 2),
                longitude: round((float) $hit['lon'], 2),
                label: (string) ($hit['display_name'] ?? $fallbackLabel),
                timezone: $this->timezone($hit),
                street: $this->street($hit),
                postalCode: $this->part($hit, ['postcode']),
                city: $this->part($hit, ['city', 'town', 'village', 'municipality', 'hamlet', 'suburb']),
                countryCode: ($code = $this->part($hit, ['country_code'])) === null ? null : mb_strtoupper($code),
            ))
            ->values()
            ->all();
    }

    /**
     * Street and number in the order of German-speaking countries — where
     * Fahoba's families live. Elsewhere the number would come first; the
     * field stays editable for that.
     *
     * @param  array<string, mixed>  $hit
     */
    private function street(array $hit): ?string
    {
        $road = $this->part($hit, ['road', 'pedestrian', 'footway', 'path']);

        if ($road === null) {
            return null;
        }

        return trim($road.' '.($this->part($hit, ['house_number']) ?? ''));
    }

    /**
     * The first of several address keys that is set. Nominatim names a small
     * place "village" and a large one "city".
     *
     * @param  array<string, mixed>  $hit
     * @param  list<string>  $keys
     */
    private function part(array $hit, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = data_get($hit, 'address.'.$key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $hit */
    private function timezone(array $hit): ?string
    {
        $country = data_get($hit, 'address.country_code');

        if (! is_string($country) || $country === '') {
            return null;
        }

        return $this->timezones->forCountry(
            $country,
            (float) $hit['lat'],
            (float) $hit['lon'],
        );
    }
}
