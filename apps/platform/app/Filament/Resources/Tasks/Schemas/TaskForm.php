<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Schemas;

use App\Enums\Daypart;
use App\Integrations\ProviderRegistry;
use App\Models\Person;
use App\Models\Task;
use App\Services\RecurrenceRule;
use App\Support\LocalDates;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Without `household_id`: `BelongsToHousehold` sets the column in the creating
 * hook. As a select field it would offer every household — `Household` is the
 * tenant itself and therefore carries no scope that would narrow the list.
 *
 * The recurrence is asked for in everyday fields and only assembled into the
 * RFC 5545 rule in `rrule` on save. The helper fields themselves never reach
 * the database.
 */
class TaskForm
{
    private const array SIMPLE = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Task'))
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        /*
                         * For an imported task, title, note and due date
                         * belong to the provider: the next sync would pull
                         * them anyway, and a change here would be a second
                         * truth until then.
                         */
                        Callout::make(fn (?Task $record): string => __('This task comes from :provider. Whatever is there is pulled in at the next sync — stars, assignee and repetition only apply here.', [
                            'provider' => app(ProviderRegistry::class)->forAccount($record->account)->label(),
                        ]))
                            ->icon(Heroicon::OutlinedCloudArrowDown)
                            ->columnSpanFull()
                            ->visible(fn (?Task $record): bool => $record?->isImported() ?? false),

                        Grid::make(4)
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('title')
                                    ->label(__('What needs doing?'))
                                    ->placeholder(__('For example “Take out the paper recycling”'))
                                    ->required()
                                    ->maxLength(255)
                                    ->disabled(fn (?Task $record): bool => $record?->isImported() ?? false)
                                    ->columnSpanFull(),

