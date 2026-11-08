<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Areas\ApiArea;
use App\Auth\ApiTokens;
use App\Models\Calendar;
use App\Models\Checklist;
use App\Models\Event;
use App\Models\Household;
use App\Models\ListItem;
use App\Models\Person;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tagwerk\Mcp\McpArea;
use Tests\TestCase;

/**
 * An assistant reaches the household through the app's own API: what the
 * account may not do, the assistant may not either, and a read-only token
 * offers no writing at all.
 */
final class McpTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private User $parent;

    private Checklist $list;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'Europe/Vienna'));
        $this->household = Household::factory()->create(['timezone' => 'Europe/Vienna']);
        $this->optIn($this->household, ApiArea::KEY, McpArea::KEY);
        $this->parent = $this->account(Person::factory()->for($this->household)->create(['name' => 'Anna']));
        $this->list = Checklist::factory()->create(['household_id' => $this->household->id, 'name' => 'Einkauf']);
    }

    private function account(Person $person): User
    {
        return User::factory()->create(['household_id' => $this->household->id, 'person_id' => $person->id]);
    }

    private function token(bool $write, ?User $user = null): string
    {
        return app(ApiTokens::class)->create($user ?? $this->parent, (int) $this->household->id, 'Assistent', $write);
    }

    /** The calendar every new household has, written without a third party. */
    private function builtinCalendar(): Calendar
    {
        return Calendar::withoutHouseholdScope()->where('household_id', $this->household->id)->firstOrFail();
    }

    /** @param array<string, mixed> $params */
    private function rpc(string $token, string $method, array $params = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{error: bool, data: mixed}
     */
    private function tool(string $token, string $name, array $arguments = []): array
    {
        $result = $this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => $arguments])->assertOk()->json('result');
        $text = $result['content'][0]['text'];

        return ['error' => $result['isError'] ?? false, 'data' => json_decode($text, true) ?? $text];
    }

    /** @return list<string> */
    private function tools(string $token): array
    {
        return array_column($this->rpc($token, 'tools/list')->assertOk()->json('result.tools'), 'name');
    }

    public function test_a_write_token_sees_every_tool(): void
    {
        $this->assertEqualsCanonicalizing([
            'list-events', 'create-event', 'list-tasks', 'complete-task', 'task-statistics',
            'list-lists', 'add-list-item', 'complete-list-item', 'meal-plan',
        ], $this->tools($this->token(write: true)));
    }

    public function test_a_read_token_sees_no_writing_tool(): void
    {
        $this->assertEqualsCanonicalizing(['list-events', 'list-tasks', 'task-statistics', 'list-lists', 'meal-plan'], $this->tools($this->token(write: false)));
    }

    public function test_a_read_token_cannot_call_a_writing_tool(): void
    {
        $this->rpc($this->token(write: false), 'tools/call', ['name' => 'add-list-item', 'arguments' => ['list_id' => $this->list->id, 'title' => 'Milch']])
            ->assertJsonPath('error.code', -32602);

        $this->assertDatabaseMissing('list_items', ['title' => 'Milch']);
    }

    public function test_reading_lists_and_adding_an_item(): void
    {
        $token = $this->token(write: true);

        $added = $this->tool($token, 'add-list-item', ['list_id' => $this->list->id, 'title' => 'Milch', 'quantity' => '2 l']);
        $this->assertFalse($added['error']);

        $lists = $this->tool($token, 'list-lists')['data'];
        $this->assertSame('Einkauf', $lists[0]['name']);
        $this->assertSame(['id' => $added['data']['id'], 'title' => 'Milch', 'quantity' => '2 l', 'done' => false], $lists[0]['items'][0]);

        $this->assertFalse($this->tool($token, 'complete-list-item', ['item_id' => $added['data']['id']])['error']);
        $this->assertNotNull(ListItem::withoutHouseholdScope()->find($added['data']['id'])->done_at);
    }

    public function test_events_come_with_calendars_and_go_in(): void
    {
        $calendar = $this->builtinCalendar();
        $token = $this->token(write: true);

        $created = $this->tool($token, 'create-event', [
            'calendar_id' => $calendar->id,
            'title' => 'Zahnarzt',
            'starts_at' => '2026-10-22T09:00:00+02:00',
            'ends_at' => '2026-10-22T10:00:00+02:00',
        ]);
        $this->assertFalse($created['error']);

        $agenda = $this->tool($token, 'list-events', ['from' => '2026-10-20', 'to' => '2026-10-26'])['data'];
        $this->assertSame('Europe/Vienna', $agenda['timezone']);
        $this->assertSame([['id' => $calendar->id, 'name' => $calendar->summary, 'writable' => true]], $agenda['calendars']);
        $this->assertSame('Zahnarzt', $agenda['events'][0]['title']);
        $this->assertTrue(CarbonImmutable::parse('2026-10-22 07:00', 'UTC')->equalTo(Event::withoutHouseholdScope()->sole()->starts_at));
    }

    public function test_the_api_validates_for_the_assistant(): void
    {
        $calendar = $this->builtinCalendar();

        $result = $this->tool($this->token(write: true), 'create-event', [
            'calendar_id' => $calendar->id,
            'title' => 'Rückwärts',
            'starts_at' => '2026-10-22T10:00:00+02:00',
            'ends_at' => '2026-10-22T09:00:00+02:00',
        ]);

        $this->assertTrue($result['error']);
        $this->assertDatabaseMissing('events', ['title' => 'Rückwärts']);
    }

    public function test_tasks_are_listed_and_ticked_off(): void
    {
        $child = Person::factory()->child()->for($this->household)->create(['name' => 'Lena']);
        $task = Task::factory()->create(['household_id' => $this->household->id, 'title' => 'Zähne putzen', 'person_id' => $child->id, 'due_on' => '2026-10-20', 'rrule' => null, 'points' => 2]);
        $token = $this->token(write: true);

        $occurrence = $this->tool($token, 'list-tasks', ['from' => '2026-10-20', 'to' => '2026-10-20'])['data']['occurrences'][0];
        $this->assertSame(['Zähne putzen', 'Lena', false], [$occurrence['title'], $occurrence['person_name'], $occurrence['done']]);

        $this->assertFalse($this->tool($token, 'complete-task', ['task_id' => $task->id, 'date' => '2026-10-20'])['error']);
        $this->assertDatabaseHas('task_completions', ['task_id' => $task->id, 'person_id' => $child->id]);
    }

    public function test_statistics_name_people_instead_of_ids(): void
    {
        $child = Person::factory()->child()->for($this->household)->create(['name' => 'Lena']);
        $task = Task::factory()->create(['household_id' => $this->household->id, 'title' => 'Müll rausbringen']);

        foreach (['2026-10-19', '2026-10-20'] as $day) {
            TaskCompletion::factory()->create(['household_id' => $this->household->id, 'task_id' => $task->id, 'person_id' => $child->id, 'occurred_on' => $day]);
        }

        $result = $this->tool($this->token(write: false), 'task-statistics', ['from' => '2026-10-01', 'to' => '2026-10-20']);

        $this->assertFalse($result['error']);
        $this->assertSame(['task_id' => $task->id, 'title' => 'Müll rausbringen', 'total' => 2, 'done_by' => ['Lena' => 2]], $result['data']['tasks'][0]);
        $this->assertSame(1, $result['data']['weekdays']['Lena']['Monday']);
        $this->assertContains('Lena', array_column($result['data']['people'], 'name'));
    }

    public function test_a_childs_assistant_gets_no_statistics(): void
    {
        $child = $this->account(Person::factory()->child()->for($this->household)->create());

        $this->assertSame(
            ['error' => true, 'data' => __('This account may not do that.')],
            $this->tool($this->token(write: false, user: $child), 'task-statistics'),
        );
    }

    public function test_the_assistant_can_do_no_more_than_its_account(): void
    {
        $child = $this->account(Person::factory()->child()->for($this->household)->create());
        $calendar = $this->builtinCalendar();

        $result = $this->tool($this->token(write: true, user: $child), 'create-event', [
            'calendar_id' => $calendar->id,
            'title' => 'Familienfest',
            'starts_at' => '2026-10-22T09:00:00+02:00',
            'ends_at' => '2026-10-22T10:00:00+02:00',
        ]);

        $this->assertSame(['error' => true, 'data' => __('This account may not do that.')], $result);
        $this->assertDatabaseMissing('events', ['title' => 'Familienfest']);
    }

    public function test_the_app_token_does_not_open_mcp(): void
    {
        $this->rpc($this->parent->createToken('mobile', ['*'])->plainTextToken, 'tools/list')->assertForbidden();
    }

    public function test_switched_off_mcp_is_gone(): void
    {
        $token = $this->token(write: true);
        $this->household->update(['opted_in_features' => [ApiArea::KEY]]);

        $this->rpc($token, 'tools/list')->assertNotFound();
    }

    public function test_another_households_list_is_not_found(): void
    {
        $other = Checklist::factory()->create(['household_id' => Household::factory()->create()->id]);

        $result = $this->tool($this->token(write: true), 'add-list-item', ['list_id' => $other->id, 'title' => 'Fremd']);

        $this->assertTrue($result['error']);
        $this->assertDatabaseMissing('list_items', ['title' => 'Fremd']);
    }
}
