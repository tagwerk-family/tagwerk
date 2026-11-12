<?php

declare(strict_types=1);

namespace App\Integrations\Holidays\Yasumi;

use App\Support\Locale;
use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Holidays\HolidayEntry;
use Fahoba\PluginApi\Holidays\HolidaySource;
use Throwable;
use Yasumi\Holiday;
use Yasumi\Yasumi;

/**
 * Holidays from Yasumi — a library, no service, no network.
 */
final class YasumiHolidays implements HolidaySource
{
    public function key(): string
    {
        return 'yasumi';
    }

    public function label(): string
    {
        return 'Yasumi';
    }

    public function supports(string $region): bool
    {
        try {
            Yasumi::createByISO3166_2($region, (int) date('Y'), self::locale());

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function forYear(string $region, int $year): array
    {
        try {
            $provider = Yasumi::createByISO3166_2($region, $year, self::locale());
        } catch (Throwable) {
            return [];
        }

        $entries = [];

        foreach ($provider->getHolidays() as $holiday) {
            $entries[] = new HolidayEntry(
                CarbonImmutable::parse($holiday->format('Y-m-d')),
                $holiday->getName(),
                $holiday->getType() === Holiday::TYPE_OFFICIAL,
            );
        }

        return $entries;
    }

    /**
     * Yasumi's locale from the language's tag. The Austrian one gets the
     * German names more precisely right ("Staatsfeiertag" instead of "Tag der
     * Arbeit"); for other countries Yasumi falls back to "de" on its own, and
     * a language it does not know at all gets the English names.
     */
    private static function locale(): string
    {
        return str_replace('-', '_', Locale::intlTag(app()->getLocale()));
    }
}
