<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ListItemChange;
use App\Integrations\ProviderRegistry;
use App\Models\ProviderAccount;
use Fahoba\PluginApi\Lists\ListItemDraft;
use Fahoba\PluginApi\Lists\SyncsItems;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Passes a tick, an untick or a removal on to a provider that syncs both ways.
 *
 * Carries the name instead of the item: after a removal there is no item
 * left to load.
 */
final class MirrorListItemJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $provider,
        private readonly int $accountId,
        private readonly string $target,
        private readonly string $name,
        private readonly ?string $spec,
        private readonly ListItemChange $change,
    ) {}

    public function handle(ProviderRegistry $registry): void
    {
        $provider = $registry->listProvider($this->provider);
        $account = ProviderAccount::withoutHouseholdScope()->find($this->accountId);

        if (! $provider instanceof SyncsItems || $account === null) {
            return;
        }

        $item = new ListItemDraft($this->name, $this->spec);

        // Same promise as when pushing: an outage costs the mirror, not the list.
        try {
            match ($this->change) {
                ListItemChange::Done => $provider->markDone($account, $this->target, $item, true),
                ListItemChange::Open => $provider->markDone($account, $this->target, $item, false),
                ListItemChange::Removed => $provider->remove($account, $this->target, $item),
            };
        } catch (Throwable $exception) {
            Log::warning('Could not mirror a list change to the provider.', [
                'provider' => $this->provider,
                'item' => $this->name,
                'change' => $this->change->value,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
