<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ProviderArea;
use App\Integrations\ProviderRegistry;
use App\Jobs\PushTaskCompletionJob;
use App\Models\Device;
use App\Models\Household;
use App\Models\ProviderAccount;
use App\Models\Task;
use App\Support\HouseholdContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ticked off on the wall, reported outside.
 *
 * And only where there is a counterpart: an own task has none, a job for it
 * would be wasted work.
 */
final class TaskCompletionPushTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // A past day can no longer be ticked: the clock stands on the first day the tests tick.
        $this->travelTo(CarbonImmutable::parse('2026-09-22 08:00', 'Europe/Vienna'));

        $this->household = Household::factory()->create();
        app(HouseholdContext::class)->setId((int) $this->household->id);

        $device = Device::factory()->create(['household_id' => $this->household->id]);
        $this->token = $device->createToken('wall')->plainTextToken;
    }

    private function externalTask(): Task
    {
        $connection = ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
            'area' => ProviderArea::Tasks,
            'provider' => 'google',
        ]);

        return Task::factory()->create([
            'household_id' => $this->household->id,
            'provider' => 'google',
            'task_account_id' => $connection->id,
            'external_id' => 'abc',
            'due_on' => '2026-09-22',
            'rrule' => null,
        ]);
    }

    public function test_completing_reports_the_completion(): void
    {
        Queue::fake();

        $task = $this->externalTask();

        $this->withToken($this->token)
            ->postJson("/api/wall/tasks/{$task->id}/completion", ['date' => '2026-09-22'])
            ->assertCreated();

        Queue::assertPushed(PushTaskCompletionJob::class);
    }

    public function test_undoing_reports_too(): void
    {
        Queue::fake();

        $task = $this->externalTask();

        $this->withToken($this->token)
            ->deleteJson("/api/wall/tasks/{$task->id}/completion", ['date' => '2026-09-22'])
            ->assertOk();

        Queue::assertPushed(PushTaskCompletionJob::class);
    }

    public function test_an_own_task_reports_nothing(): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        $task = Task::factory()->create([
            'household_id' => $this->household->id,
            'due_on' => '2026-09-22',
            'rrule' => null,
        ]);

        $this->withToken($this->token)
            ->postJson("/api/wall/tasks/{$task->id}/completion", ['date' => '2026-09-22'])
            ->assertCreated();

        Queue::assertNotPushed(PushTaskCompletionJob::class);
    }

    /**
     * If the external service fails, the completion still stands: it is
     * recorded in Fahoba, and that is the authoritative answer.
     */
    public function test_an_outage_outside_only_costs_the_report(): void
    {
        Http::preventStrayRequests();
        Http::fake(['tasks.googleapis.com/*' => Http::response(status: 500)]);

        $task = $this->externalTask();

        (new PushTaskCompletionJob((int) $task->id, true))->handle(
            app(ProviderRegistry::class),
        );

        $this->assertTrue(true, 'The job does not pass the error on.');
    }
}
