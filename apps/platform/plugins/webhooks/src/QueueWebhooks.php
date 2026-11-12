<?php

declare(strict_types=1);

namespace Fahoba\Webhooks;

use App\Models\Household;
use Fahoba\PluginApi\Events\DomainEvent;

/** Hears every domain event and hands it to the household's targets that chose it. */
final class QueueWebhooks
{
    public function handle(DomainEvent $event): void
    {
        $household = Household::query()->find($event->householdId);

        if ($household === null || $household->suspended_at !== null || ! $household->has(WebhooksArea::KEY)) {
            return;
        }

        $targets = Webhook::withoutHouseholdScope()
            ->where('household_id', $event->householdId)
            ->where('active', true)
            ->get()
            ->filter(fn (Webhook $webhook): bool => in_array($event->name, $webhook->events, true));

        foreach ($targets as $webhook) {
            // After the commit: the receiver may ask back, and must find what it was told about.
            DeliverWebhookJob::dispatch($event->householdId, (int) $webhook->id, $event->name, $event->payload, now()->toIso8601String())
                ->afterCommit();
        }
    }
}
