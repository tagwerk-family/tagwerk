<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\ReadOnlyAdmin;
use BackedEnum;
use Fahoba\PluginApi\Households\AvailableWhileReadOnly;
use Filament\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Where every admin page of a read-only household leads. Only there.
 */
class ReadOnlyHousehold extends Page implements AvailableWhileReadOnly
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static ?string $slug = 'read-only';

    public static function canAccess(): bool
    {
        return app(ReadOnlyAdmin::class)->applies();
    }

    public static function getNavigationLabel(): string
    {
        return __('Read-only');
    }

    public function getTitle(): string
    {
        return __('This household is read-only.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make(__('Wall and phones still show everything, but nothing can be changed. Whoever runs this server can lift it.')),
        ]);
    }
}
