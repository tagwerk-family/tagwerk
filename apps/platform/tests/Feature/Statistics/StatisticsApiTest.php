<?php

declare(strict_types=1);

namespace Tests\Feature\Statistics;

use App\Enums\Feature;
use App\Enums\PersonRole;
use App\Models\Household;
use App\Models\Person;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Services\DevicePairingService;
use App\Support\HouseholdContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/** The statistics for the app, the assistant and API tokens: parents only. */
final class StatisticsApiTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Person $parent;

    private Person $child;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-16 18:00', 'Europe/Vienna'));

        $this->household = Household::factory()->create(['timezone' => 'Europe/Vienna']);
        app(HouseholdContext::class)->setId($this->household->id);

        $this->parent = Person::factory()->for($this->household)->create(['name' => 'Alex', 'role' => PersonRole::Adult]);
        $this->child = Person::factory()->child()->for($this->household)->create(['name' => 'Ben', 'color' => '#2563eb']);

        $task = Task::factory()->for($this->household)->create(['title' => 'Zähne putzen', 'icon' => '🪥']);

        foreach (['2026-09-14', '2026-09-15'] as $day) {
            TaskCompletion::factory()->create([
                'household_id' => $this->household->id,
                'task_id' => $task->id,
                'person_id' => $this->child->id,
                'occurred_on' => $day,
            ]);
        }

        TaskCompletion::factory()->create([
            'household_id' => $this->household->id,
            'task_id' => $task->id,
            'person_id' => null,
            'occurred_on' => '2026-09-16',
        ]);
    }

    private function phoneOf(Person $person): string
    {
        return User::factory()->create([
            'household_id' => $this->household->id,
            'person_id' => $person->id,
        ])->createToken('phone')->plainTextToken;
    }

    public function test_a_parent_reads_the_whole_picture(): void
    {
        $response = $this->withToken($this->phoneOf($this->parent))
            ->getJson('/api/statistics')
            ->assertOk()
            ->assertJsonPath('period.from', '2026-08-20')
            ->assertJsonPath('period.until', '2026-09-16')
            ->assertJsonPath('period.bucket', 'day')
            ->assertJsonPath('totals.done', 3)
            ->assertJsonPath('tasks.0.title', 'Zähne putzen')
            ->assertJsonPath('tasks.0.total', 3)
            ->assertJsonPath('tasks.0.counts.'.$this->child->id, 2)
            ->assertJsonPath('tasks.0.counts.none', 1);

        $this->assertCount(28, $response->json('timeline.labels'));
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 0], $response->json('timeline.series.'.$this->child->id));
        $this->assertSame([1, 1, 0, 0, 0, 0, 0], $response->json('weekdays.'.$this->child->id));
        $this->assertSame(['Ben', 'Alex'], array_column($response->json('people'), 'name'));
    }

    public function test_maps_stay_objects_when_empty(): void
    {
        $this->withToken($this->phoneOf($this->parent))
            ->getJson('/api/statistics?period=custom&from=2025-01-01&until=2025-01-31')
            ->assertOk()
            ->assertJsonPath('totals.done', 0)
            ->assertSee('"reliability":{}', false)
            ->assertSee('"stars":{}', false);
    }

    public function test_filters_narrow_it(): void
    {
        $this->withToken($this->phoneOf($this->parent))
            ->getJson('/api/statistics?period=custom&from=2026-09-15&until=2026-09-16&people[]='.$this->child->id)
            ->assertOk()
            ->assertJsonPath('totals.done', 1)
            ->assertJsonCount(1, 'people');
    }

    public function test_bad_filters_are_refused(): void
    {
        $token = $this->phoneOf($this->parent);

        $this->withToken($token)->getJson('/api/statistics?period=week')->assertUnprocessable();
        $this->withToken($token)->getJson('/api/statistics?period=custom&from=2026-01-01')->assertUnprocessable();
        $this->withToken($token)->getJson('/api/statistics?period=custom&from=2020-01-01&until=2026-01-01')->assertUnprocessable();
    }

    public function test_a_child_may_not(): void
    {
        $this->withToken($this->phoneOf($this->child))->getJson('/api/statistics')->assertForbidden();
    }

    public function test_the_wall_asks_for_the_pin(): void
    {
        $this->parentWithPin($this->household);
        Auth::forgetGuards();

        $pairing = app(DevicePairingService::class);
        $token = $pairing->redeem($pairing->createCode($this->household, 'Küche')->pairing_code)['token'];

        $this->withToken($token)->getJson('/api/statistics')->assertStatus(423);
    }

    public function test_gone_with_the_tasks_area(): void
    {
        $this->household->forceFill(['disabled_features' => [Feature::Tasks->value, Feature::Points->value]])->save();

        $this->withToken($this->phoneOf($this->parent))->getJson('/api/statistics')->assertNotFound();
    }
}
