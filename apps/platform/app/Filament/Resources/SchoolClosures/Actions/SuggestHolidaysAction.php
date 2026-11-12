<?php

declare(strict_types=1);

namespace App\Filament\Resources\SchoolClosures\Actions;

use App\Enums\ProviderArea;
use App\Integrations\ProviderRegistry;
use App\Models\Household;
use App\Models\ProviderAccount;
use App\Models\SchoolClosure;
use App\Support\HouseholdContext;
use Carbon\CarbonImmutable;
use Fahoba\PluginApi\SchoolHolidays\SchoolHolidaySource;
use Fahoba\PluginApi\SchoolHolidays\SchoolHolidaySuggestion;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Suggests school holidays — from every source that knows something for the
 * household.
 *
 * Built-in sources compute from the region, connected ones (an ICS calendar)
 * read their URL. Duplicate periods appear once.
 *
 * Deliberately a suggestion and not a sync: the checkbox list is reviewed,
 * corrected and confirmed. What the rule does not know — school-specific days
 * off, bridge days, a school with its own schedule — is entered alongside by
 * hand, and none of it is ever overwritten.
 */
final class SuggestHolidaysAction
{
    public static function make(): Action
    {
        return Action::make('suggestHolidays')
            ->label(__('Suggest holidays'))
            ->icon(Heroicon::Sparkles)
            ->visible(fn (): bool => self::sources() !== [])
            ->schema([
                Select::make('school_year')
                    ->label(__('School year'))
                    ->options(self::schoolYears())
                    ->default(self::currentSchoolYear())
                    ->live()
                    ->required(),

                CheckboxList::make('holidays')
                    ->label(__('Suggestions'))
                    ->options(fn (Get $get): array => self::suggestions((int) $get('school_year')))
                    ->default(fn (Get $get): array => array_keys(self::suggestions((int) $get('school_year'))))
                    ->bulkToggleable()
                    ->helperText(__('Check the dates — every school differs. Ticked ones are created, existing entries stay untouched.')),
            ])
            ->action(function (array $data): void {
                $chosen = (array) ($data['holidays'] ?? []);
                $created = 0;

                foreach (self::suggestionsByKey((int) $data['school_year']) as $period) {
                    if (! in_array($period->key(), $chosen, true)) {
                        continue;
                    }

                    SchoolClosure::query()->create([
                        'household_id' => app(HouseholdContext::class)->id(),
                        'person_id' => null,
                        'title' => $period->title,
                        'starts_on' => $period->startsOn->toDateString(),
                        'ends_on' => $period->endsOn->toDateString(),
                    ]);

                    $created++;
                }

                Notification::make()
                    ->title(trans_choice('One period created.|:count periods created.', $created))
                    ->success()
                    ->send();
            });
    }

    /** @return array<int, string> */
    private static function schoolYears(): array
    {
        $current = self::currentSchoolYear();

        $years = [];

        for ($year = $current - 1; $year <= $current + 2; $year++) {
            $years[$year] = $year.'/'.substr((string) ($year + 1), 2);
        }

        return $years;
    }

    /** A school year starts in September. */
    private static function currentSchoolYear(): int
    {
        $now = CarbonImmutable::now();

        return $now->month >= 9 ? $now->year : $now->year - 1;
    }

    /** @return array<string, string> */
    private static function suggestions(int $schoolYear): array
    {
        $options = [];

        foreach (self::suggestionsByKey($schoolYear) as $period) {
            $options[$period->key()] = $period->label();
        }

        return $options;
    }

    /** @return array<string, SchoolHolidaySuggestion> */
    private static function suggestionsByKey(int $schoolYear): array
    {
        $holidays = [];

        foreach (self::sources() as [$source, $account]) {
            foreach ($source->forSchoolYear($account, self::region(), $schoolYear) as $suggestion) {
                $holidays[$suggestion->key()] ??= $suggestion;
            }
        }

        uasort(
            $holidays,
            static fn (SchoolHolidaySuggestion $a, SchoolHolidaySuggestion $b): int => $a->startsOn <=> $b->startsOn,
        );

        return $holidays;
    }

    /**
     * The sources that can suggest something, each with its account — without
     * an account for the built-in ones, with one for the connected ones.
     *
     * @return array<int, array{0: SchoolHolidaySource, 1: ProviderAccount|null}>
     */
    private static function sources(): array
    {
        $accounts = ProviderAccount::query()->forArea(ProviderArea::SchoolHolidays)->get()->groupBy('provider');
        $region = self::region();
        $sources = [];

        foreach (app(ProviderRegistry::class)->schoolHolidaySources() as $key => $source) {
            foreach ([null, ...($accounts[$key] ?? [])] as $account) {
                if ($source->isAvailable($account, $region)) {
                    $sources[] = [$source, $account];
                }
            }
        }

        return $sources;
    }

    private static function region(): ?string
    {
        return Household::query()->find(app(HouseholdContext::class)->id())?->holiday_region;
    }
}
