<?php

declare(strict_types=1);

namespace Fahoba\Webhooks\Filament;

use App\Filament\Concerns\BelongsToArea;
use App\Filament\NavigationGroup;
use App\Support\HouseholdTimezone;
use App\Support\OutboundHttp;
use BackedEnum;
use Closure;
use Fahoba\PluginApi\Events\DomainEvent;
use Fahoba\Webhooks\DeliverWebhookJob;
use Fahoba\Webhooks\Webhook;
use Fahoba\Webhooks\WebhookDelivery;
use Fahoba\Webhooks\WebhooksArea;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
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
 * Integrations → Webhooks. The signing secret is shown once; the receiver
 * checks `X-Fahoba-Signature` (HMAC-SHA256 of the body) with it.
 */
class WebhookResource extends Resource
{
    use BelongsToArea;

    protected static ?string $model = Webhook::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Integrations;

    protected static ?int $navigationSort = 40;

    public static function area(): string
    {
        return WebhooksArea::KEY;
    }

    public static function getModelLabel(): string
    {
        return __('Webhook');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Webhooks');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(80),
            TextInput::make('url')
                ->label(__('Address'))
                ->required()
                ->url()
                ->maxLength(2048)
                ->helperText(__('Must be reachable from the internet; addresses in a home network are refused.'))
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (! app(OutboundHttp::class)->isPublic((string) $value)) {
                        $fail(__('This address is not reachable from the internet.'));
                    }
                }),
            CheckboxList::make('events')
                ->label(__('Events'))
                ->options(fn (): array => DomainEvent::labels())
                ->required()
                ->columns(2),
            Toggle::make('active')->label(__('Active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('lastDelivery'))
            ->columns([
                TextColumn::make('name')->label(__('Name')),
                TextColumn::make('url')->label(__('Address'))->limit(40),
                IconColumn::make('active')->label(__('Active'))->boolean(),
                TextColumn::make('lastDelivery.status')
                    ->label(__('Last delivery'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        WebhookDelivery::DELIVERED => 'success',
                        WebhookDelivery::FAILED => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        WebhookDelivery::DELIVERED => __('Delivered'),
                        WebhookDelivery::FAILED => __('Failed'),
                        default => __('Pending'),
                    })
                    ->placeholder(__('Never')),
                TextColumn::make('lastDelivery.created_at')
                    ->label(__('When'))
                    ->dateTime()
                    ->timezone(fn (): string => HouseholdTimezone::current())
                    ->placeholder('–'),
            ])
            ->recordActions([
                Action::make('ping')
                    ->label(__('Send test'))
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->action(function (Webhook $record): void {
                        DeliverWebhookJob::dispatchSync((int) $record->household_id, (int) $record->id, 'ping', [], now()->toIso8601String());
                        $delivery = $record->lastDelivery()->first();

                        $delivery?->status === WebhookDelivery::DELIVERED
                            ? Notification::make()->title(__('Delivered'))->success()->send()
                            : Notification::make()->title(__('Failed'))->body($delivery?->error)->danger()->send();
                    }),
                Action::make('secret')
                    ->label(__('New secret'))
                    ->icon(Heroicon::OutlinedKey)
                    ->requiresConfirmation()
                    ->modalDescription(__('The receiver needs the new secret to check signatures.'))
                    ->action(fn (Webhook $record) => self::announce($record->renewSecret())),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function announce(string $secret): void
    {
        Notification::make()
            ->title(__('Copy the signing secret now — it is not shown again.'))
            ->body($secret)
            ->persistent()
            ->success()
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageWebhooks::route('/'),
        ];
    }
}
