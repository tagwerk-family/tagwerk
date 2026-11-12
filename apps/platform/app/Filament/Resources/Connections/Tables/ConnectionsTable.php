<?php

declare(strict_types=1);

namespace App\Filament\Resources\Connections\Tables;

use App\Enums\ConnectionStatus;
use App\Enums\ProviderArea;
use App\Integrations\ProviderRegistry;
use App\Models\ProviderAccount;
use App\Support\HouseholdTimezone;
use Fahoba\PluginApi\Connections\UsesCredentials;
use Fahoba\PluginApi\Connections\UsesOAuth;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class ConnectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount(['calendars', 'checklists', 'tasks', 'photoDates']))
            ->columns([
                TextColumn::make('area')
                    ->label(__('Area'))
                    ->badge()
                    ->formatStateUsing(fn (ProviderArea $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('provider')
                    ->label(__('Provider'))
                    ->badge()
                    ->formatStateUsing(fn (string $state, ProviderAccount $record): string => app(ProviderRegistry::class)
                        ->forAccount($record)
                        ->label()),
                TextColumn::make('account_label')
                    ->label(__('Account'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('State'))
                    ->badge()
                    ->formatStateUsing(fn (ConnectionStatus $state): string => $state->label())
                    ->color(fn (ConnectionStatus $state): string => $state === ConnectionStatus::Active
                        ? 'success'
                        : 'danger'),
                /*
                 * What the connection brings, in the words of its area: a
                 * column headed "Kalender" read "0" for a shopping list.
                 */
                TextColumn::make('contents')
                    ->label(__('Contents'))
                    ->state(fn (ProviderAccount $record): ?string => self::contents($record))
                    ->placeholder('—'),
                TextColumn::make('synced_at')
                    ->label(__('Last fetched'))
                    ->timezone(fn (): string => HouseholdTimezone::current())
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->defaultSort('account_label')
            ->recordActions([
                /*
                 * Only for connections that keep several lists. One with a
                 * single list would get a choice that is none.
                 */
                Action::make('importList')
                    ->label(__('Which list'))
                    ->icon('heroicon-o-inbox-stack')
                    ->visible(fn (ProviderAccount $record): bool => self::lists($record) !== [])
                    ->schema(fn (ProviderAccount $record): array => [
                        Select::make('import_list')
                            ->label(__('Bring in this list'))
                            ->options(self::lists($record))
                            ->default(($record->settings ?? [])['import_list'] ?? null)
                            ->placeholder(__('The account\'s default list'))
                            ->helperText(__('Its tasks appear on the wall, where stars and an assignee can be added.')),
                    ])
                    ->action(function (ProviderAccount $record, array $data): void {
                        $record->forceFill([
                            'settings' => [...($record->settings ?? []), 'import_list' => $data['import_list'] ?? null],
                        ])->save();
                    }),

                Action::make('reconnect')
                    ->label(__('Reconnect'))
                    ->icon('heroicon-o-arrow-path')
                    ->url(fn (ProviderAccount $record): string => route('connection.connect', [
                        'area' => $record->area->value,
                        'provider' => $record->provider,
                    ]))
                    ->visible(fn (ProviderAccount $record): bool => self::usesOAuth($record)
                        && ! $record->isActive()),
                DeleteAction::make()
                    ->label(__('Disconnect'))
                    ->modalHeading(__('End connection'))
                    ->modalDescription(fn (ProviderAccount $record): string => $record->area === ProviderArea::Lists
                        ? __('The lists in Family Home Base stay with all their items; only the sync ends.')
                        : __('Whatever was brought in from it is removed as well.'))

                    /*
                     * Only what was connected, by consent or by credentials:
                     * the built-in calendar has neither, and its events live
                     * nowhere else — disconnecting would be a deletion with
                     * no way back.
                     */
                    ->visible(fn (ProviderAccount $record): bool => self::connected($record)),
            ]);
    }

    /**
     * The account's lists remembered during sync.
     *
     * @return array<string, string>
     */
    private static function lists(ProviderAccount $account): array
    {
        return collect((array) (($account->settings ?? [])['lists'] ?? []))
            ->mapWithKeys(fn (array $list): array => [(string) $list['id'] => (string) $list['name']])
            ->all();
    }

    private static function connected(ProviderAccount $account): bool
    {
        $provider = app(ProviderRegistry::class)->forAccount($account);

        return $provider instanceof UsesOAuth || $provider instanceof UsesCredentials;
    }

    private static function usesOAuth(ProviderAccount $account): bool
    {
        return app(ProviderRegistry::class)->forAccount($account) instanceof UsesOAuth;
    }

    private static function contents(ProviderAccount $record): ?string
    {
        /*
         * Lists: how many the account holds as well, remembered on connect.
         * "1 Liste" alone would not tell whether the other two are missing
         * on purpose.
         */
        $targets = count((array) (($record->settings ?? [])['targets'] ?? []));

        if ($record->area === ProviderArea::Lists && $targets > 0) {
            return trans_choice(':count of :total list synced|:count of :total lists synced', $targets, [
                'count' => $record->checklists_count,
                'total' => $targets,
            ]);
        }

        [$count, $label] = match ($record->area) {
            ProviderArea::Calendar => [$record->calendars_count, ':count calendar|:count calendars'],
            ProviderArea::Lists => [$record->checklists_count, ':count list|:count lists'],
            ProviderArea::Tasks => [$record->tasks_count, ':count task|:count tasks'],
            ProviderArea::Photos => [$record->photo_dates_count, ':count picture|:count pictures'],
            default => [null, ''],
        };

        return $count === null ? null : trans_choice($label, (int) $count, ['count' => Number::format((int) $count, locale: app()->getLocale())]);
    }
}
