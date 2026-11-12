<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Geocoding;

/**
 * Turns an address into coordinates.
 *
 * Needed for the sun position: when it gets light and dark depends on the
 * place — and nobody knows their latitude by heart.
 */
interface Geocoder
{
    public function isConfigured(): bool;

    public function label(): string;

    /**
     * Suggestions for a typed address, best first.
     *
     * Empty when nothing matches.
     *
     * @return array<int, Coordinates>
     */
    public function search(string $query, int $limit = 5): array;

    /**
     * The place of an address entered in parts — after someone corrected a
     * suggestion by hand. Null when the source does not know it.
     */
    public function locate(?string $street, ?string $postalCode, string $city, string $countryCode): ?Coordinates;
}