                                /*
                                 * For children who cannot read yet: a
                                 * toothbrush emoji is quicker to take in than
                                 * "Zähne putzen". The suggestion list replaces
                                 * the emoji picker a browser keyboard does not
                                 * offer — any character can be typed.
                                 */
                                ViewField::make('icon')
                                    ->label(__('Icon'))
                                    ->view('filament.forms.icon-picker')
                                    ->viewData(['placeholder' => '🪥'])
                                    ->rule('nullable')
                                    ->rule('string')
                                    ->rule('max:16')
                                    ->columnSpanFull(),
                            ]),

                        TextInput::make('points')
                            ->label(__('Stars'))
                            ->helperText(__('Credited when ticked off. 0 does not count.'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),

                        Toggle::make('active')
                            ->label(__('Active'))
                            ->helperText(__('Tasks switched off do not appear on the tablet but are kept along with their history.'))
                            ->inline(false)
                            ->default(true),

                        Textarea::make('notes')
                            ->label(__('Note'))
                            ->rows(2)
                            ->disabled(fn (?Task $record): bool => $record?->isImported() ?? false)
                            ->columnSpanFull(),
                    ]),

                Section::make(__('When'))
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        ToggleButtons::make('repeat')
                            ->label(__('Repeats'))
                            ->options([
                                'once' => __('Once'),
                                'DAILY' => __('Daily'),
                                'WEEKLY' => __('Weekly'),
                                'MONTHLY' => __('Monthly'),
                                'YEARLY' => __('Yearly'),
                                'custom' => __('Custom rule'),
                            ])
                            ->icons([
                                'once' => Heroicon::OutlinedCalendar,
                                'DAILY' => Heroicon::OutlinedSun,
                                'WEEKLY' => Heroicon::OutlinedCalendarDays,
                                'MONTHLY' => Heroicon::OutlinedArrowPath,
                                'YEARLY' => Heroicon::OutlinedCake,
                                'custom' => Heroicon::OutlinedCodeBracket,
                            ])
                            ->inline()
                            ->default('once')
                            ->required()
                            ->dehydrated(false)
                            ->live()
                            ->columnSpanFull(),

                        DatePicker::make('due_on')
                            ->native(false)
                            ->firstDayOfWeek(1)
                            ->label(fn (Get $get): string => self::isOnce($get) ? __('Due on') : __('Starts on'))
                            ->helperText(fn (Get $get): ?string => self::isOnce($get)
                                ? null
                                : __('Counting starts here, including whose turn it is.'))
                            ->default(fn (): string => CarbonImmutable::today()->toDateString())
                            ->required()
                            ->live(),

                        Select::make('interval')
                            ->label(__('How often'))
                            ->options(fn (Get $get): array => self::intervals((string) $get('repeat')))
                            ->default(1)
                            ->selectablePlaceholder(false)
                            ->required()
                            ->visible(fn (Get $get): bool => in_array($get('repeat'), self::SIMPLE, true))
                            ->dehydrated(false)
                            ->live(),

                        ToggleButtons::make('weekdays')
                            ->label(__('On which days'))
                            ->options(['MO' => __('Mon'), 'TU' => __('Tue'), 'WE' => __('Wed'), 'TH' => __('Thu'), 'FR' => __('Fri'), 'SA' => __('Sat'), 'SU' => __('Sun')])
                            ->multiple()
                            ->inline()
                            ->helperText(__('Without a choice: on the weekday of the start.'))
                            ->visible(fn (Get $get): bool => $get('repeat') === 'WEEKLY')
                            ->dehydrated(false)
                            ->live()
                            ->columnSpanFull(),

                        ToggleButtons::make('month_mode')
                            ->label(__('In the month'))
                            ->options(['date' => __('On a date'), 'weekday' => __('On a weekday')])
                            ->inline()
                            ->default('date')
                            ->required()
                            ->visible(fn (Get $get): bool => $get('repeat') === 'MONTHLY')
                            ->dehydrated(false)
                            ->live()
                            ->columnSpanFull(),

                        TextInput::make('month_day')
                            ->label(__('Day of the month'))
                            ->prefix(__('on day'))
                            // German writes the ordinal with a dot: "am 15."
                            ->suffix(fn (): ?string => app()->getLocale() === 'de' ? '.' : null)
                            ->integer()
                            ->minValue(1)
                            ->maxValue(31)
                            ->required()
                            ->helperText(__('If a month lacks the day, such as the 31st, there is no date that month.'))
                            ->visible(fn (Get $get): bool => $get('repeat') === 'MONTHLY' && $get('month_mode') !== 'weekday')
                            ->dehydrated(false)
                            ->live(onBlur: true),

                        Select::make('month_week')
                            ->label(__('On the'))
                            ->options(RecurrenceRule::positions())
                            ->default(1)
                            ->selectablePlaceholder(false)
                            ->required()
                            ->visible(fn (Get $get): bool => self::onWeekday($get))
                            ->dehydrated(false)
                            ->live(),

                        Select::make('month_weekday')
                            ->label(__('Weekday'))
                            ->options(RecurrenceRule::weekdays())
                            ->default('MO')
                            ->selectablePlaceholder(false)
                            ->required()
                            ->visible(fn (Get $get): bool => self::onWeekday($get))
                            ->dehydrated(false)
                            ->live(),

                        TextInput::make('custom_rule')
                            ->label(__('Rule per RFC 5545'))
                            ->placeholder('FREQ=MONTHLY;BYMONTHDAY=-1')
                            ->helperText(__('For special cases like “on the last day of the month” or “only ten times”.'))
                            ->required()
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (! is_string($value) || ! RecurrenceRule::isValid(strtoupper(trim($value)))) {
                                    $fail(__('Family Home Base does not understand this rule.'));
                                }
                            })
                            ->visible(fn (Get $get): bool => $get('repeat') === 'custom')
                            ->dehydrated(false)
                            ->live(onBlur: true)
                            ->columnSpanFull(),

                        /*
                         * Instead of one task per time: "Zähne putzen"
                         * morning and evening stays one task and is ticked
                         * twice a day.
                         */
                        ToggleButtons::make('dayparts')
                            ->label(__('Time of day'))
                            ->options(Daypart::options())
                            ->multiple()
                            ->inline()
                            ->helperText(__('None chosen: any time of the day. Several: tick off once per time of day.'))
                            ->columnSpanFull(),

                        // Days off come from School closures; a one-off is set on its day on purpose.
                        Toggle::make('skip_holidays')
                            ->label(__('Pause during holidays'))
                            ->helperText(__('Skipped on the days off under School closures. Holidays of one child only pause it for that child.'))
                            ->visible(fn (Get $get): bool => ! self::isOnce($get))
                            ->columnSpanFull(),

                        Callout::make(fn (Get $get): string => RecurrenceRule::describeRule(self::rule($get), self::start($get)))
                            ->description(fn (Get $get): string => self::preview($get))
                            ->icon(Heroicon::OutlinedArrowPath)
                            ->color('primary')
                            ->visible(fn (Get $get): bool => ! self::isOnce($get))
                            ->columnSpanFull(),

                        /*
                         * Carries the stored rule. On load it is spread over
                         * the helper fields — which is why this field comes
                         * after them, otherwise their defaults would overwrite
                         * what was loaded. On save it is rebuilt from the
                         * helper fields, never taken from the browser.
                         */
                        Hidden::make('rrule')
                            ->afterStateHydrated(function (?string $state, Get $get, Set $set): void {
                                foreach (self::fields($state, self::start($get)) as $field => $value) {
                                    $set($field, $value);
                                }
                            })
                            ->dehydrateStateUsing(fn (Get $get): ?string => self::rule($get)),
                    ]),

                Section::make(__('Who'))
                    ->icon(Heroicon::OutlinedUsers)
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        /*
                         * Not __('Everyone'): that is one occurrence anybody may tick.
                         * Here every person has their own and earns their own
                         * stars.
                         */
                        Toggle::make('per_person')
                            ->label(__('For each person separately'))
                            ->helperText(__('Everyone ticks off on their own and gets their own stars.'))
                            ->inline(false)
                            ->live()
                            ->columnSpanFull(),

                        Select::make('per_person_ids')
                            ->label(__('Who exactly'))
                            ->multiple()
                            ->options(fn (): array => Person::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->placeholder(__('All children'))
                            ->helperText(__('Leave empty: all children, including ones added later.'))
                            ->visible(fn (Get $get): bool => (bool) $get('per_person'))
                            ->dehydrateStateUsing(fn (mixed $state): ?array => filled($state) ? array_map(intval(...), (array) $state) : null)
                            ->columnSpanFull(),

                        Select::make('person_id')
                            ->label(__('Assigned to'))
                            ->relationship('person', 'name')
                            ->placeholder(__('Everyone'))
                            ->helperText(__('Without a choice anyone may tick off.'))
                            ->visible(fn (Get $get): bool => ! $get('per_person')),

                        /*
                         * The order of the selection is the order of turns —
                         * the field holds nothing else. Counting starts at the
                         * start date: the rule's first date goes to the first
                         * person.
                         */
                        Select::make('rotation')
                            ->label(__('Taking turns'))
                            ->multiple()
                            ->options(fn (): array => Person::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->helperText(__('One person at a time takes their turn in this order; this overrides “Assigned to”. Single dates can be swapped on the tablet without shifting the order.'))
                            ->visible(fn (Get $get): bool => ! self::isOnce($get) && ! $get('per_person')),
                    ]),
            ]);
    }

    private static function isOnce(Get $get): bool
    {
        return in_array($get('repeat'), [null, '', 'once'], true);
    }

    private static function onWeekday(Get $get): bool
    {
        return $get('repeat') === 'MONTHLY' && $get('month_mode') === 'weekday';
    }

    private static function start(Get $get): ?CarbonImmutable
    {
        return filled($get('due_on')) ? CarbonImmutable::parse($get('due_on')) : null;
    }

    /** @return array<int, string> */
    private static function intervals(string $frequency): array
    {
        [$every, $many] = match ($frequency) {
            'DAILY' => [__('Every day'), fn (int $count): string => __('Every :count days', ['count' => $count])],
            'WEEKLY' => [__('Every week'), fn (int $count): string => __('Every :count weeks', ['count' => $count])],
            'MONTHLY' => [__('Every month'), fn (int $count): string => __('Every :count months', ['count' => $count])],
            default => [__('Every year'), fn (int $count): string => __('Every :count years', ['count' => $count])],
        };

        $options = [1 => $every];

        foreach (range(2, 12) as $count) {
            $options[$count] = $many($count);
        }

        return $options;
    }

    private static function rule(Get $get): ?string
    {
        $frequency = $get('repeat');
        $interval = (int) ($get('interval') ?: 1);

        return match ($frequency) {
            'DAILY', 'YEARLY' => (new RecurrenceRule($frequency, $interval))->toRrule(),
            'WEEKLY' => (new RecurrenceRule('WEEKLY', $interval, (array) $get('weekdays')))->toRrule(),
            'MONTHLY' => self::onWeekday($get)
                ? (new RecurrenceRule(
                    'MONTHLY',
                    $interval,
                    position: (int) $get('month_week'),
                    positionWeekday: (string) $get('month_weekday'),
                ))->toRrule()
                : (new RecurrenceRule(
                    'MONTHLY',
                    $interval,
                    monthDay: filled($get('month_day')) ? (int) $get('month_day') : null,
                ))->toRrule(),
            'custom' => filled($get('custom_rule')) ? strtoupper(trim((string) $get('custom_rule'))) : null,
            default => null,
        };
    }

    private static function preview(Get $get): string
    {
        $rule = self::rule($get);

        if ($rule === null || ! RecurrenceRule::isValid($rule)) {
            return __('Once the rule is complete, the next dates show here.');
        }

        $today = CarbonImmutable::today();
        $start = self::start($get) ?? $today;
        $dates = RecurrenceRule::upcoming($rule, $start, $start->max($today), 4);

        if ($dates === []) {
            return __('This rule has no dates left.');
        }

        return __('Next dates: :dates', ['dates' => implode(', ', array_map(
            fn (CarbonImmutable $day): string => LocalDates::weekdayDayMonth($day),
            $dates,
        ))]);
    }

    /**
     * The stored rule, spread over the helper fields.
     *
     * @return array<string, mixed>
     */
    private static function fields(?string $rule, ?CarbonImmutable $start): array
    {
        $fields = [
            'repeat' => 'once',
            'interval' => 1,
            'weekdays' => [],
            'month_mode' => 'date',
            'month_day' => $start?->day,
            'month_week' => 1,
            'month_weekday' => 'MO',
            'custom_rule' => null,
        ];

        if (blank($rule)) {
            return $fields;
        }

        $simple = RecurrenceRule::parse($rule);

        if ($simple === null) {
            return [...$fields, 'repeat' => 'custom', 'custom_rule' => $rule];
        }

        return [
            ...$fields,
            'repeat' => $simple->frequency,
            'interval' => $simple->interval,
            'weekdays' => $simple->weekdays,
            'month_mode' => $simple->position !== null ? 'weekday' : 'date',
            'month_day' => $simple->monthDay ?? $start?->day,
            'month_week' => $simple->position ?? 1,
            'month_weekday' => $simple->positionWeekday ?? 'MO',
        ];
    }
}
