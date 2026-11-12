<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something happened in a household that a plugin may want to react to.
 *
 * The one place plugins hook into the course of the day, without knowing
 * models or each other: `Event::listen(DomainEvent::class, …)`. Fired from
 * model events (`DomainEvents`), so the sync fires it as well as the wall.
 *
 * The payload is plain data in a stable shape — ids, titles, ISO dates —
 * never a model: a listener that queues must not depend on the household
 * scope being set when it runs.
 */
final readonly class DomainEvent
{
    use Dispatchable;

    public const EVENT_SAVED = 'event.saved';

    public const EVENT_DELETED = 'event.deleted';

    public const TASK_COMPLETED = 'task.completed';

    public const TASK_REOPENED = 'task.reopened';

    public const LIST_ITEM_ADDED = 'list_item.added';

    public const LIST_ITEM_COMPLETED = 'list_item.completed';

    public const REWARD_REQUESTED = 'reward.requested';

    public const REWARD_REDEEMED = 'reward.redeemed';

    public const NAMES = [
        self::EVENT_SAVED, self::EVENT_DELETED,
        self::TASK_COMPLETED, self::TASK_REOPENED,
        self::LIST_ITEM_ADDED, self::LIST_ITEM_COMPLETED,
        self::REWARD_REQUESTED, self::REWARD_REDEEMED,
    ];

    /** @param  array<string, scalar|null>  $payload */
    public function __construct(
        public string $name,
        public int $householdId,
        public array $payload,
    ) {}

    /** @return array<string, string> name → what someone choosing reads */
    public static function labels(): array
    {
        return [
            self::EVENT_SAVED => __('Event created or changed'),
            self::EVENT_DELETED => __('Event deleted'),
            self::TASK_COMPLETED => __('Task ticked off'),
            self::TASK_REOPENED => __('Task taken back'),
            self::LIST_ITEM_ADDED => __('Item added to a list'),
            self::LIST_ITEM_COMPLETED => __('List item ticked off'),
            self::REWARD_REQUESTED => __('Reward requested'),
            self::REWARD_REDEEMED => __('Reward redeemed'),
        ];
    }
}
