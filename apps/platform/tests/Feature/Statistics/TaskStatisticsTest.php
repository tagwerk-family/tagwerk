<?php

declare(strict_types=1);

namespace Tests\Feature\Statistics;

use App\Models\Household;
use App\Models\Person;
use App\Models\PointEntry;
use App\Models\RewardRedemption;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Services\Statistics\StatisticsPeriod;
use App\Services\Statistics\TaskStatistics;
use App\Support\HouseholdContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TaskStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Person $max;

    private Person $lena;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-16 18:00', 'Europe/Vienna'));

        $this->household = Household::factory()->create(['timezone' => 'Europe/Vienna']);
        app(HouseholdContext::class)->setId((int) $this->household->id);

        $this->max = Person::factory()->child()->create(['household_id' => $this->household->id, 'name' => 'Max', 'birthday' => '2016-01-01']);
        $this->lena = Person::factory()->child()->create(['household_id' => $this->household->id, 'name' => 'Lena', 'birthday' => '2018-01-01']);
    }

    private function statistics(): TaskStatistics
    {
        return app(TaskStatistics::class);
    }

    private function period(string $from = '2026-09-10', string $until = '2026-09-16', array $people = [], ?int $task = null): StatisticsPeriod
    {
        return new StatisticsPeriod(CarbonImmutable::parse($from), CarbonImmutable::parse($until), $people, $task);
    }

    private function task(string $title, array $attributes = []): Task
    {
        return Task::factory()->create(['household_id' => $this->household->id, 'title' => $title, ...$attributes]);
    }

    private function done(Task $task, ?Person $by, string $on, array $attributes = []): TaskCompletion
    {
        return TaskCompletion::factory()->create([
            'household_id' => $this->household->id,
            'task_id' => $task->id,
            'person_id' => $by?->id,
            'occurred_on' => $on,
            'completed_at' => CarbonImmutable::parse($on.' 17:00', 'Europe/Vienna'),
            ...$attributes,
        ]);
    }

    public function test_counts_who_did_which_task_most_done_first(): void
    {
        $dishes = $this->task('Geschirrspüler');
        $table = $this->task('Tisch decken');

        $this->done($table, $this->max, '2026-09-14');
        $this->done($dishes, $this->max, '2026-09-14');
        $this->done($dishes, $this->lena, '2026-09-15');
        $this->done($dishes, $this->lena, '2026-09-16');
        $this->done($dishes, null, '2026-09-13');

        $matrix = $this->statistics()->matrix($this->period());

        $this->assertSame(['Geschirrspüler', 'Tisch decken'], array_column($matrix, 'title'));
        $this->assertSame([$this->max->id => 1, $this->lena->id => 2, 'none' => 1], $matrix[0]['counts']);
        $this->assertSame(4, $matrix[0]['total']);
    }

    public function test_leaves_out_skipped_days_other_periods_and_other_households(): void
    {
        $dishes = $this->task('Geschirrspüler');
        $this->done($dishes, $this->max, '2026-09-14');
        $this->done($dishes, $this->max, '2026-09-15', ['skipped' => true]);
        $this->done($dishes, $this->max, '2026-09-01');

        $other = Household::factory()->create();
        app(HouseholdContext::class)->setId((int) $other->id);
        $foreign = Task::factory()->create(['household_id' => $other->id]);
        TaskCompletion::factory()->create(['household_id' => $other->id, 'task_id' => $foreign->id, 'occurred_on' => '2026-09-14']);
        app(HouseholdContext::class)->setId((int) $this->household->id);

        $matrix = $this->statistics()->matrix($this->period());

        $this->assertCount(1, $matrix);
        $this->assertSame(1, $matrix[0]['total']);
    }

    public function test_a_retired_task_keeps_its_history(): void
    {
        $dishes = $this->task('Geschirrspüler');
        $this->done($dishes, $this->max, '2026-09-14');
        $dishes->retire();

        $this->assertSame('Geschirrspüler', $this->statistics()->matrix($this->period())[0]['title']);
    }

    public function test_narrows_to_people_and_one_task(): void
    {
        $dishes = $this->task('Geschirrspüler');
        $table = $this->task('Tisch decken');
        $this->done($dishes, $this->max, '2026-09-14');
        $this->done($dishes, $this->lena, '2026-09-15');
        $this->done($table, $this->lena, '2026-09-14');

        $matrix = $this->statistics()->matrix($this->period(people: [$this->lena->id], task: $dishes->id));

        $this->assertSame([['task_id' => $dishes->id, 'title' => 'Geschirrspüler', 'icon' => null, 'counts' => [$this->lena->id => 1], 'total' => 1]], $matrix);
    }

    public function test_the_timeline_has_every_day_of_a_short_period(): void
    {
        $dishes = $this->task('Geschirrspüler');
        $this->done($dishes, $this->max, '2026-09-10');
        $this->done($dishes, $this->max, '2026-09-16');
        $this->done($dishes, $this->lena, '2026-09-15');

        $timeline = $this->statistics()->timeline($this->period());

        $this->assertCount(7, $timeline['labels']);
        $this->assertSame([1, 0, 0, 0, 0, 0, 1], $timeline['series'][$this->max->id]);
        $this->assertSame([0, 0, 0, 0, 0, 1, 0], $timeline['series'][$this->lena->id]);
    }

    public function test_a_long_period_counts_by_month(): void
    {
        $dishes = $this->task('Geschirrspüler');
        $this->done($dishes, $this->max, '2026-07-03');
        $this->done($dishes, $this->max, '2026-07-30');
        $this->done($dishes, $this->max, '2026-09-16');

        $period = $this->period('2025-09-17', '2026-09-16');
        $timeline = $this->statistics()->timeline($period);

        $this->assertSame('month', $period->bucket());
        $this->assertCount(13, $timeline['labels']);
        $this->assertSame(2, $timeline['series'][$this->max->id][10]);
        $this->assertSame(1, $timeline['series'][$this->max->id][12]);
    }

    public function test_counts_weekdays_monday_first(): void
    {
        $dishes = $this->task('Geschirrspüler');
        $this->done($dishes, $this->max, '2026-09-14'); // Monday
        $this->done($dishes, $this->max, '2026-09-13'); // Sunday

        $this->assertSame([1, 0, 0, 0, 0, 0, 1], $this->statistics()->weekdays($this->period())[$this->max->id]);
    }

    public function test_reliability_is_own_occurrences_up_to_today(): void
    {
        $dishes = $this->task('Geschirrspüler', ['person_id' => $this->max->id, 'rrule' => 'FREQ=DAILY', 'due_on' => '2026-09-01']);
        $this->task('Für alle', ['rrule' => 'FREQ=DAILY', 'due_on' => '2026-09-01']);

        foreach (['2026-09-10', '2026-09-11', '2026-09-12', '2026-09-13', '2026-09-14'] as $day) {
            $this->done($dishes, $this->max, $day);
        }

        // The period reaches past today; tomorrow is not due yet.
        $reliability = $this->statistics()->reliability($this->period(until: '2026-09-20'), CarbonImmutable::now('Europe/Vienna'));

        $this->assertSame([$this->max->id => ['due' => 7, 'done' => 5]], $reliability);
    }

    public function test_a_skipped_day_was_not_due(): void
    {
        $dishes = $this->task('Geschirrspüler', ['person_id' => $this->max->id, 'rrule' => 'FREQ=DAILY', 'due_on' => '2026-09-01']);
        $this->done($dishes, $this->max, '2026-09-16', ['skipped' => true]);

        $reliability = $this->statistics()->reliability($this->period('2026-09-15', '2026-09-16'), CarbonImmutable::now('Europe/Vienna'));

        $this->assertSame([$this->max->id => ['due' => 1, 'done' => 0]], $reliability);
    }

    public function test_stars_earned_and_spent_without_gifts(): void
    {
        $entry = fn (Person $person, int $points, array $attributes = []) => PointEntry::query()->forceCreate([
            'household_id' => $this->household->id, 'person_id' => $person->id, 'points' => $points, 'reason' => 'Test', ...$attributes,
        ]);

        $entry($this->max, 10);
        $entry($this->max, 3);
        $redemption = RewardRedemption::factory()->create(['household_id' => $this->household->id, 'person_id' => $this->max->id, 'cost' => 5]);
        $entry($this->max, -5, ['reward_redemption_id' => $redemption->id]);
        $gift = '0195b2a4-6c1e-7000-8000-000000000001';
        $entry($this->max, -4, ['gift_id' => $gift]);
        $entry($this->lena, 4, ['gift_id' => $gift]);

        // Before the period in Vienna, although the same UTC date as its first day.
        $entry($this->max, 50, ['created_at' => CarbonImmutable::parse('2026-09-09 23:30', 'Europe/Vienna')->utc()->format('Y-m-d H:i:s')]);

        $stars = $this->statistics()->stars($this->period(), 'Europe/Vienna');

        $this->assertSame([$this->max->id => ['earned' => 13, 'spent' => 5]], $stars);
    }

    public function test_the_period_comes_from_the_filters(): void
    {
        $today = CarbonImmutable::parse('2026-09-16');

        $default = StatisticsPeriod::fromFilters(null, $today);
        $this->assertSame(['2026-08-20', '2026-09-16'], [$default->from->toDateString(), $default->until->toDateString()]);

        $year = StatisticsPeriod::fromFilters(['period' => 'year'], $today);
        $this->assertSame('2026-01-01', $year->from->toDateString());

        $custom = StatisticsPeriod::fromFilters(['period' => 'custom', 'from' => '2026-09-30', 'until' => '2026-09-01', 'people' => ['3', 'x'], 'task' => '7'], $today);
        $this->assertSame(['2026-09-01', '2026-09-30'], [$custom->from->toDateString(), $custom->until->toDateString()]);
        $this->assertSame([3], $custom->personIds);
        $this->assertSame(7, $custom->taskId);

        $broken = StatisticsPeriod::fromFilters(['period' => 'custom', 'from' => 'gestern'], $today);
        $this->assertSame('2026-08-20', $broken->from->toDateString());
    }
}
