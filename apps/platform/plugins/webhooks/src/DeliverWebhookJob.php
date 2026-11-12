<?php

declare(strict_types=1);

namespace Fahoba\Webhooks;

use App\Support\HouseholdContext;
use App\Support\OutboundHttp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * One event to one target, signed, retried twice.
 *
 * Catches everything itself: with the `sync` queue an exception would reach
 * the request, and a webhook that is down would make ticking a task fail
 * although it is long done. A failure costs the delivery, never the action.
 */
final class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** Seconds before the second and the third attempt. */
    public const BACKOFF = [10, 60];

    public string $uuid;

    /** @param  array<string, mixed>  $data */
    public function __construct(
        public int $householdId,
        public int $webhookId,
        public string $event,
        public array $data,
        public string $occurredAt,
    ) {
        $this->uuid = (string) Str::uuid();
    }

    public function handle(HouseholdContext $context, OutboundHttp $http): void
    {
        $context->forId($this->householdId, function () use ($http): void {
            $webhook = Webhook::query()->find($this->webhookId);

            if ($webhook === null || ! $webhook->active) {
                return;
            }

            try {
                $this->deliver($webhook, $http);
            } catch (Throwable $exception) {
                Log::warning('Webhook delivery failed.', ['webhook' => $this->webhookId, 'error' => $exception->getMessage()]);
            }
        });
    }

    private function deliver(Webhook $webhook, OutboundHttp $http): void
    {
        $body = (string) json_encode([
            'event' => $this->event,
            'delivery' => $this->uuid,
            'household_id' => $this->householdId,
            'occurred_at' => $this->occurredAt,
            'data' => $this->data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $own = [
            'Event' => $this->event,
            'Delivery' => $this->uuid,
            'Signature' => 'sha256='.hash_hmac('sha256', $body, (string) $webhook->secret),
        ];

        $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'Fahoba-Webhooks/1.0'];

        // Receivers set up before the rename to Fahoba check X-Tagwerk-*.
        foreach ($own as $name => $value) {
            $headers['X-Fahoba-'.$name] = $value;
            $headers['X-Tagwerk-'.$name] = $value;
        }

        $response = $http->send('POST', $webhook->url, [
            'headers' => $headers,
            'body' => $body,
        ]);

        $delivered = $response !== null && $response->successful();
        // Run at once (sync queue, "send test") there is no later attempt to wait for.
        $last = $delivered || $this->attempts() >= $this->tries || $this->job === null || $this->job instanceof SyncJob;

        WebhookDelivery::query()->updateOrCreate(['uuid' => $this->uuid], [
            'household_id' => $this->householdId,
            'webhook_id' => $webhook->id,
            'event' => $this->event,
            'status' => $delivered ? WebhookDelivery::DELIVERED : ($last ? WebhookDelivery::FAILED : WebhookDelivery::PENDING),
            'response_code' => $response?->status(),
            'error' => $response === null ? __('Not reachable or not a public address.') : ($delivered ? null : Str::limit($response->body(), 200)),
            'attempts' => $this->attempts(),
        ]);

        $this->prune($webhook);

        if (! $last) {
            $this->release(self::BACKOFF[$this->attempts() - 1] ?? 60);
        }
    }

    private function prune(Webhook $webhook): void
    {
        $keep = $webhook->deliveries()->orderByDesc('id')->limit(WebhookDelivery::KEEP)->pluck('id');
        $webhook->deliveries()->whereNotIn('id', $keep)->delete();
    }
}
