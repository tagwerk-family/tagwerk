<?php

declare(strict_types=1);

namespace App\Filament\Resources\Calendars;

use App\Filament\Concerns\NeedsHousehold;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Calendars\Pages\EditCalendar;
use App\Filament\Resources\Calendars\Pages\ListCalendars;
use App\Filament\Resources\Calendars\Schemas\CalendarForm;
use App\Filament\Resources\Calendars\Tables\CalendarsTable;
use App\Models\Calendar;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Calendars are created when taken over from Google, not by hand. Only what
 * belongs to Fahoba is editable: the mapping to a person and whether the
 * calendar is synced at all.
 */
class CalendarResource extends Resource
{
    use NeedsHousehold;

    protected static ?string $model = Calendar::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::CalendarDays;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Calendar;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('Calendar');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Calendars');
    }

    protected static ?string $recordTitleAttribute = 'summary';

    public static function form(Schema $schema): Schema
    {
        return CalendarForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CalendarsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return [
            'index' => ListCalendars::route('/'),
            'edit' => EditCalendar::route('/{record}/edit'),
        ];
    }
}
