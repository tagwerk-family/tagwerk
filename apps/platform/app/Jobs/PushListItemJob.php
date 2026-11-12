<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\ProviderRegistry;
use App\Models\ProviderAccount;
use Fahoba\PluginApi\Lists\ListItemDraft;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes an item to the list provider — on the queue, not in the request.
 *
 * Such a service is a convenience. If adding an item depended on its response
 * time, the shopping list on the tablet would be slow whenever it is — and
 * unusable whenever it is down.
 */
final class PushListItemJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $provider,
        private readonly ?int $accountId,
        private readonly string $name,
        private readonly ?string $spec = null,
        private readonly ?string $target = null,
    ) {}

    public function handle(ProviderRegistry $registry): void
    {
        try {
            $registry->listProvider($this->provider)->push(
                [new ListItemDraft($this->name, $this->spec)],
                $this->accountId === null
                    ? null
                    : ProviderAccount::withoutHouseholdScope()->find($this->accountId),
                $this->target,
            );
        } catch (Throwable $exception) {
            /*
             * The failure is logged, not rethrown.
             *
             * The promise "an outage costs a convenience, not the core
             * feature" must not depend on the configured queue driver. With
             * `sync` — common in tests and small installations — a rethrown
             * exception would break the response, and adding an item would
             * fail although it is long saved in Fahoba.
             *
             * No retry: the item is in the authoritative list. A retry would
             * only be a second chance to miss the same convenience.
             */
            Log::warning('Could not push item to the list provider.', [
                'provider' => $this->provider,
                'item' => $this->name,
                'target' => $this->target,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
