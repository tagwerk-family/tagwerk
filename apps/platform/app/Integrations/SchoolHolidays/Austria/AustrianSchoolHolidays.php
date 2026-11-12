<?php

declare(strict_types=1);

namespace App\Integrations\SchoolHolidays\Austria;

use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Fahoba\PluginApi\SchoolHolidays\SchoolHolidaySource;
use Fahoba\PluginApi\SchoolHolidays\SchoolHolidaySuggestion;
use Yasumi\Yasumi;

/**
 * The usual Austrian school holidays of a school year.
 *
 * No third-party service and no network access: Austrian school holidays are
 * largely a set of rules. Autumn and Christmas fall on fixed dates, Easter and
 * Whitsun follow the Easter date, semester and summer holidays depend on the
 * federal state.
 *
 * This class writes nothing. It fills a checklist that someone looks at,
 * corrects and saves — which is why it is bearable if a rule stops being
 * right in a few years. It cannot know school-specific days off anyway; each
 * school sets those itself.
 */
final class AustrianSchoolHolidays implements SchoolHolidaySource
{
    /** Semester holidays: which February week the federal state gets. */
    private const SEMESTER_WEEK = [
        'AT-9' => 1, // Vienna
        'AT-3' => 1, // Lower Austria
        'AT-1' => 2, // Burgenland
        'AT-2' => 2, // Carinthia
        'AT-5' => 2, // Salzburg
        'AT-7' => 2, // Tyrol
        'AT-8' => 2, // Vorarlberg
        'AT-4' => 3, // Upper Austria
        'AT-6' => 3, // Styria
    ];

    /** These federal states start their summer holidays earlier. */
    private const EARLY_SUMMER = ['AT-1', 'AT-3', 'AT-9'];

    public function key(): string
    {
        return 'austria';
    }

    public function label(): string
    {
        return __('Austrian school holidays');
    }

    public function isAvailable(?ConnectedAccount $account, ?string $region): bool
    {
        return is_string($region) && str_starts_with($region, 'AT');
    }

    /**
     * The holidays of the school year that starts in the given year.
     *
     * Without a federal state (plain "AT") semester and summer holidays are
     * left out: both depend on the state, and a guessed period would be worse
     * than none.
     *
     * @return array<int, SchoolHolidaySuggestion>
     */
    public function forSchoolYear(?ConnectedAccount $account, ?string $region, int $startYear): array
    {
        if (! $this->isAvailable($account, $region)) {
            return [];
        }

        $easter = $this->easterSunday($startYear + 1);

        $holidays = [
            // Nationwide and on fixed days since 2020.
            new SchoolHolidaySuggestion(
                __('Autumn holidays'),
                CarbonImmutable::create($startYear, 10, 27),
                CarbonImmutable::create($startYear, 10, 31),
            ),
            new SchoolHolidaySuggestion(
                __('Christmas holidays'),
                CarbonImmutable::create($startYear, 12, 24),
                CarbonImmutable::create($startYear + 1, 1, 6),
            ),
            new SchoolHolidaySuggestion(
                __('Easter holidays'),
                // Saturday before Palm Sunday through Easter Monday.
                $easter->subDays(8),
                $easter->addDay(),
            ),
            new SchoolHolidaySuggestion(
                __('Whitsun holidays'),
                // Saturday before Whit Sunday through Whit Tuesday.
                $easter->addDays(48),
                $easter->addDays(51),
            ),
        ];

        $semesterWeek = self::SEMESTER_WEEK[$region] ?? null;

        if ($semesterWeek !== null) {
            $monday = $this->mondayInFebruary($startYear + 1, $semesterWeek);

            $holidays[] = new SchoolHolidaySuggestion(
                __('Semester holidays'),
                $monday,
                $monday->addDays(5),
            );

            $holidays[] = $this->summerHolidays($region, $startYear + 1);
        }

        usort(
            $holidays,
            static fn (SchoolHolidaySuggestion $a, SchoolHolidaySuggestion $b): int => $a->startsOn <=> $b->startsOn,
        );

        return $holidays;
    }

    /**
     * Starts on the first Saturday in July, a week later in the west; ends on
     * the Sunday before school starts (first or second Monday in September).
     */
    private function summerHolidays(string $region, int $year): SchoolHolidaySuggestion
    {
        $early = in_array($region, self::EARLY_SUMMER, true);

        $start = CarbonImmutable::create($year, 7, 1)->startOfDay();
        $start = $start->isSaturday() ? $start : $start->next(CarbonImmutable::SATURDAY);

        $schoolStart = CarbonImmutable::create($year, 9, 1)->startOfDay();
        $schoolStart = $schoolStart->isMonday() ? $schoolStart : $schoolStart->next(CarbonImmutable::MONDAY);

        if (! $early) {
            $start = $start->addWeek();
            $schoolStart = $schoolStart->addWeek();
        }

        return new SchoolHolidaySuggestion(__('Summer holidays'), $start, $schoolStart->subDay());
    }

    private function mondayInFebruary(int $year, int $week): CarbonImmutable
    {
        $first = CarbonImmutable::create($year, 2, 1)->startOfDay();
        $monday = $first->isMonday() ? $first : $first->next(CarbonImmutable::MONDAY);

        return $monday->addWeeks($week - 1);
    }

    /**
     * The Easter date comes from Yasumi — the same library that already
     * provides the public holidays. Our own Easter computation would be a
     * second source for the same number.
     */
    private function easterSunday(int $year): CarbonImmutable
    {
        $easter = Yasumi::create('Austria', $year, 'de_AT')->getHoliday('easter');

        return CarbonImmutable::parse($easter->format('Y-m-d'));
    }
}
