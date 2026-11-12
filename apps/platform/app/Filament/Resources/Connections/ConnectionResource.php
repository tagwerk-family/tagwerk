<?php

declare(strict_types=1);

namespace App\Filament\Resources\Connections;

use App\Filament\Concerns\NeedsHousehold;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Connections\Pages\ListConnections;
use App\Filament\Resources\Connections\Tables\ConnectionsTable;
use App\Models\ProviderAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * A household's connections — across all areas.
 *
 * One list instead of one per area: whoever wants to know what Fahoba has
 * open to the outside should see it in one place. The area is in the row.
 *
 * No form: a connection is made through the provider's consent or with the
 * household itself — creating one by hand would make no sense.
 */
class ConnectionResource extends Resource
{
    use NeedsHousehold;

    protected static ?string $model = ProviderAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Link;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Integrations;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('Connection');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Connections');
    }

    public static function table(Table $table): Table
    {
        return ConnectionsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return [
            'index' => ListConnections::route('/'),
        ];
    }
}
