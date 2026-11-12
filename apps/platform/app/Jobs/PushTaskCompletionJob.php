<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\ProviderRegistry;
use App\Models\Task;
use Fahoba\PluginApi\Tasks\WritesTasks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reports a completion to the provider the task came from.
 *
 * On the queue, not in the request: if ticking depended on a third-party
 * service's response time, the wall would be slow whenever it is — and
 * unusable whenever it is down.
 */
final class PushTaskCompletionJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $taskId,
        private readonly bool $done,
    ) {}

    public function handle(ProviderRegistry $registry): void
    {
        $task = Task::withoutHouseholdScope()->with('account')->find($this->taskId);
        $connection = $task?->account;

        if ($task === null || $connection === null) {
            return;
        }

        try {
            $provider = $registry->forAccount($connection);

            if ($provider instanceof WritesTasks && $task->external_id !== null) {
                $provider->markDone($connection, $task->external_id, $this->done);
            }
        } catch (Throwable $exception) {
            /*
             * Logged, not rethrown — as with the list provider: the
             * completion is stored in Fahoba, and that is the authoritative
             * answer to "is this done?". No retry, because a retry would only
             * be a second chance to miss the same convenience.
             */
            Log::warning('Could not report completion to the provider.', [
                'task' => $this->taskId,
                'provider' => $connection->provider,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
