<?php

declare(strict_types=1);

namespace Tests\Feature\SchoolHolidays;

use App\Integrations\SchoolHolidays\Austria\AustrianSchoolHolidays;
use Fahoba\PluginApi\SchoolHolidays\SchoolHolidaySuggestion;
use Tests\TestCase;

/**
 * The suggestions for the 2026/27 school year.
 *
 * Easter Sunday 2027 is 28 March, Whit Sunday 16 May.
 */
final class AustrianSchoolHolidaysTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> title => from/to */
    private function holidays(?string $region, int $year = 2026): array
    {
        $suggestions = app(AustrianSchoolHolidays::class)->forSchoolYear(null, $region, $year);

        $byTitle = [];

        foreach ($suggestions as $suggestion) {
            $byTitle[$suggestion->title] = [
                $suggestion->startsOn->toDateString(),
                $suggestion->endsOn->toDateString(),
            ];
        }

        return $byTitle;
    }

    public function test_autumn_and_christmas_are_the_same_nationwide(): void
    {
        $holidays = $this->holidays('AT-4');

        $this->assertSame(['2026-10-27', '2026-10-31'], $holidays['Herbstferien']);
        $this->assertSame(['2026-12-24', '2027-01-06'], $holidays['Weihnachtsferien']);
    }

    public function test_easter_and_whitsun_depend_on_the_easter_date(): void
    {
        $holidays = $this->holidays('AT-4');

        // Easter Sunday 2027: 28 March. The Saturday before is 20 March.
        $this->assertSame(['2027-03-20', '2027-03-29'], $holidays['Osterferien']);

        // Whit Sunday 2027: 16 May. The Saturday before is 15 May.
        $this->assertSame(['2027-05-15', '2027-05-18'], $holidays['Pfingstferien']);
    }

    /**
     * Vienna and Lower Austria break in the first week of February, most
     * states in the second, Upper Austria and Styria in the third.
     */
    public function test_semester_break_differs_per_state(): void
    {
        // 1 February 2027 is a Monday.
        $this->assertSame(['2027-02-01', '2027-02-06'], $this->holidays('AT-9')['Semesterferien']);
        $this->assertSame(['2027-02-08', '2027-02-13'], $this->holidays('AT-2')['Semesterferien']);
        $this->assertSame(['2027-02-15', '2027-02-20'], $this->holidays('AT-4')['Semesterferien']);
    }

    public function test_the_west_starts_summer_holidays_a_week_later(): void
    {
        // The first Saturday in July 2027 is 3 July.
        $this->assertSame('2027-07-03', $this->holidays('AT-9')['Sommerferien'][0]);
        $this->assertSame('2027-07-10', $this->holidays('AT-4')['Sommerferien'][0]);

        // School starts in the east on the first Monday in September, i.e. 6 September.
        $this->assertSame('2027-09-05', $this->holidays('AT-9')['Sommerferien'][1]);
        $this->assertSame('2027-09-12', $this->holidays('AT-4')['Sommerferien'][1]);
    }

    /**
     * Without a state there is no basis for semester and summer holidays. A
     * guessed period would be worse than none.
     */
    public function test_without_state_semester_and_summer_are_left_out(): void
    {
        $holidays = $this->holidays('AT');

        $this->assertArrayHasKey('Herbstferien', $holidays);
        $this->assertArrayNotHasKey('Semesterferien', $holidays);
        $this->assertArrayNotHasKey('Sommerferien', $holidays);
    }

    public function test_there_are_no_suggestions_for_germany(): void
    {
        $this->assertSame([], $this->holidays('DE-BY'));
        $this->assertSame([], $this->holidays(null));
    }

    public function test_suggestions_are_sorted_by_date(): void
    {
        $suggestions = app(AustrianSchoolHolidays::class)->forSchoolYear(null, 'AT-4', 2026);

        $dates = array_map(
            static fn (SchoolHolidaySuggestion $suggestion): string => $suggestion->startsOn->toDateString(),
            $suggestions,
        );

        $sorted = $dates;
        sort($sorted);

        $this->assertSame($sorted, $dates);
        $this->assertSame('Herbstferien', $suggestions[0]->title);
    }
}
