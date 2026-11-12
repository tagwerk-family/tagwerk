<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Weather;

use DateTimeZone;
use Throwable;

/**
 * Celsius or Fahrenheit — decided by where the household lives, not by
 * anyone's choice: a family in Ohio reads 78°, one in Vienna 26°.
 */
enum TemperatureUnit: string
{
    case Celsius = 'celsius';
    case Fahrenheit = 'fahrenheit';

    /** The United States and the few places that measure like them. */
    private const FAHRENHEIT_COUNTRIES = ['US', 'PR', 'GU', 'VI', 'AS', 'MP', 'UM', 'BS', 'BZ', 'KY', 'LR', 'PW', 'FM', 'MH'];

    /**
     * From the household's time zone: it follows the place already, and
     * PHP's zone database knows each zone's country. No second question.
     */
    public static function forTimezone(?string $timezone): self
    {
        try {
            $country = (new DateTimeZone($timezone ?? 'UTC'))->getLocation()['country_code'] ?? null;
        } catch (Throwable) {
            return self::Celsius;
        }

        return in_array($country, self::FAHRENHEIT_COUNTRIES, true) ? self::Fahrenheit : self::Celsius;
    }

    /** The letter after the degree sign. */
    public function symbol(): string
    {
        return $this === self::Fahrenheit ? 'F' : 'C';
    }
}
