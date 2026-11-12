<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Geocoding;

/**
 * A place on earth, together with the spelling it was found under and — as
 * far as the source knows them — the parts of its address.
 */
final readonly class Coordinates
{
    /**
     * @param  string|null  $timezone  null when no zone could be determined
     *                                 for the place — the household's then
     *                                 stays as it is
     * @param  string|null  $countryCode  ISO 3166-1 alpha-2, upper case
     */
    public function __construct(
        public float $latitude,
        public float $longitude,
        public string $label,
        public ?string $timezone = null,
        /** Street and house number, as written in the country. */
        public ?string $street = null,
        public ?string $postalCode = null,
        public ?string $city = null,
        public ?string $countryCode = null,
    ) {}
}
