<?php

namespace App\Filament\Resources\Households\Schemas;

use App\Areas\AreaDefinition;
use App\Areas\Areas;
use App\Enums\Backdrop;
use App\Enums\Feature;
use App\Enums\MealKind;
use App\Enums\ThemeMode;
use App\Integrations\ProviderRegistry;
use App\Models\Household;
use App\Models\SupportAccess;
use App\Services\ParentPin;
use App\Services\Photos\PhotoRules;
use App\Support\Designs;
use App\Support\Locale;
use Fahoba\PluginApi\Geocoding\Coordinates;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class HouseholdForm
{
    /**
     * ISO 3166-2 codes as Yasumi understands them. Grouped by country, so
     * nobody has to know "AT-4" to pick Upper Austria.
     *
     * @return array<string, array<string, string>>
     */
    public static function holidayRegions(): array
    {
        return [
            __('Austria') => [
                'AT' => __('Austria – nationwide'),
                'AT-1' => __('Burgenland'),
                'AT-2' => __('Carinthia'),
                'AT-3' => __('Lower Austria'),
                'AT-4' => __('Upper Austria'),
                'AT-5' => __('Salzburg'),
                'AT-6' => __('Styria'),
                'AT-7' => __('Tyrol'),
                'AT-8' => __('Vorarlberg'),
                'AT-9' => __('Vienna'),
            ],
            __('Germany') => [
                'DE' => __('Germany – nationwide'),
                'DE-BW' => __('Baden-Württemberg'),
                'DE-BY' => __('Bavaria'),
                'DE-BE' => __('Berlin'),
                'DE-BB' => __('Brandenburg'),
                'DE-HB' => __('Bremen'),
                'DE-HH' => __('Hamburg'),
                'DE-HE' => __('Hesse'),
                'DE-MV' => __('Mecklenburg-Western Pomerania'),
                'DE-NI' => __('Lower Saxony'),
                'DE-NW' => __('North Rhine-Westphalia'),
                'DE-RP' => __('Rhineland-Palatinate'),
                'DE-SL' => __('Saarland'),
                'DE-SN' => __('Saxony'),
                'DE-ST' => __('Saxony-Anhalt'),
                'DE-SH' => __('Schleswig-Holstein'),
                'DE-TH' => __('Thuringia'),
            ],
            __('Switzerland') => [
                'CH' => __('Switzerland – nationwide'),
            ],
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                /*
                 * Accordions: the form is long, and whoever comes here wants
                 * one thing. On create everything stays open, otherwise
                 * required fields would hide in closed sections.
                 */
                Section::make(__('General'))
                    ->description(__('Name, address and holidays.'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Household name'))
                            ->placeholder(__('For example “The Bergers”'))
                            ->required(),
                        Select::make('address_lookup')
                            ->label(__('Find address'))
                            ->placeholder(__('Street, number and place'))
                            ->helperText(__('Pick a suggestion — it fills the fields below, which you can still correct.'))
                            ->searchable()
                            // Nominatim allows one request per second; without this
                            // pause every keystroke would cause one of its own.
                            ->searchDebounce(700)
                            ->searchPrompt(__('Enter at least three characters'))
                            ->searchingMessage(__('Searching …'))
                            ->noSearchResultsMessage(__('Nothing found — street, number and place together usually work best.'))
                            // Only the search; the address lives in the fields below.
                            ->dehydrated(false)
                            ->getSearchResultsUsing(function (string $search): array {
                                if (mb_strlen(trim($search)) < 3) {
                                    return [];
                                }

                                return collect(app(ProviderRegistry::class)->geocoder()?->search($search) ?? [])
                                    /*
                                     * The whole find is packed into the key:
                                     * Filament only passes the value to the
                                     * listener, and a second lookup just to
                                     * learn the parts again would be one
                                     * request too many.
                                     */
                                    ->mapWithKeys(fn (Coordinates $place): array => [
                                        json_encode(get_object_vars($place), JSON_THROW_ON_ERROR) => $place->label,
                                    ])
                                    ->all();
                            })
                            /*
                             * Without this Filament refuses to validate the
                             * selection on save: the suggestions come from
                             * outside, there is no fixed option list.
                             */
                            ->getOptionLabelUsing(fn (?string $value): ?string => self::found($value)?->label)
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                $place = self::found($state);

                                if ($place === null) {
                                    return;
                                }

                                $set('street', $place->street);
                                $set('postal_code', $place->postalCode);
                                $set('city', $place->city);
                                $set('country_code', $place->countryCode);
                                self::place($place, $set);
                            })
                            ->columnSpanFull(),

                        /*
                         * The address in parts. Filled from the suggestion,
                         * editable by hand: a new house or a farm name is
                         * missing from OpenStreetMap more often than one
                         * thinks. A change looks the place up again.
                         */
                        TextInput::make('street')
                            ->label(__('Street and number'))
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(self::relocate(...)),
                        TextInput::make('postal_code')
                            ->label(__('Postcode'))
                            ->required()
                            ->maxLength(16)
                            ->live(onBlur: true)
                            ->afterStateUpdated(self::relocate(...)),
                        TextInput::make('city')
                            ->label(__('Place'))
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(self::relocate(...)),
                        Select::make('country_code')
                            ->label(__('Country'))
                            ->options(self::countries(...))
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(self::relocate(...)),

                        TextEntry::make('location')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->state(fn (Get $get): string => $get('latitude') === null
                                ? __('Not located yet — without a location there is no weather and no sun position.')
                                : __('Located at :latitude, :longitude — weather and sun position use this point.', [
                                    'latitude' => number_format((float) $get('latitude'), 2),
                                    'longitude' => number_format((float) $get('longitude'), 2),
                                ]))
                            ->color(fn (Get $get): string => $get('latitude') === null ? 'danger' : 'gray'),

                        /*
                         * What follows from the address lives in columns of
                         * its own, but in no input field: nobody knows their
                         * latitude by heart.
                         */
                        Hidden::make('address'),
                        Hidden::make('latitude'),
                        Hidden::make('longitude'),

                        Hidden::make('timezone'),
                        Select::make('locale')
                            ->label(__('Language'))
                            ->helperText(__('For the wall and every account that has not chosen its own.'))
                            ->options(Locale::supported())
                            ->default(config('app.locale'))
                            ->selectablePlaceholder(false)
                            ->required(),
                        Select::make('holiday_region')
                            ->label(__('Holidays'))
                            ->helperText(__('School and public holidays appear on the wall.'))
                            ->options(self::holidayRegions())
                            ->searchable()
                            ->required()
                            ->default('AT'),
                    ]),

                Section::make(__('Parent PIN'))
                    ->description(__('Every parent sets their own PIN in their profile (user menu → Profile). Each of them unlocks the wall tablet; as long as nobody has one, everything is allowed on the wall.'))
                    ->columnSpanFull()
                    ->hiddenOn('create')
                    ->collapsible()
                    ->collapsed(self::collapsedOnEdit(...))
                    ->schema([
                        TextEntry::make('pin_parents')
                            ->label(__('PIN set by'))
                            ->state(fn (?Household $record): ?string => $record === null ? null : (app(ParentPin::class)
                                ->parents($record)
                                ->pluck('name')
                                ->join(', ') ?: null))
                            ->placeholder(__('Nobody has set one yet')),
                    ]),

                Section::make(__('Areas'))
                    ->description(__('What appears on the wall and the phone. Areas switched off disappear from the bar; their data is kept and is back once switched on again.'))
                    ->collapsible()
                    ->collapsed(self::collapsedOnEdit(...))
                    ->columnSpanFull()
                    ->schema([
                        CheckboxList::make('enabled_features')
                            ->hiddenLabel()
                            ->columns(2)
                            ->options(fn (): array => array_map(fn (AreaDefinition $area): string => $area->label, self::areas(optIn: false)))
                            ->descriptions(fn (): array => array_map(fn (AreaDefinition $area): string => $area->description, self::areas(optIn: false)))

                            /*
                             * What is off gets stored — what is on gets shown.
                             * A tick to untick reads more easily than a list
                             * of absences, and an area added later is there by
                             * itself instead of having to be added.
                             */
                            ->formatStateUsing(fn (?array $state, ?Household $record): array => array_values(array_intersect(
                                $record?->features() ?? app(Areas::class)->keys(),
                                array_keys(self::areas(optIn: false)),
                            )))
                            ->dehydrated(false)
                            ->live()
                            ->afterStateUpdated(fn (array $state, Set $set): mixed => $set(
                                'disabled_features',
                                array_values(array_diff(array_keys(self::areas(optIn: false)), $state)),
                            )),

                        Hidden::make('disabled_features'),
                    ]),

                /*
                 * The other way round: these open the household to programs
                 * and addresses outside, so they are off until a parent ticks
                 * them — an installed package hands out no door by itself.
                 */
                Section::make(__('Interfaces'))
                    ->description(__('Off until switched on. Each opens the household to other programs; switch on only what you use. Switched off, every token and address of it stops working at once.'))
                    ->collapsible()
                    ->collapsed(self::collapsedOnEdit(...))
                    ->columnSpanFull()
                    ->visible(fn (): bool => self::areas(optIn: true) !== [])
                    ->schema([
                        CheckboxList::make('opted_in_features')
                            ->hiddenLabel()
                            ->columns(2)
                            ->options(fn (): array => array_map(fn (AreaDefinition $area): string => $area->label, self::areas(optIn: true)))
                            ->descriptions(fn (): array => array_map(fn (AreaDefinition $area): string => $area->description, self::areas(optIn: true)))
                            // A removed plugin's key drops out on the next save.
                            ->formatStateUsing(fn (?array $state): array => array_values(array_intersect((array) $state, array_keys(self::areas(optIn: true))))),
                    ]),

                Section::make(__('Stars'))
                    ->description(__('Extra stars for a child who has done all of their own tasks of a day.'))
                    ->collapsible()
                    ->collapsed(self::collapsedOnEdit(...))
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => in_array(Feature::Points->value, $get('enabled_features') ?? [], true))
                    ->schema([
                        TextInput::make('day_bonus')
                            ->label(__('Bonus for a finished day'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(10)
                            ->default(0)
                            ->required()
                            ->suffix('⭐')
                            ->helperText(__('0 switches it off. Keep it small — about one task\'s worth: it should round off the day, not outweigh the tasks themselves. Only the child\'s own tasks count, and undoing one takes the bonus back.')),
                    ]),

                Section::make(__('Support'))
                    ->description(__('The operator of this server can open the household to help. Every access is recorded.'))
                    ->collapsible()
                    ->collapsed(self::collapsedOnEdit(...))
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('last_support_access')
                            ->label(__('Last support access'))
                            ->state(fn (?Household $record): ?string => $record === null ? null : SupportAccess::query()
                                ->where('household_id', $record->id)
                                ->latest()
                                ->first()
                                ?->created_at
                                ->setTimezone($record->timezone ?? config('app.timezone'))
                                ->locale(app()->getLocale())
                                ->isoFormat('L LT'))
                            ->placeholder(__('Never')),
                    ]),

                Section::make(__('Photo frame'))
                    ->description(__('Which pictures the photo frame on the wall prefers.'))
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed(self::collapsedOnEdit(...))
                    ->visible(fn (?Household $record): bool => $record?->has(Feature::Photos) ?? true)
                    ->schema([
                        Select::make('photo_rule')
                            ->label(__('Selection'))
                            ->placeholder(__('Evenly from all sources'))
                            ->options(fn (): array => app(PhotoRules::class)->options())
                            // A plugin removed since: the choice falls back to evenly.
                            ->in(fn (): array => array_keys(app(PhotoRules::class)->options()))
                            ->live()
                            ->helperText(fn (Get $get): string => app(PhotoRules::class)->find($get('photo_rule'))?->description()
                                ?? __('Every source gets its turn equally often; the age of the pictures does not matter.')),
                    ]),

                Section::make(__('Meals'))
                    ->description(__('When the family cooks. The meal plan offers only these meals.'))
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed(self::collapsedOnEdit(...))
                    ->visible(fn (?Household $record): bool => $record?->has(Feature::Meals) ?? true)
                    ->schema([
                        CheckboxList::make('meal_kinds')
                            ->label(__('We cook'))
                            ->options(collect(MealKind::cases())
                                ->mapWithKeys(fn (MealKind $kind): array => [$kind->value => $kind->label()])
                                ->all())
                            ->columns(2)
                            // Nothing stored means both: shown ticked, so the form says what applies.
                            ->formatStateUsing(fn (?array $state, ?Household $record): array => array_map(
                                fn (MealKind $kind): string => $kind->value,
                                $record?->mealKinds() ?? MealKind::cases(),
                            ))
                            ->required()
                            ->minItems(1),
                    ]),

                Section::make(__('Always at home'))
                    ->description(__('Never put on the shopping list from a recipe.'))
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed(self::collapsedOnEdit(...))
                    ->visible(fn (?Household $record): bool => $record?->has(Feature::Meals) ?? true)
                    ->schema([
                        TagsInput::make('pantry_staples')
                            ->label(__('Pantry staples'))
                            ->formatStateUsing(fn (?array $state, ?Household $record): array => $state
                                ?? Household::defaultStaples($record->locale ?? (string) config('app.locale')))
                            ->nestedRecursiveRules(['string', 'max:60'])
                            ->helperText(__('A word also covers what ends in it: “oil” covers olive oil.')),
                    ]),

                Section::make(__('Daily summary'))
                    ->description(__('A message to the adults\' phones in the evening: what got done today and what is still open.'))
                    ->collapsible()
                    ->collapsed(self::collapsedOnEdit(...))
                    ->columnSpanFull()
                    ->schema([
                        TimePicker::make('summary_at')
                            ->label(__('Time'))
                            ->seconds(false)
                            ->default('19:30')
                            ->required()
                            ->helperText(__('Children do not get the message.')),
                    ]),

                Section::make(__('Light and dark theme'))
                    ->description(__('When wall and phone switch. Single devices and people can choose light or dark for good.'))
                    ->collapsible()
                    ->collapsed(self::collapsedOnEdit(...))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('theme_mode')
                            ->label(__('Switch by'))
                            ->options(collect(ThemeMode::cases())
                                ->mapWithKeys(fn (ThemeMode $mode): array => [$mode->value => $mode->label()])
                                ->all())
                            ->default(ThemeMode::Fixed->value)
                            ->required()
                            ->columnSpanFull(),

                        Select::make('backdrop')
                            ->label(__('Glass look background'))
                            ->options(collect(Backdrop::cases())
                                ->mapWithKeys(fn (Backdrop $backdrop): array => [$backdrop->value => $backdrop->label()])
                                ->all())
                            ->default(Backdrop::Dawn->value)
                            ->required()
                            ->helperText(__('Shown on devices and phones that switch on the glass look in their settings.'))
                            ->columnSpanFull(),

                        Select::make('design')
                            ->label(__('Design'))
                            ->options(fn (): array => app(Designs::class)->options())
                            ->placeholder('Family Home Base')
                            ->visible(fn (): bool => app(Designs::class)->options() !== [])
                            ->helperText(__('Colours, font and corners from an installed design. Devices and phones may choose their own.'))
                            ->columnSpanFull(),

                        TimePicker::make('theme_light_at')
                            ->label(__('Light from'))
                            ->seconds(false)
                            ->default('07:00')
                            ->required()
                            ->helperText(__('With sun position, the fallback as long as no place is chosen.')),

                        TimePicker::make('theme_dark_at')
                            ->label(__('Dark from'))
                            ->seconds(false)
                            ->default('20:00')
                            ->required(),

                        TextInput::make('sunrise_offset')
                            ->label(__('Sunrise offset'))
                            ->suffix(__('minutes'))
                            ->numeric()
                            ->integer()
                            ->minValue(-180)
                            ->maxValue(180)
                            ->default(0)
                            ->required()
                            ->helperText(__('Negative: light earlier. Positive: light later.')),

                        TextInput::make('sunset_offset')
                            ->label(__('Sunset offset'))
                            ->suffix(__('minutes'))
                            ->numeric()
                            ->integer()
                            ->minValue(-180)
                            ->maxValue(180)
                            ->default(0)
                            ->required()
                            ->helperText(__('Negative: dark earlier. Positive: dark later.')),
                    ]),
            ]);
    }

    /** The find packed into a suggestion's key, or null. */
    private static function found(?string $value): ?Coordinates
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $parts */
            $parts = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

            return new Coordinates(...$parts);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Location, time zone and the one-line address from a find. */
    private static function place(Coordinates $place, Set $set): void
    {
        $set('latitude', $place->latitude);
        $set('longitude', $place->longitude);
        $set('address', self::oneLine($place->street, $place->postalCode, $place->city));

        // Empty when no zone could be determined for the place.
        if ($place->timezone !== null) {
            $set('timezone', $place->timezone);
        }
    }

    /**
     * After a correction by hand: look the address up again. Found, it moves
     * the location; not found, the previous location stays — a house missing
     * from the map is still in the same valley.
     */
    private static function relocate(Get $get, Set $set): void
    {
        $set('address', self::oneLine($get('street'), $get('postal_code'), $get('city')));

        $city = $get('city');
        $country = $get('country_code');

        if (! is_string($city) || $city === '' || ! is_string($country) || $country === '') {
            return;
        }

        $geocoder = app(ProviderRegistry::class)->geocoder();

        // With the street first, then without: a street the map lacks
        // should not lose the whole place.
        $place = $geocoder?->locate($get('street'), $get('postal_code'), $city, $country)
            ?? $geocoder?->locate(null, $get('postal_code'), $city, $country);

        if ($place === null) {
            return;
        }

        $set('latitude', $place->latitude);
        $set('longitude', $place->longitude);

        if ($place->timezone !== null) {
            $set('timezone', $place->timezone);
        }
    }

    /** "Dorfstraße 3, 4274 Schönau" for tables and lists. */
    private static function oneLine(mixed $street, mixed $postalCode, mixed $city): ?string
    {
        $place = trim(implode(' ', array_filter([$postalCode, $city], is_string(...))));
        $line = implode(', ', array_filter([is_string($street) ? trim($street) : null, $place]));

        return $line === '' ? null : $line;
    }

    /**
     * Every country that has a time zone, named in the interface's language.
     * From PHP's zone database and intl — no list of our own to maintain.
     *
     * @return array<string, string>
     */
    private static function countries(): array
    {
        $countries = [];

        foreach (\DateTimeZone::listIdentifiers() as $zone) {
            $code = (new \DateTimeZone($zone))->getLocation()['country_code'] ?? '??';

            if ($code !== '??') {
                $countries[$code] = \Locale::getDisplayRegion('-'.$code, app()->getLocale()) ?: $code;
            }
        }

        asort($countries, SORT_LOCALE_STRING);

        return $countries;
    }

    /** Closed when editing, open when creating: see the note in configure(). */
    /** @return array<string, AreaDefinition> the areas on by default, or the opt-in ones */
    private static function areas(bool $optIn): array
    {
        return array_filter(app(Areas::class)->all(), fn (AreaDefinition $area): bool => $area->optIn === $optIn);
    }

    private static function collapsedOnEdit(string $operation): bool
    {
        return $operation === 'edit';
    }
}
