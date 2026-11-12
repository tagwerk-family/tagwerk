<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConnectionStatus;
use App\Enums\ProviderArea;
use App\Integrations\ProviderRegistry;
use App\Jobs\SyncListJob;
use App\Models\Checklist;
use App\Models\Household;
use App\Models\ProviderAccount;
use Fahoba\PluginApi\Lists\SyncsItems;
use Illuminate\Console\Command;

final class SyncListsCommand extends Command
{
    protected $signature = 'fahoba:sync-lists';

    protected $description = 'Pulls items from providers that sync lists both ways';

    public function handle(ProviderRegistry $registry): int
    {
        $queued = 0;

        // One-way providers have nothing to hand back.
        $keys = array_keys(array_filter(
            $registry->providers(ProviderArea::Lists),
            static fn (object $provider): bool => $provider instanceof SyncsItems,
        ));

        Checklist::withoutHouseholdScope()
            ->whereIn('list_provider', $keys)
            ->whereIn('list_account_id', ProviderAccount::withoutHouseholdScope()
                ->where('area', ProviderArea::Lists->value)
                ->where('status', ConnectionStatus::Active->value)
                ->select('id'))
            ->whereIn('household_id', Household::active()->writable()->select('id'))
            ->each(function (Checklist $checklist) use (&$queued): void {
                SyncListJob::dispatch((int) $checklist->id);
                $queued++;
            });

        $this->info("Queued {$queued} lists.");

        return self::SUCCESS;
    }
}
