<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Checklist;
use App\Models\Event;
use App\Models\Household;
use App\Models\ListItem;
use App\Models\Person;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Events\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as Events;
use Tests\TestCase;

/**
 * What plugins hook into: fired from the models, so every write path — wall,
 * phone, admin, sync — tells them the same.
 */
final class DomainEventsTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        app(HouseholdContext::class)->setId((int) $this->household->id);
        Events::fake([DomainEvent::class]);
    }

    /** @return list<string> */
    private function fired(): array
    {
        return Events::dispatched(DomainEvent::class)
            ->map(fn (array $call): string => $call[0]->name)
            ->values()
            ->all();
    }

    public function test_an_event_reports_saving_and_deleting(): void
    {
        $event = Event::factory()->create(['household_id' => $this->household->id, 'title' => 'Zahnarzt']);
        $event->update(['title' => 'Kieferorthopäde']);
        $event->delete();

        $this->assertSame([DomainEvent::EVENT_SAVED, DomainEvent::EVENT_SAVED, DomainEvent::EVENT_DELETED], $this->fired());

        Events::assertDispatched(DomainEvent::class, fn (DomainEvent $fired): bool => $fired->householdId === $this->household->id
            && $fired->payload['title'] === 'Kieferorthopäde'
            && is_string($fired->payload['starts_at']));
    }

    public function test_a_save_that_changes_nothing_visible_stays_quiet(): void
    {
        $event = Event::factory()->create(['household_id' => $this->household->id]);
        $event->update(['external_version' => 'etag-2']);
        $event->save();

        $this->assertSame([DomainEvent::EVENT_SAVED], $this->fired());
    }

    public function test_ticking_and_taking_back_a_task(): void
    {
        $task = Task::factory()->create(['household_id' => $this->household->id, 'title' => 'Tisch decken']);
        $completion = TaskCompletion::factory()->create(['household_id' => $this->household->id, 'task_id' => $task->id]);
        $completion->delete();

        $this->assertSame([DomainEvent::TASK_COMPLETED, DomainEvent::TASK_REOPENED], $this->fired());
        Events::assertDispatched(DomainEvent::class, fn (DomainEvent $fired): bool => $fired->payload['title'] === 'Tisch decken');
    }

    public function test_list_items_report_adding_and_ticking_once(): void
    {
        $list = Checklist::factory()->create(['household_id' => $this->household->id]);
        $item = ListItem::factory()->create(['household_id' => $this->household->id, 'list_id' => $list->id, 'title' => 'Milch', 'done_at' => null]);
        $item->update(['done_at' => now()]);
        $item->update(['done_at' => now()->addMinute()]);
        $item->update(['title' => 'Hafermilch']);

        $this->assertSame([DomainEvent::LIST_ITEM_ADDED, DomainEvent::LIST_ITEM_COMPLETED], $this->fired());
    }

    public function test_a_reward_is_requested_then_redeemed(): void
    {
        $child = Person::factory()->child()->create(['household_id' => $this->household->id]);
        $reward = Reward::factory()->create(['household_id' => $this->household->id, 'name' => 'Eis']);
        $redemption = RewardRedemption::factory()->create([
            'household_id' => $this->household->id,
            'reward_id' => $reward->id,
            'person_id' => $child->id,
            'approved_at' => null,
            'rejected_at' => null,
        ]);
        $redemption->update(['approved_at' => now()]);

        $this->assertSame([DomainEvent::REWARD_REQUESTED, DomainEvent::REWARD_REDEEMED], $this->fired());
        Events::assertDispatched(DomainEvent::class, fn (DomainEvent $fired): bool => $fired->payload['title'] === 'Eis');
    }

    public function test_every_name_has_a_label(): void
    {
        $this->assertSame(DomainEvent::NAMES, array_keys(DomainEvent::labels()));
    }
}
