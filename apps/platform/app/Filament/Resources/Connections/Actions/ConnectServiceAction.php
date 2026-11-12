<?php

declare(strict_types=1);

namespace App\Filament\Resources\Connections\Actions;

use App\Enums\ConnectionStatus;
use App\Enums\ProviderArea;
use App\Integrations\ProviderRegistry;
use App\Models\ProviderAccount;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Connections\CredentialField;
use Fahoba\PluginApi\Connections\DescribesSetup;
use Fahoba\PluginApi\Connections\FinishesConnection;
use Fahoba\PluginApi\Connections\UsesCredentials;
use Fahoba\PluginApi\Connections\UsesOAuth;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\RedirectResponse;
use Throwable;

/**
 * The way to a new connection — in three steps instead of a row of buttons.
 *
 * Before, every area and provider had a button of its own in the header. With
 * two services that worked; with Google Tasks, which both accepts lists and
 * supplies tasks, there were two identically labelled buttons doing different
 * things.
 *
 * It stays extensible because nothing here names a provider: the choice
 * comes from the registry, the explanations from the provider, and so do the
 * input fields. A new service is a class and an entry in
 * `config/fahoba.php`.
 */
final class ConnectServiceAction
{
    public static function make(): Action
    {
        return Action::make('connectService')
            ->label(__('Connect a service'))
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading(__('Link a service'))
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel(__('Connect'))
            ->steps([
                self::areaStep(),
                self::serviceStep(),
                self::setupStep(),
            ])
            ->action(self::finish(...));
    }

    private static function areaStep(): Step
    {
        return Step::make(__('What'))
            ->description(__('What the connection is for'))
            ->schema([
                ToggleButtons::make('area')
                    ->hiddenLabel()
                    ->options(collect(ProviderArea::cases())
                        ->mapWithKeys(fn (ProviderArea $area): array => [
                            $area->value => $area->label(),
                        ])
                        ->all())
                    ->icons([
                        ProviderArea::Calendar->value => Heroicon::OutlinedCalendarDays,
                        ProviderArea::Lists->value => Heroicon::OutlinedShoppingCart,
                        ProviderArea::Tasks->value => Heroicon::OutlinedCheckCircle,
                        ProviderArea::Photos->value => Heroicon::OutlinedPhoto,
                        ProviderArea::Recipes->value => Heroicon::OutlinedBookOpen,
                        ProviderArea::SchoolHolidays->value => Heroicon::OutlinedAcademicCap,
                    ])
                    ->columns(2)
                    ->required()
                    /*
                     * Area first, then service: a Google account for the
                     * calendar is not the same as one for tasks. If only the
                     * service were asked for, "Google Tasks" would appear
                     * twice in the same list.
                     */
                    ->live()
                    ->afterStateUpdated(fn (callable $set) => $set('provider', null)),
            ]);
    }

    private static function serviceStep(): Step
    {
        return Step::make(__('Which'))
            ->description(__('The service behind it'))
            ->schema([
                ToggleButtons::make('provider')
                    ->hiddenLabel()
                    ->options(fn (Get $get): array => collect(self::providers($get))
                        ->map(fn (object $provider): string => $provider->label())
                        ->all())
                    ->icons(fn (Get $get): array => collect(self::providers($get))
                        ->map(fn (object $provider): string => $provider instanceof DescribesSetup
                            ? $provider->icon()
                            : 'heroicon-o-puzzle-piece')
                        ->all())
                    ->columns(2)
                    ->required()
                    ->live(),

                Text::make(fn (Get $get): string => self::chosen($get) instanceof DescribesSetup
                    ? self::chosen($get)->description()
                    : '')
                    ->visible(fn (Get $get): bool => self::chosen($get) instanceof DescribesSetup),
            ]);
    }

