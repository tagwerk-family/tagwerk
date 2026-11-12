<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Calendar\CalendarImportService;
use App\Services\Lists\ListImportService;
use App\Services\Tasks\TaskImportService;
use Fahoba\PluginApi\Connections\FinishesConnection;

/**
 * The domain a connection applies to.
 *
 * Every connection stands alone: a Google account for the calendar does not
 * mean the same service also gets the shopping list. Those are two grants
 * with different scopes, two rows, disconnected separately.
 */
enum ProviderArea: string
{
    case Calendar = 'calendar';
    case Lists = 'lists';
    case Tasks = 'tasks';
    case Photos = 'photos';
    case Recipes = 'recipes';
    case SchoolHolidays = 'school_holidays';

    public function label(): string
    {
        return match ($this) {
            self::Calendar => __('Calendar'),
            self::Lists => __('Lists'),
            self::Tasks => __('Tasks'),
            self::Photos => __('Photos'),
            self::Recipes => __('Recipes'),
            self::SchoolHolidays => __('School holidays'),
        };
    }

    /**
     * Who fetches the area's data right after a grant was given.
     *
     * Null for areas without a grant: a photo source is a folder read when
     * viewed, recipes are read when planning, a holiday calendar is read only
     * when suggesting — there is nothing to fetch and nothing that could go stale between two runs.
     *
     * @return class-string<FinishesConnection>|null
     */
    public function finisher(): ?string
    {
        return match ($this) {
            self::Calendar => CalendarImportService::class,
            self::Lists => ListImportService::class,
            self::Tasks => TaskImportService::class,
            self::Photos, self::Recipes, self::SchoolHolidays => null,
        };
    }
}
