<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConnectionStatus;
use App\Enums\ProviderArea;
use App\Integrations\ProviderRegistry;
use App\Jobs\SyncTasksJob;
use App\Models\Household;
use App\Models\ProviderAccount;
use Fahoba\PluginApi\Tasks\ImportsTasks;
use Illuminate\Console\Command;

/**
 * Imports the tasks of all connected accounts.
 *
 * Polling instead of notifications: Google Tasks has no push channels, and
 * for a wall tablet it is enough when dad's task shows up within fifteen
 * minutes.
 */
final class SyncTasksCommand extends Command
{
    protected $signature = 'fahoba:sync-tasks';

    protected $description = 'Imports the tasks of all connected accounts';

    public function handle(ProviderRegistry $registry): int
    {
        $queued = 0;

        // A provider without import has nothing to deliver.
        $keys = array_keys(array_filter(
            $registry->providers(ProviderArea::Tasks),
            static fn (object $provider): bool => $provider instanceof ImportsTasks,
        ));

        ProviderAccount::withoutHouseholdScope()
            ->where('area', ProviderArea::Tasks->value)
            ->where('status', ConnectionStatus::Active->value)
            ->whereIn('provider', $keys)
            // A suspended household keeps its data, but nothing runs for it.
            ->whereIn('household_id', Household::active()->writable()->select('id'))
            ->each(function (ProviderAccount $connection) use (&$queued): void {
                SyncTasksJob::dispatch((int) $connection->id);
                $queued++;
            });

        $this->info("Queued {$queued} connections.");

        return self::SUCCESS;
    }
}