    private static function setupStep(): Step
    {
        return Step::make(__('Set up'))
            ->description(__('What is left to do'))
            ->schema([
                UnorderedList::make(fn (Get $get): array => array_map(
                    fn (string $sentence): Text => Text::make($sentence),
                    self::chosen($get) instanceof DescribesSetup
                        ? self::chosen($get)->setupNotes()
                        : [],
                ))
                    ->visible(fn (Get $get): bool => self::chosen($get) instanceof DescribesSetup
                        && self::chosen($get)->setupNotes() !== []),

                Text::make(__('“Connect” takes you to the provider, which asks for your approval.'))
                    ->visible(fn (Get $get): bool => self::chosen($get) instanceof UsesOAuth),

                ...self::credentialInputs(),
            ]);
    }

    /**
     * The input fields of every provider that asks for credentials.
     *
     * All at once and shown depending on the choice: a schema that rebuilds
     * itself when moving on loses its state in Livewire.
     *
     * @return array<int, TextInput>
     */
    private static function credentialInputs(): array
    {
        $inputs = [];
        $registry = app(ProviderRegistry::class);

        foreach (ProviderArea::cases() as $area) {
            foreach ($registry->connectableProviders($area) as $key => $provider) {
                if (! $provider instanceof UsesCredentials) {
                    continue;
                }

                foreach ($provider->credentialFields() as $field) {
                    $inputs[] = self::input($area, $key, $field);
                }
            }
        }

        return $inputs;
    }

    private static function input(ProviderArea $area, string $key, CredentialField $field): TextInput
    {
        $visible = fn (Get $get): bool => $get('area') === $area->value
            && $get('provider') === $key;

        return TextInput::make("credentials.{$area->value}.{$key}.{$field->name}")
            ->label($field->label)
            ->placeholder($field->placeholder)
            ->helperText($field->hint)
            ->password($field->secret)
            ->revealable($field->secret)
            ->visible($visible)
            // Required only while the field is visible: otherwise validation
            // would hang on fields of a service nobody chose.
            ->required(fn (Get $get): bool => $field->required && $visible($get));
    }

    /** @param  array<string, mixed>  $data */
    private static function finish(array $data): ?RedirectResponse
    {
        $area = ProviderArea::from((string) $data['area']);
        $key = (string) $data['provider'];
        $provider = app(ProviderRegistry::class)->connectableProviders($area)[$key] ?? null;

        if ($provider instanceof UsesCredentials) {
            self::withCredentials($provider, $area, $key, $data);

            return null;
        }

        // The consent flow belongs to the provider: the admin ends here.
        return redirect()->to(route('connection.connect', [
            'area' => $area->value,
            'provider' => $key,
        ]));
    }

    /** @param  array<string, mixed>  $data */
    private static function withCredentials(
        UsesCredentials $provider,
        ProviderArea $area,
        string $key,
        array $data,
    ): void {
        /** @var array<string, string|null> $credentials */
        $credentials = data_get($data, "credentials.{$area->value}.{$key}", []);

        $error = $provider->verifyCredentials($credentials);

        if ($error !== null) {
            Notification::make()->title($error)->danger()->send();

            return;
        }

        $account = ProviderAccount::updateOrCreate(
            [
                'household_id' => app(HouseholdContext::class)->id(),
                'area' => $area,
                'provider' => $key,
            ],
            [
                'account_label' => $provider->accountLabel($credentials),
                'credentials' => $credentials,

                // Tokens of a previous login may belong to another account.
                'access_token' => null,
                'refresh_token' => null,
                'token_expires_at' => null,
                'status' => ConnectionStatus::Active,
            ],
        );

        $finisher = $area->finisher();

        /*
         * The credentials were just accepted, so a failure here is passing.
         * The connection stays; only the lists to choose from are missing
         * until the next connect.
         */
        try {
            if ($finisher !== null) {
                /** @var FinishesConnection $finisher */
                $finisher = app($finisher);
                $finisher->finish($account);
            }
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title(__('Connected, but the lists there could not be fetched.'))
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('Connected.'))
            ->success()
            ->send();
    }

    /**
     * The connectable providers of the chosen area.
     *
     * @return array<string, object>
     */
    private static function providers(Get $get): array
    {
        $area = ProviderArea::tryFrom((string) $get('area'));

        return $area === null
            ? []
            : app(ProviderRegistry::class)->connectableProviders($area);
    }

    private static function chosen(Get $get): ?object
    {
        return self::providers($get)[(string) $get('provider')] ?? null;
    }
}
