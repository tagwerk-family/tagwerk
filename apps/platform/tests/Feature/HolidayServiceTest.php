<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Household;
use App\Services\Holidays\HolidayService;
use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Holidays\HolidayEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class HolidayServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Otherwise one run carries its cached results into the next.
        Cache::flush();
    }

    private function household(?string $region): Household
    {
        return Household::factory()->create(['holiday_region' => $region]);
    }

    /** @return array<int, string> */
    private function names(Household $household, string $from, string $to): array
    {
        return app(HolidayService::class)
            ->between($household, CarbonImmutable::parse($from), CarbonImmutable::parse($to))
            ->map(fn (HolidayEntry $entry): string => $entry->name)
            ->all();
    }

    public function test_upper_austria_has_its_state_holiday(): void
    {
        $names = $this->names($this->household('AT-4'), '2026-05-01', '2026-05-31');

        // Florian is the Upper Austrian state holiday — it proves that the
        // state and not just the country is evaluated.
        $this->assertContains('Florian', $names);
        $this->assertContains('Staatsfeiertag', $names);
    }

    public function test_vienna_does_not_know_florian(): void
    {
        $this->assertNotContains('Florian', $this->names($this->household('AT-9'), '2026-05-01', '2026-05-31'));
    }

    public function test_range_across_the_turn_of_the_year(): void
    {
        $names = $this->names($this->household('AT-4'), '2026-12-20', '2027-01-10');

        $this->assertContains('Christtag', $names);
        $this->assertContains('Neujahr', $names);
    }

    public function test_holidays_outside_the_range_are_missing(): void
    {
        $names = $this->names($this->household('AT-4'), '2026-07-01', '2026-07-31');

        $this->assertSame([], $names);
    }

    public function test_unknown_region_returns_empty_list(): void
    {
        // A view without holidays is better than no view at all.
        $this->assertSame([], $this->names($this->household('XX-99'), '2026-01-01', '2026-12-31'));
    }

    public function test_household_with_empty_region_returns_empty_list(): void
    {
        // The column is not nullable and has a default; it only becomes empty
        // when someone clears the field in the admin.
        $this->assertSame([], $this->names($this->household(''), '2026-01-01', '2026-12-31'));
    }

    public function test_results_are_sorted_ascending(): void
    {
        $holidays = app(HolidayService::class)->between(
            $this->household('AT-4'),
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-12-31'),
        );

        $dates = $holidays->map(fn (HolidayEntry $e): string => $e->date->toDateString())->all();
        $sorted = $dates;
        sort($sorted);

        $this->assertSame($sorted, $dates);
        $this->assertGreaterThan(10, count($dates));
    }
}
