<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Integrations\ProviderRegistry;
use App\Models\ProviderAccount;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Fahoba\PluginApi\Connections\FinishesConnection;
use Fahoba\PluginApi\Lists\RemoteList;
use Fahoba\PluginApi\Tasks\ImportsTasks;
use Fahoba\PluginApi\Tasks\ListsTaskLists;
use Fahoba\PluginApi\Tasks\RemoteTask;

/**
 * Pulls in the tasks of a connected account.
 *
 * Title, notes and due date come from outside and are refreshed on every
 * sync. Points, assignee, rotation and order are **never** touched by the
 * sync: that is the enrichment the household has laid on top, and writing it
 * away would mean setting it again after every sync.
 */
final class TaskImportService implements FinishesConnection
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly HouseholdContext $context,
    ) {}

    public function finish(ConnectedAccount $account): void
    {
        $this->import(ProviderAccount::of($account));
    }

    /**
     * @return int number of tasks taken over
     */
    public function import(ProviderAccount $account): int
    {
        return $this->context->forId(
            (int) $account->household_id,
            function () use ($account): int {
                $provider = $this->registry->forAccount($account);

                if (! $provider instanceof ImportsTasks) {
                    return 0;
                }

                $this->rememberLists($account, $provider);

                $count = 0;

                foreach ($provider->tasks($account) as $remote) {
                    $count += $this->takeOver($account, $remote);
                }

                $account->forceFill(['synced_at' => now()])->save();

                return $count;
            },
        );
    }

    /**
     * The account's lists for the picker in the admin.
     *
     * In the connection's settings instead of a table of their own: the names
     * only serve the picker, and a table for a few names would also have to
     * be maintained.
     */
    private function rememberLists(ProviderAccount $account, ImportsTasks $provider): void
    {
        if (! $provider instanceof ListsTaskLists) {
            return;
        }

        $settings = (array) ($account->settings ?? []);

        $settings['lists'] = array_map(
            static fn (RemoteList $list): array => ['id' => $list->id, 'name' => $list->name],
            $provider->taskLists($account),
        );

        $account->forceFill(['settings' => $settings])->save();
    }

    private function takeOver(ProviderAccount $account, RemoteTask $remote): int
    {
        $task = Task::withoutHouseholdScope()
            ->withTrashed()
            ->where('task_account_id', $account->id)
            ->where('external_id', $remote->externalId)
            ->first();

        // Deleted by the household: the sync must not bring it back.
        if ($task?->trashed()) {
            return 0;
        }

        if ($task === null) {
            $task = Task::withoutHouseholdScope()->create([
                'household_id' => $account->household_id,
                'provider' => $account->provider,
                'task_account_id' => $account->id,
                'external_id' => $remote->externalId,
                'title' => $remote->title,
                'notes' => $remote->notes,
                'due_on' => $remote->dueOn?->toDateString(),
                'external_version' => $remote->version,
            ]);

            $this->complete($task, $remote);

            return 1;
        }

        // Unchanged: the row stays as the household left it.
        if ($remote->version !== null && $remote->version === $task->external_version) {
            return 0;
        }

        $task->forceFill([
            'title' => $remote->title,
            'notes' => $remote->notes,
            'due_on' => $remote->dueOn?->toDateString(),
            'external_version' => $remote->version,
        ])->save();

        $this->complete($task, $remote);

        return 1;
    }

    /**
     * Ticked outside means ticked here — but without points.
     *
     * Points belong to the person who tapped. Whoever ticks a task on the
     * train has done it; the remote service does not know whom to credit,
     * and guessing would be worse than booking nothing.
     */
    private function complete(Task $task, RemoteTask $remote): void
    {
        if (! $remote->isDone() || $task->due_on === null) {
            return;
        }

        TaskCompletion::withoutHouseholdScope()->firstOrCreate(
            [
                'task_id' => $task->id,
                'occurred_on' => $task->due_on->toDateString(),
            ],
            [
                'household_id' => $task->household_id,
                'person_id' => null,
                'completed_at' => $remote->completedAt,
            ],
        );
    }
}
