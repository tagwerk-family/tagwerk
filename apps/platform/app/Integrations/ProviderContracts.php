<?php

declare(strict_types=1);

namespace App\Integrations;

use Fahoba\PluginApi\Calendar\CalendarProvider;
use Fahoba\PluginApi\Geocoding\Geocoder;
use Fahoba\PluginApi\Holidays\HolidaySource;
use Fahoba\PluginApi\Lists\ListProvider;
use Fahoba\PluginApi\Photos\PhotoSource;
use Fahoba\PluginApi\Recipes\RecipeProvider;
use Fahoba\PluginApi\SchoolHolidays\SchoolHolidaySource;
use Fahoba\PluginApi\Tasks\TaskProvider;
use Fahoba\PluginApi\Weather\WeatherSource;

/**
 * Which contract a provider must fulfil per area.
 *
 * One place for the catalog, which checks plugins when they are offered, and
 * for the contract tests, which check every registered provider. A new area
 * without an entry here stands out in those tests.
 */
final class ProviderContracts
{
    /** @var array<string, class-string> */
    public const BASE = [
        'calendar' => CalendarProvider::class,
        'lists' => ListProvider::class,
        'tasks' => TaskProvider::class,
        'recipes' => RecipeProvider::class,
        'photos' => PhotoSource::class,
        'geocoding' => Geocoder::class,
        'weather' => WeatherSource::class,
        'holidays' => HolidaySource::class,
        'school_holidays' => SchoolHolidaySource::class,
    ];
}
