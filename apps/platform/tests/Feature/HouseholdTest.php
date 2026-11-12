<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Household;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HouseholdTest extends TestCase
{
    use RefreshDatabase;

    public function test_household_stores_time_zone_and_holiday_region(): void
    {
        $household = Household::create([
            'name' => 'Familie Muster',
            'timezone' => 'Europe/Vienna',
            'holiday_region' => 'AT-4',
        ]);

        $this->assertSame('Europe/Vienna', $household->fresh()->timezone);
        $this->assertSame('AT-4', $household->fresh()->holiday_region);
    }

    public function test_tests_run_against_postgresql(): void
    {
        $this->assertSame('pgsql', config('database.default'));

        /*
         * Prefix instead of the exact name: a worktree next to the main tree
         * needs its own test database, otherwise two runs migrate the same
         * one at once. The purpose stays — it has to be a test database,
         * never SQLite and never the development database.
         */
        $this->assertStringStartsWith(
            'fahoba_test',
            Household::query()->getConnection()->getDatabaseName(),
        );
    }
}
