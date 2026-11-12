<?php

declare(strict_types=1);

namespace Fahoba\IcsFeed\Filament;

use App\Filament\Concerns\BelongsToArea;
use App\Filament\NavigationGroup;
use App\Models\Person;
use App\Support\HouseholdTimezone;
use BackedEnum;
use Fahoba\IcsFeed\IcsFeed;
use Fahoba\IcsFeed\IcsFeedArea;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Integrations → Calendar subscriptions. The address is shown once, when it
 * is made; a new one replaces it, and the old one stops working.
 */
class IcsFeedResource extends Resource
{
    use BelongsToArea;

    protected static ?string $model = IcsFeed::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRss;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Integrations;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'calendar-subscriptions';

    public static function area(): string
    {
        return IcsFeedArea::KEY;
    }

    public static function getModelLabel(): string
    {
        return __('Calendar subscription');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Calendar subscriptions');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(80),
            Select::make('person_id')
                ->label(__('Person'))
                ->options(fn (): array => Person::ordered()->pluck('name', 'id')->all())
                ->placeholder(__('Whole family'))
                ->helperText(__('With a person: only their events.')),
            Toggle::make('include_closures')->label(__('Holidays and school-free days')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name')),
                TextColumn::make('person.name')->label(__('Person'))->placeholder(__('Whole family')),
                IconColumn::make('include_closures')->label(__('Holidays'))->boolean(),
                TextColumn::make('last_used_at')
                    ->label(__('Last fetched'))
                    ->dateTime()
                    ->timezone(fn (): string => HouseholdTimezone::current())
                    ->placeholder(__('Never')),
            ])
            ->recordActions([
                Action::make('renew')
                    ->label(__('New address'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->modalDescription(__('The old address stops working; every app that subscribed needs the new one.'))
                    ->action(fn (IcsFeed $record) => self::announce($record->renew())),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function announce(string $address): void
    {
        Notification::make()
            ->title(__('Copy the address now — it is not shown again.'))
            ->body($address)
            ->persistent()
            ->success()
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageIcsFeeds::route('/'),
        ];
    }
}
