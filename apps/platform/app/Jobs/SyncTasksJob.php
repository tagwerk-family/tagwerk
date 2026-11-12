<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ProviderAccount;
use App\Services\Tasks\TaskImportService;
use Fahoba\PluginApi\Connections\AuthorizationLost;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Imports the tasks of one connection.
 */
final class SyncTasksJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $accountId) {}

    public function handle(TaskImportService $import): void
    {
        $connection = ProviderAccount::withoutHouseholdScope()->find($this->accountId);

        if ($connection === null) {
            return;
        }

        try {
            $import->import($connection);
        } catch (AuthorizationLost) {
            /*
             * Not an incident: the state is already stored on the connection
             * and the wall shows it. Retrying would not help — a human has to
             * grant access again.
             */
            Log::info('Task sync needs a new grant.', [
                'connection' => $this->accountId,
            ]);
        }
    }
}
