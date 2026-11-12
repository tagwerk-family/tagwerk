<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Event;
use App\Models\ListItem;
use App\Models\RewardRedemption;
use App\Models\TaskCompletion;
use DateTimeInterface;
use Fahoba\PluginApi\Events\DomainEvent;

/**
 * Turns model events into `DomainEvent`s — at one place, not at every write
 * site, so a Google or Bring sync fires the same as the wall.
 *
 * Only changes someone would call an event count: a save that changed
 * nothing visible fires nothing.
 */
final class DomainEvents
{
    private const EVENT_FIELDS = ['title', 'description', 'location', 'starts_at', 'ends_at', 'all_day', 'calendar_id'];

    public static function register(): void
    {
        // created and updated apart: `wasRecentlyCreated` stays true on the instance for every later save.
        Event::created(static fn (Event $event) => self::fire(DomainEvent::EVENT_SAVED, $event->household_id, self::event($event)));
        Event::updated(static function (Event $event): void {
            if ($event->wasChanged(self::EVENT_FIELDS)) {
                self::fire(DomainEvent::EVENT_SAVED, $event->household_id, self::event($event));
            }
        });
        Event::deleted(static fn (Event $event) => self::fire(DomainEvent::EVENT_DELETED, $event->household_id, self::event($event)));

        TaskCompletion::created(static fn (TaskCompletion $completion) => self::fire(DomainEvent::TASK_COMPLETED, $completion->household_id, self::completion($completion)));
        TaskCompletion::deleted(static fn (TaskCompletion $completion) => self::fire(DomainEvent::TASK_REOPENED, $completion->household_id, self::completion($completion)));

        ListItem::created(static function (ListItem $item): void {
            self::fire(DomainEvent::LIST_ITEM_ADDED, $item->household_id, self::item($item));

            if ($item->done_at !== null) {
                self::fire(DomainEvent::LIST_ITEM_COMPLETED, $item->household_id, self::item($item));
            }
        });
        ListItem::updated(static function (ListItem $item): void {
            if ($item->wasChanged('done_at') && $item->done_at !== null && $item->getOriginal('done_at') === null) {
                self::fire(DomainEvent::LIST_ITEM_COMPLETED, $item->household_id, self::item($item));
            }
        });

        RewardRedemption::created(static fn (RewardRedemption $redemption) => self::fire(
            $redemption->approved_at === null ? DomainEvent::REWARD_REQUESTED : DomainEvent::REWARD_REDEEMED,
            $redemption->household_id,
            self::redemption($redemption),
        ));
        RewardRedemption::updated(static function (RewardRedemption $redemption): void {
            if ($redemption->wasChanged('approved_at') && $redemption->approved_at !== null) {
                self::fire(DomainEvent::REWARD_REDEEMED, $redemption->household_id, self::redemption($redemption));
            }
        });
    }

    /** @param  array<string, scalar|null>  $payload */
    private static function fire(string $name, mixed $householdId, array $payload): void
    {
        if ($householdId !== null) {
            DomainEvent::dispatch($name, (int) $householdId, $payload);
        }
    }

    /** @return array<string, scalar|null> */
    private static function event(Event $event): array
    {
        return [
            'id' => $event->id,
            'calendar_id' => $event->calendar_id,
            'title' => $event->title,
            'location' => $event->location,
            'starts_at' => self::iso($event->starts_at),
            'ends_at' => self::iso($event->ends_at),
            'all_day' => (bool) $event->all_day,
        ];
    }

    /** @return array<string, scalar|null> */
    private static function completion(TaskCompletion $completion): array
    {
        return [
            'id' => $completion->id,
            'task_id' => $completion->task_id,
            // Null in a background run without context — the scope hides the task then.
            'title' => $completion->task?->title,
            'date' => $completion->occurred_on?->toDateString(),
            'person_id' => $completion->person_id,
            'for_person_id' => $completion->for_person_id,
            'daypart' => $completion->daypart,
        ];
    }

    /** @return array<string, scalar|null> */
    private static function item(ListItem $item): array
    {
        return [
            'id' => $item->id,
            'list_id' => $item->list_id,
            'title' => $item->title,
            'quantity' => $item->quantity,
        ];
    }

    /** @return array<string, scalar|null> */
    private static function redemption(RewardRedemption $redemption): array
    {
        return [
            'id' => $redemption->id,
            'reward_id' => $redemption->reward_id,
            'title' => $redemption->reward?->name,
            'person_id' => $redemption->person_id,
            'cost' => $redemption->cost,
        ];
    }

    private static function iso(?DateTimeInterface $at): ?string
    {
        return $at?->format(DATE_ATOM);
    }
}
