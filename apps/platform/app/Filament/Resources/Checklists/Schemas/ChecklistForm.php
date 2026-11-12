<?php

declare(strict_types=1);

namespace App\Filament\Resources\Checklists\Schemas;

use App\Enums\ListKind;
use App\Enums\ProviderArea;
use App\Integrations\ProviderRegistry;
use App\Models\ProviderAccount;
use Fahoba\PluginApi\Connections\UsesCredentials;
use Fahoba\PluginApi\Connections\UsesOAuth;
use Fahoba\PluginApi\Lists\ListProvider;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/** Without `household_id` — same reasoning as in `TaskForm`. */
class ChecklistForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Title'))
                    ->required()
                    ->maxLength(255),

                Select::make('kind')
                    ->label(__('Kind'))
                    ->options(collect(ListKind::cases())
                        ->mapWithKeys(fn (ListKind $kind): array => [$kind->value => $kind->label()])
                        ->all())
                    ->default(ListKind::Generic->value)
                    ->required(),

                ColorPicker::make('color')
                    ->label(__('Colour')),

                Select::make('list_provider')
                    ->label(__('Also push to'))
                    ->placeholder(__('Nowhere — the list stays in Fahoba'))

                    /*
                     * From the registry: a new list service is offered here as
                     * soon as it is registered.
                     */
                    ->options(fn (): array => collect(app(ProviderRegistry::class)->listProviders())
                        ->map(fn (ListProvider $provider): string => $provider->label())
                        ->all())
                    ->live()
                    ->helperText(__('New items are handed over there as well. Services that sync also bring their items and ticks here.')),

                // Every service that needs a connection; the rest has nothing to choose.
                Select::make('list_account_id')
                    ->label(__('Through which connection'))
                    ->options(fn (Get $get): array => ProviderAccount::query()
                        ->forArea(ProviderArea::Lists)
                        ->where('provider', $get('list_provider'))
                        ->pluck('account_label', 'id')
                        ->all())
                    ->live()
                    ->required(fn (Get $get): bool => self::needsAccount($get('list_provider')))
                    ->visible(fn (Get $get): bool => self::needsAccount($get('list_provider'))),

                Select::make('list_target')
                    ->label(__('Into which list there'))
                    ->placeholder(__('What the list is called here'))
                    ->options(fn (Get $get): array => self::targets($get('list_account_id')))
                    ->visible(fn (Get $get): bool => self::targets($get('list_account_id')) !== []),
            ]);
    }

    private static function needsAccount(mixed $provider): bool
    {
        if (! is_string($provider) || $provider === '') {
            return false;
        }

        $provider = app(ProviderRegistry::class)->listProvider($provider);

        return $provider instanceof UsesOAuth || $provider instanceof UsesCredentials;
    }

    /**
     * The account's lists remembered when connecting.
     *
     * @return array<string, string>
     */
    private static function targets(mixed $accountId): array
    {
        if (! is_numeric($accountId)) {
            return [];
        }

        $targets = (array) ((ProviderAccount::find((int) $accountId)?->settings ?? [])['targets'] ?? []);

        return collect($targets)
            ->mapWithKeys(fn (array $target): array => [(string) $target['id'] => (string) $target['name']])
            ->all();
    }
}
