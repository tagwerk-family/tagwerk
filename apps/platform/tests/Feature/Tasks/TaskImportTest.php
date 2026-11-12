<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ProviderArea;
use App\Models\Household;
use App\Models\Person;
use App\Models\ProviderAccount;
use App\Models\Task;
use App\Services\Tasks\TaskImportService;
use App\Support\HouseholdContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Imported and enriched.
 *
 * Dad's task comes from outside; the household layers points, assignee and
 * rotation on top. The core of these tests: a sync must never overwrite this
 * enrichment — otherwise it would be gone after every round.
 */
final class TaskImportTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private ProviderAccount $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        app(HouseholdContext::class)->setId((int) $this->household->id);

        config([
            'services.google.client_id' => 'client.apps.googleusercontent.com',
            'services.google.client_secret' => 'secret',
        ]);

        $this->connection = ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
            'area' => ProviderArea::Tasks,
            'provider' => 'google',
            'access_token' => 'ya29.valid',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    /**
     * One response per sync, in order.
     *
     * As a sequence and not as a second Http::fake: Laravel collects the
     * rules instead of replacing them — the first would win every time.
     *
     * @param  array<int, array<string, mixed>>  ...$rounds
     */
    private function respond(array ...$rounds): void
    {
        Http::preventStrayRequests();

        $sequence = Http::fakeSequence('tasks.googleapis.com/*');

        foreach ($rounds as $items) {
            $sequence->push(['items' => $items]);
        }

        // After that nothing changes anymore: another sync finds the same
        // state.
        $sequence->whenEmpty(Http::response(['items' => end($rounds) ?: []]));
    }

    public function test_an_external_task_becomes_a_row_with_origin(): void
    {
        $this->respond([
            ['id' => 'abc', 'title' => 'Müll rausbringen', 'due' => '2026-09-22T00:00:00.000Z', 'updated' => 'v1'],
        ]);

        $this->assertSame(1, app(TaskImportService::class)->import($this->connection));

        $task = Task::query()->firstOrFail();

        $this->assertSame('Müll rausbringen', $task->title);
        $this->assertSame('google', $task->provider);
        $this->assertSame('abc', $task->external_id);
        $this->assertSame((int) $this->connection->id, (int) $task->task_account_id);
    }

    public function test_the_enrichment_survives_a_sync(): void
    {
        $this->respond(
            [['id' => 'abc', 'title' => 'Müll rausbringen', 'updated' => 'v1']],
            [['id' => 'abc', 'title' => 'Restmüll rausbringen', 'updated' => 'v2']],
        );

        app(TaskImportService::class)->import($this->connection);

        $person = Person::factory()->create(['household_id' => $this->household->id]);

        // What only Fahoba knows.
        Task::query()->firstOrFail()->update([
            'points' => 5,
            'person_id' => $person->id,
            'rrule' => 'FREQ=WEEKLY;BYDAY=MO',
        ]);

        // The title was changed outside — a real second sync.
        app(TaskImportService::class)->import($this->connection);

        $task = Task::query()->firstOrFail();

        $this->assertSame('Restmüll rausbringen', $task->title);
        $this->assertSame(5, $task->points);
        $this->assertSame((int) $person->id, (int) $task->person_id);
        $this->assertSame('FREQ=WEEKLY;BYDAY=MO', $task->rrule);
    }

    public function test_fetching_twice_does_not_create_twice(): void
    {
        $this->respond([['id' => 'abc', 'title' => 'Müll rausbringen', 'updated' => 'v1']]);

        app(TaskImportService::class)->import($this->connection);
        app(TaskImportService::class)->import($this->connection);

        $this->assertSame(1, Task::query()->count());
    }

    public function test_a_deleted_task_stays_deleted(): void
    {
        $this->respond([['id' => 'abc', 'title' => 'Müll rausbringen', 'updated' => 'v1']]);

        app(TaskImportService::class)->import($this->connection);
        Task::query()->firstOrFail()->forceFill(['external_version' => 'v0'])->delete();

        $this->assertSame(0, app(TaskImportService::class)->import($this->connection));
        $this->assertSame(0, Task::query()->count());
        $this->assertSame(1, Task::onlyTrashed()->count());
    }

    /** Without a new version there is nothing to write. */
    public function test_unchanged_task_is_not_touched(): void
    {
        $this->respond([['id' => 'abc', 'title' => 'Müll rausbringen', 'updated' => 'v1']]);

        app(TaskImportService::class)->import($this->connection);

        $this->assertSame(0, app(TaskImportService::class)->import($this->connection));
    }

    public function test_own_tasks_stay_builtin(): void
    {
        Http::preventStrayRequests();

        $task = Task::factory()->create(['household_id' => $this->household->id]);

        $this->assertSame('builtin', $task->provider);
        $this->assertNull($task->external_id);
    }

    public function test_a_task_without_title_is_not_imported(): void
    {
        $this->respond([
            ['id' => 'empty', 'title' => '  ', 'updated' => 'v1'],
            ['id' => 'abc', 'title' => 'Müll rausbringen', 'updated' => 'v1'],
        ]);

        app(TaskImportService::class)->import($this->connection);

        $this->assertSame(1, Task::query()->count());
    }

    public function test_completed_outside_counts_here_too(): void
    {
        $this->respond([[
            'id' => 'abc',
            'title' => 'Müll rausbringen',
            'due' => '2026-09-22T00:00:00.000Z',
            'completed' => '2026-09-22T18:30:00.000Z',
            'updated' => 'v1',
        ]]);

        app(TaskImportService::class)->import($this->connection);

        $task = Task::query()->firstOrFail();

        $this->assertSame(1, $task->completions()->count());

        /*
         * But without points: the external service does not know whom to
         * credit, and guessing would be worse than booking nothing.
         */
        $this->assertNull($task->completions()->first()->person_id);
    }
}
