<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\SendPushNotificationJob;
use App\Models\Household;
use App\Models\Person;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Support\HouseholdContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class DailySummaryTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->household = Household::factory()->create([
            'timezone' => 'Europe/Vienna',
            'summary_at' => '19:30',
        ]);

        app(HouseholdContext::class)->setId((int) $this->household->id);
    }

    private function task(string $title, ?string $completedBy = null): Task
    {
        $task = Task::factory()->create([
            'household_id' => $this->household->id,
            'title' => $title,
            'due_on' => '2026-09-17',
        ]);

        if ($completedBy !== null) {
            TaskCompletion::create([
                'household_id' => $this->household->id,
                'task_id' => $task->id,
                'person_id' => Person::factory()->child()->create([
                    'household_id' => $this->household->id,
                    'name' => $completedBy,
                ])->id,
                'occurred_on' => '2026-09-17',
                'completed_at' => '2026-09-17 16:00:00',
            ]);
        }

        return $task;
    }

    private function inTheEvening(string $time = '19:45'): void
    {
        $this->travelTo(CarbonImmutable::parse("2026-09-17 {$time}", 'Europe/Vienna'));
    }

    public function test_sends_the_summary_after_the_target_time(): void
    {
        $this->task('Müll rausbringen', 'Lena');
        $this->task('Zimmer aufräumen');

        $this->inTheEvening();
        $this->artisan('fahoba:send-daily-summary')->assertSuccessful();

        Queue::assertPushed(SendPushNotificationJob::class);
        $this->assertSame('2026-09-17', $this->household->fresh()->summary_sent_on?->toDateString());
    }

    public function test_nothing_comes_before_the_target_time(): void
    {
        $this->task('Müll rausbringen', 'Lena');

        $this->inTheEvening('18:00');
        $this->artisan('fahoba:send-daily-summary')->assertSuccessful();

        Queue::assertNotPushed(SendPushNotificationJob::class);
        $this->assertNull($this->household->fresh()->summary_sent_on);
    }

    public function test_second_run_does_not_send_again(): void
    {
        $this->task('Müll rausbringen', 'Lena');

        $this->inTheEvening();
        $this->artisan('fahoba:send-daily-summary')->assertSuccessful();
        $this->artisan('fahoba:send-daily-summary')->assertSuccessful();

        Queue::assertPushed(SendPushNotificationJob::class, 1);
    }

    /**
     * A household without tasks gets no notification — but the marker is
     * set, otherwise the command would check it again every fifteen minutes
     * until midnight.
     */
    public function test_no_notification_without_tasks(): void
    {
        $this->inTheEvening();
        $this->artisan('fahoba:send-daily-summary')->assertSuccessful();

        Queue::assertNotPushed(SendPushNotificationJob::class);
        $this->assertSame('2026-09-17', $this->household->fresh()->summary_sent_on?->toDateString());
    }

    /**
     * The target time applies in the household's zone: in Vienna it is
     * already 19:45, in Los Angeles only 10:45 on the same day.
     */
    public function test_target_time_applies_in_the_household_zone(): void
    {
        $distant = Household::factory()->create([
            'timezone' => 'America/Los_Angeles',
            'summary_at' => '19:30',
        ]);

        $this->inTheEvening();
        $this->artisan('fahoba:send-daily-summary')->assertSuccessful();

        $this->assertSame('2026-09-17', $this->household->fresh()->summary_sent_on?->toDateString());
        $this->assertNull($distant->fresh()->summary_sent_on);
    }
}
